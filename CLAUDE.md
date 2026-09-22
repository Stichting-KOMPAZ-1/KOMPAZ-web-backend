# CLAUDE.md — KOMPAZ web backend

Multi-tenant user and organization management with passwordless (magic-link) sign-in and invitations.
PHP 8.4, Laravel 13, MySQL, Nova 5 for the operator's panel, deployed to fortrabbit.

## Layout

```
app/Actions          use cases, one class per thing that can happen: {Feature}/{Verb}{Thing}Action
app/Models           Eloquent models; entity behaviour lives on them, orchestration does not
app/Enums            fixed state as enums, stored and serialized by name
app/Events           domain events, all dispatched after the transaction commits
app/Listeners        the reactions to those, one per event
app/Http             thin controllers, form requests, API resources, middleware
app/Services         the authentication machinery: token issuing and secret hashing
app/Support          Access (tenancy), Errors (problem details), Pagination, Search, Images
app/Nova             the operator's panel; every write is an Action delegating to app/Actions
tests/               Feature (through HTTP, against real MySQL) and Unit
```

## Commands

```sh
docker compose up -d mysql                # the dev database, on localhost:3307
php artisan serve                         # run the API
composer check                            # THE gate: PHPStan level 6 + Pint, both must be clean
php artisan test                          # 119 tests; needs the MySQL container running
php artisan migrate --seed                # schema, plus the platform organization and its first admin
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
   user is a bug.
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
    API and no more permissive. `RunsUseCase` is what they share: it resolves the operator, takes
    the one selected record, and turns a `ProvidesProblemDetail` refusal into the panel's banner
    while rethrowing anything else — a defect reported as a refusal would tell an operator a rule
    stopped them when nothing did.
19. **Audit columns are stamped by the `StampsAuditor` trait** — never set `created_by`/`updated_by`
    in an action. Model keys are UUIDv7 via `HasUuids`: time-ordered, so inserts land at the end of
    the primary-key index instead of scattering.

## Things that have already cost time

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

Two long-lived branches, as in the other backends:

| Branch | Deploys to | App |
| --- | --- | --- |
| `development` | development | `en-0efyj5` |
| `main` | production | `en-j8qfex` |

fortrabbit is linked to the GitHub repository and deploys on push, so **merging is the deploy** and
nothing in CI ships anything. That also means CI does not gate a release: the tests and the build
run alongside each other, and a red build still ships. Work on a feature branch and open a pull
request into `development`, where CI has to be green before it can merge.

The branch is named `development`, not `develop`, because that is the branch fortrabbit watches.

Commit messages: imperative and descriptive.
