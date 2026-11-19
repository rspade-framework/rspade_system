# Deploying RSpade to Production

Running an RSpade application on the production image: what the host needs, the
first install, the recommended procedure for every deploy after it, rolling back,
and the container's environment switches.

Day-to-day care of a running installation - backups, updates, health, logs,
maintenance mode - is in
[System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md).
The reference for everything here is `php artisan rsx:man prod`.

**Contents**

1. [The production image](#1-the-production-image)
2. [What you need](#2-what-you-need)
3. [First install](#3-first-install)
4. [Deploying an update](#4-deploying-an-update)
5. [Rolling back](#5-rolling-back)
6. [Before you launch](#6-before-you-launch)
7. [The container environment](#7-the-container-environment)
8. [Related guides and reference](#8-related-guides-and-reference)

---

## 1. The production image

    ghcr.io/rspade-framework/rspade-server-prod

It is the development runtime without the database server, published for x86 and
ARM. Each version is tagged with its build date (`2026.11.19`) and the newest also
as `latest`; a version tag never changes, so pin one if you want to decide when the
image moves.

What makes it a production image:

- **No database server.** You provide MySQL and name it in `.env`.
- **A sealed build.** Production serves assets compiled once, minified and pinned;
  the application refuses to serve anything that drifts from the seal, and editing
  a file on the server changes nothing until the next build.
- **Sized to the machine** at every start: PHP runs as `www-data` in a worker pool
  the container sizes from the cores and memory it has.
- **Nothing invented.** A blank credential stops the start instead of being made up,
  and the database is migrated only when you say so.

The application is not in the image. The project is a git checkout on the host,
mounted at `/var/www/html`, so one image serves any checkout and an image update
never touches your code or data.

---

## 2. What you need

**A host with Docker.** At least 1 vCPU, 2 GB of memory and 1 GB of swap; 2 vCPU,
4 GB and 4 GB of swap is comfortable. The container sizes itself to whatever it is
given (`php artisan rsx:man server_sizing`).

**Your own MySQL server**, reachable from the container, with a database and a user
for the application. A managed database service is fine.

**A TLS-terminating reverse proxy** in front of the container - Caddy, nginx, a
cloud load balancer. The container never holds a certificate: the proxy forwards to
the container's port **8000**, which tells the application the visitor is on https.
(Port 80 is plain http, for a container with nothing in front of it - a proxy
pointed there gets `http://` links and a login that loops.) The proxy should append
`X-Forwarded-For`, which proxies do by default.

**The project checkout on the host**, with the framework submodule:

```bash
git clone --recurse-submodules <your repository> /srv/my-app
```

**A `.env` in the project root** - it is not in git. Start from `.env.dist` and set:

| Key | |
|---|---|
| `APP_URL` | the https address people type, e.g. `https://app.example.com` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | your MySQL server |
| `RSPADE_DEFAULT_EMAIL`, `RSPADE_DEFAULT_PASSWORD` | the first account, created by the first migrate. The production image checks they are set at every start |
| `TRUSTED_PROXIES` | the address of the proxy as the container sees it (or set `RSPADE_TRUSTED_PROXIES` on the container instead) |
| `LOG_LEVEL` | `info` |
| `APP_KEY` | leave blank on a first install: the first start generates it. From then on it must never change or be lost - back `.env` up |

Mail, storage and third-party credentials are ordinary `.env` settings;
`.env.README` in the project root explains every one.

**Trusted proxies matter.** Without the proxy listed, every visitor appears to come
from the proxy's address: the login throttle counts the whole world as one visitor,
login history names the proxy, and the request scheduler treats every visitor as a
single client. List the proxy's own address (pin it, if Docker assigns it), never a
range that clients can reach the container from directly. `php artisan rsx:man prod`,
BEHIND A LOAD BALANCER.

---

## 3. First install

Start the container on a Docker network shared with your proxy, publishing nothing:

```bash
docker run -d --name my-app --network web \
  --restart unless-stopped \
  --stop-timeout 86400 \
  --log-driver local \
  -v /srv/my-app:/var/www/html \
  ghcr.io/rspade-framework/rspade-server-prod:latest

docker exec my-app rspade-wait-started
```

`rspade-wait-started` returns when the start has finished, non-zero if it failed
(the `[rspade] ERROR:` line is in `docker logs my-app`). `--stop-timeout` lets a
stop finish what is mid-write instead of killing it after Docker's default 10
seconds; `--log-driver local` keeps Docker's log of the container rotated.

Then build, seal and create the schema, on the empty database:

```bash
docker exec my-app php artisan rsx:mode:set prod   # production mode: build and seal
docker exec my-app php artisan migrate             # the schema, and the first account
docker exec my-app php artisan rsx:health
```

Until those have run the site is not ready to serve; point the proxy's public name
at it once `rsx:health` passes. Sign in with `RSPADE_DEFAULT_EMAIL` and
`RSPADE_DEFAULT_PASSWORD`.

The same container with Compose:

```yaml
services:
  app:
    image: ghcr.io/rspade-framework/rspade-server-prod:latest
    restart: unless-stopped
    stop_grace_period: 24h
    logging:
      driver: local
    volumes:
      - /srv/my-app:/var/www/html
    networks: [web]

networks:
  web:
    external: true
```

---

## 4. Deploying an update

The recommended procedure for every deploy - new application code, a framework
update, a new image, or all three:

1. **Pull the latest RSpade image.**

   ```bash
   docker pull ghcr.io/rspade-framework/rspade-server-prod:latest
   ```

2. **Stop the existing container, if any.**

   ```bash
   docker stop my-app && docker rm my-app
   ```

3. **Pull the project's git updates.**

   ```bash
   git -C /srv/my-app pull --recurse-submodules
   ```

4. **Snapshot the database.** How varies by environment: a filesystem snapshot on
   the host (LVM, ZFS, btrfs), or a cloud snapshot of the database or its volume on
   AWS or Azure. This snapshot is your rollback.

5. **Start the container with `RSPADE_BUILD_MIGRATE_ON_START=1`.**

   ```bash
   docker run -d --name my-app --network web \
     --restart unless-stopped \
     --stop-timeout 86400 \
     --log-driver local \
     -e RSPADE_BUILD_MIGRATE_ON_START=1 \
     -v /srv/my-app:/var/www/html \
     ghcr.io/rspade-framework/rspade-server-prod:latest

   docker exec my-app rspade-wait-started
   docker exec my-app php artisan rsx:health
   ```

With the switch on, the start builds and reseals the application
(`rsx:build --force`) and then runs the migrations, **before** the web server or the
task scheduler starts - so no request and no background job ever runs new code
against an old schema. If the build or a migration fails, the container stops with
the output in `docker logs my-app` and serves nothing.

**The migration runs bare**, with no automatic undo: that is what step 4 is for.
Before step 2, note what you are deploying from, so a rollback can name it:

```bash
git -C /srv/my-app rev-parse HEAD                               # the code
docker exec my-app grep ^image_version= /opt/rspade/image.conf  # the image
```

While the container is stopped, the proxy answers visitors with its own error page.
For a longer window with a proper "down for maintenance" page, see maintenance mode
in [System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md#6-maintenance-mode).

Two things to know about the switch:

- **It stays with the container.** Every later restart of that container - a host
  reboot included - builds and migrates again. With the code unchanged that costs
  only the build time; there is nothing pending to migrate.
- **Start one container with it.** Two containers starting at once against the same
  database would both migrate. On a cluster, deploy one node with the switch and
  start the others without it.

`php artisan rsx:man prod`, THE PRODUCTION IMAGE, covers the alternatives: deploying
without the switch (migrate and build as separate commands inside a maintenance
window), building elsewhere, and running on a server without a container.

---

## 5. Rolling back

Rolling back is the deploy in reverse:

1. Stop and remove the container.
2. Restore the database snapshot from step 4.
3. Return the checkout to the previous revision:

   ```bash
   git -C /srv/my-app checkout --recurse-submodules <previous revision>
   ```

4. Start the previous image version, with the switch on, so the previous code is
   built and sealed again:

   ```bash
   docker run -d --name my-app ... \
     -e RSPADE_BUILD_MIGRATE_ON_START=1 \
     ghcr.io/rspade-framework/rspade-server-prod:<previous version>
   ```

There is nothing to migrate - the restored database already matches the previous
code. Once the problem is fixed, deploy forward again with the normal procedure.

---

## 6. Before you launch

Two checks cover what is impractical to enforce automatically:

- walk `php artisan rsx:man launch_checklist` on the production host;
- have the launch conformance audits run against your code
  (`php artisan rsx:conformance:list --when=launch`).

Neither replaces the other.

---

## 7. The container environment

Set with `-e` on `docker run`, or under `environment:` in a compose file. A boolean
takes `1`/`0`, `true`/`false`, `yes`/`no` or `on`/`off`.

### The ones to know

| Variable | Effect |
|---|---|
| `RSPADE_BUILD_MIGRATE_ON_START` | `1` makes the start a deployment: build and reseal, then migrate, before anything serves (section 4). Off by default; the production image then only reports pending migrations |
| `RSPADE_TRUSTED_PROXIES` | the proxies in front of the container, addresses or CIDR ranges, comma-separated - used when `.env` `TRUSTED_PROXIES` is blank. A malformed value or a `/0` range stops the start |
| `RSPADE_APP_URL` | written into `.env` as `APP_URL` at start - for a deployment that sets its address from outside the checkout |
| `RSPADE_LOG_TO_DOCKER` | `1` echoes the nginx access log and the application's errors (with stack traces) onto `docker logs`. Off by default; run such a container with `--log-driver local` |

### Advanced overrides

**These rarely need setting. The defaults are right for most situations: the
container sizes its web workers, background task workers, memory limits and cache
to the machine at every start.** Set one only for a case the automatic sizing
cannot see - a machine past its ceiling of 140 PHP processes, memory another
program on the host needs, or your own change control for updates. A value that is
not a whole number in range stops the start, naming the switch.

| Variable | Default |
|---|---|
| `RSPADE_PHP_WORKERS` | PHP web workers; calculated from the machine |
| `RSPADE_PHP_MEMORY_LIMIT_MB` | PHP's `memory_limit`, web and command line; 352 in production, 320 in development |
| `RSPADE_PHP_TYPICAL_MB` | what one PHP process typically holds, for the memory budget; 128 |
| `RSPADE_PHP_WORKERS_PER_CORE` | the CPU ceiling on PHP workers; 3 |
| `RSX_TASK_MAX_WORKERS` | on-demand background task workers; two thirds of the calculated task workers. Also read from `.env` |
| `RSX_TASK_SCHEDULED_WORKERS` | scheduled background task workers; a third of the calculated task workers. Also read from `.env` |
| `RSPADE_MEMORY_RESERVED_MB` | memory to leave alone for other programs on the host; 0 |
| `RSPADE_REDIS_MEMORY_MB` | the Redis cache's cap; scaled to the machine |
| `RSPADE_MYSQL_BUFFER_MB` | development image only: the MySQL buffer pool; 64 |
| `PHP_SECURITY_UPDATES` | `0` turns the automatic security updates off; on by default |
| `PHP_SECURITY_UPDATE_INTERVAL_HOURS` | hours between security update checks; 4 |

`php artisan rsx:man server_sizing` explains the sizing and when to take it over;
the [container image README](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/resource/docker/README.md#environment)
is the complete list.

---

## 8. Related guides and reference

| | |
|---|---|
| [System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md) | backups, updates, health, logs, maintenance mode, the `/_sys` panel |
| [Development environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPMENT_ENVIRONMENT.md) | the development container |
| [Developer crash course](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md) | the framework in one sitting |
| [The container image](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/resource/docker/README.md) | services, ports, every environment switch, the start sequence |
| `php artisan rsx:man prod` | production in full: the sealed build, every deployment recipe, the read-only posture, every administration command |
| `php artisan rsx:man server_sizing` | machine sizing |
| `php artisan rsx:man launch_checklist` | the checks before going live |
