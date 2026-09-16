# KOMPAZ Web Backend

Multi-tenant user and organization management for the zelfzorgacademie: passwordless sign-in,
invitations, roles and per-organization branding. A JSON API for the frontend, and a Laravel Nova
panel for the people who run the platform.

- **PHP 8.4 · Laravel 13 · MySQL 8 · Sanctum · Nova 5**, deployed to [fortrabbit](https://www.fortrabbit.com).

## Getting started

```sh
composer install
cp .env.example .env
php artisan key:generate

docker compose up -d mysql                     # MySQL 8 on localhost:3307
php artisan migrate --seed                     # schema + the platform organization

php artisan serve
```

Set `SEED_PLATFORM_ADMINISTRATOR_EMAIL` before seeding, or the first administrator is skipped and
nobody can invite anybody. The seeder is idempotent and runs on every deploy.

## Signing in

There are no passwords. A person asks for a link, clicks it, and is signed in.

```
POST /api/auth/magic-link   {"email": "iemand@example.com"}   -> 202, always
POST /api/auth/tokens       {"token": "<from the link>"}      -> an API token
```

`POST /api/auth/magic-link` is accepted whether or not the address belongs to an account, so the
endpoint cannot be used to find out who has one. In local development the link is written to
`storage/logs/laravel.log`; anywhere else an unconfigured mailer fails at startup, because a log
that contains sign-in links is a credential leak.

The secret is single-use. Redeeming it spends it with one conditional `UPDATE`, so two requests
carrying the same secret cannot both open a session. Redeeming a link also accepts an outstanding
invitation.

## Staying signed in

The API token is a Sanctum personal access token: opaque, stored only as a hash, and valid for
thirty days.

```
POST   /api/auth/tokens/refresh   -> a new token, and the current one is deleted
DELETE /api/auth/tokens/current   -> signs out, on every device
GET    /api/auth/me               -> the profile behind the token
```

Both are authenticated, unlike the two sign-in endpoints: the token being renewed or withdrawn is
the one the request carries. A client whose token has expired signs in again through their inbox
rather than refreshing.

Because a token is a row rather than a signed claim, revoking one is immediate and there is nothing
that can go stale. Sanctum reads the user row on every request, so deleting somebody, demoting them
or moving them between organizations takes effect on their very next call — no comparison, no
window to wait out. Deleting a user and moving one between organizations both delete their tokens
outright as well.

## Endpoints

| Method | Path | Who |
| --- | --- | --- |
| `POST` | `/api/auth/magic-link` | anyone |
| `POST` | `/api/auth/tokens` | anyone, with a link's secret |
| `POST` | `/api/auth/tokens/refresh` | any signed-in user |
| `DELETE` | `/api/auth/tokens/current` | any signed-in user |
| `GET` | `/api/auth/me` | any signed-in user |
| `GET` | `/api/users` | administrator |
| `GET` | `/api/users/{id}` | any signed-in user, within their organization |
| `PUT` | `/api/users/me` | any signed-in user |
| `POST` | `/api/users/invitations` | administrator |
| `POST` | `/api/users/{id}/invitations` | administrator |
| `POST` | `/api/users/{id}/restore` | administrator |
| `PUT` | `/api/users/{id}` | administrator |
| `DELETE` | `/api/users/{id}` | administrator |
| `GET` | `/api/organizations` | any signed-in user |
| `GET` | `/api/organizations/{id}` | any signed-in user, within their organization |
| `POST` | `/api/organizations` | platform administrator |
| `PUT` | `/api/organizations/{id}` | administrator |
| `DELETE` | `/api/organizations/{id}` | platform administrator |
| `GET` | `/api/organizations/{id}/logo` | any signed-in user |
| `PUT` | `/api/organizations/{id}/logo` | administrator |
| `DELETE` | `/api/organizations/{id}/logo` | administrator |

## Roles and tenancy

Three hierarchical roles: `Member` < `Administrator` < `PlatformAdministrator`.

A role says *what kind of thing* somebody may do; it never says *whose data*. Those are separate
questions and both get asked — a route states a minimum role, and the action behind it asks
`OrganizationAccess` which organization the caller is entitled to. A platform administrator reaches
every organization; everybody else only their own.

Granting is stricter than managing. An administrator runs the people in their organization,
including removing a fellow administrator somebody above them appointed, but cannot appoint one: a
role is only ever granted from above. Who administers an organization is the platform's call.

Platform administration is a job at the organization that runs the platform, so that role cannot be
held in a tenant.

Three commands can leave an organization with nobody able to administer it — deleting, demoting and
moving — and a move or demotion can also take the last platform administrator. All three ask
`AdministratorCoverage`, which refuses.

## Invitations, deletion and restoring

Inviting somebody creates them as `Invited` and emails a link that both accepts the invitation and
signs them in. Re-inviting a pending user resends rather than conflicting, because the row commits
before the email goes out and repeating the request is the obvious recovery.

Deleting is soft. The row survives because restoring has to know what to put back, because the audit
columns elsewhere refer to people by identifier, and because the address is the unique key — a hard
delete would burn the email address. Their sign-in links and API tokens are deleted outright: a link
in an inbox, or a token in a browser, has to stop working the moment the account does.

Inviting a deleted address brings that same row back as a fresh invitation, so deleting somebody
never burns their address for good.

Only somebody who could actually sign in is told their account is gone. For a user still `Invited`
every line of that notice would be untrue, so revoking an invitation is silent.

## Logos

One image per organization, at most 10 MB, in PNG, JPEG, SVG or WebP.

The format is read out of the bytes. The upload's `Content-Type` and file name are never believed,
because the stored value is what a later response is labelled with — and an SVG is only accepted
when its *root* element is `<svg>`, so an HTML page containing an inline chart is not an image. Logos
are served with `Content-Security-Policy: default-src 'none'; sandbox` and `nosniff`.

The row is a pointer and the bytes live on a disk — the `local` one, which is persistent on
fortrabbit and unreachable over HTTP. Letting go of one is therefore not a single transaction.
An upload writes its bytes *before* its row and a deletion removes its row *before* its bytes, so
every failure leaves an orphaned file rather than a row pointing at nothing. An organization with no
usable logo is served a placeholder, which is also the answer when a row names a file that has gone.

## The admin panel

Nova lives at `/nova`, and only a platform administrator reaches it. Since there are no passwords,
Nova's own login is replaced by the same emailed link everybody else uses, at `/beheer/inloggen`.
The role is checked again when the link is claimed: a link outlives a demotion by up to thirty
minutes.

The panel is read-mostly on purpose. Inviting, editing and deleting all carry rules — who may grant
which role, whether an administrator would be left, whether a deletion notice would be truthful —
that the API's actions enforce, and a Nova form writing those columns directly would bypass every
one of them.

## Errors

RFC 9457 problem documents, with `application/problem+json` and a `traceId` that also comes back as
`X-Request-Id`:

```json
{
  "type": "https://datatracker.ietf.org/doc/html/rfc9110#section-15.5.10",
  "title": "Conflict",
  "status": 409,
  "detail": "Deze organisatienaam bestaat al. Geef de organisatie een unieke naam.",
  "instance": "/api/organizations",
  "traceId": "3cbd8c19-4e21-4e57-8409-8531937c95b6"
}
```

Everything a caller reads is Dutch. `title` stays English because it names the status from the HTTP
specification's vocabulary; `detail` is the sentence written for the reader. Validation failures
answer **400** with an `errors` object keyed by field.

## Build and test

```sh
composer check      # PHPStan level 6 + Pint, both must be clean
php artisan test    # 115 tests
composer fix        # apply Pint
```

Tests run against a real MySQL rather than SQLite in memory: the schema enforces "at most one
platform organization" with a generated column, uniqueness folds through the collation, and a lost
insert race is recognized by MySQL's own error number. None of that would be exercised otherwise.

## Deployment

fortrabbit, by git push. See [docs/deployment.md](docs/deployment.md).
