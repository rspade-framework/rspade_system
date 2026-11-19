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
# SCHEDULED: one attempt. When anything was installed php-fpm is restarted onto the new
# binary and libraries WITHOUT CUTTING A REQUEST, through Proxority (every PHP request
# passes through it):
#
#     1. proxority hold --holder-pid=<this script>   new requests wait, parked, never
#                                                     refused; returns once nothing is in
#                                                     flight at php-fpm
#     2. supervisorctl restart php-fpm
#     3. wait until php-fpm answers its status page (127.0.0.1:3201/_fpm_status)
#     4. proxority release                            the parked requests run on the new PHP
#
# None of the waits has a deadline; the pause between status probes is a cadence. An EXIT
# trap releases the hold on every way out after step 1 (a failed restart, a signal), and a
# holder that dies outright is released by Proxority itself, which watches the pid. Where
# Proxority is not running (or the hold fails) php-fpm is reloaded instead: USR2 to its
# master re-executes the new binary on the same process and sockets, replacing the workers,
# and a request in flight at that instant is cut.
#
# nginx is restarted when an nginx package changed (connections open at that instant are
# cut): its graceful binary upgrade (USR2, then QUIT to the old master) hands the listening
# sockets to a NEW master process that supervisor does not track, so under supervisor the
# restart is the clean way. Anything else that changed (a library under redis, supervisor,
# the node services) loads at the next container restart, and the status says so. Running
# CLI processes - task workers included - keep the binary they started with and pick up the
# new one when they next start.
#
# OFF SWITCH: PHP_SECURITY_UPDATES=0 (or false/no/off) in the container's environment,
# e.g. `docker run -e PHP_SECURITY_UPDATES=0 ...`. On by default.
#
# REPORTS to /var/lib/rspade/security-updates.json, which the rsx:health "Security Updates"
# row reads. Never exits non-zero: a failed check must not stop a container or a program.
#
set -u

MODE="${1:-scheduled}"
STATUS_DIR="${2:-/var/lib/rspade}"
STATUS_FILE="$STATUS_DIR/security-updates.json"
RETRY_PAUSE_SECONDS=60

# php-fpm's status page on the loopback nginx listener (nginx/site.conf, :3201): answering
# means php-fpm is up and accepting work.
FPM_STATUS_URL="http://127.0.0.1:3201/_fpm_status"

# Seconds between status probes while php-fpm restarts: a cadence, not a deadline.
FPM_PROBE_PAUSE_SECONDS=1

export DEBIAN_FRONTEND=noninteractive

# One collation for sort and join below: join requires its inputs sorted the way it compares.
export LC_ALL=C

say() { echo "[security-updates] $*"; }

# Whether a switch is on: 0/false/no/off is off, anything else (1/true/yes/on) is on.
switch_on() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        0|false|no|off) return 1 ;;
        *) return 0 ;;
    esac
}

case "$MODE" in
    startup|scheduled) ;;
    *) say "unknown mode '$MODE' (startup|scheduled)"; exit 0 ;;
esac

enabled() {
    switch_on "${PHP_SECURITY_UPDATES:-1}"
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

# The old reload: USR2 to the php-fpm master re-executes the new binary in place.
reload_php_fpm() {
    local master
    master="$(pgrep -f 'php-fpm: master process' | head -n1)"
    if [ -n "$master" ]; then
        say "reloading php-fpm (master $master) onto PHP $(php_version)..."
        kill -USR2 "$master"
    fi
}

HELD=0

release_hold() {
    [ "$HELD" = 1 ] || return 0
    HELD=0
    proxority release || say "WARNING: proxority release failed; Proxority releases the hold itself once this process ends"
}

# Hold Proxority, restart php-fpm, wait until it answers, release. Answers non-zero, having
# done nothing, when Proxority is not running or refuses the hold.
restart_php_fpm_held() {
    command -v proxority >/dev/null 2>&1 || return 1
    proxority status >/dev/null 2>&1 || return 1

    say "holding Proxority; waiting for the requests in flight to finish..."
    # The trap is set first: a signal between the hold and the release still releases.
    trap release_hold EXIT
    trap 'exit 143' TERM INT HUP
    HELD=1
    if ! proxority hold --reason="php security update" --holder-pid="$$"; then
        say "WARNING: proxority hold failed"
        # Whatever state the failure left behind, this process does not keep a hold.
        release_hold
        return 1
    fi

    say "restarting php-fpm onto PHP $(php_version)..."
    if supervisorctl restart php-fpm; then
        say "waiting for php-fpm to answer..."
        until curl -fsS -o /dev/null "$FPM_STATUS_URL" 2>/dev/null; do
            sleep "$FPM_PROBE_PAUSE_SECONDS"
        done
        say "php-fpm answers."
    else
        say "WARNING: php-fpm did not restart"
    fi

    release_hold
    return 0
}

if ! enabled; then
    say "automatic security updates are off (PHP_SECURITY_UPDATES=${PHP_SECURITY_UPDATES})."
    write_status disabled "Turned off by PHP_SECURITY_UPDATES." "" false
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
    restart_php_fpm_held || reload_php_fpm

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
