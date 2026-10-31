#!/bin/bash
#
# Install the security updates this container is allowed to take, and report what happened.
#
#     rspade-security-update startup     # the entrypoint, BEFORE supervisor starts anything
#     rspade-security-update scheduled   # the security-updates supervisor program
#
# An optional second argument names the directory the status is written to (a test's
# scratch directory); it defaults to /var/lib/rspade.
#
# WHY THIS EXISTS. PHP comes from the ondrej PPA, unpinned within its minor version, so a
# running container CAN take a PHP patch release with apt - but nothing used to ask. A PHP
# security release then reached a box only when somebody rebuilt the image, and a release
# that grants a remote shell on any unpatched PHP is exactly the one an operator rebuilds
# last. So the container patches itself: before PHP first serves anything at every start,
# and every few hours while it runs.
#
# WHAT IT MAY INSTALL is unattended-upgrades' allow-list, configured in
# /etc/apt/apt.conf.d/52rspade-security-updates: the Ubuntu security pocket and the ondrej
# PHP PPA - PHP PATCH releases of the image's PHP minor version (8.5.x; a new minor version
# is a different package set and only ever arrives with a new image). The development
# image also holds MySQL back (53rspade-security-updates-dev): its maintenance steps would
# run on the data directory, under a migrate that may be snapshotting it.
#
# STARTUP: one attempt; on a failure of `apt-get update` or of the upgrade, one more a
# minute later; then the container starts regardless, on whatever is installed, and the
# scheduled program keeps trying. Apt keeps its own network behaviour - no deadline is
# added here; the minute is a pause between attempts, never a cap on one. Nothing is
# running yet, so nothing needs restarting: supervisor starts every service on what is
# now installed.
#
# SCHEDULED: one attempt. When anything was installed php-fpm is reloaded - USR2 to its
# master re-executes the new binary and its libraries on the same process and sockets,
# replacing the workers (a request in flight at that instant is cut, as on any php-fpm
# reload). nginx is restarted when an nginx package changed. Anything else that changed
# (a library under redis, supervisor, the node services) loads at the next container
# restart, and the status says so. Running CLI processes - task workers included - keep
# the binary they started with and pick up the new one when they next start.
#
# OFF SWITCH: RSX_AUTO_SECURITY_UPDATES=0 (or false/no/off) in the container's environment,
# e.g. `docker run -e RSX_AUTO_SECURITY_UPDATES=0 ...`. On by default.
#
# REPORTS to /var/lib/rspade/security-updates.json, which the rsx:health "Security Updates"
# row reads. Never exits non-zero: a failed check must not stop a container or a program.
#
set -u

MODE="${1:-scheduled}"
STATUS_DIR="${2:-/var/lib/rspade}"
STATUS_FILE="$STATUS_DIR/security-updates.json"
RETRY_PAUSE_SECONDS=60

export DEBIAN_FRONTEND=noninteractive

# One collation for sort and join below: join requires its inputs sorted the way it compares.
export LC_ALL=C

say() { echo "[security-updates] $*"; }

case "$MODE" in
    startup|scheduled) ;;
    *) say "unknown mode '$MODE' (startup|scheduled)"; exit 0 ;;
esac

enabled() {
    case "$(printf '%s' "${RSX_AUTO_SECURITY_UPDATES:-1}" | tr '[:upper:]' '[:lower:]')" in
        0|false|no|off) return 1 ;;
        *) return 0 ;;
    esac
}

# Every installed package and its version, one per line, sorted - compared before and after.
installed() {
    dpkg-query -W -f='${Package} ${Version}\n' 2>/dev/null | sort
}

php_version() {
    php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown
}

json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# write_status <result> <message> <upgraded lines> <restart_recommended>
write_status() {
    local result="$1" message="$2" upgraded="$3" restart="$4" items="" line

    while IFS= read -r line; do
        [ -n "$line" ] || continue
        items="${items:+$items, }\"$(json_escape "$line")\""
    done <<< "$upgraded"

    mkdir -p "$STATUS_DIR" && chmod 755 "$STATUS_DIR"
    printf '{"checked_at": "%s", "mode": "%s", "result": "%s", "message": "%s", "php_version": "%s", "upgraded": [%s], "restart_recommended": %s}\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$MODE" "$result" "$(json_escape "$message")" \
        "$(php_version)" "$items" "$restart" > "$STATUS_FILE.tmp" \
        && chmod 644 "$STATUS_FILE.tmp" && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE"
}

# One attempt: refresh the lists, then let unattended-upgrade install what the allow-list
# permits. Either step failing fails the attempt.
attempt() {
    if ! apt-get update -q; then
        say "apt-get update failed"
        return 1
    fi

    if ! unattended-upgrade -v; then
        say "unattended-upgrade failed"
        return 1
    fi

    return 0
}

if ! enabled; then
    say "automatic security updates are off (RSX_AUTO_SECURITY_UPDATES=${RSX_AUTO_SECURITY_UPDATES})."
    write_status disabled "Turned off by RSX_AUTO_SECURITY_UPDATES." "" false
    exit 0
fi

before="$(installed)"
say "checking for security updates ($MODE)..."

ok=1
if ! attempt; then
    if [ "$MODE" = startup ]; then
        say "retrying in ${RETRY_PAUSE_SECONDS}s..."
        sleep "$RETRY_PAUSE_SECONDS"
        attempt || ok=0
    else
        ok=0
    fi
fi

after="$(installed)"

# "package old -> new" for every package whose version changed or that is new.
upgraded="$(join -a 2 -e '(new)' -o '0,1.2,2.2' <(printf '%s\n' "$before") <(printf '%s\n' "$after") \
    | awk '$2 != $3 { print $1 " " $2 " -> " $3 }')"

if [ "$ok" = 0 ] && [ -z "$upgraded" ]; then
    if [ "$MODE" = startup ]; then
        say "WARNING: could not check for security updates; starting on PHP $(php_version) as installed. The scheduled check will try again."
    else
        say "WARNING: the security update check failed; the next scheduled check will try again."
    fi
    write_status failed "The update check failed; see the container log." "" false
    exit 0
fi

if [ -z "$upgraded" ]; then
    say "up to date (PHP $(php_version))."
    write_status current "No security updates were available." "" false
    exit 0
fi

say "installed:"
printf '%s\n' "$upgraded" | sed 's/^/    /'

restart=false
if [ "$MODE" = scheduled ]; then
    master="$(pgrep -f 'php-fpm: master process' | head -n1)"
    if [ -n "$master" ]; then
        say "reloading php-fpm (master $master) onto PHP $(php_version)..."
        kill -USR2 "$master"
    fi

    if printf '%s\n' "$upgraded" | grep -q '^nginx'; then
        say "restarting nginx..."
        supervisorctl restart nginx || say "WARNING: nginx did not restart"
    fi

    if printf '%s\n' "$upgraded" | grep -qv -e '^php' -e '^nginx' -e '^libnginx'; then
        restart=true
        say "other updated packages load at the next container restart."
    fi
fi

result=updated
[ "$ok" = 0 ] && result=partial
write_status "$result" "Security updates were installed." "$upgraded" "$restart"
exit 0
