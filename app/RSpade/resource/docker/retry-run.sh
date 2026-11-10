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

# STALE PID AND LOCK FILES. Before EVERY start - the first one and each restart - the wrapper
# checks the pid and lock files the service is known to keep, and removes each one whose pid
# is not a running copy of THAT service. A pidfile only blocks a start when the process it
# names is alive AND is the program named.
#
# Why every start and not just container boot: pids in a container start again from 1, so
# after a host reboot that kept the container's filesystem (/run is not a tmpfs) a pidfile's
# old pid is almost always taken by some other process - a php-fpm worker - and a service
# that refuses to start while "its" pid is alive stays down until that unrelated process
# happens to exit. That kept the lock daemon down for six hours on a live deployment while
# supervisor showed it RUNNING. Checked at every start, the wrapper's next attempt recovers
# from inside the running container, whatever made the file stale.
#
# THE TABLE is keyed by the supervisor program label, so a service is covered wherever it
# is declared. A service not listed keeps no such file. Each entry: a file and the pattern
# its process's command line must match (/proc/<pid>/cmdline, NULs as spaces, grep -E).
#
#     retry-run.sh <label> <command> [args...]           retry forever (the default)
#     retry-run.sh --exec <label> <command> [args...]    check, then BECOME the service:
#         no retry and no restart - for a program supervisor must restart itself, or not
#         at all (mysql: `migrate` stops it to snapshot the datadir; dockerd)
#     retry-run.sh --clear-stale-pidfile <file> <pattern>   the check for one file
#
runtime_files() {
    case "$1" in
        rsx-lockd)
            # lockd checks its own (configurable) pidfile by identity too; this is the default.
            echo '/var/run/rsx-lockd.pid|lockd\.js'
            ;;
        nginx)
            echo '/run/nginx.pid|(^|/| )nginx( |:|$)'
            ;;
        mysql)
            echo '/var/run/mysqld/mysqld.pid|(^|/)mysqld( |$)'
            echo '/var/run/mysqld/mysqld.sock.lock|(^|/)mysqld( |$)'
            echo '/var/run/mysqld/mysqlx.sock.lock|(^|/)mysqld( |$)'
            ;;
        dockerd)
            echo '/var/run/docker.pid|(^|/)dockerd( |$)'
            # dockerd starts its own containerd and acts on a pid it finds here as its own.
            echo '/var/run/docker/containerd/containerd.pid|(^|/)containerd( |$)'
            ;;
    esac
}

# Remove $1 unless the pid in it is a running process whose command line matches $2. Says
# what it removed and why, one line.
clear_stale_pidfile() {
    local file="$1" pattern="$2" pid cmdline

    [ -e "$file" ] || return 0

    pid="$(tr -cd '0-9' < "$file" 2>/dev/null | head -c 10)"

    # This wrapper is never a running copy of the service it is about to start - and its own
    # command line names that service, so after a reboot that handed it the old pid it would
    # otherwise match.
    if [ "$pid" = "$$" ]; then
        echo "[pidfile] removing stale ${file}: pid ${pid} is this wrapper, not the service"
        rm -f -- "$file"
        return 0
    fi

    if [ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ]; then
        cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)"

        # A zombie's cmdline is empty: it is no running service.
        if [ -n "$cmdline" ] && printf '%s' "$cmdline" | grep -Eq -- "$pattern"; then
            return 0
        fi

        echo "[pidfile] removing stale ${file}: pid ${pid} is not this service (${cmdline:-a zombie})"
    else
        echo "[pidfile] removing stale ${file}: pid ${pid:-<none>} is not running"
    fi

    rm -f -- "$file"
}

clear_stale_runtime_files() {
    local entry

    while IFS= read -r entry; do
        [ -n "$entry" ] || continue
        clear_stale_pidfile "${entry%%|*}" "${entry#*|}"
    done < <(runtime_files "$1")
}

if [ "${1:-}" = '--clear-stale-pidfile' ]; then
    [ "$#" -eq 3 ] || { echo 'usage: retry-run.sh --clear-stale-pidfile <file> <pattern>' >&2; exit 1; }
    clear_stale_pidfile "$2" "$3"
    exit 0
fi

if [ "${1:-}" = '--exec' ]; then
    shift
    LABEL="${1:?retry-run.sh --exec needs a label}"
    shift
    [ "$#" -gt 0 ] || { echo "[$LABEL] no command given" >&2; exit 1; }

    clear_stale_runtime_files "$LABEL"
    exec "$@"
fi

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

    clear_stale_runtime_files "$LABEL"

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
