#!/bin/bash
#
# RSpade container entrypoint.
#
# Brings a mounted project from "freshly cloned" to "serving", then hands the
# container over to supervisor. Every step is idempotent: a second start finds
# its work already done and says nothing.
#
# ORDERING NOTE, because it is not the obvious arrangement. supervisord is
# started in the BACKGROUND and the bootstrap runs after it, rather than the
# reverse. `migrate` in development mode snapshots the database by stopping and
# restarting MySQL through supervisorctl - so supervisor has to already be up
# when migrations run, or the snapshot has nothing to talk to.
#
set -u

APP_DIR=/var/www/html
TARGET="${RSPADE_CONTAINER_TARGET:-dev}"

say()  { echo "[rspade] $*"; }
warn() { echo "[rspade] WARNING: $*" >&2; }
die()  { echo "[rspade] ERROR: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# 0. Sanity: is a project actually mounted here?
# -----------------------------------------------------------------------------
if [ ! -f "$APP_DIR/system/artisan" ]; then
    die "No RSpade project found at $APP_DIR (system/artisan is missing).

  This image runs YOUR project - it does not contain one. Mount a checkout:

      docker run -v \"\$(pwd)\":$APP_DIR -p 8080:80 <image>

  If you have not created a project yet, start from the RSpade starter
  repository: https://github.com/rspade-framework/rspade"
fi

cd "$APP_DIR" || die "Cannot enter $APP_DIR"

# -----------------------------------------------------------------------------
# 1. .env - create it from the template on first boot
# -----------------------------------------------------------------------------
if [ ! -f "$APP_DIR/.env" ]; then
    [ -f "$APP_DIR/.env.dist" ] || die ".env is missing and there is no .env.dist to create it from."
    cp "$APP_DIR/.env.dist" "$APP_DIR/.env" || die "Could not create .env"
    say "Created .env from .env.dist"
fi

# Read a key's current value from .env (empty when absent or blank).
#
# Takes the last NON-EMPTY definition rather than the first line that matches.
# A .env can legitimately end up with a key declared twice - a blank one from the
# template plus a real one appended later - and reading the blank one makes a
# configured value look unset.
env_value() {
    sed -n "s/^$1=//p" "$APP_DIR/.env" | grep -v '^[[:space:]]*$' | tail -n1
}

# Set a key in .env, replacing the line if present and appending if not.
#
# Pure bash on purpose. sed would need a delimiter that cannot appear in the
# value (an APP_URL contains slashes, a generated password can contain almost
# anything), and reaching for python here would add a runtime dependency that is
# only present in this image transitively.
#
# The file is rewritten through `cat >`, not `mv`, so that .env keeps its
# identity - it may be the target of a symlink, and replacing the inode would
# break the link rather than update the file.
env_set() {
    local key="$1" value="$2" tmp line found=0
    tmp="$(mktemp)"

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            "$key="*)
                printf '%s=%s\n' "$key" "$value" >> "$tmp"
                found=1
                ;;
            *)
                printf '%s\n' "$line" >> "$tmp"
                ;;
        esac
    done < "$APP_DIR/.env"

    [ "$found" -eq 1 ] || printf '%s=%s\n' "$key" "$value" >> "$tmp"

    cat "$tmp" > "$APP_DIR/.env"
    rm -f "$tmp"
}

# -----------------------------------------------------------------------------
# 2. APP_URL - the container cannot discover its own published port
# -----------------------------------------------------------------------------
# Docker does not tell a container which host port maps to it, so APP_URL cannot
# be inferred - it must be declared. RSPADE_APP_URL is the declaration; without
# it we leave whatever .env already says alone.
if [ -n "${RSPADE_APP_URL:-}" ]; then
    if [ "$(env_value APP_URL)" != "$RSPADE_APP_URL" ]; then
        env_set APP_URL "$RSPADE_APP_URL"
        say "Set APP_URL=$RSPADE_APP_URL"
    fi
fi

APP_URL_NOW="$(env_value APP_URL)"
case "$APP_URL_NOW" in
    http://*|https://*) : ;;
    *) warn "APP_URL is '$APP_URL_NOW', which is not an http(s) URL. Pass -e RSPADE_APP_URL=http://localhost:8080 (matching your published port), or edit .env." ;;
esac

# -----------------------------------------------------------------------------
# 3. First-user credentials
# -----------------------------------------------------------------------------
# The framework refuses to create the first user while these are blank, and
# ships no default on purpose. In DEVELOPMENT we generate a password so that
# "clone it and run it" holds, and print it once - the developer can change it
# in .env any time before the first migrate. In PRODUCTION we refuse: inventing
# a credential for a production system is not ours to do.
if [ -z "$(env_value RSPADE_DEFAULT_EMAIL)" ] || [ -z "$(env_value RSPADE_DEFAULT_PASSWORD)" ]; then
    if [ "$TARGET" = "prod" ]; then
        die "RSPADE_DEFAULT_EMAIL and RSPADE_DEFAULT_PASSWORD must be set in .env before this container can migrate. See .env.README."
    fi

    generated_email="${RSPADE_DEFAULT_EMAIL:-admin@localhost}"
    generated_password="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"

    env_set RSPADE_DEFAULT_EMAIL "$generated_email"
    env_set RSPADE_DEFAULT_PASSWORD "$generated_password"

    echo ""
    say "=============================================================="
    say " Generated the first-user credentials (development target)"
    say ""
    say "   email:    $generated_email"
    say "   password: $generated_password"
    say ""
    say " They are stored in .env as RSPADE_DEFAULT_EMAIL and"
    say " RSPADE_DEFAULT_PASSWORD. Change them there before the first"
    say " migrate if you want something else. See .env.README."
    say "=============================================================="
    echo ""
fi

# -----------------------------------------------------------------------------
# 4. Writable storage
# -----------------------------------------------------------------------------
mkdir -p storage/rsx-build storage/rsx-tmp storage/flock storage/rsx-framework storage/logs 2>/dev/null || true
if [ "$TARGET" = "prod" ]; then
    # Production runs php-fpm as www-data, so the tree it writes to must be its
    # own. Development runs as root and needs no ownership change.
    chown -R www-data:www-data storage 2>/dev/null || warn "Could not chown storage/ to www-data"
fi

# -----------------------------------------------------------------------------
# 5. MySQL runtime directory (development target only)
# -----------------------------------------------------------------------------
# The DATA directory needs nothing here. Installing mysql-server initialises
# /var/lib/mysql at build time, and Docker copies an image directory into a named
# volume the first time that volume is mounted empty - so a fresh volume arrives
# already initialised. (Whether the databases exist is a separate question,
# answered after MySQL is actually listening - see below.)
mkdir -p /var/run/mysqld 2>/dev/null || true
chown mysql:mysql /var/run/mysqld 2>/dev/null || true

# -----------------------------------------------------------------------------
# 6. Start supervisor in the background
# -----------------------------------------------------------------------------
say "Starting services..."
supervisord -c /etc/supervisor/supervisord.conf &
SUPERVISOR_PID=$!

# Forward a container stop to supervisor rather than dying and orphaning it.
trap 'kill -TERM "$SUPERVISOR_PID" 2>/dev/null; wait "$SUPERVISOR_PID"' TERM INT

# -----------------------------------------------------------------------------
# 7. Wait for Redis
# -----------------------------------------------------------------------------
# Every artisan command boots the framework, and the framework reaches for its
# cache while booting - so nothing that runs artisan may happen before this.
say "Waiting for Redis..."
until (echo > /dev/tcp/127.0.0.1/6379) >/dev/null 2>&1; do
    sleep 1
done

# -----------------------------------------------------------------------------
# 8. Wait for the database to answer
# -----------------------------------------------------------------------------
# Deliberately unbounded. A database that is slow to come up is normal - it is
# replaying a log or warming a large buffer pool - and a deadline here would
# convert "wait longer" into a failed start. The container is stoppable
# throughout, and every attempt is visible.
db_host="$(env_value DB_HOST)";     db_host="${db_host:-127.0.0.1}"
db_port="$(env_value DB_PORT)";     db_port="${db_port:-3306}"

say "Waiting for the database at ${db_host}:${db_port}..."
attempt=0
until (echo > "/dev/tcp/${db_host}/${db_port}") >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $((attempt % 30)) -eq 0 ]; then
        say "  still waiting for ${db_host}:${db_port} (${attempt}s)"
    fi
    sleep 1
done
say "Database is accepting connections."

# -----------------------------------------------------------------------------
# 9. Provision the databases (development target, first boot only)
# -----------------------------------------------------------------------------
if [ "$TARGET" = "dev" ]; then
    # The datadir being present says nothing about whether OUR databases exist -
    # a fresh volume carries the server's own system schema and nothing else. So
    # the question is asked of the server directly, which is both the honest
    # signal and naturally idempotent.
    until mysql -uroot -e "SELECT 1" >/dev/null 2>&1; do
        sleep 1
    done

    if ! mysql -uroot -e "USE rspade" >/dev/null 2>&1; then
        say "Provisioning databases..."
        mysql -uroot < /opt/rspade/provision.sql \
            || die "Database provisioning failed. See mysql/provision.sql in the image."
        say "Databases provisioned."
    fi
fi

# -----------------------------------------------------------------------------
# 10. APP_KEY
# -----------------------------------------------------------------------------
# Generated HERE, last of the bootstrap steps, and not a moment earlier. It looks
# like a pure local operation - write a random string into .env - but artisan
# boots the whole framework to do it, and that boot reads both the cache and the
# database. Attempted before Redis, MySQL and the provisioned schema all exist,
# it fails with a bare "Connection refused" naming none of them.
if [ -z "$(env_value APP_KEY)" ]; then
    # --show PRINTS a key instead of writing one, and we write it ourselves.
    # Letting artisan edit .env appends a second APP_KEY line when the existing
    # one is blank, and then the file has two - which is not merely untidy: the
    # .env parser takes the FIRST definition, so the application keeps reading
    # the blank one and every boot generates another key.
    new_key="$(php system/artisan key:generate --show 2>/dev/null | tr -d '\r\n')"

    case "$new_key" in
        base64:*)
            env_set APP_KEY "$new_key"
            say "Generated APP_KEY"
            ;;
        *)
            die "Could not generate APP_KEY. Run 'php system/artisan key:generate --show' inside the container to see why."
            ;;
    esac
fi

# -----------------------------------------------------------------------------
# 11. Migrations
# -----------------------------------------------------------------------------
# DEVELOPMENT migrates automatically: a freshly cloned project should just work.
#
# PRODUCTION DOES NOT. Schema changes are a deploy decision, not a side effect of
# a container restart - and with more than one replica, several containers
# starting at once would race each other through the same migration. Run it
# yourself, once, as a deliberate step:
#
#     docker exec <container> php system/artisan migrate
#
if [ "$TARGET" = "dev" ]; then
    say "Running migrations..."
    if ! php system/artisan migrate --force; then
        warn "Migrations did not complete. The application may not work until this is resolved."
        warn "A common first-run cause: RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD are blank in .env (see .env.README)."
    fi
else
    if php system/artisan migrate:pending 2>/dev/null | grep -qv "No pending migrations"; then
        say "NOTE: there are pending migrations. Production does not migrate automatically:"
        say "      php system/artisan migrate"
    fi
fi

# -----------------------------------------------------------------------------
# 12. The application's own container hook
# -----------------------------------------------------------------------------
# A documented extension point: rsx/resource/docker/configure.sh runs as root at
# container startup, before the application serves traffic. Invoked through bash
# rather than executed directly - the exec bit does not survive every checkout.
if [ -f "$APP_DIR/rsx/resource/docker/configure.sh" ]; then
    say "Running the application's configure.sh..."
    bash "$APP_DIR/rsx/resource/docker/configure.sh" \
        || warn "configure.sh exited non-zero"
fi

# -----------------------------------------------------------------------------
# 13. Ready
# -----------------------------------------------------------------------------
echo ""
say "RSpade is up. ${APP_URL_NOW:-(APP_URL unset)}"
echo ""

wait "$SUPERVISOR_PID"
