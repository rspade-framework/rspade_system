#!/bin/bash
#
# Run a supervised service, and start it again whenever it stops - forever, with a wait
# between attempts.
#
#     retry-run.sh <label> <command> [args...]
#
# WHY THIS EXISTS. Supervisor restarts a program that exits, but it cannot WAIT before doing
# so: a program that exits after `startsecs` is restarted at once, every time, and one that
# exits sooner is retried 1 s, 2 s, 3 s ... later until `startretries` runs out and it is
# parked in FATAL for the life of the container. Neither is what a service needs:
#
#   - a crash loop must not spin a core, so every restart waits;
#   - a failure is retried FOREVER, because most are transient: on a first start the
#     application (APP_KEY, the database, a build) arrives while the services are already
#     starting, so a service can fail at 12:00:01 and succeed at 12:00:20, and a service that
#     gives up on a condition that resolves itself is just a bug with extra steps.
#
# THE WAIT doubles from MIN_DELAY (2 s) to MAX_DELAY (60 s) across consecutive failures, so a
# genuinely broken service costs one log line a minute. A run that lasted MAX_DELAY or longer
# was not part of a crash loop: the next restart starts over at MIN_DELAY, so a long-lived
# service that crashes once comes straight back.
#
# SIGNALS. The wrapper is supervisor's main pid, so every signal supervisor sends reaches it,
# and it passes each one on to the service and waits for the service to exit - so a graceful
# stop (a proxy draining, a daemon finishing its tick) is waited for, and supervisor's
# stopwaitsecs applies to the service itself. After a stop signal (TERM, INT, QUIT) the
# wrapper exits with the service and restarts nothing. HUP, USR1 and USR2 are passed on and
# change nothing here (a service re-reading its configuration).
#
# The service runs in the background of this shell so the wrapper can take signals while it
# runs; bash ignores SIGINT and SIGQUIT in a background command, so the service is started
# with both restored to their defaults.
#
set -u

LABEL="${1:?retry-run.sh needs a label}"
shift

[ "$#" -gt 0 ] || { echo "[$LABEL] no command given" >&2; exit 1; }

MIN_DELAY="${RSPADE_RETRY_MIN_DELAY:-2}"
MAX_DELAY="${RSPADE_RETRY_MAX_DELAY:-60}"

child=''
stopping=0

forward() {
    local signal="$1"

    case "$signal" in
        TERM|INT|QUIT) stopping=1 ;;
    esac

    if [ -n "$child" ]; then
        kill -s "$signal" "$child" 2>/dev/null
    fi
}

for signal in TERM INT QUIT HUP USR1 USR2; do
    # shellcheck disable=SC2064 - the signal name is bound now, deliberately
    trap "forward $signal" "$signal"
done

delay="$MIN_DELAY"
attempt=0

while true; do
    attempt=$((attempt + 1))
    started=$SECONDS

    ( trap - INT QUIT; exec "$@" ) &
    child=$!

    # wait returns early (above 128) when a trapped signal arrives; the service is still
    # running then, so wait again until it has really exited and its status is its own.
    while true; do
        wait "$child"
        status=$?
        kill -0 "$child" 2>/dev/null || break
    done
    child=''

    if [ "$stopping" -eq 1 ]; then
        exit "$status"
    fi

    if [ $((SECONDS - started)) -ge "$MAX_DELAY" ]; then
        delay="$MIN_DELAY"
    fi

    if [ "$status" -eq 0 ]; then
        echo "[$LABEL] exited cleanly; restarting in ${delay}s."
    else
        echo "[$LABEL] exited with status ${status} (attempt ${attempt}); restarting in ${delay}s."
    fi

    # The sleep runs in the background too, so a stop signal during it is taken at once.
    sleep "$delay" &
    sleeper=$!
    wait "$sleeper" 2>/dev/null
    if [ "$stopping" -eq 1 ]; then
        kill "$sleeper" 2>/dev/null
        exit 0
    fi

    delay=$((delay * 2))
    [ "$delay" -gt "$MAX_DELAY" ] && delay="$MAX_DELAY"
done
