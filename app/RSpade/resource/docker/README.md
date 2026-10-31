# The RSpade container

The image definition for the RSpade application container. One Dockerfile, three
targets — and `build.sh` is the way to build them, because it also applies the
names and tags everything else expects.

    bash build.sh              # dev      -> rspade/rspade-server-dev
    bash build.sh prod         # prod     -> rspade/rspade-server-prod
    bash build.sh docconvert   # the document sandbox image
    bash build.sh both         # the two application images
    bash build.sh all          # every image this file defines

The application is **not** baked into the image. It is mounted at
`/var/www/html`, so one image serves every project.

## The third image: `rspade/rspade-docconvert`

The `docconvert` target is **not** an application image and shares no layer with
the other two. It is a bare `ubuntu:24.04` carrying `libreoffice`,
`poppler-utils`, `fonts-liberation` and `fonts-dejavu-core` — no PHP, no nginx,
no redis, no supervisor, no entrypoint.

That is the whole design. When `LIBREOFFICE_SANDBOX=docker`, this is the image a
hostile uploaded document is handed, one throwaway container per file, so every
binary and library it carries is something a converter exploit could reach for —
and an application runtime carries a great deal that converts nothing.

**It builds itself.** The render worker builds it the first time a sandboxed
conversion wants it, so switching the posture on is a setting and not an install
step. `php artisan rsx:heal document-sandbox-image` pays that cost deliberately
during a deploy instead. An operator who points `LIBREOFFICE_SANDBOX_IMAGE` at an
image of their own gets neither: that image is theirs to carry soffice, pdftotext
and fonts, and theirs to pull. See `rsx:man libreoffice`, THE CONVERTER IMAGE.

## Where this fits

Three tiers own container configuration, and they do not overlap:

| Tier | Location | Owned by | Changes propagate |
|---|---|---|---|
| Image definition | `system/app/RSpade/resource/docker/` (here) | the framework | hard-synced on every `rsx:framework:pull` |
| App startup hook | `rsx/resource/docker/configure.sh` | the application | it is your file |
| Deployment | `docker-compose.yml` at the project root | the developer | your ports, your volumes |

Because this directory is inside a framework-owned zone, **local edits here are
overwritten by the next framework update.** To install packages or run setup for
your own application, use `rsx/resource/docker/configure.sh` — the entrypoint
runs it as root before the application serves traffic.

## dev vs prod

|  | dev | prod |
|---|---|---|
| MySQL server | in the container | **yours** — `mysql-client` only |
| Migrations at start | automatic | **never** — run them deliberately |
| `migrate` snapshot/rollback | yes, against a local DB | **no** — nothing to snapshot |
| OPcache | revalidate every request | revalidate every 10s |
| PHP-FPM | root, `ondemand`, 8 workers a pool | `www-data`, `dynamic`, pools **sized to the machine at start** (below) |
| PHP memory limit | 256 MB | 320 MB |
| Headless-browser libraries (Playwright / Chromium) | yes | yes — an application prints PDFs with it; see below |
| Blank credentials | generated + printed once | **refuses to start** |
| LibreOffice, poppler | yes | yes — document preview is a runtime feature |
| Fonts (Liberation, DejaVu, Noto) | yes | yes — the same set, so a server-drawn document is the same in either |
| ImageMagick policy (`imagemagick/policy.xml`) | raster coders only | raster coders only |

## What is installed, and why

Every package is in the image for a reason, and the reason decides which target
carries it. The Dockerfile says the same beside each line; this is the list in one
place.

**Both targets (the `base` stage)**

| Packages | Why |
|---|---|
| `nginx`, `libnginx-mod-http-brotli-filter` | the web server, with Brotli for compiled bundles |
| `redis-server`, `redis-tools` | cache, session and realtime plumbing; `redis-cli` is what the entrypoint and `rsx:health` probe with |
| `supervisor` | starts and restarts every service; the container's process manager |
| `unattended-upgrades` | the automatic security updates (Security updates, below), held to an allow-list |
| `mysql-client` | `migrate`, `db:query`, the schema-cache restore and operator access all shell out to it. The SERVER is a dev-target concern |
| `ca-certificates`, `curl`, `wget`, `gnupg2`, `unzip` | fetching and verifying at build time and at run time (composer, mirrored assets, package keys) |
| `imagemagick`, `ghostscript`, `optipng` | thumbnails and uploads: ImageMagick rasterises, Ghostscript is how it reads a PDF's first page, optipng shrinks what is stored |
| `libreoffice`, `poppler-utils` | document preview and search: soffice converts Office files to a PDF rendition, `pdftotext` extracts text. Runtime features, so not dev-only |
| Chromium (`npx playwright install chromium`, in `/opt/ms-playwright`) | the browser itself, at the build the shipped Playwright package launches. See "The headless browser" below |
| `libnss3`, `libnspr4`, `libatk-bridge2.0-0`, `libatspi2.0-0`, `libdrm2`, `libgbm1`, `libxkbcommon0`, `libxcomposite1`, `libxdamage1`, `libxfixes3`, `libxrandr2`, `libxcursor1`, `libasound2t64` | the shared libraries headless Chromium links. **Not a test-only dependency**: besides `rsx:debug` and the browser tests, a downstream application drives the same browser through the shipped `playwright` package to **generate PDFs on the server**. That is why they are in prod, and why they must stay |
| `fonts-liberation`, `fonts-dejavu-core`, `fontconfig`, `fonts-noto-core` | what LibreOffice, ImageMagick and the browser draw text with. A server-made document is set in the server's fonts, so both targets carry one set. Liberation stands in for the common Office faces and is what Chromium prints generic sans-serif and serif in; DejaVu Sans Mono is its monospace; Noto supplies the scripts and symbols those do not cover |
| `git`, `openssh-client` | framework updates and `rsx:git`; `openssh-client` is git's transport for an ssh remote, which git only recommends, so it is named |
| `rsync`, `procps`, `less`, `nano` | the updater copies with rsync; `ps`/`pkill` are how workers and the health checks see processes; a pager and an editor for whoever shells in |
| `python3` | `bin/mysqlpv`, the progress viewer on a database dump or restore. Base and not dev, because the schema-cache restore runs on `migrate` in every target |
| PHP 8.5 and its extensions | the list mirrors `Rsx_Php_Requirements::REQUIRED_EXTENSIONS`, which every request enforces at boot. `sodium` and OPcache are compiled into the binary and have no package |
| Composer | application-layer packages (`rsx:composer`) |
| Node 23, npm 11 | the framework's own helpers are node: the realtime relay, the full-page cache proxy, rsx-lockd, the JS compilers, Playwright. npm is pinned to 11 because 12 refuses this node |

**Development only (the `dev` stage)**

| Packages | Why |
|---|---|
| `mysql-server` | a self-contained environment; production brings its own database |
| `python3-aiosmtpd` | the mail catcher: an SMTP sink with no web UI, so nothing leaves a development box |
| `fzf`, `jq`, `man-db`, `gh` | quality of life for a developer in the shell |
| Docker Engine, `iptables`, `uidmap`, a pinned `runc` | `rsx:test` runs in sibling containers started against this daemon (below) |

### The headless browser

Both images carry a complete, working headless Chromium: the shared libraries, and
the browser itself in `/opt/ms-playwright`, installed at build time by the same
Playwright version the framework ships in `system/node_modules` (`ARG
PLAYWRIGHT_VERSION` in the Dockerfile; a framework test fails when the two differ).
The `playwright` package finds it with nothing to configure:

    const { chromium } = require('playwright');
    const browser = await chromium.launch();

- `PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright` is set for every process that
  inherits the container's environment.
- PHP-FPM clears its workers' environment, so a browser started from a web request
  does not see that variable. The per-user default locations for `root`
  (development PHP) and `www-data` (production PHP) are links to the same
  directory, so it is found there too.
- Chromium only. Firefox and WebKit are not installed in either target.
- A box whose `system/` carries a newer Playwright than its image was built for
  fetches the build it is missing with `cd system && npx playwright install
  chromium`; rebuilding the image is the durable answer.
- Reach the browser through the `playwright` package, never by a path into that
  directory: the directory names carry a build number that moves with every
  Playwright update.
- Chromium refuses to start its own sandbox as root, so a process running as root
  passes `args: ['--no-sandbox']`. Hand it only HTML the application generated.

## Services

All under supervisor; `supervisorctl status` lists them.

| Service | What it does |
|---|---|
| `nginx` | web server; `:80` plain, `:8000` asserting https behind a terminator |
| `php-fpm` | two pools — `www`, and `ajax` which exists to prevent an SSR deadlock |
| `redis` | cache, realtime delivery, short-lived counters |
| `mysql` | dev target only |
| `fpc-proxy` | full-page cache; calls back to nginx on `:3201` for misses |
| `realtime` | WebSocket relay behind `/ws` |
| `rsx-lockd` | cluster lock daemon — load-bearing, see its config |
| `tasks` | **the cron replacement**: ticks `rsx:task:process` once a minute |
| `dockerd` | dev target only — the container's own Docker Engine, for the test runner |
| `security-updates` | installs security updates and PHP patch releases every 4 hours (below) |

That last one matters. The framework's documented driver for all background and
scheduled work is a single crontab line, and a container has no crontab. Without
it, `Task::dispatch()` succeeds and nothing ever runs it — silently.

## Docker-in-docker (dev target)

The development image installs Docker Engine and runs it as the `dockerd`
supervisor program, because `php artisan rsx:test` runs every invocation in
sibling containers when it can. Three things are worth knowing:

- **runc is PINNED** to a version that can start a container while running inside
  one, in a single build ARG with the reasoning beside it. The download and its
  checksum are both fatal to the build: an image that silently kept the packaged
  binary would fail every `docker run` deep inside container init.
- **The inner engine gets its own address space** (`bip 10.210.0.1/24`, pools
  `10.211.0.0/16`), never the outer `172.17` bridge — whose route to this
  container's own gateway an inner `docker0` would otherwise shadow.
- **Half the job is on the host.** The container has to be STARTED with the
  capabilities and confinement dockerd and runc need — at minimum
  `--cap-add=CAP_NET_ADMIN --cap-add=CAP_SYS_ADMIN` plus
  `--security-opt apparmor=unconfined --security-opt seccomp=unconfined`. Without
  them the daemon says so in one line and exits without restarting, and the test
  runner runs sequentially. The full flag list and its `docker-compose.yml`
  equivalent are in `rsx:man testing`.

## ImageMagick reads raster coders only

`imagemagick/policy.xml` replaces the distribution's `/etc/ImageMagick-6/policy.xml`. It
denies every coder and then allows the raster formats plus PDF (a document's first page,
through Ghostscript). The vector and scripting coders an uploaded file could reach are
refused: SVG/MSVG (an `<image href="text:/etc/passwd">` inside an SVG rasterises a local
file into the thumbnail), MVG, MSL, TEXT, LABEL. The framework never asks for them — an SVG
upload is sanitized and its thumbnail is the extension icon, and the icons are PNG — and
`rsx:health` FAILs its "ImageMagick Coder Policy" row on a box where any is readable. A host
running the framework outside this image installs the same file by hand.

## Two things that look wrong and are not

**MySQL is inside the dev container rather than its own compose service.**
`migrate` snapshots the database before running by stopping MySQL through
`supervisorctl`, copying the data directory, and starting it again. That
requires MySQL to be a supervised program in the same container as PHP. Split it
out and migration rollback stops working.

That is also exactly why **prod runs migrations bare**: this image ships
`mysql-client` only, so there is no mysqld to stop and `/var/lib/mysql` is not
the database. `migrate` there takes no snapshot and performs no rollback, and
prints the reason before it starts. It decides by the dev marker
`/.rspade_container_dev` plus a local database host — not by `/.rspade_container`,
which both targets carry. A restore into a data directory that is not the live
database would report a successful rollback while the real database stayed
broken, so it is neither attempted nor advertised. `rsx:man migrations`,
SNAPSHOT PROTECTION.

**php-fpm runs as root in the dev target.** The application tree is a bind mount
from a machine whose file ownership we cannot predict; running as `www-data`
would make everything the app writes owned by uid 33, which the developer then
cannot edit. Root inside a container is not root on the host. The previous
arrangement solved this with `chmod -R 777` plus a permanent inotify watcher
re-applying it — that is gone. Production has no bind mount and so runs as
`www-data`.

## Ports

| Port | Publish it when |
|---|---|
| `80` | plain http — the development default |
| `8000` | a reverse proxy terminates TLS ahead of the container |

`APP_URL` must name the port your browser actually uses. A container cannot
discover its host-side mapping, so pass `RSPADE_APP_URL` (the compose file
does). Get it wrong and the realtime socket dials the wrong port.

## Environment

| Variable | Effect |
|---|---|
| `RSPADE_APP_URL` | written into `.env` as `APP_URL` at start |
| `RSPADE_CONTAINER_TARGET` | `dev` or `prod`; set by the image, not by you |
| `PHP_FPM_WORKER_COUNT` | prod only: the total php-fpm worker count, instead of the one calculated from the machine (below) |
| `RSX_AUTO_SECURITY_UPDATES` | `0` (or `false`/`no`/`off`) turns the automatic security updates off; on when unset (below) |
| `RSPADE_SECURITY_UPDATE_HOURS` | hours between the scheduled security update checks; `4` when unset |

## Security updates

**The container patches its own PHP.** PHP comes from the ondrej PPA, unpinned within the
image's minor version (8.5), so a PHP security release is a patch release (8.5.x) that apt
can install into a running container. A release that hands a remote shell to anyone who can
reach an unpatched PHP does not wait for an image rebuild, so the container does not either:

- **At every start, before PHP serves anything**, the entrypoint installs the security
  updates the container may take. If `apt-get update` or the upgrade fails it tries once
  more a minute later, then starts regardless on whatever is installed - an unreachable
  mirror never stops a container - and the scheduled check keeps trying.
- **Every 4 hours** (within 10 minutes either side of the slot, so a fleet does not arrive at
  the PPA at once) the `security-updates` program checks again. When something was installed
  it reloads php-fpm in place onto the new PHP (a request in flight at that instant is cut,
  as on any php-fpm reload), restarts nginx when nginx changed, and leaves anything else that
  changed to load at the next container restart. Running CLI processes - task workers
  included - keep the PHP they started with and pick up the new one when they next start.

**What may be installed** is `unattended-upgrades` held to an allow-list
(`apt/52rspade-security-updates`): the Ubuntu security pocket, and the ondrej PHP PPA. Nothing
from the release or `-updates` pockets, nodesource or Docker. A new PHP minor version is a
different package set, so it only ever arrives with a new image. The dev image also holds
MySQL back (`apt/53rspade-security-updates-dev`): its package scripts work on the data
directory, which a `migrate` may be snapshotting.

**Every check is reported** in `/var/lib/rspade/security-updates.json`, which the
`rsx:health` "Security Updates" row reads, and in the container log.

**Turning it off** is for an operator whose own change control installs updates:

```
docker run -e RSX_AUTO_SECURITY_UPDATES=0 ...
```

or `RSX_AUTO_SECURITY_UPDATES: "0"` under the service's `environment:` in a compose file.
The `rsx:health` row then warns, so the decision stays visible.

**It is a safety net, not the upgrade path.** A container that patches itself drifts from
the image it was started from, and only packages that the allow-list covers are refreshed.
Pull or rebuild the image periodically - and promptly when a PHP security release is
announced - so the base system, the bundled services and the PHP minor version stay current.

## PHP-FPM is sized to the machine (prod)

At every start, before php-fpm runs, the production container works out how many
workers the machine can carry and writes both pools (`rspade-php-fpm-workers`,
`php-fpm-workers.sh` here - the formula and its reasoning are in that script):

```
total   = max(1, min(cores * 3,
                     floor((memory_mb - 1000) * 0.9 / 320 + floor(min(swap_mb, 1000) / 450) - 4)))
web     = max(2, floor(total / 4))         # page requests
ajax    = max(1, ceil(total * 3 / 4))      # Ajax requests
standby = max(1, min(4, floor(pool / 2)))  # idle workers each pool keeps
```

320 MB is one worker at its memory limit (`php/php-prod.ini`). The first 1000 MB and a
tenth of the rest are left to the operating system; four workers' worth is held back
for Redis, the other services and the background task workers; swap earns at most two
more. A limit docker puts on the container (`--memory`, `--cpus`) is what is measured,
not the host behind it.

| Memory (no swap) | Total | Web | Ajax |
|---|---|---|---|
| 2 GB | 1 | 2 | 1 |
| 4 GB | 4 | 2 | 3 |
| 8 GB | 16 | 4 | 12 |
| 16 GB | 39 | 9 | 30 |

Set the total yourself with `-e PHP_FPM_WORKER_COUNT=24` (compose: `environment:`);
the split and the standby counts are still derived from it. The start line says what
was chosen and from what:

```
[rspade] php-fpm sized from 4 cores, 7950 MB memory, 0 MB swap: 12 worker(s) -> web 3 (1 standby), ajax 9 (4 standby).
```

The development image is not sized: it keeps 8 on-demand workers a pool.

Everything else is ordinary `.env` configuration — see `.env.README` in the
project root.

## First boot

1. Creates `.env` from `.env.dist` if absent.
2. Generates `APP_KEY` if blank.
3. Sets `APP_URL` from `RSPADE_APP_URL`.
4. **dev**: generates first-user credentials if blank and prints them once.
   **prod**: refuses to start rather than inventing a credential.
5. Unpacks a pristine MySQL data directory (dev, empty volume only).
6. Starts supervisor.
7. Waits for the database — without a deadline, because a slow start is normal
   and a timeout would turn "wait longer" into a failed boot.
8. Provisions databases (dev, first boot only).
9. **dev**: migrates. **prod**: reports pending migrations and leaves them.
10. Runs `rsx/resource/docker/configure.sh` if present.

Every step is idempotent; a restart finds its work done and says nothing.

## Architecture

Authored to build on amd64 and arm64 alike: everything installs through apt or a
distribution setup script, nothing fetches an architecture-pinned binary, and the
base image is pinned by tag rather than by a single-arch digest. Keep it that way
— a hardcoded `x86_64` download URL is what breaks the arm64 build.
