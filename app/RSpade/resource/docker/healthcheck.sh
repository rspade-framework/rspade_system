#!/bin/bash
#
# The RSpade container's health check: is every supervised service up?
#
#     bash /usr/local/bin/rspade-healthcheck [--project-root=<dir>] [--state-dir=<dir>]
#
# Exit 0 = healthy, 1 = unhealthy, with one line on stdout per problem (nothing at all when
# everything is up). The image's Docker HEALTHCHECK runs it; an operator may run it by hand,
# and a host that builds its own container definition points its own health check at it.
# A health check only REPORTS - nothing restarts an unhealthy container because of it.
#
# WHY. Every long-running service runs under the retry runner (rspade-retry-run), which
# restarts it forever - so supervisor shows the program RUNNING however often the service
# itself exits, because supervisor's process is the runner. A lock daemon once stayed down
# for six hours on a live deployment behind exactly that RUNNING. This reads what the runners
# record instead.
#
# THE CHECKS, every one reported (not just the first):
#
#   1. Every runner state file (/run/rspade-services, or --state-dir;
#      retry-run.sh documents the format) whose status is `failing` - five or more
#      consecutive exits, not yet recovered. A file whose runner_pid is not a live runner for
#      that label is left over from an earlier runner and is ignored.
#   2. `supervisorctl status`: a program FATAL, BACKOFF, EXITED or UNKNOWN. STOPPED is
#      somebody's deliberate act and is not a failure. supervisorctl not answering at all is.
#   3. rsx-lockd answers a ping ({"id":1,"op":"ping"}, answered before any hello, so no key
#      is needed) on LOCK_SERVER_HOST:LOCK_SERVER_PORT, whenever supervisor has an rsx-lockd
#      program. Every request takes a site lock from it, so a daemon that does not answer is
#      an application that does not serve - stopped by hand included. The deliberate way to
#      stop it is maintenance mode, below.
#
# HEALTHY BY DEFINITION, with one note line:
#
#   - while maintenance mode is on (the flag file in <storage>/state, resolved through
#     system/bin/lib/rsx_paths.sh, so RSX_STORAGE_PATH is honoured): services are stopped
#     on purpose;
#   - when there is no checkout at <project>/system (default /var/www/html): with no
#     application the services wait for one, and that is the container working as designed.
#
# NO TIMEOUT is set anywhere here; Docker applies its own HEALTHCHECK timeout to the whole
# run, and a ping that never answers is that timeout's to judge.
#
# --project-root and --state-dir move the project (default /var/www/html, the container's)
# and the state directory; the test suite points them at scratch copies.
#
set -u

PROJECT_ROOT=/var/www/html
STATE_DIR=/run/rspade-services

for arg in "$@"; do
    case "$arg" in
        --project-root=*) PROJECT_ROOT="${arg#--project-root=}" ;;
        --state-dir=*)    STATE_DIR="${arg#--state-dir=}" ;;
        *)
            echo "usage: rspade-healthcheck [--project-root=<dir>] [--state-dir=<dir>]" >&2
            exit 1
            ;;
    esac
done
PROJECT_ROOT="${PROJECT_ROOT%/}"

if [ ! -f "$PROJECT_ROOT/system/bin/lib/rsx_paths.sh" ]; then
    echo "[INFO] no checkout at ${PROJECT_ROOT}/system: the services wait for one."
    exit 0
fi

RSX_PATHS_PROJECT_ROOT_DIR="$PROJECT_ROOT"
# shellcheck source=/dev/null
. "$PROJECT_ROOT/system/bin/lib/rsx_paths.sh"

# Mirrors App\RSpade\Core\Paths\Rsx_Project_Paths::maintenance_flag_file().
MAINTENANCE_FLAG="$(rsx_state_root)/.maintenance.mode.framework.update"

if [ -e "$MAINTENANCE_FLAG" ]; then
    echo "[INFO] maintenance mode is on (${MAINTENANCE_FLAG}): services are stopped on purpose."
    exit 0
fi

unhealthy=0

problem() {
    echo "[ERROR] $*"
    unhealthy=1
}

# One key of a state file.
state_value() {
    local file="$1" key="$2" line
    line="$(grep -m1 -E "^${key}=" "$file" 2>/dev/null)" || true
    printf '%s' "${line#*=}"
}

# True when $1 is a live retry runner for label $2.
is_live_runner() {
    local pid="$1" label="$2" cmdline

    [ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 1
    cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)"
    case "$cmdline" in
        *retry-run*" ${label} "*) return 0 ;;
    esac

    return 1
}

# 1. Runner state files.
if [ -d "$STATE_DIR" ]; then
    for file in "$STATE_DIR"/*.state; do
        [ -f "$file" ] || continue
        label="$(state_value "$file" label)"
        [ "$(state_value "$file" status)" = failing ] || continue
        is_live_runner "$(state_value "$file" runner_pid)" "$label" || continue

        problem "${label}: failing - $(state_value "$file" consecutive_failures) consecutive exits" \
            "(last status $(state_value "$file" last_exit_status)) since $(state_value "$file" streak_since)"
    done
fi

# 2. Supervisor's view. Its exit status says "something is not running", which includes a
# deliberate STOPPED, so the lines are read instead.
# A line is a program only when its second word is one of supervisor's process states;
# anything else (an unreachable socket, a usage error) is supervisorctl not answering.
supervisor_status=''
if command -v supervisorctl >/dev/null 2>&1; then
    supervisor_output="$(supervisorctl status 2>&1)" || true

    while read -r program state rest; do
        [ -n "$program" ] || continue
        case "$state" in
            STOPPED|STARTING|RUNNING|STOPPING)
                ;;
            FATAL|BACKOFF|EXITED|UNKNOWN)
                problem "supervisor: ${program} is ${state}${rest:+ (${rest})}"
                ;;
            *)
                continue
                ;;
        esac
        supervisor_status+="${program} ${state}"$'\n'
    done <<< "$supervisor_output"

    if [ -z "$supervisor_status" ]; then
        problem "supervisor: supervisorctl status did not answer: $(printf '%s' "$supervisor_output" | head -n1)"
    fi
fi

# 3. The lock daemon answers. One ping, as bin/maintenance-mode.sh's lockd_answers_ping.
lockd_answers_ping() {
    local host="$1" port="$2" reply='' fd

    { exec {fd}<>"/dev/tcp/${host}/${port}"; } 2>/dev/null || return 1
    { printf '{"id":1,"op":"ping"}\n' >&"$fd"; } 2>/dev/null || { exec {fd}>&-; return 1; }
    IFS= read -r -u "$fd" reply 2>/dev/null || true
    exec {fd}>&-

    case "$reply" in
        *'"status":"ok"'*) return 0 ;;
    esac

    return 1
}

if printf '%s\n' "$supervisor_status" | grep -Eq '^rsx-lockd[[:space:]]'; then
    lockd_host="$(rsx_env_value LOCK_SERVER_HOST 127.0.0.1)"
    lockd_port="$(rsx_env_value LOCK_SERVER_PORT 6210)"

    if ! lockd_answers_ping "$lockd_host" "$lockd_port"; then
        problem "rsx-lockd: no answer to a ping on ${lockd_host}:${lockd_port} - every request that takes a lock fails"
    fi
fi

exit "$unhealthy"
