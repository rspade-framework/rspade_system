# The RSpade Development Environment

How the development container runs, and how to fit it to your machine: Compose
or plain `docker run`, file ownership, the database and your own tools, a reverse
proxy in front of a development box, Claude Code, and keeping the image current.

New to the framework itself? Read the
[developer crash course](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md)
first; this page is about the box, not the code.

**Contents**

1. [Compose](#1-compose)
2. [File ownership on Linux: PUID and PGID](#2-file-ownership-on-linux-puid-and-pgid)
3. [Running it without Compose](#3-running-it-without-compose)
4. [The database](#4-the-database)
5. [Behind a reverse proxy](#5-behind-a-reverse-proxy)
6. [Claude Code in the container](#6-claude-code-in-the-container)
7. [Updating the image](#7-updating-the-image)
8. [Logs and the Docker log driver](#8-logs-and-the-docker-log-driver)
9. [Related guides](#9-related-guides)

---

## 1. Compose

The project ships a `docker-compose.yml` at its root, and `docker compose up` is
the whole of starting it:

```bash
docker compose up
```

The first start downloads the development image,
`ghcr.io/rspade-framework/rspade-server-dev` (built for x86 and ARM alike), once.
Open **http://localhost:8080** and the application walks you through its setup
screens: its own address first, then the account you want to sign in with. Keep
the first run in the foreground so you can watch the services come up; after that,
`docker compose up -d` is fine.

The development image holds everything the application runs on - MySQL, Redis,
nginx, PHP and the framework's own services - so your machine needs Docker and
git and nothing else. Plan on 4 GB of memory for Docker (2 GB is uncomfortably
tight) and about 6 GB of disk for the image.

What the compose file sets, and why:

| | |
|---|---|
| `image:` | the published development image; `docker compose pull` fetches a newer one |
| `ports: "8080:80"` | the application. Change the host side freely; nothing else needs to agree with it, because the application learns its own address from the first request on the setup screen |
| `.:/var/www/html` | your project. The container serves what you edit |
| `./storage/mysql_data:/var/lib/mysql` | the database, kept as an ordinary directory in your project |
| `./storage/.claude:/root/.claude` | Claude Code's login, settings and history |
| `logging: driver: local` | Docker's own log rotation (section 8) |
| `stop_grace_period: 24h` | lets MySQL finish flushing on a stop instead of being killed mid-write |

**macOS and Windows:** if MySQL fails to start with errors about file locking or
InnoDB, the Docker Desktop filesystem layer is the reason. Swap the one database
mount for a named volume (`- rspade-mysql:/var/lib/mysql`, plus a top-level
`volumes:` block declaring `rspade-mysql:`) and everything else keeps working. The
compose file carries the same note beside that line.

---

## 2. File ownership on Linux: PUID and PGID

On a Linux host, set `PUID` and `PGID` to your own user and group ids, so the
files the application writes into your project - the database files included -
belong to you rather than to root, and you can move or delete your own project
without `sudo`:

```bash
id -u    # -> PUID
id -g    # -> PGID
```

The compose file has the `environment:` block for them, commented out; uncomment
it and fill in the numbers. macOS and Windows do not need this - Docker Desktop
already presents every file as yours - and setting them there changes nothing.

---

## 3. Running it without Compose

The compose file is not doing anything clever. Here is the whole of it as a single
`docker run`:

```bash
docker run -d \
  --name rspade \
  -p 8080:80 \
  -e PUID=$(id -u) -e PGID=$(id -g) \
  -v "$PWD":/var/www/html \
  -v "$PWD/storage/mysql_data":/var/lib/mysql \
  -v "$PWD/storage/.claude":/root/.claude \
  --log-driver local \
  --stop-timeout 86400 \
  ghcr.io/rspade-framework/rspade-server-dev:latest
```

Reach for this when Compose is in your way: when the container is one service in
a larger stack, when you want the project, the database or Claude Code's state
somewhere other than this directory, or when the port should not be published to
the host at all because something else is terminating traffic in front of it.

| | |
|---|---|
| `-p 8080:80` | the application. Drop it entirely behind a reverse proxy on a shared network (section 5) |
| `-e PUID` / `-e PGID` | run as you (section 2). Linux only; harmless elsewhere |
| `-v "$PWD":/var/www/html` | your project |
| `-v .../var/lib/mysql` | the database. Point it anywhere you like - it is an ordinary directory, and it arrives empty: the container initialises it on first start |
| `-v .../root/.claude` | Claude Code's login and history, if you use it |
| `--log-driver local` | Docker's own log rotation (section 8) |
| `--stop-timeout 86400` | lets the container shut down cleanly, however long MySQL takes to flush |

Pass a command and it runs once the application is actually serving, taking the
container down when it exits - so this boots in the foreground, shows you the
startup, and leaves you at a prompt:

```bash
docker run -it --rm \
  -p 8080:80 \
  -v "$PWD":/var/www/html \
  -v "$PWD/storage/mysql_data":/var/lib/mysql \
  ghcr.io/rspade-framework/rspade-server-dev:latest bash
```

Keep the database mount even here. Without it the container gets a fresh, empty
database that `--rm` throws away when the shell exits - fine if that is what you
meant, surprising if it is not.

---

## 4. The database

The development container runs its own MySQL, and the client tools are in there
with it - you do not need MySQL installed on your machine to use them.

```bash
# A SQL prompt on the application's database
docker compose exec app mysql rspade

# Dump the whole schema and its data
docker compose exec app mysqldump rspade | less

# Throw the database away and start over
echo "drop database rspade; create database rspade" | docker compose exec -T app mysql
```

From a shell inside the container, `php artisan db:query "SELECT ..."` does the
same without leaving the framework's tooling.

**Name the database.** `mysql` on its own connects to the server without selecting
anything, and the first query answers `ERROR 1046 (3D000): No database selected`.
`mysql rspade` is the form to use; the same goes for `mysqldump`. If you renamed
the database in `.env`, use that name.

**After a reset, migrate.** Dropping the database leaves an empty one and an
application that cannot serve a page. Bring the schema back:

```bash
docker compose exec app php artisan migrate
```

The setup screens return on the next page load, because the account you had lived
in the database you just dropped.

**No password, and that is not an oversight.** MySQL's `root` account here
authenticates by unix socket - the operating-system user connecting *is* the
credential - and `docker compose exec` runs as root inside the container. So these
commands arrive already authenticated, with more privilege than the application
itself has. The application connects as the `rspade` user with the password in
`.env`. None of this reaches outside the container: the socket is not on your
machine and the port is not published.

### A GUI client

TablePlus, DataGrip, DBeaver and the like need the MySQL port published to your
machine, which it is not by default. Add it to the `app` service in
`docker-compose.yml`:

```yaml
    ports:
      - "8080:80"
      - "3306:3306"
```

Then connect to `127.0.0.1:3306` with `DB_USERNAME` and `DB_PASSWORD` from `.env`.
Leave it off if you do not need it: it is one more thing listening on your machine.

---

## 5. Behind a reverse proxy

A development box can sit behind a TLS-terminating proxy exactly as a production
one does - a shared team server, or a machine you reach by a real hostname. The
container never holds a certificate: TLS is terminated in front of it, and the
proxy forwards to port **8000**. That port serves the same site as port 80 while
telling the application the visitor is on https, which is what makes generated
URLs `https://` and the session cookie `Secure`. Port 80 is plain http, for a
container with nothing in front of it.

Put the container on a network with your proxy and publish nothing:

```bash
docker network create web

docker run -d --name rspade --network web \
  -e VIRTUAL_HOST=app.example.com \
  -e VIRTUAL_PORT=8000 \
  -e LETSENCRYPT_HOST=app.example.com \
  -v "$PWD":/var/www/html \
  -v "$PWD/storage/mysql_data":/var/lib/mysql \
  --log-driver local \
  --stop-timeout 86400 \
  ghcr.io/rspade-framework/rspade-server-dev:latest
```

That labelling is [nginx-proxy](https://github.com/nginx-proxy/nginx-proxy)'s.
With [Caddy](https://caddyserver.com/), a two-line Caddyfile does the same job and
obtains the certificate itself:

```
app.example.com {
    reverse_proxy rspade:8000
}
```

**Set `APP_URL` in `.env` to the address people actually type**, not the
container's - `APP_URL=https://app.example.com`. RSpade generates every URL from
that one value.

Two things that follow, worth knowing before you debug them:

- **The port decides the scheme, not a header.** The container ignores an
  `X-Forwarded-Proto` a proxy sends: a proxy pointed at port 80 gets `http://`
  redirects and links however it is configured. If logins loop or links come out
  `http://`, the proxy is forwarding to 80 - point it at 8000.
- **Name the proxy as trusted.** Forward `X-Forwarded-For` (both proxies above do
  by default) and set the proxy's address in `RSPADE_TRUSTED_PROXIES` on the
  container (or `TRUSTED_PROXIES` in `.env`), so the application sees each
  visitor's own address and not the proxy's. Unlisted, every visitor shares one
  address - for the login throttle, for login history and for the request
  scheduler. `php artisan rsx:man prod`, BEHIND A LOAD BALANCER, has the detail.

---

## 6. Claude Code in the container

RSpade works with any agentic AI tooling, or none - but Claude Code is the
official assistant, and the project ships its instruction files, skills and CLI
configuration. Run it inside the container, where the project, the tooling, the
documentation and the RSpade git proxy all are:

```bash
docker compose exec app claude
```

or type `claude` from a shell you already have open there
(`docker compose exec app bash`).

The first `claude` installs Claude Code into the container; after that, the
launcher checks for a newer release each time it starts and updates itself, so the
CLI never goes stale with the image. Nothing has to be built into the image for it.

**Your settings persist.** The compose file maps `~/.claude` in the container to
`storage/.claude` in your project, so your login, preferences and history survive
`docker compose down` and a new image. That directory is gitignored.

---

## 7. Updating the image

The image carries the services the framework runs on (MySQL, Redis, nginx, PHP,
Node and the framework's own daemons), and each new version brings their updates.
Fetch the current one and recreate the container on it:

```bash
docker compose pull
docker compose up -d
```

Your project and the database live in mounted directories on your disk, so
recreating the container loses nothing. Without Compose, `docker pull` the image,
then `docker stop` and `docker rm` the container and run it again with the same
arguments.

Each version is tagged with its build date (`2026.11.19`) and the newest also as
`latest`. To see which version a container runs:

```bash
docker compose exec app grep ^image_version= /opt/rspade/image.conf
```

**When a framework update needs a newer image**, the framework says so rather than
half-working: a checkout states the oldest image it runs on, and on an older image
the container refuses to start, commands refuse and pages answer with an error -
each naming the `docker pull` that fixes it. `php artisan rsx:framework:pull`
checks before it applies a release, and `php artisan rsx:health` reports the
"Container Image" row. The pull and recreate above is the remedy.

**PHP security patches arrive between images.** The container installs PHP and
operating-system security updates at every start and every four hours while it
runs, without cutting a request in flight. That is a safety net, not a substitute
for pulling the image now and then.
[System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md)
covers how it works and how to turn it off.

---

## 8. Logs and the Docker log driver

`docker compose logs app` (or `docker logs <name>`) shows the container's start -
each step, the sizing it chose, any `[rspade] ERROR:` that stopped it - and what
its services print. The application's own log is `storage/logs/laravel.log`, in
your project.

Docker's default `json-file` log driver keeps everything a container prints and
never rotates it, so a container left running for months grows a log file without
limit. The `local` driver rotates and compresses instead, which is why the compose
file sets

```yaml
    logging:
      driver: local
```

and the `docker run` examples above pass `--log-driver local`.

To follow the web server's access log and the application's errors in
`docker logs` as well, start the container with `RSPADE_LOG_TO_DOCKER=1`:

```yaml
    environment:
      RSPADE_LOG_TO_DOCKER: "1"
```

It echoes the nginx access log and every ERROR-and-above entry written to
`laravel.log` (with its stack trace), prefixed `[nginx]` and `[laravel]`. It is off
by default, and the `local` driver matters most when it is on.

---

## 9. Related guides

| | |
|---|---|
| [Developer crash course](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md) | the framework in one sitting |
| [System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md) | what runs in the container, backups, updates, health, logs, maintenance mode |
| [Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md) | the production image, first install, the deploy procedure, rollback, the container environment |
| [The container image](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/resource/docker/README.md) | the image definition: services, ports, every environment switch, the start sequence |
| `php artisan rsx:man` | every reference topic, listed |
