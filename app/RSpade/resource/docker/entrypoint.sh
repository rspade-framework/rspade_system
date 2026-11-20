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
# Supervisor starts only the INFRASTRUCTURE on its own (MySQL, Redis, rsx-lockd, the mail
# catcher, the security updater, the log echo). The programs that serve and work - php-fpm,
# nginx, Proxority, the realtime relay, the task scheduler, the companion daemon - are
# autostart=false and started by step 11b, once the build and the migrations are done.
#
set -u

# THE STARTUP RECORD (startup-state.sh, /usr/local/bin/rspade-startup): what this start is
# doing, and whether it finished or failed, for `rspade-wait-started`. Begun before anything
# else; a record that cannot be written is reported and never stops the start.
startup_record() {
    [ -f /usr/local/bin/rspade-startup ] || return 0
    bash /usr/local/bin/rspade-startup "$@" || true
}
startup_record begin

APP_DIR=/var/www/html
TARGET="${RSPADE_CONTAINER_TARGET:-dev}"

say()  { echo "[rspade] $*"; }
warn() { echo "[rspade] WARNING: $*" >&2; }
die()  { echo "[rspade] ERROR: $*" >&2; startup_record failed "$*"; exit 1; }

# switch_is_on <name> <value> : return 0 for on (1, true, yes, on - any case), 1 for off
# (unset, empty, 0, false, no, off), 2 with a sentence on stderr for anything else.
switch_is_on() {
    case "$(printf '%s' "$2" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        ''|0|false|no|off) return 1 ;;
    esac
    echo "$1 is '$2': it must be 1/0, true/false, yes/no or on/off." >&2
    return 2
}

# start_steps <target> <mode> <RSPADE_BUILD_MIGRATE_ON_START value> <never migrated> : what
# step 10 runs, one word per line, in order. <mode> is the application mode (development,
# debug, production); <target> the image (dev, prod); <never migrated> is 1 when the dev
# image's database has never been migrated (no _migrations table - a new project, or a
# database deliberately deleted to start over), 0 otherwise.
#
#   build          php system/artisan rsx:build             (switch on, development)
#   build-force    php system/artisan rsx:build --force     (switch on, debug/production:
#                                                            build and reseal)
#   migrate        php system/artisan migrate --force; a failure stops the start
#                                                           (switch on)
#   migrate-new    the same, a failure only warned about    (switch off, dev image, a
#                                                            database never migrated)
#   pending        report pending migrations, run none      (switch off, otherwise)
#
# Returns 2 (the sentence on stderr) for a switch value that is neither on nor off.
start_steps() {
    local target="$1" mode="$2" value="$3" never_migrated="${4:-0}" answer

    switch_is_on RSPADE_BUILD_MIGRATE_ON_START "$value"
    answer=$?

    case "$answer" in
        0)
            if [ "$mode" = development ]; then
                echo build
            else
                echo build-force
            fi
            echo migrate
            ;;
        1)
            if [ "$target" = dev ] && [ "$never_migrated" = 1 ]; then
                echo migrate-new
            else
                echo pending
            fi
            ;;
        *)
            return 2
            ;;
    esac
}

# The owner of the trees the application writes, "user:group": set by step 5b (the PUID
# identity, or www-data on the prod image), empty when the files stay as they are.
APP_OWNER=""

# hand_over_app_trees : give storage/ (all but storage/mysql_data), build/ and tmp/ to
# APP_OWNER. Run by step 5b, and again after step 10 has built or migrated: those
# commands run as root, and the files they create must belong to the application too.
#
# storage/mysql_data is EXCLUDED, and must stay excluded: it is the database's
# bind-mounted data directory, mysqld requires it to be owned by its own user, and
# handing it to the application's stops the database from starting.
hand_over_app_trees() {
    [ -n "$APP_OWNER" ] || return 0
    find storage -mindepth 1 -maxdepth 1 ! -name mysql_data \
        -exec chown -R "$APP_OWNER" {} + 2>/dev/null \
        || warn "could not chown storage/ to ${APP_OWNER}"
    chown "$APP_OWNER" storage 2>/dev/null || true
    chown -R "$APP_OWNER" build tmp 2>/dev/null || true
}

# The supervisor programs that SERVE or WORK, configured autostart=false: step 11b starts
# them once the build and the migrations are done, so nothing answers a request or runs a
# task against a schema or build that is about to change. Infrastructure (MySQL, Redis,
# rsx-lockd, the mail catcher, the security updater, the log echo) starts with supervisor.
SERVING_PROGRAMS="php-fpm nginx proxority fpc-proxy realtime tasks rsx-companiond"

# -----------------------------------------------------------------------------
# 0. Is a framework checkout present?
# -----------------------------------------------------------------------------
startup_record step "checking for a framework checkout"
# THIS IS A QUESTION, NOT A GATE. The container is a working stack in its own
# right - nginx, php-fpm, MySQL, Redis, supervisor - and it must come up and serve
# whatever is in the docroot even when there is no RSpade checkout at all. An
# empty mount, a bare PHP file, a project mid-clone: all of those are a container
# that starts, not a container that refuses.
#
# Refusing here used to be the behaviour and it was wrong in both directions: it
# denied a perfectly good stack to anyone who wanted one, and it turned every
# framework-side mishap into "the container will not start", which is the least
# informative symptom available.
#
# So: detect, say something useful, and carry on. Everything framework-shaped
# below is conditional on HAS_FRAMEWORK; everything service-shaped is not.
#
# The one piece of service configuration that DOES follow the answer is nginx's
# .php URL policy (nginx/site.conf's header, THE .PHP URL POLICY). Every listener's
# `location ~ \.php$` includes /etc/nginx/rspade/php-urls.conf, written here at
# every start - before supervisor starts nginx - from one of the two variants the
# image ships beside it:
#
#   HAS_FRAMEWORK=1  php-urls-checkout.conf     .php URLs answer 404. RSpade has none,
#                                               and none is executed or served as source.
#   HAS_FRAMEWORK=0  php-urls-no-checkout.conf  a .php file in the docroot runs, through
#                                               Proxority and the loopback listener like any
#                                               PHP-bound request (scheme pinned), so a bare
#                                               PHP file is served as promised above.
#
# Copied rather than linked so that a container restarted after a checkout appears (or
# goes away) picks the right one; skipped when the variants are absent (an older image
# under a newer entrypoint - its site.conf includes no snippet).
HAS_FRAMEWORK=0
if [ -f "$APP_DIR/system/artisan" ]; then
    HAS_FRAMEWORK=1
elif [ -d "$APP_DIR/rsx" ] && [ -d "$APP_DIR/system" ] && [ -z "$(ls -A "$APP_DIR/system" 2>/dev/null)" ]; then
    # THE COMMON CASE IS A CLONE WITHOUT SUBMODULES, not a missing project. system/
    # is a git submodule, so `git clone` on its own leaves an empty directory there
    # and every symptom - no artisan, a 500 from the web server - points nowhere near
    # the cause. Naming it is the whole value of this branch.
    warn "The framework is not checked out: $APP_DIR/system is empty.

  system/ is a git submodule, and a plain 'git clone' does not populate it. From
  your project directory on the HOST:

      git submodule update --init --recursive

  Then restart the container. (Cloning with --recurse-submodules avoids this.)
  Starting the services anyway - the web server will serve what is there."
else
    say "No RSpade checkout at $APP_DIR - starting the services only.

  This image runs YOUR project; it does not contain one. To run an RSpade project,
  mount a checkout:

      docker run -v \"\$(pwd)\":$APP_DIR -p 8080:80 <image>

  Starting from scratch:

      git clone --recurse-submodules https://github.com/rspade-framework/rspade"
fi

if [ -f /etc/nginx/rspade/php-urls-checkout.conf ] && [ -f /etc/nginx/rspade/php-urls-no-checkout.conf ]; then
    if [ "$HAS_FRAMEWORK" = "1" ]; then
        cp -f /etc/nginx/rspade/php-urls-checkout.conf /etc/nginx/rspade/php-urls.conf
    else
        cp -f /etc/nginx/rspade/php-urls-no-checkout.conf /etc/nginx/rspade/php-urls.conf
        say ".php files in the docroot will run (no RSpade checkout)."
    fi
fi

# -----------------------------------------------------------------------------
# 0b. Is this image older than the checkout it runs?
# -----------------------------------------------------------------------------
# The checkout states the oldest image it runs on (min_image_version in
# resource/docker/image_contract.conf) and this image records its own version
# (image_version in /opt/rspade/image.conf). An older image may lack a service or tool
# the checkout depends on, so the container DOES NOT START: the sentence names the image
# to pull. The same check refuses every artisan command and web request
# (bootstrap/rsx_image_contract.php), so starting anyway would only serve 500s.
#
# Skipped on the framework authors' box (IS_FRAMEWORK_DEVELOPER true), whose image is
# built on the host and carries no record - with one line saying so. The library comes
# from the CHECKOUT; a checkout that predates the minimum image version (no library, or
# one from the earlier contract-counter model, which cannot read this image's record) has
# nothing to compare, and a newer image under an older checkout is fine.
if [ "$HAS_FRAMEWORK" = "1" ] && [ -f "$APP_DIR/system/bin/lib/rsx_image_contract.sh" ]; then
    . "$APP_DIR/system/bin/lib/rsx_image_contract.sh"
fi
if ! declare -F rsx_image_contract_version_older >/dev/null; then
    :
elif rsx_image_contract_framework_developer "$APP_DIR/.env"; then
    warn "IS_FRAMEWORK_DEVELOPER is set: the minimum image version is not checked."
    startup_record warning "IS_FRAMEWORK_DEVELOPER is set: the minimum image version is not checked."
else
    IMAGE_CONTRACT_SENTENCE="$(rsx_image_contract_problem "$APP_DIR/system/app/RSpade/resource/docker/image_contract.conf" \
        /opt/rspade/image.conf /.rspade_container "$APP_DIR/.env" 2>&1)"
    case $? in
        0) die "${IMAGE_CONTRACT_SENTENCE}" ;;
        2) die "the checkout's minimum image version cannot be read: ${IMAGE_CONTRACT_SENTENCE}" ;;
    esac
fi

cd "$APP_DIR" || die "Cannot enter $APP_DIR"

# -----------------------------------------------------------------------------
# 1. .env - prepared by the framework's own env heal
# -----------------------------------------------------------------------------
startup_record step "preparing .env"
# Creating .env from .env.dist, adding keys that appeared in .env.dist since the
# last boot, checking the credentials nothing can connect without, and minting an
# APP_KEY when there is none are ONE operation with ONE implementation, and this
# is it. The same file runs pre-boot from both entrypoints and behind
# `php artisan rsx:env:heal`; it deliberately needs no autoloader, no config and
# no database, because the framework cannot boot until it has done its work.
#
# Everything below this line reads .env, so it has to happen here, first.
#
# SKIPPED WHEN THE FILE IS NOT THERE, and that is version skew rather than an
# error. THIS SCRIPT IS BAKED INTO THE IMAGE; system/ is a git submodule the
# project pins independently. So a container built today can legitimately start
# against no checkout at all, or one pinned to a release that predates the env
# heal - and dying on a missing file would make a newer image unable to boot an
# older project, the exact combination somebody hits bisecting or rolling back.
#
# If the file EXISTS it must SUCCEED. A heal that runs and fails has found a real
# problem - unreadable .env, missing credentials - and the container must not
# paper over it. The tolerance is for ABSENCE only, never for failure.
if [ -f "$APP_DIR/system/bootstrap/rsx_env_heal.php" ]; then
    php "$APP_DIR/system/bootstrap/rsx_env_heal.php" || die "The environment configuration could not be prepared (see above)."
elif [ "$HAS_FRAMEWORK" = "1" ]; then
    warn "system/bootstrap/rsx_env_heal.php is absent - this system/ checkout predates the env heal. Continuing with .env as it stands."
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
# 2. APP_KEY - made by the env heal above, not here
# -----------------------------------------------------------------------------
# An application key is 32 random bytes, base64-encoded, and it is generated in
# step 1 by the env heal - BEFORE anything else runs, which is the requirement.
#
# The alternative was `artisan key:generate`, and it was a trap: writing a random
# string into a file looks local, but artisan boots the entire framework to do
# it, and that boot wants Redis, the database, and a provisioned schema. Which
# means the key could only be made AFTER the services were up - while two of
# those services (rsx-lockd, the realtime relay) refuse to start WITHOUT a key.
# A circle with no entry point, and on a fresh install it failed exactly there.
#
# It was generated HERE in shell for a while, which broke the circle but left two
# implementations of one rule. The heal is autoloader-free for exactly the same
# reason this shell was, so the shell copy is gone and the rule has one home.
# Note that the heal MINTS a key only in development: on a debug or production
# box an empty APP_KEY is refused loudly, because a new key silently invalidates
# every encrypted value and signed cookie made with the one that went missing.

# -----------------------------------------------------------------------------
# 3. APP_URL - the container cannot discover its own published port
# -----------------------------------------------------------------------------
startup_record step "setting APP_URL"
# Docker does not tell a container which host port maps to it, so APP_URL cannot be
# inferred here. THE BROWSER KNOWS, and the first-run welcome screen asks it: whatever
# host and port you actually browse is the one recorded. That is why a blank APP_URL on
# a first boot is the NORMAL state and not a warning.
#
# RSPADE_APP_URL remains as a declaration for an automated deployment that wants to skip
# the screen entirely - CI, a provisioning script, anything with no human to browse.
if [ -n "${RSPADE_APP_URL:-}" ]; then
    if [ "$(env_value APP_URL)" != "$RSPADE_APP_URL" ]; then
        env_set APP_URL "$RSPADE_APP_URL"
        say "Set APP_URL=$RSPADE_APP_URL"
    fi
fi

APP_URL_NOW="$(env_value APP_URL)"
case "$APP_URL_NOW" in
    http://*|https://*)
        : ;;
    "")
        # First run. Say what happens next rather than warning about a state the
        # application is about to resolve by itself.
        say "APP_URL is not set yet - the welcome screen will record it from your first visit."
        ;;
    *)
        warn "APP_URL is '$APP_URL_NOW', which is not an http(s) URL. Clear it to let the welcome screen set it, or correct it in .env."
        ;;
esac

# -----------------------------------------------------------------------------
# 4. First-user credentials (PRODUCTION ONLY)
# -----------------------------------------------------------------------------
startup_record step "checking the first-user credentials"
# DEVELOPMENT CREATES NO USER HERE, and that is the whole point: the first-run
# setup screen asks for the account in the browser, on first visit. Leaving
# RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD blank is what lets it - the
# create_admin_test_user migration deliberately creates nothing when they are
# empty, and its sibling then skips the site profile that would belong to it.
#
# This step used to GENERATE a credential in development so that "clone it and
# run it" held. That predated the setup screen and, once the screen existed,
# actively defeated it: the generated admin@localhost account was already in
# login_users by the time anybody opened a browser, so the screen had nothing to
# offer and the developer logged in as a user they never chose (field report,
# 2026-08-20). Blank is not a gap to fill here. It is the signal.
#
# PRODUCTION has no such screen - the wizard is development-only by design - so
# the credentials remain REQUIRED there, and refusing early beats failing inside
# the migration with the database half-built.
# Gated on HAS_FRAMEWORK: with no checkout there is nothing to migrate and no
# account to create, so demanding credentials would refuse a stack that is not
# being asked to run an application at all.
if [ "$TARGET" = "prod" ] && [ "$HAS_FRAMEWORK" = "1" ]; then
    if [ -z "$(env_value RSPADE_DEFAULT_EMAIL)" ] || [ -z "$(env_value RSPADE_DEFAULT_PASSWORD)" ]; then
        die "RSPADE_DEFAULT_EMAIL and RSPADE_DEFAULT_PASSWORD must be set in .env before this container can migrate.

   They are the credentials of the first account you will sign in with. There is
   no default and no setup screen in production - a shared, published password is
   not a starting point. See .env.README."
    fi
fi

# -----------------------------------------------------------------------------
# 4b. Environment updates
# -----------------------------------------------------------------------------
startup_record step "applying environment updates"
# system/bin/environment_updates/*.sh make the surrounding environment correct:
# they move the volatile trees into their current layout, wire the Claude Code
# status line and skills, repair tracked symlinks, and so on.
#
# WHY THE CONTAINER HAS TO RUN THEM. Their triggers are a framework update and a
# successful manifest build - both of which assume an environment that has
# already been used. A freshly cloned project started for the first time has had
# neither, so it ran with none of these applied: uploads and logs landing inside
# system/ instead of the project's own storage/, no status line, no commit guard.
# The container start is the one moment guaranteed to happen before anything else
# in a new project, which makes it the right place to ask.
#
# BEFORE SUPERVISOR, and before the storage preparation below, on purpose. A
# relocation MOVES trees that every service is about to open files in - sockets
# included; doing that underneath running services is how you get processes
# holding deleted paths. Ordering it here also means the ownership pass below
# applies to the final locations rather than ones we are about to abandon.
#
# The scripts are idempotent and silent when already applied (their published
# contract), so on every boot after the first this prints nothing. Failures are
# NON-FATAL: an environment that could not be improved still runs.
if [ -f "$APP_DIR/system/bin/post-update.sh" ]; then
    PROJECT_ROOT="$APP_DIR" SYSTEM_DIR="$APP_DIR/system" \
        bash "$APP_DIR/system/bin/post-update.sh" \
        || warn "environment updates reported a failure (non-fatal)"
fi

# -----------------------------------------------------------------------------
# 5. Writable storage
# -----------------------------------------------------------------------------
startup_record step "preparing writable storage"
# storage/ holds user data and process state; tmp/ holds derived caches. build/ is
# NOT created here: it holds build outputs, and a missing one must fail loud naming
# the build command rather than quietly appearing empty.
#
# The roots come from the path library, so an RSX_STORAGE_PATH or RSX_TMP_PATH in the
# project's .env is honoured here exactly as PHP honours it, and every process started
# below inherits the same absolutes.
#
# TMPDIR is NOT among them: PHP sets it for its own processes in the first lines of both
# entrypoints, while mysqld reads TMPDIR too and refuses to start when it points at a
# directory the database user cannot write.
if [ "$HAS_FRAMEWORK" = 1 ] && [ -f "$APP_DIR/system/bin/lib/rsx_paths.sh" ]; then
    RSX_PATHS_PROJECT_ROOT_DIR="$APP_DIR"
    . "$APP_DIR/system/bin/lib/rsx_paths.sh"
    rsx_export_paths
    mkdir -p "$(rsx_storage_root)/logs" "$(rsx_state_root)/flock" "$(rsx_tmp_root)" 2>/dev/null || true
else
    mkdir -p storage/logs storage/state storage/state/flock tmp 2>/dev/null || true
fi

# -----------------------------------------------------------------------------
# 5b. PUID / PGID - run the application as YOUR user
# -----------------------------------------------------------------------------
# THE PROBLEM THIS SOLVES, which only Linux hosts have. Your project is a bind
# mount, and by default the application runs as root inside the container - so
# every file it writes (.env, compiled bundles, logs, uploads) comes out owned by
# root ON YOUR MACHINE, and you cannot edit your own project without sudo. macOS
# and Windows never see it: Docker Desktop's filesystem layer presents everything
# as yours regardless.
#
# Set PUID and PGID to your own ids and the application runs as you instead:
#
#     id -u    # -> PUID
#     id -g    # -> PGID
#
# UNSET IS THE DEFAULT and changes nothing. Mac and Windows users never need to
# think about this, and nobody gets a surprise ownership change for upgrading.
#
# nginx runs as root here, so it reaches the FPM socket whoever owns it - the one
# thing that usually makes this awkward is not a problem.
if [ -n "${PUID:-}" ]; then
    APP_GID="${PGID:-$PUID}"
    APP_USER="rspade"

    # Reuse an existing group/user with those ids rather than colliding with it -
    # uid 33 is already www-data, and creating a second name for one id is how
    # ownership gets confusing later.
    existing_group="$(getent group "$APP_GID" 2>/dev/null | cut -d: -f1)"
    if [ -n "$existing_group" ]; then
        APP_GROUP="$existing_group"
    else
        APP_GROUP="$APP_USER"
        groupadd -g "$APP_GID" "$APP_GROUP" 2>/dev/null \
            || warn "could not create group $APP_GID"
    fi

    existing_user="$(getent passwd "$PUID" 2>/dev/null | cut -d: -f1)"
    if [ -n "$existing_user" ]; then
        APP_USER="$existing_user"
    else
        useradd -u "$PUID" -g "$APP_GID" -M -s /usr/sbin/nologin "$APP_USER" 2>/dev/null \
            || warn "could not create user $PUID"
    fi

    # Point the FPM pool at that identity.
    pool=/etc/php/8.5/fpm/pool.d/www.conf
    sed -i \
        -e "s/^user = .*/user = ${APP_USER}/" \
        -e "s/^group = .*/group = ${APP_GROUP}/" \
        -e "s/^listen.owner = .*/listen.owner = ${APP_USER}/" \
        -e "s/^listen.group = .*/listen.group = ${APP_GROUP}/" \
        "$pool" || warn "could not repoint $(basename "$pool")"

    # And the database, for the same reason and with more force behind it.
    #
    # /var/lib/mysql is a bind mount into your project, so the uid mysqld runs as
    # is the uid that ends up owning your database files on your own disk. Left
    # as the packaged `mysql` user that is uid 999, which you are not: the files
    # land in your project owned by somebody who does not exist on your machine,
    # and moving or deleting your own project needs sudo.
    #
    # Docker cannot remap ownership across a bind mount (no idmap for binds, no
    # Podman's :U), so the only lever is which uid the writer runs as - and this
    # is it. The datadir chown in the MySQL step below follows the same PUID.
    #
    # The config file, NOT a command-line flag: mysqld reads its defaults file
    # first and keeps the FIRST `user` it sees, so a `--user=` on the command
    # line loses to this line and is silently ignored (with a warning buried in
    # the log). One place decides the identity.
    mysqld_cnf="/etc/mysql/mysql.conf.d/mysqld.cnf"
    if [ -f "$mysqld_cnf" ]; then
        sed -i "s/^user[[:space:]]*=.*/user = ${APP_USER}/" "$mysqld_cnf" \
            || warn "could not repoint mysqld at ${APP_USER}"
    fi

    # Hand over the things the application writes (hand_over_app_trees). Everything
    # else in the project is yours already - it came from your checkout.
    APP_OWNER="${APP_USER}:${APP_GROUP}"
    hand_over_app_trees
    [ -f "$APP_DIR/.env" ] && chown "${APP_USER}:${APP_GROUP}" "$APP_DIR/.env" 2>/dev/null

    say "Running the application as ${APP_USER} (uid ${PUID}, gid ${APP_GID})."

elif [ "$TARGET" = "prod" ]; then
    # Production has no bind-mounted developer tree to fight, so it runs as
    # www-data unconditionally and owns what it writes.
    APP_OWNER="www-data:www-data"
    hand_over_app_trees
fi

# -----------------------------------------------------------------------------
# 6. MySQL data directory (development target only)
# -----------------------------------------------------------------------------
startup_record step "preparing the MySQL data directory"
# The identity mysqld runs as (see the PUID step above: it is YOUR uid when PUID
# is set, so the database files in your project belong to you). Everything mysqld
# opens outside the data directory has to follow it - the runtime socket dir, the
# error log, the secure-file and keyring dirs. Miss one and mysqld aborts at
# startup on that path alone, having said nothing about the others.
mysql_runtime_owner="mysql"
mysql_runtime_group="mysql"
if [ -n "${PUID:-}" ]; then
    mysql_runtime_owner="${APP_USER}"
    mysql_runtime_group="${APP_GROUP}"
fi

mkdir -p /var/run/mysqld 2>/dev/null || true
for mysql_path in /var/run/mysqld /var/log/mysql /var/lib/mysql-files /var/lib/mysql-keyring; do
    [ -e "$mysql_path" ] || continue
    chown -R "${mysql_runtime_owner}:${mysql_runtime_group}" "$mysql_path" 2>/dev/null || true
done

# /var/lib/mysql is a BIND MOUNT to storage/mysql_data in your project, so you
# can see the database, back it up by copying a directory, and keep it across a
# `docker compose down -v` that would have destroyed a named volume.
#
# The cost of that choice is this block. A named volume arrives pre-populated
# (Docker copies the image's directory content in the first time it is mounted
# empty); a bind mount arrives EXACTLY as empty as it is on the host, and mysqld
# will not start against an uninitialised data directory. So the pristine tree
# from image build time is unpacked here on the first boot.
#
# The test is the `mysql` system schema, not "is the directory empty" - the host
# directory routinely holds a stray .DS_Store or an editor's dotfile, and
# treating that as an initialised database would fail much later and much worse.
if [ "$TARGET" = "dev" ]; then
    if [ ! -d /var/lib/mysql/mysql ]; then
        # TWO SPELLINGS, ONE TEMPLATE. The dev image ships a gzipped pristine
        # datadir - written once at image build, read once on a first boot, so
        # compressing it is free. The PARALLEL TEST image replaces it with a
        # fully migrated one that is unpacked on EVERY container start, where
        # gzip costs about two seconds of every worker's boot for a couple of
        # hundred megabytes of layer the exporter compresses anyway - so that one
        # is plain tar. `.tar` wins when both are present; the test image removes
        # the `.tgz` so they never are.
        template=""
        for candidate in /opt/rspade/mysql-datadir-template.tar \
                         /opt/rspade/mysql-datadir-template.tgz; do
            if [ -f "$candidate" ]; then
                template="$candidate"
                break
            fi
        done

        if [ -z "$template" ]; then
            die "The MySQL data directory at /var/lib/mysql is not initialised, and
   the image's template (/opt/rspade/mysql-datadir-template.tar or .tgz) is missing.

   This image was not built correctly. Rebuild it:
       bash system/app/RSpade/resource/docker/build.sh"
        fi

        say "First run: initialising the database in storage/mysql_data..."
        mkdir -p /var/lib/mysql
        # -xf, not -xzf: tar detects the compression itself, so one reader serves
        # both spellings above.
        tar -xf "$template" -C /var/lib/mysql \
            || die "Could not unpack the MySQL data directory template."
    fi

    # WHO OWNS THE DATA DIRECTORY. mysqld here runs as root (see
    # supervisor/conf.d/mysql.conf), so it can read and write the datadir
    # whoever owns it - which means ownership is free to serve the person
    # OUTSIDE the container instead.
    #
    # That matters because this is a bind mount: the files are in your project,
    # and files owned by a uid you do not have are files you need sudo to move,
    # copy or delete. So when PUID says who you are, the database belongs to you
    # too - the same promise PUID already makes for everything else the
    # application writes. Without PUID it stays with mysql, which is the
    # historic owner and the right default on macOS and Windows, where Docker
    # Desktop presents every file as yours regardless.
    #
    # Docker has no uid-remapping for bind mounts (no idmap, no Podman's :U), so
    # a chown is the whole mechanism available. It is CHECKED before it is
    # applied - a recursive chown across a live database on every boot would be
    # pointless work.
    datadir_owner="$mysql_runtime_owner"
    datadir_group="$mysql_runtime_group"

    want_uid="$(id -u "$datadir_owner" 2>/dev/null || echo -1)"
    if [ "$(stat -c %u /var/lib/mysql 2>/dev/null || echo -1)" != "$want_uid" ] \
        || [ "$(stat -c %u /var/lib/mysql/mysql 2>/dev/null || echo -1)" != "$want_uid" ]; then
        say "Handing the database data directory to ${datadir_owner}..."
        chown -R "${datadir_owner}:${datadir_group}" /var/lib/mysql 2>/dev/null \
            || warn "could not chown /var/lib/mysql to ${datadir_owner}"
    fi
fi

# -----------------------------------------------------------------------------
# 6b. Size PHP, Redis and MySQL to this machine
# -----------------------------------------------------------------------------
startup_record step "sizing PHP, Redis and MySQL"
# BEFORE supervisor, because every service it starts reads its configuration once: the
# php-fpm pool size (production; the development target keeps its small on-demand pool,
# a developer's box is not sized for traffic), the memory_limit php-fpm and the CLI share,
# the Redis cap, and the development MySQL buffer pool. The numbers come from this
# container's cores, memory and swap, or from the switches the container was started with
# (RSPADE_PHP_MEMORY_LIMIT_MB, RSPADE_PHP_WORKERS, RSPADE_REDIS_MEMORY_MB and the rest); the
# script holds the formula and the reasoning (rspade-sizing), and records the budget it
# chose in /var/lib/rspade/sizing.json, which the framework reads (rsx:health "Resource
# Sizing", the task pools, the /_sys Performance screen).
#
# A failure here stops the start. Services coming up with the image's placeholder sizes on
# a machine they do not fit is the outcome this step exists to prevent.
rspade-sizing apply "$TARGET" || die "could not size the container (see the line above)"

# -----------------------------------------------------------------------------
# 6b2. The proxies in front of the container
# -----------------------------------------------------------------------------
startup_record step "checking the trusted proxies"
# AFTER sizing, which rewrites the production pool files, and BEFORE supervisor starts
# nginx and php-fpm, which read their configuration once. .env TRUSTED_PROXIES, else the
# container's RSPADE_TRUSTED_PROXIES (set in the compose file), becomes nginx's realip
# configuration - so the access log and REMOTE_ADDR name the real client - and the
# container value is handed to php-fpm, whose workers never see the container's
# environment. The script holds the rule and the reasoning (rspade-trusted-proxies).
#
# A value that is not a list of addresses or CIDR ranges stops the start: it is written
# into nginx's configuration, and a /0 range would let any client name its own address.
#
# Skipped when the script is absent: an older image under a newer entrypoint.
if [ -f /usr/local/bin/rspade-trusted-proxies ]; then
    trusted_proxies_said="$(bash /usr/local/bin/rspade-trusted-proxies apply --env-file="$APP_DIR/.env" 2>&1)" \
        || die "the trusted proxies are not valid: ${trusted_proxies_said}"
    say "$trusted_proxies_said"
fi

# -----------------------------------------------------------------------------
# 6c. Security updates, BEFORE anything serves
# -----------------------------------------------------------------------------
startup_record step "installing security updates"
# A PHP security release can grant a remote shell on any unpatched PHP, and an image is
# rebuilt far less often than one is published. So the container installs the security
# updates it may take - the Ubuntu security pocket and PHP patch releases - here, before
# supervisor starts php-fpm: nothing serves on a PHP this check could have patched.
#
# One attempt, one retry a minute later, then the start continues whatever happened: a
# mirror that cannot be reached never stops a container, and the scheduled program tries
# again every 4 hours. PHP_SECURITY_UPDATES=0 turns it off. The script's header has
# the rest.
#
# Skipped when the script is absent: an older image under a newer entrypoint.
if [ -f /usr/local/bin/rspade-security-update ]; then
    bash /usr/local/bin/rspade-security-update startup
fi

# -----------------------------------------------------------------------------
# 7. Start supervisor in the background
# -----------------------------------------------------------------------------
startup_record step "starting supervisor"
say "Starting services..."
supervisord -c /etc/supervisor/supervisord.conf &
SUPERVISOR_PID=$!

# Forward a container stop to supervisor rather than dying and orphaning it.
trap 'kill -TERM "$SUPERVISOR_PID" 2>/dev/null; wait "$SUPERVISOR_PID"' TERM INT

# -----------------------------------------------------------------------------
# 8. Wait for Redis
# -----------------------------------------------------------------------------
startup_record step "waiting for Redis"
# Every artisan command boots the framework, and the framework reaches for its
# cache while booting - so nothing that runs artisan may happen before this.
say "Waiting for Redis..."
until (echo > /dev/tcp/127.0.0.1/6379) >/dev/null 2>&1; do
    sleep 1
done

# -----------------------------------------------------------------------------
# 9. Wait for the database to answer
# -----------------------------------------------------------------------------
startup_record step "waiting for the database"
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
# 9b. Provision the databases (development target, first boot only)
# -----------------------------------------------------------------------------
startup_record step "provisioning the databases"
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
# 10. Build and migrate - BEFORE anything serves
# -----------------------------------------------------------------------------
# The serving programs (SERVING_PROGRAMS above) are autostart=false and start in step 11b,
# after this step, so no request and no task ever runs against a schema or a build this
# step is still changing.
#
# RSPADE_BUILD_MIGRATE_ON_START (default off) makes a start a deployment: it builds, then
# migrates, and a failure of either STOPS THE START - the output is in `docker logs`, and
# no serving program is started.
#
#   development mode    php system/artisan rsx:build, then migrate --force (snapshot-
#                       protected where migrate can protect it: rsx:man migrations)
#   debug / production  php system/artisan rsx:build --force (build and reseal), then
#                       migrate --force - a BARE run, no rollback: the operator snapshots
#                       the database before starting the container (rsx:man prod)
#
# Off (the default), a start builds nothing and migrates nothing. Pending migrations are
# reported; run them as a deliberate step (docker exec <container> php system/artisan
# migrate), or start ONE container with the switch on. Schema changes are a deploy
# decision, not a side effect of a container restart, and several replicas starting at
# once would race each other through the same migration.
#
# The one exception is a NEW DATABASE in the development image: a database that has never
# been migrated (no _migrations table - the first start of a freshly cloned project, or a
# database folder deleted to start over) is migrated, so the quick start (clone, docker
# compose up) needs no extra step. There is nothing in such a database to protect. A
# failure is warned about and the start continues. The production image's database is the
# operator's and is never created here, so it has no such exception.
#
# The mode is the application's (RSX_MODE: the container's environment, else .env), read
# as bootstrap/rsx_mode.php reads it. Skipped entirely without a checkout - artisan is the
# thing that is missing.
if [ "$HAS_FRAMEWORK" = "1" ]; then
    if declare -F rsx_env_value >/dev/null; then
        app_mode="$(rsx_env_value RSX_MODE)"
    else
        app_mode="${RSX_MODE:-$(env_value RSX_MODE)}"
    fi
    app_mode="$(printf '%s' "$app_mode" | tr '[:upper:]' '[:lower:]' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    case "$app_mode" in
        ''|dev) app_mode=development ;;
        prod)   app_mode=production ;;
    esac

    # Has the dev image's database ever been migrated? Asked of the server as root over the
    # socket, as step 9b provisions it: no _migrations table in the application database
    # means never.
    never_migrated=0
    if [ "$TARGET" = "dev" ]; then
        app_database="$(env_value DB_DATABASE)"
        app_database="${app_database:-rspade}"
        migrations_tables="$(mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${app_database//\'/}' AND table_name = '_migrations'" 2>/dev/null)"
        [ "$migrations_tables" = 0 ] && never_migrated=1
    fi

    start_steps_said="$(start_steps "$TARGET" "$app_mode" "${RSPADE_BUILD_MIGRATE_ON_START:-}" "$never_migrated" 2>&1)" \
        || die "$start_steps_said"

    trees_written=0
    for start_step in $start_steps_said; do
        case "$start_step" in
            build)
                startup_record step "building"
                say "Building (RSPADE_BUILD_MIGRATE_ON_START)..."
                php system/artisan rsx:build \
                    || die "The build failed (see above). No web service was started."
                ;;
            build-force)
                startup_record step "building and sealing"
                say "Building and sealing the ${app_mode} build (RSPADE_BUILD_MIGRATE_ON_START)..."
                php system/artisan rsx:build --force \
                    || die "The build failed (see above). No web service was started."
                ;;
            migrate)
                startup_record step "running migrations"
                say "Running migrations (RSPADE_BUILD_MIGRATE_ON_START)..."
                php system/artisan migrate --force \
                    || die "Migrations failed (see above). No web service was started."
                ;;
            migrate-new)
                startup_record step "running migrations"
                say "New database: running the migrations that create it..."
                if ! php system/artisan migrate --force; then
                    warn "Migrations did not complete. The application may not work until this is resolved."
                    warn "Run them yourself to see the full error:  php system/artisan migrate"
                fi
                ;;
            pending)
                startup_record step "checking for pending migrations"
                if php system/artisan migrate:pending 2>/dev/null | grep -qv "No pending migrations"; then
                    say "NOTE: there are pending migrations. A start does not migrate unless"
                    say "      RSPADE_BUILD_MIGRATE_ON_START=1; run them with: php system/artisan migrate"
                fi
                ;;
        esac
        [ "$start_step" = pending ] || trees_written=1
    done

    # The build and migrate commands above ran as root: what they wrote under build/,
    # tmp/ and storage/ goes back to the application's user (step 5b) before it serves.
    if [ "$trees_written" = 1 ]; then
        startup_record step "handing the written files to the application user"
        hand_over_app_trees
    fi
fi

# -----------------------------------------------------------------------------
# 11. The application's own container hook
# -----------------------------------------------------------------------------
# A documented extension point: rsx/resource/docker/configure.sh runs as root at
# container startup, before the application serves traffic. Invoked through bash
# rather than executed directly - the exec bit does not survive every checkout.
if [ -f "$APP_DIR/rsx/resource/docker/configure.sh" ]; then
    startup_record step "running the application's configure.sh"
    say "Running the application's configure.sh..."
    bash "$APP_DIR/rsx/resource/docker/configure.sh" \
        || warn "configure.sh exited non-zero"
fi

# -----------------------------------------------------------------------------
# 11b. Start serving
# -----------------------------------------------------------------------------
startup_record step "starting the web and worker services"
# The programs this image carries out of SERVING_PROGRAMS (a production image has no
# development-only programs; the test image removes some), started together - each
# `supervisorctl start` waits out its program's startsecs, and in sequence those add up
# for nothing. Whether they started without a checkout too: the container serves what is
# in the docroot either way. A program that does not start is warned about, never a
# stopped start: supervisor and the health check (rspade-healthcheck) keep reporting it.
say "Starting the web and worker services..."
supervisor_programs="$(supervisorctl status 2>/dev/null | awk '{ print $1 }')"
start_pids=()
start_names=()
for program in $SERVING_PROGRAMS; do
    printf '%s\n' "$supervisor_programs" | grep -qx "$program" || continue
    supervisorctl start "$program" >/dev/null 2>&1 &
    start_pids+=("$!")
    start_names+=("$program")
done
for i in "${!start_pids[@]}"; do
    wait "${start_pids[$i]}" || warn "${start_names[$i]} did not start: supervisorctl status ${start_names[$i]}"
done

# -----------------------------------------------------------------------------
# 12. Ready
# -----------------------------------------------------------------------------
# The start is over: rspade-wait-started returns from here, before a passed command runs.
startup_record started

echo ""
if [ "$HAS_FRAMEWORK" = "1" ]; then
    say "RSpade is up. ${APP_URL_NOW:-(APP_URL unset)}"
    say "      framework $(cd "$APP_DIR/system" 2>/dev/null && /usr/bin/git rev-parse --short=12 HEAD 2>/dev/null || echo 'unknown')"
else
    # Say what IS running rather than announcing an application that is not here.
    say "Services are up (nginx, php-fpm, MySQL, Redis). No RSpade checkout at $APP_DIR."
fi
echo ""

# -----------------------------------------------------------------------------
# 13. Hand over
# -----------------------------------------------------------------------------
# With a COMMAND passed to the container, run it now that the application is
# actually serving, and take the container down when it finishes. That is what
# makes this work:
#
#     docker compose run --service-ports --rm app bash
#
# - boot in the foreground, watch the setup happen, land at a prompt, and the
# container stops when the shell exits. It is also just the conventional
# entrypoint contract: an image that ignores its own CMD cannot be composed with
# anything.
#
# With no command - the ordinary `docker compose up` - wait on supervisor and
# keep serving, which is what a development server should do: outlive the
# terminal that started it.
if [ "$#" -gt 0 ]; then
    "$@"
    status=$?

    say "Command finished (exit ${status}). Stopping services..."
    kill -TERM "$SUPERVISOR_PID" 2>/dev/null
    wait "$SUPERVISOR_PID" 2>/dev/null

    exit "$status"
fi

wait "$SUPERVISOR_PID"
