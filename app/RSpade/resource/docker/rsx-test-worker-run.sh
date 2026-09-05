#!/bin/bash
#
# The CMD of a parallel-test worker container.
#
#     rsx-test-worker-run <worker-id> <orchestrator-socket>
#
# WHY THIS EXISTS. The container entrypoint starts supervisor and then waits for
# exactly two things - redis and mysql - because those are the two the entrypoint
# itself needs in order to provision and migrate. It then runs the CMD. Every
# other supervised program is still coming up at that moment: rsx-lockd and
# mail-catcher both declare startsecs=5, so a worker that begins testing on the
# entrypoint's readiness is testing against a container whose lock daemon is not
# listening and whose SMTP catcher is not accepting mail.
#
# That is not a theoretical race. It cost 17 failures across three mail concerns
# (the catcher refused the connection the test had just enqueued mail for) plus a
# health-command failure, every one of them a service that was RUNNING a few
# seconds later. SPAWNING IS NOT READINESS - the same rule maintenance-mode.sh
# enforces before it lets php-fpm serve traffic, and for the same reason: a
# request that takes a cluster lock against a daemon which is not yet listening
# waits with no deadline and no error.
#
# So this waits for the WHOLE supervisor roster to be RUNNING and for rsx-lockd
# to answer a ping, and only then execs the worker in its own place - same PID,
# so the container's exit status is the test worker's exit status.
#
# NO TIMEOUT ANYWHERE. Coming up takes as long as it takes, and a bound here
# would convert a slow-but-working container into a failed test run. What this
# does instead is refuse to WAIT ON THE DEAD: a supervisord that is not answering
# at all, or any program parked in FATAL, is a container that will never become
# ready, and that is reported loudly with the status output and a non-zero exit
# rather than waited on forever.
#
set -u

WORKER_ID="${1:?rsx-test-worker-run needs a worker id}"
WORKER_SOCKET="${2:?rsx-test-worker-run needs an orchestrator socket path}"

SUPERVISOR_CONF=/etc/supervisor/supervisord.conf
PROJECT_ROOT=/var/www/html

# The roster is read from supervisor itself, so a program added to conf.d/ is
# waited for with no edit here. These four are named explicitly as the MINIMUM
# that must exist: if one of them is missing from the status output, the image is
# not the image this script was written for and testing against it would produce
# failures that look like framework bugs.
REQUIRED_PROGRAMS="mysql redis rsx-lockd mail-catcher"

say() {
    printf '[test-worker %s] %s\n' "$WORKER_ID" "$1" >&2
}

fail() {
    say "$1"
    say "supervisorctl status was:"
    supervisorctl -c "$SUPERVISOR_CONF" status >&2 2>&1 || true
    exit 1
}

# -----------------------------------------------------------------------------
# .env reader - plain string ops, no Laravel. Mirrors maintenance-mode.sh, which
# has to work on a broken tree for the same reason: this runs before anything has
# proved the application boots.
# -----------------------------------------------------------------------------
env_value() {
    local key="$1" fallback="$2" env_file="$PROJECT_ROOT/.env" line
    [ -f "$env_file" ] || { printf '%s' "$fallback"; return 0; }
    line="$(grep -m1 -E "^[[:space:]]*${key}[[:space:]]*=" "$env_file" 2>/dev/null)" || true
    [ -n "$line" ] || { printf '%s' "$fallback"; return 0; }
    line="${line#*=}"
    line="$(printf '%s' "$line" | tr -d '"'"'" | tr -d '[:space:]')"
    [ -n "$line" ] || { printf '%s' "$fallback"; return 0; }
    printf '%s' "$line"
}

# One ping against rsx-lockd. 0 = the daemon answered ok. The wire protocol
# answers ping PRE-HELLO, so a bare freshly-started daemon can prove it is
# serving frames without holding APP_KEY.
lockd_answers_ping() {
    local host="$1" port="$2" reply='' lockd_fd

    { exec {lockd_fd}<>"/dev/tcp/${host}/${port}"; } 2>/dev/null || return 1
    { printf '{"id":1,"op":"ping"}\n' >&"$lockd_fd"; } 2>/dev/null \
        || { exec {lockd_fd}>&-; return 1; }
    IFS= read -r -u "$lockd_fd" reply 2>/dev/null || true
    exec {lockd_fd}>&-

    case "$reply" in
        *'"status":"ok"'*) return 0 ;;
    esac

    return 1
}

# -----------------------------------------------------------------------------
# Wait for the supervisor roster.
#
# `supervisorctl status` exits non-zero whenever any program is not RUNNING, so
# its exit code cannot distinguish "still starting" from "cannot talk to
# supervisord". The output does: no output at all means nothing answered.
# -----------------------------------------------------------------------------
wait_for_supervisor() {
    local status_output name state waited=0 not_ready fatal missing program

    while true; do
        status_output="$(supervisorctl -c "$SUPERVISOR_CONF" status 2>&1)" || true

        if [ -z "$status_output" ]; then
            fail "supervisord is not answering - the container has no process manager, so no service will ever come up."
        fi

        case "$status_output" in
            *'refused connection'*|*'no such file'*|*'SHUTDOWN_STATE'*)
                # supervisord itself is not up yet. It is started by the
                # entrypoint before the CMD runs, so this is a narrow startup
                # window rather than a dead container - keep polling.
                sleep 1
                waited=$((waited + 1))
                continue
                ;;
        esac

        not_ready=''
        fatal=''
        while read -r name state _rest; do
            [ -n "$name" ] || continue
            case "$state" in
                RUNNING) ;;
                FATAL)   fatal="$fatal $name" ;;
                *)       not_ready="$not_ready $name($state)" ;;
            esac
        done <<< "$status_output"

        if [ -n "$fatal" ]; then
            fail "supervised program(s) in FATAL:$fatal - this container will never become ready."
        fi

        missing=''
        for program in $REQUIRED_PROGRAMS; do
            printf '%s\n' "$status_output" | grep -qE "^${program}[[:space:]]" \
                || missing="$missing $program"
        done

        if [ -n "$missing" ]; then
            fail "supervisor roster is missing required program(s):$missing"
        fi

        if [ -z "$not_ready" ]; then
            [ "$waited" -gt 0 ] && say "all supervised services are RUNNING (${waited}s)."
            return 0
        fi

        sleep 1
        waited=$((waited + 1))
        if [ $((waited % 5)) -eq 0 ]; then
            say "waiting for services (${waited}s):$not_ready"
        fi
    done
}

wait_for_lockd() {
    local host port waited=0

    host="$(env_value LOCK_SERVER_HOST 127.0.0.1)"
    port="$(env_value LOCK_SERVER_PORT 6210)"

    while ! lockd_answers_ping "$host" "$port"; do
        sleep 1
        waited=$((waited + 1))
        if [ $((waited % 5)) -eq 0 ]; then
            say "waiting for rsx-lockd to answer on ${host}:${port} (${waited}s)..."
        fi
    done

    [ "$waited" -gt 0 ] && say "rsx-lockd is serving (${waited}s)."

    return 0
}

wait_for_supervisor
wait_for_lockd

cd "$PROJECT_ROOT" || fail "cannot enter $PROJECT_ROOT"

exec php artisan rsx:test --framework \
    --_worker-id="$WORKER_ID" \
    --_worker-socket="$WORKER_SOCKET"
