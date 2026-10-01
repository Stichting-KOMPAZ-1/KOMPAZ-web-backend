# CLAUDE.md — KOMPAZ web backend

Multi-tenant user and organization management with passwordless (magic-link) sign-in and
invitations, plus the modules and e-learning courses those organizations are given.
PHP 8.4, Laravel 13, MySQL, Nova 5 for the operator's panel, deployed to fortrabbit.

## Layout

```
app/Actions          use cases, one class per thing that can happen: {Feature}/{Verb}{Thing}Action;
                     content's are in Modules/ and serve the panel and the API alike
app/Models           Eloquent models; entity behaviour lives on them, orchestration does not
app/Enums            fixed state as enums, stored and serialized by name
app/Events           domain events, all dispatched after the transaction commits
app/Listeners        the reactions to those, one per event
app/Http             thin controllers, form requests, API resources, middleware
app/Services         the authentication machinery: token issuing and secret hashing
app/Support          Access (tenancy), Errors (problem details), Pagination, Search, Images,
                     Files (a row pointing at a disk), Modules (reach, copy, list limits),
                     Videos (the video disk, format sniffing, playback redirects)
app/Nova             the operator's panel; every write is an Action delegating to app/Actions,
                     except the content resources — see rule 18. Repeatables/ holds the
                     repeating form rows; Module and ModuleActivation are the same content
                     seen by the side that writes it and the side that has a tenant
tests/               Feature (through HTTP, against real MySQL) and Unit
```

## Commands

```sh
docker compose up -d                      # the dev database on localhost:3307, and Azurite
                                          # for uploaded videos (one-time setup: docs/deployment.md)
php artisan serve                         # run the API
composer check                            # THE gate: PHPStan level 6 + Pint, both must be clean
php artisan test                          # 430 tests; needs the MySQL container running
php artisan migrate --seed                # schema, the platform organization, its first admin,
                                          # and, on an empty table only, the first module
                                          # categories (the platform manages them after that)
```

## Iron rules

1. **`composer check` is the gate.** PHPStan runs at level 6 over `app`, `routes`, `database` and
   `tests`, and Pint enforces `declare(strict_types=1)` everywhere. CI runs both plus the tests.
2. **Tenancy is manual.** Every action calls `OrganizationAccess` (`ensureCanRead` / `ensureCanManage`
   / `ensureCanManageRole` / `ensureCanChangeRole` / `ensureCanGrantRole` / `ensureCanHoldRole` /
   `resolveTarget`). A `role:` middleware on a route is a floor on seniority and **never** a tenant
   check; both questions get asked. An unscoped new query is a security bug, not an oversight.
3. **Soft delete has two deliberate exceptions.** `withTrashed()` appears in exactly three places:
   inviting (a deleted row still holds the address, which is the unique key), restoring (its whole
   subject is a deleted row), and the roster's `includeDeleted`. Anywhere else, reaching a deleted
   user is a bug. The address is unique across the whole table rather than within an organization,
   so a re-invitation takes the deleted row over and brings it along into whatever organization the
   invitation names — leaving it where it was would free the address for that one organization and
   burn it for every other. Taking one over is a move, and a move needs both ends: a caller who does
   not manage the organization the row sits in is told the address is in use, word for word what
   somebody still there answers, so nothing leaks out of an organization they cannot see. **An
   invitation nobody ever accepted is the one thing deleting somebody removes for real**
   (`forceDelete` in `DeleteUserAction`, guarded on `activated_at` being null): there is no account
   behind it to restore and nothing was ever done under its identifier, so a marked row would only
   hold its address hostage. Somebody who did sign in once is still only marked — including when a
   re-invitation of theirs is withdrawn, which is why the status alone does not decide it. The
   *other* `forceDelete` is `PurgeUserAction`, which is not a delete at all but the panel's separate
   operation: the panel offers **archiveren** (that same `DeleteUserAction`, which marks and can be
   undone) and **verwijderen** (this, which cannot), because an operator choosing between two
   buttons that both end an account needs the words to say which one is reversible. It gives up what
   soft delete exists to keep — `created_by`/`updated_by` elsewhere go on naming an identifier with
   no row under it, which is why those columns carry no foreign key — and it asks
   `AdministratorCoverage` only of somebody not already marked, since one who is left that pool when
   they were marked and asking again would make their row permanently unremovable.
4. **A single-use secret is spent with a conditional `UPDATE`, never a read followed by a write.**
   `ClaimLoginTokenAction` puts every reason to refuse — unknown, spent, expired — into the `WHERE`
   and checks the affected-row count, so a link is either claimed by this request or not claimed at
   all. Two requests carrying the same secret cannot both open a session. Do not "simplify" this
   back into a check-then-save. It is one method because both things that can redeem a link — the
   API and Nova — must spend it the same way.
5. **A sign-in has two credentials, and both are rows.** An API token is a Sanctum personal access
   token: only a hash is stored, and it is revoked by deleting its row. The browser application
   signs in the same way but keeps a session cookie instead, so a script on the page never holds a
   credential — and a session is revoked the same way, by deleting its row in `sessions`
   (`SessionRegistry`). Neither carries a claim, so nothing goes stale: the user row is read on
   every request, which is why deleting or demoting somebody takes effect on their very next call
   with nothing to compare. Anything that should end a session calls
   `AuthenticationTokenService::revokeAll`, which ends both kinds — never `$user->tokens()` alone,
   or a browser stays signed in. This is also why `SESSION_DRIVER` must be `database`, checked at
   startup.
6. **Which credential a caller gets is decided by where the request came from.** A request whose
   Referer or Origin matches `sanctum.stateful` is put through Sanctum's session and CSRF
   middleware (`statefulApi()` in `bootstrap/app.php`); every other request reaches the API with an
   Authorization header and nothing else, exactly as before. Nothing in a request body or header
   can ask for a session. Redeeming a link always answers with a token as well, so a mobile or
   server client is unaffected. Sanctum consults the session guard *before* it reads a bearer
   token, so a request carrying both is answered as the session.
7. **Cross-cutting reactions go through events**, never service calls from an action. Every event
   implements `ShouldDispatchAfterCommit`, so a reaction never holds a transaction open across
   network I/O — and so it may fail after the data is safely committed. Anything that raises one
   must therefore be safe to repeat (re-inviting a pending user resends rather than conflicting).
8. **Listeners are registered by name in `AppServiceProvider`, and discovery is off**
   (`->withEvents(discover: false)` in `bootstrap/app.php`). With both on, every listener fires
   twice and every notice goes out twice.
9. **Two files know which database this is**: `Support/Persistence/UniqueConstraint.php` (MySQL error
   1062, which turns a lost uniqueness race into a 409 instead of a 500) and
   `Support/Search/SearchPattern.php` (case folding through `UPPER()` on both sides). Changing
   provider means changing both — nothing else.
10. **A name and an email are unique folded, not as typed.** `users.normalized_email` and
    `organizations.normalized_name` carry the unique index. Compare against the normalized column,
    never the raw one.
11. **At most one platform organization**, enforced by a generated column
    (`platform_marker`) with a unique index — MySQL has no partial indexes, and NULLs do not collide.
12. **An uploaded file is a row that points at a disk, and its format is read out of its bytes.**
    `LogoImage::detectContentType()` decides the media type; the upload's own `Content-Type` and
    file name are never believed, because the stored value is what a later response is labelled
    with. The key is minted from the organization's id and a fresh identifier, never accepted from
    a caller. The bucket is private, so a logo is read back through the application: the API,
    which checks the token, and `/beheer/organisaties/{id}/logo` for the panel, whose pages send a
    session cookie and cannot send a token. Both answer out of `ServedLogo` — same bytes, same
    headers, a different guard.
13. **A file outlives its transaction, so letting go of one is an event.** Every path that stops
    pointing at a file dispatches `OrganizationLogoDiscarded`, handled after the commit. An upload
    writes its bytes *before* its row; a deletion removes its row *before* its bytes. Every failure
    therefore leaves an orphaned file rather than a row pointing at nothing — an orphan costs
    storage and is logged, a dangling pointer would be a broken image. Never "fix" this by deleting
    the file first.
14. **"Somebody has to be left" lives in `AdministratorCoverage`**, not in the action. Deleting,
    demoting and moving all take a person out of an organization's administrators, and a move or a
    demotion can also take the last platform administrator. A fourth way to remove somebody asks
    there too.
15. **Anything a caller reads is Dutch; anything an operator reads is English.** Every `detail`,
    every validation message, every conflict. Log messages and startup failures stay English:
    nobody reading those is a user. Problem-details `title` is the exception and stays English — it
    names the status from the HTTP specification's vocabulary. Wording the product dictates lives in
    a constant (`OrganizationMessages`) when two requests have to answer alike, and is asserted by a
    test.
16. **Validation failures answer 400, not Laravel's 422.** That is the status this API has always
    returned and the one clients branch on; `ProblemDetailFactory` states it. Scramble does not
    know that: its built-in extensions describe Laravel's defaults, so the generated document at
    `/docs/api` claimed 422 with `{message, errors}` until `ProblemDetailResponseExtension` replaced
    them. Every refusal in the document is `application/problem+json`, and
    `ApiDocumentationTest` fails if one stops being.
17. **Outside local development, startup refuses `MAIL_MAILER=log`**, which writes sign-in links
    into the log. Never widen that exemption past `local` and `testing`.
18. **The panel writes only through `app/Nova/Actions`.** Nova's own create, edit and delete are
    refused on every resource (`authorizedToCreate`/`Update`/`Delete`) and every field is
    `readonly()`, because a Nova form writes columns straight to the database and would go around
    the folded email column, `AdministratorCoverage`, the tenancy checks and the events that carry
    the notices people are owed. Each operation the API offers is instead a `sole()` or
    `standalone()` Nova action calling the same use case, so the panel is exactly as capable as the
    API and no more permissive — with one deliberate exception in the other direction,
    `PurgeUser`, which removes a user for good and has no endpoint behind it, because a client that
    can be wrong about a request should not be able to be irreversibly wrong. It is still a use
    case in `app/Actions` rather than a form, for the reason every other operation is. `RunsUseCase` is what they share: it resolves the operator, takes
    the one selected record, and turns a `ProvidesProblemDetail` refusal into the panel's banner
    while rethrowing anything else — a defect reported as a refusal would tell an operator a rule
    stopped them when nothing did. **A refusal about something the operator typed names its field**
    (`attempt(..., refusalField: 'name')`) and is answered as a validation error instead: Nova
    closes the dialog on a banner and leaves it open on a field error, and a closed dialog means
    retyping a form to correct one word. Nova also hands its own validator no messages, so a rule
    that has to answer in the product's words is a rule *object* (`OrganizationName`,
    `AcceptableLogo`) — which is what keeps the panel's forms and the API's form requests refusing
    the same things in the same sentences. **The content resources are the deliberate exception**
    (`Module`, `ELearning`, `Chapter`, `Step`, and the rows under them): Nova's own create and edit
    are allowed there. Read the reasons above and notice that none of them is about content — a
    module has no folded unique column, nobody is emailed when one changes, and the one tenancy
    question it raises is answered by `ModuleActivation` rather than by a use case. What is left is
    a form writing columns, which is what a form is for. The rules that do exist still live outside
    the resource — a count is a rule object, an upload is `AcceptableLogo` — and anything that has
    to hold true whoever performs it is still an action in `app/Actions`. **Users, organizations and
    module categories do not move**: the reasons in this rule are all still true of them — a
    category's name is unique folded, so it is written by `CreateModuleCategory`,
    `RenameModuleCategory` and `DeleteModuleCategory`, over the same use cases the API calls.
19. **A link goes back to where it was asked for.** `SignInLink` is the only thing that builds
    one. A magic link asked for through `POST /api/auth/magic-link` comes from the frontend's login
    page, so `SignInLink::frontend` points it at `FRONTEND_URL` + `/inloggen`, and the
    frontend redeems it through the API. Sending it to the panel instead spent the secret on a
    route that turns away everybody who is not an operator. Invitations and the panel's own link
    use `SignInLink::panel` and land on `nova.sign-in.claim`. Spending an invitation is what moves
    an invitee from invited to active, so the click accepts the invitation whether or not the
    panel then admits them. Whether it does is asked of the `viewNova` gate and not of the role —
    an invitation reaches the route for somebody who was never meant in at all, and it outlives a
    demotion by a week where a magic link outlives one by thirty minutes. Refused there means no
    session, rather than a 403 on the next page. `POST /api/auth/tokens` redeems any of these
    secrets for a token, which is how the frontend spends its link.
20. **Audit columns are stamped by the `StampsAuditor` trait** — never set `created_by`/`updated_by`
    in an action. Model keys are UUIDv7 via `HasUuids`: time-ordered, so inserts land at the end of
    the primary-key index instead of scattering.
21. **"Signed out" is one call, never a list of things to delete.** There are two kinds of
    credential now — the API token a client carries and the cookie session a browser holds — and
    `AuthenticationTokenService` (`revokeAll`, `revokeAllFor`) is the only place that knows both.
    An action that reaches for `$user->tokens()` or `personal_access_tokens` itself is a bug
    waiting for the next credential: archiving an organization did exactly that, was written before
    the cookie existed, and went on passing its tests while leaving every browser signed in to a
    closed organization. Anything that ends somebody's access asks that service.
22. **A module belongs to nobody; a `ModuleActivation` belongs to exactly one organization.** That
    row is the tenant boundary for the whole module feature, and it is why activations are rows with
    keys of their own rather than a bare pivot: an organization's own videos, its own links and —
    above all — its contact details hang off it. A method that answered "the videos of this module"
    without saying whose would be the bug that shows one organization another one's phone number.
    `ScopesToOperator::scopeToOperatorsOrganization` does not fit here, because a module carries no
    `organization_id`; **`ModuleAccess` is where that question is asked instead**, and every content
    endpoint asks it the way every user endpoint asks `OrganizationAccess`.
    `ModuleAccess::resolveActivation` both refuses and returns, because for everybody but a platform
    administrator the two are one lookup. A platform administrator gets null, not somebody's:
    picking an organization would be picking whose phone number to show them. **Content refuses with
    404, not 403** — which modules the platform has written is not something one organization should
    be able to enumerate through another's refusals, and this is deliberately the opposite of the
    choice made for organizations, where the caller already holds the identifier. **"Globaal" is
    computed, never stored** (`ModuleReach`): a module switched on everywhere yesterday stops being
    global the moment there is a new organization it was not switched on for. **"Actief bij" is a form field
    that writes no column**: it is a `BooleanGroup` whose `fillUsing` returns a callable, which Nova
    runs *after* the model is saved — the only way a create form can hand out a module that did not
    exist when the form was read. It calls the same use case the two dialogs call, so the date rule
    has one implementation and three doors.
23. **What a row is allowed to be is a check constraint, not only a form rule.** A module video has
    exactly one owner and is a link or a file; a content block has what its type says and nothing
    belonging to another type; a picture is three columns that are only ever true together. All are
    stated on the table, because these rows are read back and assembled into somebody's screen — a
    picture block with no picture is a gap with nothing to explain it, and a row carrying both a
    link and a file makes "the video" a question about precedence. A form refuses them first and in
    Dutch; the constraint is what holds when something goes around the form. `applyFile`/`applyUrl`
    clear each other for that reason: a save the database refuses is a failure the operator did not
    cause and cannot read.
24. **Deleting content is permanent, and nothing soft-deletes.** Rule 3 is about people; the product
    asked twice, in words an operator reads before confirming, for these to be gone. Deleting a
    module unlinks its courses and deletes nothing of theirs; deleting a course unlinks its modules
    the same way. Both are plain cascading foreign keys, so no use case has to remember them. **What
    a cascade cannot do is tell anyone which files went**: a foreign key removes rows without
    Eloquent seeing one of them, so anything holding a `*_storage_key` has to be found *before* the
    delete — afterwards nothing knows where the bytes were. That is `DiscardsStoredFiles` and its
    `discardableKeys()`, which a parent implements by going and collecting its descendants' keys. It
    is a **model** concern rather than an action because content has no single use case a delete
    passes through: the panel, a test and tinker are three callers and all three owe the disk the
    same thing.
25. **Content has two doors, the panel and the API, and one set of rules.** Modules, courses,
    chapters and steps were written in the panel only, until the frontend's admin pages needed the
    same; the API now writes all of it. Nothing is stated twice: every field's rule is
    `Support\Modules\ContentRules`, asked by the Nova fields and the form requests alike; a step's
    blocks are saved by `SaveStepBlocksAction`, which the panel's `ContentBlockPreset` calls too;
    a picture is stored by `Support\Files\StoredImage`, reading its format from its bytes (rule
    12); an ordered list of rows is `OrderedRows`, which deletes through the model (rule 24).
    **Writing the platform's content is the platform's**: a `PlatformAdministrator` floor on the
    routes *and* `ModuleAccess::ensureCanManageContent` in every action. **What an organization
    adds to its copy is that organization's**: `PUT /api/modules/{module}/organizations/{org}`,
    asked of `OrganizationAccess::ensureCanManage` with the organization in the address, so an
    organization administrator reaches their own copy and the platform any (rule 22). **A list
    left out of a JSON body is left as it is, and `[]` clears it** — a client renaming a course
    must not unlink its modules by not mentioning them. A key a client sends for a row is looked up
    among that parent's rows only, and is a new row otherwise. Pictures are their own endpoints,
    multipart, sent as a POST with `_method=PUT` because PHP reads no files out of a real PUT; a
    course is created multipart because it cannot exist without one. A file is still never
    addressed by its own identifier — `/api/modules/{module}/videos/{video}/file` — and what is
    nested is bound through its parent (`scopeBindings()` on the chapter and step routes), so a
    chapter named under the wrong course is not found. `ServedFile` is `ServedLogo` without the
    placeholder, and stays a separate class for that one reason: an organization must always look
    like something, content need not. A module with no picture answers `imageUrl: null` rather
    than an address that 404s, so a client is not made to probe once per card.
26. **A count is the one rule the database cannot hold, so it lives on the form — twice.** A check
    constraint is about a row; "at most ten videos" is about a set. The product's numbers are in
    `config/kompaz.modules`, the sentences in `ModuleMessages`, and the rule is `LimitedList` — a
    rule *object*, because Nova hands its own validator no messages and `max:10` would answer an
    operator in Laravel's English about a field called `videos`. Stated on both forms that build
    such a list, since there is no one place underneath them that sees the whole set.
27. **Deleting a module is the one place the content carve-out does not reach.** Nova's own row
    delete is off for that resource, because its confirmation modal carries a generic sentence and
    no resource can give it one of its own — and the product wrote a specific one, which promises
    that the courses inside survive the module. `Actions\DeleteModule` is a `DestructiveAction`
    carrying that copy verbatim from `ModuleMessages`, asserted by a test. Overriding Nova's global
    Dutch string would have worked today, because this is the only resource with a native delete at
    all, and would have quietly become wrong for the next one.
28. **An uploaded video never passes through PHP, and an upload is spent exactly once.** Videos are
    on their own disk — a private Azure blob container, Azurite locally — and every other file
    stays on the default one; the key says which (`VideoStorage`: a video's starts with `videos/`),
    so `ContentFileDiscarded` still carries a key and nothing more. The browser asks for a link
    that can create one blob (`IssueVideoUploadAction`), writes the file in blocks, and asks for it
    to be looked at (`CompleteVideoUploadAction`): rule 12 again, the format read from the first
    bytes and the size read off the blob, and anything else removed on the spot. A form or request
    then names the upload by its key, and `ClaimVideoUploadAction` spends it with rule 4's
    conditional `UPDATE` — issued to this caller, verified, unclaimed, unexpired. **That claim is a
    tenancy check, not bookkeeping**: an upload anyone could name would let one organization show
    another's video under its own module, and two rows on one blob would each delete the other's
    bytes. Link or upload, never both, and neither keeps the file a row already has
    (`ApplyVideoSourceAction`, shared by module videos and video blocks through both doors).
    Playback is a 302 to a read-only link that expires (`VideoPlayback`), not `ServedFile`: Azure
    answers the range requests a player makes, and a PHP process holding two gigabytes could not.
29. **Prose is markup, and it is cleaned in exactly one place: the column.** A module's and a
    chapter's description, a module's source attribution, a text block's body and an
    organization's two contact notes are written in the panel's editor (`Nova\Fields\RichText`,
    Nova's own `Trix` configured once) and stored as HTML. `Support\Html\SanitizedHtml` is the
    cast on every one of those columns and the only thing that ever strips anything — **Nova's own
    Trix sanitizing is deliberately off**, because the panel is one of two doors (rule 25) and a
    field that cleaned as well would be a second allowlist to keep in step with the first. A cast
    rather than a field, a request or an action, because those three never meet: a Nova form writes
    the column straight, the API writes it through an action, and a block's body reaches its row
    from `ContentBlockPreset` without the field that drew it filling anything. The allowlist is
    Symfony's `allowSafeElements()`, which is what Nova's Trix defaults to, so what the editor can
    produce is what survives. Nothing is cleaned on the way out; the row already holds what was
    allowed. **Markup that strips to nothing becomes null**, which is right for a column that may be
    left out and is a check constraint away from a 500 for one that may not — so a field that may
    not be blank pairs `required` with `NonEmptyHtml` (`ContentRules::markup()`), rule 23's division
    again. Attachments stay off: a picture inside a step is an image block, which is a row that can
    be found again (rules 12 and 13). **A client renders these fields as HTML** — `description`,
    `sourceAttribution`, `body`, `reason` and `availability` changed meaning the day this landed,
    and the frontend has to escape nothing and render them.

## Things that have already cost time

- **Nova's `asHasMany()` preset writes a list by query.** Without a unique field it deletes every row
  under the parent with one query and inserts the list again on each save; with one, it still
  removes the missing rows by query. No model event sees either, so it cannot hold a row that owns a
  file — and a re-insert drops an upload the form never sends back. Module videos kept `asHasMany()`
  for reading and the row resource, and swapped the writing half for `Repeatables\ModuleVideoPreset`,
  which goes through `SaveModuleVideosAction`. Links and contacts still use Nova's: they own nothing.
- **Nova does not set `$resource` on every path that serializes an action, so every prefilled
  dialog opened blank.** A row's inline menu is rendered from the listing, where no single record
  is in hand, and `RunsUseCase::selected()` read only `$this->resource` — so "Actief bij" showed
  every organization unticked whatever was actually assigned, and saving after ticking one box
  silently removed the rest. `selected()` now falls back to the `resourceId` the request carries,
  in that order: `$resource` is what works from a detail page, where there is no `resourceId` at
  all. `->default()` is *not* the fix and looks like it — Nova serializes `value ?? default`, and a
  BooleanGroup resolves to `[]` rather than null, so a default is never reached. The value goes in
  `meta`, which is merged last.
- **Without a policy, Nova's `authorizedTo*` methods only draw buttons.** The request that opens,
  edits or replicates a single record asks `authorizeToView`/`authorizeToUpdate`, which consult a
  policy and let everything through when there is none — and there are none here. So an
  organization administrator could open and rename any module or course, and overwrite another
  organization's contacts with a `PUT` typed by hand, while every listing correctly hid them and
  `detailQuery` answered 404 (an edit finds its record without it). The base `App\Nova\Resource`
  now enforces each resource's own answers on those paths. A resource's `authorizedToView` and
  `authorizedToUpdate` are therefore real tenant checks: ask them of the row, as `ModuleActivation`
  does, and never answer a bare `true`. Deletes and actions were never affected — Nova filters
  those rows through `authorizedToDelete` and answers 200 either way, so a test proves a refused
  delete by the row still being there.
- **Nova's own repeater preset cannot hold a step's blocks.** It matches a row to its repeatable by
  model class, so three kinds of block over one model all read back as the first kind; and it
  removes rows with a query delete, which no model event sees, so a removed picture's bytes would
  stay on the disk (rule 24). `Repeatables\ContentBlockPreset` reads by type and deletes through the
  model. It looks up a row's hidden key only among the step's own blocks.
- **`outl1ne/nova-sortable`'s own routes must never be registered.** The package draws the
  drag-and-drop for chapters and steps, and its controller authenticates nobody (its routes carry
  only the `nova` group) and calls `$parent->{$viaRelationship}()` on a record looked up from the
  request — `delete` is a method too. Its provider is in `dont-discover`; its script and stylesheet
  are registered in `NovaServiceProvider`, and the three paths it posts to are ours
  (`NovaReorderController`, behind `nova:api`), which know two lists and nothing else. Re-enabling
  discovery, or upgrading and letting a new provider in, puts that controller back.
- **This application's own Nova frontend is one committed script, `resources/nova/panel`.** It
  holds the module form's picker (`Fields\CheckboxList`, a BooleanGroup to the server with search
  and select all, which Nova has no field for) and the fix that makes nova-sortable's table read
  its rows back after a drag — without it the second drag on a page reported success and moved
  nothing. The deploy runs Composer and nothing else, so `dist/js/panel.js` is built locally
  (`npm ci && npm run production` there) and committed; editing a source file without rebuilding
  changes nothing in the panel. Its webpack is pinned to Nova's devtool's: laravel-mix 6 breaks on
  newer webpack releases.
- **The frontend's nginx forwards only the paths it lists.** The panel is reached through the
  frontend's domain, whose nginx (`default.conf.template` in the frontend repository) sends
  `/nova`, `/nova-api`, `/nova-vendor`, `/vendor/nova` and `/beheer` here and everything else to
  the SPA. A new path the panel calls — a Nova package's routes, a new `/beheer/...` sibling —
  has to be added there too, or it fails only when deployed: a GET gets the SPA's `index.html`,
  and a POST gets nginx's own "405 Not Allowed". `/nova-vendor` was missing, and dragging a row
  was the first thing to post there.
- **`createButtonLabel()` is also the create form's submit button.** Nova uses the one label for
  the button above a table and for the button that saves the form, so "+ Nieuw hoofdstuk" on the
  first put the same words under a filled-in form. Leave it at Nova's ":resource aanmaken".
- **An Image field's `preview()` is not its thumbnail.** Nova draws the thumbnail — the picture on
  a form and in a table — from the disk's public address unless told otherwise, and the disk is
  private, so every picture in the panel was a broken image while the preview route worked. Every
  Image field here sets `->thumbnail()` to the same panel route as `->preview()`.
- **Nova builds breadcrumbs inside its page controllers, one level deep.** A step read "Stappen ›
  …", naming a list nobody navigates to and leaving out its course and chapter. There is no hook on
  the resource, so `NovaServiceProvider::register()` binds subclasses of the detail, create and
  edit page controllers; a resource that implements `Breadcrumbs\NestedResource` gets the whole
  path (`ContentTrail`), and every other one keeps Nova's.
- **A form's image preview is built for a record that does not exist yet.** Nova assembles the
  creation fields against an unsaved model, so `route(..., ['x' => $this->model()->getKey()])` in a
  `preview()` callback throws — and the whole create form answers 500, not a missing thumbnail. Any
  callback on a form field has to survive having no key. A test that posts to a form does not catch
  this; only one that *opens* it does, which is why `creation-fields` and `update-fields` are now
  asserted for both content resources.

- **Nova asks for JSON on every request, so `expectsJson()` handed the whole panel the API's error
  shape.** A validation failure in a Nova form or action arrived as a 400 problem detail, and
  Nova's frontend binds field errors from a **422** and nothing else (`if (status === 422)` in its
  action modal) — so the dialog closed on a generic banner instead of staying open with the
  sentence under the input, which is the whole point of `refusalField` in rule 18. `refusalField`
  existed, was used twice, and had no test; the tests that did cover these refusals asserted the
  400 the bug produced while their own comments described the behaviour it prevented.
  `bootstrap/app.php` now excludes `nova-api/*` and the panel's own path from the problem-details
  renderer. **The panel is a browser, not a client of this API**: rule 16's 400 is about `api/*`,
  and a change that makes the two agree breaks one of them.

- **`$request->is('nova*')` also matches `/nova-vendor`.** The renderer hands the panel's paths back
  to Laravel, and Laravel answers an exception it does not know — `ForbiddenAccessException`,
  `NotFoundException` — with a 500. Nova's own resources never noticed, because `RunsUseCase` turns
  those into banners first; the panel's own routes under `/nova-vendor` (video uploads and
  previews) do not go through it. So under those paths a `ProvidesProblemDetail` keeps its status in
  Laravel's `{message}` shape. A new panel route that refuses something gets that for free.
- **Scramble documents what it can infer, and a string it cannot is all it says.** The frontend
  generates its client from `/docs/api`, so each of these became an untyped field there. A resource
  named by a class string (`$resource::collection`) typed every listing's `items` as a string. A
  promoted property is a template type, which is why `PaginatedList`'s counters are methods with
  `@scramble-return int`. `->toArray($request)` on a nested resource inlines it instead of
  referencing it, so return the resource. `->value` on an enum loses the enum, so return the
  instance: they are backed by their names, so the wire is the same. `->toIso8601String()` is a
  plain string until the key carries `/** @format date-time */`. A `whenLoaded` field that every
  caller loads restates the schema as an anonymous object on each endpoint. A file's media type
  is never a literal header, which is what `ServedFileResponseExtension` is for.
  `ApiDocumentationTest` sweeps the document for each of these.
- **The auth guard caches the user it resolved, and a test shares one container across every
  request it makes.** Without `forgetGuards()` between them (see `tests/TestCase::call()`), a second
  request happily reuses the first one's caller — so a revoked token appears to keep working and a
  demotion appears not to take effect. A real request always starts with a fresh container, so this
  is a harness artifact and not a bug in the application. Two further parts of the same artifact
  surfaced once the browser could sign in: `auth.driver` is a container *singleton* and
  `Contracts\Auth\Guard` is an alias for it, so one request's guard was still being handed to the
  next one's session handler — which stamped the row it wrote with a user who had just signed out.
  And restoring the session's user across requests has to re-read the row by identifier; handing
  the object back carried a role that a demotion had already changed.
- **Signing out has to forget every guard, not just the one it logged out.** `auth:sanctum` makes
  Sanctum's guard the default for the rest of the request, and it has cached whoever it resolved.
  The session row is written *after* the response, and `DatabaseSessionHandler` stamps it with
  whatever that default guard still reports — so without `forgetGuards()` in `BrowserSession::
  close()`, signing out leaves behind a fresh row bearing the identifier of the person who just
  left, and the next revocation sweep finds it.
- **Sanctum's published migration uses `morphs()`, which is a bigint.** Users here are keyed by
  UUID, so it is `uuidMorphs()` instead; the default silently matches nobody.
- **Laravel auto-discovers listeners in `app/Listeners`.** Registering them explicitly as well sent
  every account-deleted notice twice. Discovery is now off; the explicit list is the only one.
- **`config()` is not available in the `withMiddleware` closure** in `bootstrap/app.php` — that
  closure runs while the application is being assembled, before configuration is loaded. It is the
  one place that reads `env()` directly, and the reason `bootstrap/` is not analysed for that rule.
  Everywhere else, `env()` outside `config/` returns null once the config is cached.
- **Nova's installer publishes migrations this application cannot run**: a Fortify two-factor
  migration that expects a `password` column, and a passkeys table. There are no passwords here, so
  both were removed; do not restore them by re-running `nova:install`.
- **Nova's login form asks for a password.** Its authentication routes are deliberately not
  registered; `NovaSignInController` serves the same emailed-link flow at `/beheer/inloggen`, and
  the role is checked again when the link is claimed because a link outlives a demotion by up to
  thirty minutes.
- **Pagination arithmetic is attacker-chosen.** A client picks both page number and size; a page
  past the end is answered empty without touching the database.
- **Single-shot concurrency tests lie.** A race test that passes once may simply not have
  interleaved.
- **The dev database is published on 3307**, not 3306, so it cannot collide with a local MySQL.
- **Nothing `deploy.php` writes to a file reaches the running app.** It runs on the build node, so
  only what it changes outside its own filesystem takes effect — the database is shared, which is
  why migrating and seeding belong there and nothing else does. `sustained` is not the escape
  hatch: it carries the running app's directories between releases and shares nothing with the
  build, so adding `public/vendor` to it publishes nothing. That was tried. Nova's assets are
  published by Composer (`post-install-cmd`), the one phase whose file writes become part of the
  release. Without them every panel page answers 500 with "Mix manifest not found" — `mix()`
  throws instead of rendering unstyled — while `/beheer/inloggen`, one of this application's own
  views, keeps working and makes it look like a sign-in bug.
- **Nova's migrations are not published, and its `morphs()` columns are bigint.** Every model here
  is UUID-keyed, so `Schema::morphUsingUuids()` in `AppServiceProvider::register()` is what makes
  Nova's own migrations build them correctly. `action_events.user_id` was always right, because
  `foreignIdFor` reads the model's key type — which is exactly what hid the other five columns.

## Environment notes

- `.env.example` holds no secrets. There is no token-signing key to manage: Sanctum stores hashes
  of opaque tokens rather than signing claims.
- Development mail goes to Mailtrap; without credentials it falls back to the log, which is allowed
  in `local` only.
- Uploads go to the `local` disk, like the other backends. fortrabbit's filesystem is not the
  ephemeral kind: the app runs from `/data/www` on a shared, persistent CephFS volume, and
  `sustained: storage` in `fortrabbit.yml` carries the directory between releases. Remove that entry
  and every logo goes with the next deploy. Files land in `storage/app/private`, unreachable over
  HTTP — a logo is read back through the API, which checks the token first, so there is no
  `storage:link`.
- Migrations are **not** run on boot. `deploy.php` applies them once per release, because several
  web processes start at once.

## Branch flow

Three long-lived branches, and a change travels through them in order:

| Branch | Deploys to | App |
| --- | --- | --- |
| `development` | development | `en-0efyj5` |
| `staging` | staging | `en-jf4twu` |
| `main` | production | `en-j8qfex` |

fortrabbit is linked to the GitHub repository and deploys on push, so **merging is the deploy** and
nothing in CI ships anything. That also means CI does not gate a release: the tests and the build
run alongside each other, and a red build still ships. Work on a feature branch and open a pull
request into `development`, where CI has to be green before it can merge. A release is promoted by
a pull request from `development` into `staging`, and from `staging` into `main` — never a feature
branch straight into either, so production only ever receives what staging already ran.

Each environment's frontend and API share a parent domain of their own (`SESSION_DOMAIN`), and no
environment's parent is a subdomain of another's: a cookie set on `.kompaz.igne.link` is sent to
every host beneath it, and `XSRF-TOKEN` is a name Laravel does not let us change. That is why
staging is `kompaz-staging.igne.link` and not `staging.kompaz.igne.link`.

The branch is named `development`, not `develop`, because that is the branch fortrabbit watches.

Commit messages: imperative and descriptive.
