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
- `sustained: [vendor, storage]` — the running app's own directories, carried from one release to
  the next. See the storage section below; that second entry is load-bearing.
- `composer.post-install-cmd` (in `composer.json`) publishes Nova's assets. That belongs to the
  Composer phase for a reason — see below.

`deploy.php` migrates and seeds, and does nothing else. It stops at the first failure and exits
non-zero, so a release whose migrations did not apply is visible in the deploy log rather than at
the first request.

**The post-deploy script's file writes never reach the application.** It runs on the build node once
the release has been assembled, so only what it changes *outside its own filesystem* takes effect:
the database is shared, which is why migrating and seeding work there, while every file it writes is
thrown away — silently, because the step still reports success. `sustained` does not help: it
carries the running app's directories between releases and shares nothing with the build, so adding
`public/vendor` to it publishes nothing. That was tried, and it is why the publish now runs as a
Composer script instead — the Composer phase is the only one whose file writes become part of the
release, as `bootstrap/cache/packages.php` arriving with every release shows.

Getting this wrong is not subtle in its effect and very subtle in its cause: with no
`public/vendor/nova/mix-manifest.json`, **every panel page answers 500** with `Mix manifest not
found`, because Nova's layout resolves its assets through `mix()`, which throws rather than
rendering an unstyled page. `/beheer/inloggen` keeps working — it is one of this application's own
Blade views and reaches for no Nova asset — so the failure looks like it belongs to signing in.

**Configuration is not cached in production, deliberately.** Warming it in `deploy.php` would write
to a `bootstrap/cache` nothing serves, and would be wrong even if it landed: fortrabbit injects the
runtime environment into the web processes, not into the build, so the cache would bake whatever the
build node saw. `php artisan about --only=cache` reporting `NOT CACHED` is therefore expected. It
also means `env()` outside `config/` still resolves on the deployed apps, which local development
cannot rely on.

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
