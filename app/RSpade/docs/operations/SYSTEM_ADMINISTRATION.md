# RSpade System Administration

Looking after a running RSpade installation: what runs inside the container,
where the data lives and what to back up, keeping the image and the framework
current, checking health, reading logs, taking the site down for maintenance, and
the operator's control panel.

It applies to a development box and a production box alike; where they differ, it
says so. Putting a site into production for the first time, and the deploy
procedure, are in
[Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md).

Every `php artisan` command here runs **inside the container**:
`docker compose exec app php artisan <command>` with Compose, or
`docker exec <name> php artisan <command>`.

**Contents**

1. [What runs in the container](#1-what-runs-in-the-container)
2. [Where the data lives, and what to back up](#2-where-the-data-lives-and-what-to-back-up)
3. [Updating](#3-updating)
4. [Checking health](#4-checking-health)
5. [Logs](#5-logs)
6. [Maintenance mode](#6-maintenance-mode)
7. [Automatic sizing](#7-automatic-sizing)
8. [The control panel: /_sys](#8-the-control-panel-_sys)
9. [Related guides and reference](#9-related-guides-and-reference)

---

## 1. What runs in the container

One container holds the whole runtime. The application itself is not in the image:
the project is mounted at `/var/www/html`, so one image serves any checkout.

| Service | What it does |
|---|---|
| nginx | the web server: port 80 for plain http, port 8000 behind a TLS-terminating proxy |
| Proxority | a fair-queueing proxy in front of PHP, so one busy client cannot take every worker |
| php-fpm | PHP for every request |
| Redis | cache, live-update delivery, short-lived counters |
| rsx-lockd | the lock service that keeps concurrent writes to a site in order |
| tasks | the scheduler for background and scheduled work - the container's replacement for cron |
| realtime | the WebSocket relay that lets open pages refresh themselves |
| rsx-companiond | runs the application's long-lived companion programs, if it has any |
| security-updates | installs security updates on a schedule (section 3) |
| MySQL, mail catcher | **development image only**. The production image uses your own MySQL server, and sends mail through what `.env` names |

`docker compose exec app supervisorctl status` lists them. A service that stops is
restarted, forever, with a growing pause between attempts; one that keeps failing
is reported by the container's Docker health check, by
`php artisan rsx:health`, and on the `/_sys` dashboard.

At every start the container works through a fixed sequence - check the image
version, size itself to the machine, install security updates, start the
infrastructure, wait for the database, optionally build and migrate, then start
the web and worker services - and prints each step to `docker logs`. To wait for a
start to finish from a script:

```bash
docker compose exec app rspade-wait-started
```

It exits 0 once the start is done and non-zero when the start failed. The
[container image README](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/resource/docker/README.md)
has the full service list, the start sequence and the ports.

---

## 2. Where the data lives, and what to back up

Everything the running application writes is outside the container, so a
container can be stopped, removed and recreated without losing anything.

| What | Where | Back it up? |
|---|---|---|
| Your code | the git repository (`rsx/`, with the framework pinned in `system/`) | it is in git |
| Settings and secrets | `.env` at the project root - database credentials, `APP_KEY`, mail and API keys | **yes** - it is not in git, and `APP_KEY` cannot be recovered |
| Uploaded files | `storage/uploads/` - the file store | **yes** - real user data, not regenerable |
| Other application data | `storage/app/` | **yes** |
| Logs | `storage/logs/` | as your retention policy requires |
| Operational state | `storage/state/` - the maintenance flag, lock files | harmless to include |
| The database | development: `storage/mysql_data/`. Production: your own MySQL server | **yes** |
| `build/`, `tmp/` | the build, derived caches, thumbnails | no - regenerated on demand |

So a backup is **the database, `storage/`, and `.env`**. `storage/` and `tmp/` can
be relocated with `RSX_STORAGE_PATH` / `RSX_TMP_PATH` in `.env`; back up wherever
`storage/` actually is. `php artisan rsx:man storage_directories` lists every entry.

**The development database is a directory of live MySQL files.** Copying it while
the container runs can capture a half-written state. Stop the container first
(`docker compose stop`), or take a logical dump instead:

```bash
docker compose exec app mysqldump --single-transaction rspade > backup.sql
```

**In production the database is yours**, and so is its backup: use whatever your
platform provides - a filesystem snapshot on the host, a managed database's
snapshots, or scheduled dumps. A snapshot is also step 4 of every deploy
([Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md)).

---

## 3. Updating

Three things move independently: the container image, the framework in `system/`,
and the security patches the container installs on its own.

### The image

The image carries the services above, and each new version brings their updates.
Images are published on GitHub Container Registry for x86 and ARM:

    ghcr.io/rspade-framework/rspade-server-dev     development
    ghcr.io/rspade-framework/rspade-server-prod    production

Each version is tagged with its build date (`2026.11.19`, or `2026.11.19.2` for a
second build that day), and the newest also as `latest`. A version tag never
changes: pull `latest` to follow along, or pin a version to decide when the image
moves.

Updating is a pull and a recreate - with Compose:

```bash
docker compose pull
docker compose up -d
```

without it, `docker pull` the image, `docker stop` and `docker rm` the container,
and `docker run` it again with the same arguments. In production, do it as part of
the deploy procedure. The running version is in the image itself:

```bash
docker exec <name> grep ^image_version= /opt/rspade/image.conf
```

**The framework states the oldest image it runs on.** When a framework update
depends on something only a newer image has, an older image is refused outright
rather than left to half-work: the container does not start, commands refuse, and
pages answer with an error - each naming the `docker pull` that fixes it.
`rsx:framework:pull` checks before applying a release, and `rsx:health` fails its
"Container Image" row. A newer image under an older checkout is always fine.

### The framework

The framework lives in `system/`, a git submodule, and is updated as a unit:

```bash
php artisan rsx:framework:pull
```

It raises maintenance mode, fetches and applies the current release, rebuilds, and
records the new `system/` revision as a commit in your project. Your own code in
`rsx/` is never touched; everything under `system/` is replaced. Afterwards, list
anything the release asks you to change in your own code by hand:

```bash
php artisan rsx:framework:breaking_changes
```

Update the framework on a development box, check the application, then deploy
that commit to production like any other change. `php artisan rsx:man rsx_upstream`
covers the update in full.

### Security updates are automatic

The container patches its own PHP and operating system between images:

- **At every start**, before PHP serves anything, it installs the security updates
  available. An unreachable package mirror never stops a start.
- **Every 4 hours** while it runs, it checks again. When a PHP update is installed,
  PHP is restarted **without cutting a request in flight**: new requests wait in
  Proxority's queue (never refused), requests in flight finish, PHP restarts, and
  the waiting requests run on the new version. Running background workers keep the
  PHP they started with and pick up the new one when they next start.

What it installs is limited to the Ubuntu security updates and PHP patch releases;
a new PHP minor version only arrives with a new image. The `rsx:health` row
"Security Updates" reports every check.

It is a safety net, not the upgrade path: pull a new image periodically, and
promptly when a PHP security release is announced. To turn it off - for an
operator whose own change control installs updates - start the container with
`PHP_SECURITY_UPDATES=0` (`rsx:health` then warns, so the decision stays visible);
`PHP_SECURITY_UPDATE_INTERVAL_HOURS` changes the interval.

---

## 4. Checking health

```bash
php artisan rsx:health
```

One command checks every dependency, service and environment invariant the
installation relies on - the database, Redis, the lock service, the task
scheduler, the supervised services, the image version, the sizing, the security
updates, the production seal, the schema the framework needs, and the
application's own checks. Each row is OK, INFO, WARN or FAIL, with a remedy beside
anything that is not OK. It exits 1 exactly when a row FAILs, so it works as a
deploy gate and a monitoring probe; `--json` gives a machine-readable report.

Run it after every deploy and every image update. Some rows offer an automatic
repair for a piece that is simply missing: `php artisan rsx:heal --list`.

The container also has a Docker health check, which reports a service that keeps
failing - `docker inspect --format '{{json .State.Health}}' <name>` shows it. It only
reports; nothing restarts an unhealthy container. `php artisan rsx:man health` has
the full inventory.

---

## 5. Logs

| Log | Where |
|---|---|
| The application | `storage/logs/laravel.log` - errors, warnings and what the application writes |
| Companion programs, IDE helpers | other `*.log` files in `storage/logs/` |
| The container's start and its services | `docker logs <name>` (`docker compose logs app`) |
| The web server's access log | inside the container at `/var/log/nginx/access.log`; in `docker logs` with `RSPADE_LOG_TO_DOCKER=1` |

**`storage/logs/` rotates itself.** A nightly framework task compresses each log
after 3 days and deletes it after 21 (`LOGROTATE_ENABLED`,
`LOGROTATE_DAYS_UNCOMPRESSED` and `LOGROTATE_DAYS_RETENTION` in `.env`);
`php artisan rsx:logrotate` runs the same rotation on demand. No operating-system
logrotate is involved. `php artisan rsx:man logrotate`.

**In production, set `LOG_LEVEL=info` in `.env`.** Debug logging writes every query
to disk; `rsx:health` warns about it in a production mode.

**`docker logs`.** With `RSPADE_LOG_TO_DOCKER=1` the container also echoes the nginx
access log and every ERROR-and-above entry of `laravel.log` (with its stack trace)
onto its own output, prefixed `[nginx]` and `[laravel]` - so a log collector
reading Docker's output sees requests and failures without reading files. It is
off by default.

**Docker's default log driver never rotates.** `json-file` keeps everything a
container prints for as long as the container exists. Run the container with
`--log-driver local` (Compose: `logging: driver: local`), which rotates and
compresses - always advisable, and essential with `RSPADE_LOG_TO_DOCKER=1`. The
shipped `docker-compose.yml` already sets it.

---

## 6. Maintenance mode

```bash
php artisan rsx:maintenance:enable --reason="database upgrade"
php artisan rsx:maintenance:disable
```

Enable answers every web request with a 503 page quoting the reason, refuses the
automated task runners, and stops the runtime services in a safe order; disable
brings everything back. The framework raises the same window around its own
disruptive operations - a framework update, for one.

Both commands work even on a broken or half-updated checkout, so a flag left behind
by a crash is cleared simply by running disable. The flag lives under `storage/`,
so a container recreated during maintenance comes up still in it, answering 503
until you disable it. `php artisan rsx:man maintenance_mode`.

---

## 7. Automatic sizing

At every start the container measures the cores, memory and swap it has (the limit
Docker put on it, not the host behind it) and sizes itself: the number of PHP web
workers, the background task workers, PHP's memory limit, and the Redis cache. The
start line in `docker logs` says what it chose:

```
[rspade] sized for 4 cores, 7950 MB memory, 0 MB swap: php-fpm 16, 4 task workers (1 scheduled, 3 on demand), memory_limit 320M, redis 397 MB.
```

Resize the machine, restart the container, and the pools follow. Nothing needs
setting on most machines. The overrides exist for the cases the formula cannot see
- a co-tenant on the same host, a machine past the automatic ceiling - and are
listed in
[Production deployment, the container environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md#7-the-container-environment).

| | Minimum | Comfortable |
|---|---|---|
| production | 1 vCPU, 2 GB RAM, 1 GB swap | 2 vCPU, 4 GB RAM, 4 GB swap |
| development | 2 vCPU, 4 GB RAM, 2 GB swap | 4 vCPU, 8 GB RAM |

`rsx:health` reports what the box was sized for ("Resource Sizing"), and
`php artisan rsx:man server_sizing` has the formula, the ceiling and when to take
the numbers over yourself.

---

## 8. The control panel: /_sys

Every installation serves the framework's own control panel at `/_sys`: the system
administrator's view of what the framework is doing, across every site. The
dashboard shows the mode and build, task workers, recent failed tasks, pending
mail, and the full `rsx:health` report; further screens cover the system and PHP,
background tasks and their schedules, locks, companion programs, performance
against the sizing, email and SMS, logs, and the sites and users.

Only an account marked as a **developer** may open it. The first account created on
a new installation is one; any other is made one by hand, in the database:

```sql
UPDATE login_users SET is_developer = 1 WHERE email = 'someone@example.com';
```

There is deliberately no screen or command for that. An application can switch the
panel off entirely (`'sys_panel' => ['enabled' => false]` in
`rsx/resource/config/rsx.php`). `php artisan rsx:man sys_panel` describes each
screen.

---

## 9. Related guides and reference

| | |
|---|---|
| [Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md) | the production image, first install, the deploy procedure, rollback, the container environment |
| [Development environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPMENT_ENVIRONMENT.md) | Compose, `docker run`, the database and GUI clients, Claude Code |
| [Developer crash course](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md) | the framework in one sitting |
| [The container image](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/resource/docker/README.md) | services, ports, every environment switch, the start sequence |
| `php artisan rsx:man prod` | production in full: the sealed build, deployment, every administration command |
| `php artisan rsx:man health` | every health row |
| `php artisan rsx:man maintenance_mode` | the maintenance window |
| `php artisan rsx:man server_sizing` | machine sizing |
| `php artisan rsx:man storage_directories` | `build/`, `tmp/` and `storage/`, entry by entry |
| `php artisan rsx:man launch_checklist` | before a site goes live |
