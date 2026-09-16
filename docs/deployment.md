# Deploying to fortrabbit

Two apps, both in region `eu-w1a`:

| Environment | App | SSH |
| --- | --- | --- |
| develop | `en-0efyj5` | `en-0efyj5@ssh.eu-w1a.frbit.app` |
| production | `en-j8qfex` | `en-j8qfex@ssh.eu-w1a.frbit.app` |

A push to `main` deploys to **develop** automatically. Production is a manual
`workflow_dispatch` on the Deploy workflow with `target: production`, so that promoting a release is
a decision somebody makes rather than a side effect of merging.

## How a deploy works

fortrabbit deploys by receiving a git push. It builds a release package, runs Composer, then runs
the post-deploy script, and only then swaps the release in.

`fortrabbit.yml` configures that build:

- `composer.no-dev: true` — Pint, PHPStan and PHPUnit are the gate CI runs; a public host has no use
  for a test runner.
- `post: deploy.php` — only one post script runs and chaining is not supported, so everything that
  has to happen after a deploy lives in that one file.
- `sustained: [vendor, storage]` — kept between releases.

`deploy.php` clears stale caches, migrates, seeds, and warms the config, route, view and event
caches. It stops at the first failure and exits non-zero, so a release whose migrations did not
apply is visible in the deploy log rather than at the first request.

**Migrations run there, not on boot.** Several web processes start at once, and each of them
migrating would be several writers racing through one schema.

## What CI needs

| Secret | Used for |
| --- | --- |
| `NOVA_USERNAME`, `NOVA_LICENSE_KEY` | Nova is a paid package on a private Composer repository |
| `FORTRABBIT_SSH_KEY` | a deploy key authorized on both fortrabbit apps |

Optionally set the `FORTRABBIT_REMOTE` repository **variable** to override the git remote; by
default the workflow builds it as `{app}@deploy.eu-w1a.frbit.app:{app}.git`. Confirm the exact
remote on the app's dashboard page — it is shown there — before the first deploy.

## What each app needs in its environment

Set these in the fortrabbit dashboard. The platform injects `DB_*` and `OBJECT_STORAGE_*` itself
once MySQL and Object Storage are attached, so those are not listed.

| Variable | Why |
| --- | --- |
| `APP_KEY` | `php artisan key:generate --show` locally, then paste |
| `APP_ENV` | `production` — anything but `local`/`testing` turns the startup checks on |
| `APP_DEBUG` | `false` |
| `APP_URL` | the app's own URL |
| `AUTH_SIGNING_KEY` | `php artisan kompaz:generate-signing-key`; at least 32 bytes, different per app |
| `FRONTEND_URL` | where sign-in links point |
| `MAIL_MAILER` + `MAIL_*` | a real transport; `log` is refused outside local development |
| `MAIL_FROM_ADDRESS` | the sender people will see |
| `LOGO_DISK` | `object-storage`; a local disk is refused, because the filesystem is ephemeral |
| `FILESYSTEM_DISK` | `object-storage` |
| `SESSION_DRIVER`, `CACHE_STORE` | `database` unless Redis is attached |
| `TRUSTED_PROXIES` | see below |
| `SEED_PLATFORM_ADMINISTRATOR_EMAIL` | the first administrator, or nobody can invite anybody |

The application **refuses to start** on a signing key under 32 bytes, an absolute refresh lifetime
below the sliding one, `MAIL_MAILER=log`, or a local `LOGO_DISK`. Each of those would otherwise run
insecurely rather than fail: a shared default key, a session that expires before its first refresh,
sign-in links written into a log, and uploads that vanish on the next deploy.

## Running behind fortrabbit's proxy

Requests arrive through fortrabbit's routing layer. Unconfigured, every per-client decision keys on
the proxy's address — so the whole deployment shares one rate-limit partition and HTTPS redirection
loops.

`TRUSTED_PROXIES=*` is correct here, because a fortrabbit app is unreachable except through that
proxy. Nothing is believed unless named, so leaving it empty is the safe default and setting it is a
deliberate statement about the topology.

## First run

1. Create the app, attach **MySQL** and **Object Storage**.
2. Set the environment variables above.
3. Add the CI deploy key to the app's SSH keys.
4. Push. The build migrates and seeds, planting the platform organization and its first
   administrator as `Invited`.
5. That administrator visits the frontend, asks for a sign-in link, and is activated by redeeming
   it. They reach the panel at `/beheer/inloggen`.

## Verifying a deployment

```sh
ssh en-0efyj5@ssh.eu-w1a.frbit.app 'php artisan about --only=environment'
curl -i https://<app-url>/up
curl -i -X POST https://<app-url>/api/auth/magic-link \
  -H 'Content-Type: application/json' -d '{"email":"someone@example.com"}'   # expect 202
```

## Rolling back

fortrabbit keeps previous releases and can activate one from the dashboard. A rollback does **not**
reverse a migration: the schema is forward-only, so a release that changed it needs a forward fix
rather than a rollback.

## Things deliberately not done

- **No queue worker.** Every reaction — three emails and one file deletion — runs in the request
  after the commit. A worker needs the Professional stack, and adding one before there is work to
  put on it would be infrastructure with no job. When one arrives, the listeners are already
  dispatched after commit and are safe to move onto a queue.
- **No scheduler.** Nothing runs on a timer. Expired tokens are refused by the conditions on their
  claim rather than swept up by a cron.
