# Deploying to fortrabbit

Two apps, both in region `eu-w1a`:

| Environment | App | SSH |
| --- | --- | --- |
| development | `en-0efyj5` | `en-0efyj5@ssh.eu-w1a.frbit.app` |
| main (production) | `en-j8qfex` | `en-j8qfex@ssh.eu-w1a.frbit.app` |

Both run PHP 8.5, which is why CI tests on 8.5 as well as the 8.4 the team develops on.

## How a deploy works

fortrabbit is linked to this GitHub repository and deploys a branch on push. Nothing in CI pushes
anything: merging is the deploy.

| Push to | Deploys | App |
| --- | --- | --- |
| `development` | development | `en-0efyj5` |
| `main` | production | `en-j8qfex` |

fortrabbit then builds a release: it runs Composer, runs the post-deploy script, and swaps the
release in. The app ends up at **`/data/www`** — not `~/htdocs`, which is empty and misleading.

`fortrabbit.yml` configures that build:

- `composer.no-dev: true` — Pint, PHPStan and PHPUnit are the gate CI runs; a public host has no use
  for a test runner.
- `post: deploy.php` — only one post script runs and chaining is not supported, so everything that
  has to happen after a deploy lives in that one file.
- `sustained: [vendor, storage, public/vendor, bootstrap/cache]` — kept between releases, and the
  only paths anything `deploy.php` writes can reach. See below; every entry is load-bearing.

`deploy.php` clears stale caches, publishes package assets, migrates, seeds, and warms the config,
route, view and event caches. It stops at the first failure and exits non-zero, so a release whose
migrations did not apply is visible in the deploy log rather than at the first request.

**The post-deploy script writes on the build node.** It runs once the release has been assembled, so
a file it creates outside a `sustained` path is thrown away rather than shipped — silently, because
the step still reports success. This is why `public/vendor` and `bootstrap/cache` are sustained:
without the first, Nova's published assets never reach the running release and **every panel page
answers 500** with `Mix manifest not found at: /data/www/public/vendor/nova/mix-manifest.json` — the
sign-in page still works, because it is one of this application's own Blade views and reaches for no
Nova asset, which makes the failure look like it belongs to signing in. Without the second, the
configuration, route and event caches are rebuilt on the build node and discarded, which `php
artisan about` reports as `Config … NOT CACHED` while `Views … CACHED` gives the mechanism away —
compiled views live under `storage`, which was already sustained.

Because those two directories now persist, `deploy.php` clears each cache before rebuilding it: a
sustained directory keeps whatever the last deploy left behind, and a release that failed halfway
must not answer with the previous one's routes.

**Migrations run there, not on boot.** Several web processes start at once, and each of them
migrating would be several writers racing through one schema.

### CI does not gate the deploy

Because fortrabbit deploys on push rather than being triggered by a workflow, the tests and the
release run *alongside* each other: a red build still ships. Work on a feature branch and open a
pull request into `development`, where CI has to be green before merging — that, plus a branch
protection rule, is what makes the gate real. A push straight to `development` bypasses it.

## What each app needs in its environment

Set these in the fortrabbit dashboard. The platform injects `DB_*` itself once MySQL is attached, so
those are not listed. Uploads go to the local disk, so there is no object storage to configure.

Both apps already have `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` and their MySQL credentials.
Only development has `NOVA_LICENSE_KEY`.

Nova validates its licence against the domain the panel is served from, and production's `APP_URL`
is still the default `en-j8qfex.eu-w1a.frbit.app`. If the licence is registered to
`kompaz.igne.link`, the panel will not render there until production has its own domain.

| Variable | Value | Without it |
| --- | --- | --- |
| `APP_KEY` | `php artisan key:generate --show` locally, then paste | nothing decrypts |
| `APP_ENV` | `production` | the startup checks stay off |
| `APP_DEBUG` | `false` | exception messages reach callers |
| `APP_URL` | the API's own URL, e.g. `https://backend.kompaz.igne.link` | generated URLs point at localhost |
| `NOVA_LICENSE_KEY` | the Nova licence, which Nova validates against the serving domain | the panel will not render |
| `MAIL_MAILER` + `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` | a real relay | **refuses to boot** — `log` writes sign-in links into the log |
| `MAIL_FROM_ADDRESS` | the sender people will see | mail is rejected by the relay |
| `FILESYSTEM_DISK` | `local` | — |
| `FRONTEND_URL` | `https://kompaz.igne.link` | invitation links point at `localhost:5173` |
| `SESSION_DRIVER`, `CACHE_STORE` | `database` unless Redis is attached | files that do not survive a deploy |
| `TRUSTED_PROXIES` | `*` | every client shares one rate-limit bucket |
| `SEED_PLATFORM_ADMINISTRATOR_EMAIL` | `super@igne.nl` | no first administrator, so nobody can invite anybody |
| `API_DOCS_PUBLIC` | `true` to open `/docs/api` to anyone; omit to keep it closed | — a platform administrator can read it either way |

**`FRONTEND_URL` is the public frontend, not this API.** Invitation links point there. Nova magic
links are generated from `APP_URL` and return to `/beheer/sessie`, then redirect to `/nova`.

The application **refuses to start** on `MAIL_MAILER=log`, which would write sign-in links into the
log.

## Where uploaded files live

On the local disk, like the other backends — no object storage.

That works here because fortrabbit's filesystem is not the ephemeral kind. The app runs from
`/data/www`, its home is `/data/home`, and `/data` is a shared CephFS volume: persistent, and the
same volume on every node, so a logo one process writes is readable by the next. `sustained: storage`
in `fortrabbit.yml` is what carries the directory from one release to the next — **remove that entry
and every uploaded logo goes with the next deploy.**

Nothing is served from the disk directly. Files go to `storage/app/private`, which is not reachable
over HTTP; a logo is read back through the API, which checks the caller's token first. There is no
`storage:link`, and there should not be one.

If the volume is ever outgrown, the stock `s3` disk is still in `config/filesystems.php`: attach
Object Storage, copy the `OBJECT_STORAGE_*` values fortrabbit injects into the `AWS_*` names, and
set `FILESYSTEM_DISK=s3`. Nothing in the application changes.

## Running behind fortrabbit's proxy

Requests arrive through fortrabbit's routing layer. Unconfigured, every per-client decision keys on
the proxy's address — so the whole deployment shares one rate-limit partition and HTTPS redirection
loops.

`TRUSTED_PROXIES=*` is correct here, because a fortrabbit app is unreachable except through that
proxy. Nothing is believed unless named, so leaving it empty is the safe default and setting it is a
deliberate statement about the topology.

## First run

1. Create the app and attach **MySQL**.
2. Set the environment variables above.
3. Add the CI deploy key to the app's SSH keys.
4. Push. The build migrates and seeds, planting the platform organization and its first
   administrator as `Invited`.
5. `super@igne.nl` visits `/beheer/inloggen`, asks for a sign-in link, and opens Nova by redeeming it.

## Verifying a deployment

```sh
ssh en-0efyj5@ssh.eu-w1a.frbit.app 'php artisan about --only=environment'
curl -i https://<app-url>/up
curl -I https://<app-url>/beheer/inloggen
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
