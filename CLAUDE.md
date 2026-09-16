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
app/Services         the authentication machinery: token issuing, hashing, rotation
app/Support          Access (tenancy), Errors (problem details), Pagination, Search, Images
app/Nova             the operator's panel; read-mostly, deliberately
tests/               Feature (through HTTP, against real MySQL) and Unit
```

## Commands

```sh
docker compose up -d mysql                # the dev database, on localhost:3307
php artisan serve                         # run the API
composer check                            # THE gate: PHPStan level 6 + Pint, both must be clean
php artisan test                          # 119 tests; needs the MySQL container running
php artisan migrate --seed                # schema, plus the platform organization and its first admin
php artisan kompaz:generate-signing-key   # prints a value for AUTH_SIGNING_KEY
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
   Both redemption paths put every reason to refuse into the `WHERE` and check the affected-row
   count; losing that race is a replay. Refresh rotation additionally runs inside a transaction,
   because spending the old token and inserting its successor must become visible together or a
   concurrent replay revokes a chain the successor has not joined yet. Do not "simplify" either of
   these back into a check-then-save.
5. **Refresh-token expiry is deliberately *not* one of the claim's conditions**, unlike the login
   token's. Losing that `UPDATE` revokes the session, and a token expiring between the check and the
   claim would be punished as a replay rather than reported as expired.
6. **Cross-cutting reactions go through events**, never service calls from an action. Every event
   implements `ShouldDispatchAfterCommit`, so a reaction never holds a transaction open across
   network I/O — and so it may fail after the data is safely committed. Anything that raises one
   must therefore be safe to repeat (re-inviting a pending user resends rather than conflicting).
7. **Listeners are registered by name in `AppServiceProvider`, and discovery is off**
   (`->withEvents(discover: false)` in `bootstrap/app.php`). With both on, every listener fires
   twice and every notice goes out twice.
8. **An access token outlives the account and what it says about it.** Deleting, demoting or moving
   somebody invalidates none of it. `EnsureAccountMatchesToken` is what stops the current hour,
   comparing the `role` and `org` claims against the row on every authenticated request. Anything
   else that changes what somebody may do has to be compared there too.
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
    a caller. Reads go through the API, which checks the token; the bucket is private.
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
    returned and the one clients branch on; `ProblemDetailFactory` states it.
17. **Outside local development, startup refuses**: a signing key under 32 bytes, an absolute
    refresh lifetime below the sliding one, `MAIL_MAILER=log` (it writes sign-in links into the log),
    and a local `LOGO_DISK` (fortrabbit's filesystem is ephemeral). Never widen those exemptions
    past `local` and `testing`.
18. **Audit columns are stamped by the `StampsAuditor` trait** — never set `created_by`/`updated_by`
    in an action. Model keys are UUIDv7 via `HasUuids`: time-ordered, so inserts land at the end of
    the primary-key index instead of scattering.

## Things that have already cost time

- **Laravel auto-discovers listeners in `app/Listeners`.** Registering them explicitly as well sent
  every account-deleted notice twice. Discovery is now off; the explicit list is the only one.
- **`Carbon::min()` is not static.** It compiles and then fails at runtime inside the refresh issuer.
  `RefreshTokenIssuer::earliest()` is the replacement.
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

## Environment notes

- `.env.example` holds no secrets: `AUTH_SIGNING_KEY` is deliberately empty so a deployment that
  forgets it fails at startup rather than running on a shared default. Generate one with
  `php artisan kompaz:generate-signing-key`.
- Development mail goes to Mailtrap; without credentials it falls back to the log, which is allowed
  in `local` only.
- Uploads go to fortrabbit Object Storage through the stock S3 driver (`LOGO_DISK=object-storage`);
  the platform injects the credentials.
- Migrations are **not** run on boot. `deploy.php` applies them once per release, because several
  web processes start at once.

## Branch flow

`main` is the only long-lived branch. Work on a feature branch and open a PR; CI runs Pint, PHPStan
and the tests on every push and pull request. A push to `main` deploys to the develop app
(`en-0efyj5`); production (`en-j8qfex`) is a manual `workflow_dispatch`. Commit messages: imperative
and descriptive.
