#!/bin/bash
#
# The RSpade container's startup record: has the container finished starting?
#
#     rspade-startup [--state-file=<path>] [--proc-root=<dir>] <action> [<text>]
#     rspade-wait-started [--state-file=<path>] [--proc-root=<dir>]
#
#     begin              a start begins: status=starting, a fresh record
#     step <text>        what the start is doing now ("waiting for the database")
#     warning <text>     a warning the start printed (the image-contract sentence)
#     started            the start finished: status=started
#     failed <message>   the start failed: status=failed, error=<message>
#     wait               block until the start has finished (rspade-wait-started)
#
# The entrypoint (entrypoint.sh) is the only writer: `begin` as its first statement, `step`
# at each numbered section, `warning` with the image-contract sentence, `started` right
# before "RSpade is up" (and before a command passed to the container runs), `failed` from
# die(). Docker's health check is a different question - it is healthy during maintenance by
# design, so `docker compose up --wait` cannot tell "starting" from "started". This can.
#
# THE RECORD, /run/rspade-startup: key=value lines, written to a temporary file and renamed
# into place, so a reader never sees half of one. World-readable.
#
#     status=starting|started|failed
#     step=<what the start is doing; empty before the first step>
#     pid1_start=<field 22 of /proc/1/stat - PID 1's start time - when `begin` ran>
#     began_at=<UTC>
#     finished_at=<UTC; empty while starting>
#     error=<one line; failed only>
#     warning=<one line; when the start printed one>
#
# /run survives a host reboot that keeps the container's filesystem, so a record whose
# pid1_start differs from the live PID 1's is from an earlier start and means nothing.
#
# WAITING. `wait` (installed as rspade-wait-started) has NO deadline - a start takes as long
# as it takes (a database replaying its log, a first migration), and the pipeline that calls
# it owns its own. It polls the record every second and:
#
#     started  -> exit 0, silently;
#     failed   -> the error on stderr, exit 1;
#     starting -> one line per change of step: "[rspade] still starting: <step>";
#     no record of this start -> keep waiting when PID 1's command line names
#                 rspade-entrypoint (it has not written its first line yet); otherwise
#                 exit 2: the container was not started by the RSpade entrypoint.
#
# A FAILED START STOPS THE CONTAINER: the entrypoint is PID 1 (or docker-init's only child),
# and its exit ends the container - and with it a waiter run through `docker exec`, which then
# exits non-zero without reading `failed`. The entrypoint's "[rspade] ERROR:" line is in
# `docker compose logs`. A waiter running inside the container's own start (`docker compose
# run`) reads `failed` normally.
#
# --state-file moves the record and --proc-root the /proc that PID 1 is read from; the test
# suite points both at scratch copies. A record that cannot be written is reported on stderr
# and never stops the start.
#
set -u

STATE_FILE=/run/rspade-startup
PROC_ROOT=/proc

# The options are taken wherever they stand (rspade-wait-started passes its own arguments
# after `wait`); everything else is the action and its text.
positional=()
for arg in "$@"; do
    case "$arg" in
        --state-file=*) STATE_FILE="${arg#--state-file=}" ;;
        --proc-root=*)  PROC_ROOT="${arg#--proc-root=}" ;;
        *) positional+=("$arg") ;;
    esac
done
set -- "${positional[@]+"${positional[@]}"}"

ACTION="${1:-}"
[ "$#" -gt 0 ] && shift
TEXT="$*"

usage() {
    echo "usage: rspade-startup [--state-file=<path>] [--proc-root=<dir>] begin | step <text> | warning <text> | started | failed <message> | wait" >&2
    exit 1
}

now_utc() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

# One line, whatever the caller handed in.
one_line() {
    printf '%s' "$1" | tr '\r\n\t' '   ' | tr -s ' ' | sed -e 's/^ //' -e 's/ $//'
}

# Field 22 of PID 1's stat: its start time in clock ticks since boot. The command name in
# field 2 may hold spaces and parentheses, so the fields are counted after its last ')'.
pid1_start() {
    local stat rest
    stat="$(cat "${PROC_ROOT}/1/stat" 2>/dev/null)" || return 0
    rest="${stat##*) }"
    # shellcheck disable=SC2086 - split on purpose
    set -- $rest
    # rest starts at field 3; field 22 is the 20th word.
    [ "$#" -ge 20 ] && printf '%s' "${20}"
}

value() {
    local line
    line="$(grep -m1 -E "^${1}=" "$STATE_FILE" 2>/dev/null)" || true
    printf '%s' "${line#*=}"
}

# Rewrite the record: status step pid1_start began_at finished_at error warning.
write_record() {
    local dir tmp
    dir="$(dirname "$STATE_FILE")"
    tmp="${dir}/.$(basename "$STATE_FILE").$$"

    if ! (
        umask 022
        printf 'status=%s\nstep=%s\npid1_start=%s\nbegan_at=%s\nfinished_at=%s\nerror=%s\nwarning=%s\n' \
            "$1" "$2" "$3" "$4" "$5" "$6" "$7" > "$tmp" 2>/dev/null || exit 1
        mv -f -- "$tmp" "$STATE_FILE" 2>/dev/null || exit 1
    ); then
        rm -f -- "$tmp" 2>/dev/null
        echo "[rspade] WARNING: cannot write the startup record ${STATE_FILE}; rspade-wait-started cannot see this start." >&2
    fi
    return 0
}

# Rewrite the record with one change, the rest as it stands.
update_record() {
    local status step error warning finished
    status="$(value status)"; step="$(value step)"; error="$(value error)"
    warning="$(value warning)"; finished="$(value finished_at)"

    case "$1" in
        step)    step="$2" ;;
        warning) warning="$2" ;;
        started) status=started; finished="$(now_utc)" ;;
        failed)  status=failed; error="$2"; finished="$(now_utc)" ;;
    esac

    write_record "${status:-starting}" "$step" "$(value pid1_start)" "$(value began_at)" "$finished" "$error" "$warning"
}

# True when the record describes THIS start of the container.
record_is_current() {
    local recorded live
    [ -f "$STATE_FILE" ] || return 1
    recorded="$(value pid1_start)"
    live="$(pid1_start)"
    [ -n "$recorded" ] && [ "$recorded" = "$live" ]
}

pid1_is_the_entrypoint() {
    local cmdline
    cmdline="$(tr '\0' ' ' < "${PROC_ROOT}/1/cmdline" 2>/dev/null)" || return 1
    case "$cmdline" in
        *rspade-entrypoint*) return 0 ;;
    esac
    return 1
}

do_wait() {
    local status step last_step='' announced=0

    while true; do
        if record_is_current; then
            status="$(value status)"
            case "$status" in
                started)
                    return 0
                    ;;
                failed)
                    echo "[rspade] ERROR: the container failed to start: $(value error)" >&2
                    return 1
                    ;;
                starting)
                    step="$(value step)"
                    if [ -n "$step" ] && { [ "$announced" -eq 0 ] || [ "$step" != "$last_step" ]; }; then
                        echo "[rspade] still starting: ${step}"
                        last_step="$step"
                        announced=1
                    fi
                    ;;
                *)
                    echo "[rspade] ERROR: ${STATE_FILE} has an unknown status: ${status}" >&2
                    return 1
                    ;;
            esac
        elif ! pid1_is_the_entrypoint; then
            echo "This container was not started by the RSpade entrypoint; there is no startup to wait for." >&2
            return 2
        fi

        sleep 1
    done
}

case "$ACTION" in
    begin)
        write_record starting '' "$(pid1_start)" "$(now_utc)" '' '' ''
        ;;
    step|warning|failed)
        [ -n "$TEXT" ] || usage
        update_record "$ACTION" "$(one_line "$TEXT")"
        ;;
    started)
        update_record started ''
        ;;
    wait)
        do_wait
        exit $?
        ;;
    *)
        usage
        ;;
esac

exit 0
