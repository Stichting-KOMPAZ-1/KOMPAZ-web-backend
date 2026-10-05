# Deploying to fortrabbit

Three apps, all in region `eu-w1a`:

| Environment | App | SSH |
| --- | --- | --- |
| development | `en-0efyj5` | `en-0efyj5@ssh.eu-w1a.frbit.app` |
| staging | `en-jf4twu` | `en-jf4twu@ssh.eu-w1a.frbit.app` |
| main (production) | `en-j8qfex` | `en-j8qfex@ssh.eu-w1a.frbit.app` |

All run PHP 8.5, which is why CI tests on 8.5 as well as the 8.4 the team develops on.

## How a deploy works

fortrabbit is linked to this GitHub repository and deploys a branch on push. Nothing in CI pushes
anything: merging is the deploy.

| Push to | Deploys | App |
| --- | --- | --- |
| `development` | development | `en-0efyj5` |
| `staging` | staging | `en-jf4twu` |
| `main` | production | `en-j8qfex` |

A release is promoted, never skipped ahead: feature branches merge into `development`, a pull
request from `development` into `staging` ships it to staging, and a pull request from `staging`
into `main` ships what staging already ran to production.

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
protection rule, is what makes the gate real. A push straight to `development` bypasses it; so
does one straight to `staging` or `main`, which is why those take promotions only.

## What each app needs in its environment

Set these in the fortrabbit dashboard. The platform injects `DB_*` itself once MySQL is attached, so
those are not listed. Logos and pictures go to the local disk; uploaded videos go to an Azure blob
container, whose connection string is the one storage setting to configure (see below).

Development and production already have `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` and their
MySQL credentials.
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
| `MAIL_MAILER` + `POSTMARK_TOKEN` | `postmark` and the Postmark server token, as on staging and the other backends | **refuses to boot** — `log` writes sign-in links into the log, and `postmark` without a token sends nothing |
| or `MAIL_MAILER` + `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` | `smtp` and a relay, as on development | the same |
| `MAIL_FROM_ADDRESS` | the sender people will see | mail is rejected by the relay |
| `FILESYSTEM_DISK` | `local` | — |
| `AZURE_STORAGE_CONNECTION_STRING` | the storage account's connection string, from `az storage account show-connection-string -g Kompaz -n stkompazdevelop` (develop) | **refuses to boot** — uploaded videos have nowhere to go |
| `AZURE_STORAGE_VIDEO_CONTAINER` | `videos` | — the default |
| `FRONTEND_URL` | `https://kompaz.igne.link` | invitation links point at `localhost:5173` |
| `SESSION_DOMAIN` | unset — see below | — |
| `SESSION_COOKIE` | `kompaz-<environment>-session` | — the default `kompaz-session` works, but is the same name in every environment |
| `SESSION_DRIVER`, `CACHE_STORE` | `database` unless Redis is attached | files that do not survive a deploy |
| `TRUSTED_PROXIES` | `*` | every client shares one rate-limit bucket |
| `SEED_PLATFORM_ADMINISTRATOR_EMAIL` | `super@igne.nl`, or several addresses comma-separated — each is seeded as an invited platform administrator, and an address that already has a row is left alone | no first administrator, so nobody can invite anybody |
| `API_DOCS_PUBLIC` | `true` to open `/docs/api` to anyone; omit to keep it closed | — a platform administrator can read it either way |

**`FRONTEND_URL` is the public frontend, not this API.** Invitation links point there. Nova magic
links are generated from `APP_URL` and return to `/beheer/sessie`, then redirect to `/nova`.

The application **refuses to start** on `MAIL_MAILER=log`, which would write sign-in links into the
log, on `MAIL_MAILER=postmark` without `POSTMARK_TOKEN`, and without
`AZURE_STORAGE_CONNECTION_STRING`. Postmark is sent through `coconutcraig/laravel-postmark`, which
reads `POSTMARK_TOKEN` — not `POSTMARK_API_KEY`, Laravel's own default name. Set the connection string **before** deploying
a release that has video uploads, or that release does not come up.

### Cookies stay on the frontend's host

A browser never talks to this application's own host. The frontend is built with
`VITE_API_BASEURL=/api`, and its nginx proxies `/api` and the panel's paths (`/nova`, `/nova-api`,
`/nova-vendor`, `/vendor/nova`, `/beheer`) here, so the session and `XSRF-TOKEN` cookies are
first-party to the frontend's host and **`SESSION_DOMAIN` stays unset**. A host-only cookie is
also the strictest one: set on a parent domain, it would be sent to every host beneath it, and
since Laravel does not let `XSRF-TOKEN` be renamed, a second environment underneath would read
whichever of the two the browser listed first and fail with a 419 at random.

| Variable | Development (`en-0efyj5`) | Staging (`en-jf4twu`) |
| --- | --- | --- |
| `FRONTEND_URL` | `https://kompaz.igne.link` | `https://kompaz.staging.igne.link` |
| `APP_URL` | `https://backend.kompaz.igne.link` | `https://backend.kompaz.staging.igne.link` |
| `SESSION_COOKIE` | — the default | `kompaz-staging-session` |

Each has its own `APP_KEY`. The frontend's App Platform spec for each environment, in the frontend
repository's `.do/`, names the backend its nginx proxies to.

Hostnames follow the naming policy: an environment other than development is a label of its own,
`kompaz.<environment>.igne.link` for the frontend and `backend.kompaz.<environment>.igne.link` for
this application — with a dot, never `kompaz-<environment>`. All of them are Cloudflare CNAMEs with
the proxy off, so DigitalOcean and fortrabbit issue their own certificates.

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

The stock `s3` disk is still in `config/filesystems.php` if the volume is ever outgrown. fortrabbit's
own Object Storage is documented for its old platform only, and there it has no signed uploads, no
CORS rules and no multipart upload — so an S3-compatible bucket would have to come from elsewhere.

### Uploaded videos

Not on the disk: a video is too large to pass through a PHP request, so it lives in a private Azure
blob container (`videos`, in the storage account `stkompazdevelop` in resource group `Kompaz`,
`westeurope`, Standard_LRS). The browser writes it there directly, in blocks, on a link the
application signs; the application then reads its first bytes to confirm it is a video; and a
player is redirected to a read-only link that expires. No video byte passes through fortrabbit.

What the account needs, all of it in `infra/storage.bicep`:

- **A private container.** Public access is off for the whole account; a signed link is the only
  way in, and the application only signs one after the usual permission check.
- **A CORS rule allowing `PUT` from the panel's and the frontend's origins** —
  `https://kompaz.igne.link` and `https://backend.kompaz.igne.link` on develop. Staging shares
  the account and the container, so its two origins, `https://kompaz.staging.igne.link` and
  `https://backend.kompaz.staging.igne.link`, are on the same rule. Sharing is safe because every
  key is minted fresh and every delete starts from a row in the environment's own database —
  nothing lists the container, so one environment cannot remove another's blob. Without it the
  browser's upload fails on its preflight. Reading needs no rule.
- **Shared-key access.** The application is not in Azure and has no managed identity; the account
  key is what signs the links.

Production has no storage account yet. `infra/storage.bicep` creates one; its header has the
command. Its domain goes in `corsOrigins`.

An upload somebody started and never saved is removed by the next upload anybody starts, once its
link has expired — there is no scheduler to do it sooner.

### Local video storage

`docker compose up -d` starts Azurite next to MySQL, and `.env.example` already points at it. Once,
create its container and CORS rule:

```sh
CS="$(grep ^AZURE_STORAGE_CONNECTION_STRING .env | cut -d= -f2- | tr -d '"')"
az storage container create -n videos --connection-string "$CS"
az storage cors add --services b --methods PUT OPTIONS --origins http://localhost:8000 \
  --allowed-headers 'x-ms-*' content-type --exposed-headers etag --connection-string "$CS"
```

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
  claim rather than swept up by a cron, and an abandoned video upload is removed by the next upload
  somebody starts.
