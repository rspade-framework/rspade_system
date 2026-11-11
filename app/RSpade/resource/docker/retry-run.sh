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
# genuinely broken service costs one log line a minute. A run that lasts MAX_DELAY or longer
# was not part of a crash loop: the moment it has run that long the failure streak starts
# over, and the next restart waits MIN_DELAY, so a long-lived service that crashes once comes
# straight back.
#
# A FAILING SERVICE IS VISIBLE. Because the runner retries forever, supervisor shows the
# program RUNNING however often the service itself exits - the runner is supervisor's process.
# So the runner says so itself, in two places:
#
#   - THE STATE FILE, <state dir>/<label>.state (/run/rspade-services, world-readable),
#     rewritten at every change by a write to a temporary file and a rename, so a reader never
#     sees half of one. key=value lines:
#
#         label=<label>
#         status=running|starting|failing|stopped
#         consecutive_failures=<exits in the current streak>
#         last_exit_status=<status of the last exit; empty before the first>
#         streak_since=<UTC time of the streak's first exit; empty with no streak>
#         started_at=<UTC time the current run was launched>
#         updated_at=<UTC time of this write>
#         pid=<the service's pid; empty while it is not running>
#         runner_pid=<this runner's pid>
#
#     running  = launched with no failure streak, or has run MAX_DELAY since it was launched;
#     starting = restarting within a streak shorter than FAILING_AFTER exits;
#     failing  = FAILING_AFTER (5) or more consecutive exits, until a run lasts MAX_DELAY;
#     stopped  = a stop signal ended the runner.
#     Every exit counts, a clean one too: a service exists to keep running. A file whose
#     runner_pid is not a live runner for that label is left over from an earlier runner (a
#     container restart, a SIGKILL) and means nothing. Readers: rspade-healthcheck
#     (healthcheck.sh) and the rsx:health row "Supervised Services".
#
#   - ONE [ERROR] LINE per milestone of a streak, not per attempt:
#         [ERROR] [<label>] failing: <N> consecutive exits (last status <S>) since <T>
#     at FAILING_AFTER exits and at each doubling after it (5, 10, 20, 40, ...) - the
#     per-attempt line already says each exit; this one says it has become an outage.
#
# The 5 is a COUNT of exits, not a time: with the waits above it is about half a minute.
# `--state-dir=<dir>` before the label moves the state directory (the test suite points it at
# a scratch directory); RSPADE_RETRY_MIN_DELAY / RSPADE_RETRY_MAX_DELAY change the waits.
# Needs bash 5.1 or later (`wait -n -p`).
#
# SIGNALS. The wrapper is supervisor's main pid, so every signal supervisor sends reaches it,
# and it passes each one on to the service and waits for the service to exit - so a graceful
# stop (a proxy draining, a daemon finishing its tick) is waited for, and supervisor's
# stopwaitsecs applies to the service itself. After a stop signal (TERM, INT, QUIT) the
# wrapper writes status=stopped, exits with the service and restarts nothing. HUP, USR1 and
# USR2 are passed on and change nothing here (a service re-reading its configuration).
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
#     retry-run.sh [--state-dir=<dir>] <label> <command> [args...]
#                                                         retry forever (the default)
#     retry-run.sh --exec <label> <command> [args...]    check, then BECOME the service:
#         no retry and no restart - for a program supervisor must restart itself, or not
#         at all (mysql: `migrate` stops it to snapshot the datadir; dockerd). Writes no
#         state file: the program is supervisor's own, and supervisor's state covers it.
#     retry-run.sh --clear-stale-pidfile <file> <pattern>   the check for one file
#
runtime_files() {
    case "$1" in
        rsx-lockd)
            # The DAEMON: `lockd.js run`, flags allowed before `run` - never a client of the
            # same script (`lockd.js exec ...`, `dump`, `log`), which can hold any pid. The
            # same identity lockd.js's own is_lockd() checks; lockd checks its configurable
            # pidfile itself too, this is the default path.
            echo '/var/run/rsx-lockd.pid|lockd\.js( --[^ ]+)* run( |$)'
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

STATE_DIR=/run/rspade-services
case "${1:-}" in
    --state-dir=*)
        STATE_DIR="${1#--state-dir=}"
        shift
        ;;
esac

LABEL="${1:?retry-run.sh needs a label}"
shift

[ "$#" -gt 0 ] || { echo "[$LABEL] no command given" >&2; exit 1; }

MIN_DELAY="${RSPADE_RETRY_MIN_DELAY:-2}"
MAX_DELAY="${RSPADE_RETRY_MAX_DELAY:-60}"
FAILING_AFTER=5
STATE_FILE="${STATE_DIR}/${LABEL}.state"

failures=0
last_exit_status=''
streak_since=''
started_at=''
state_unwritable=0

now_utc() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

# Rewrite the state file: $1 = status, $2 = the service's pid (empty when not running). A
# state directory that cannot be written is reported once and never stops the service.
write_state() {
    local tmp="${STATE_DIR}/.${LABEL}.state.$$"

    if ! (
        umask 022
        mkdir -p -m 755 "$STATE_DIR" 2>/dev/null || exit 1
        printf 'label=%s\nstatus=%s\nconsecutive_failures=%s\nlast_exit_status=%s\nstreak_since=%s\nstarted_at=%s\nupdated_at=%s\npid=%s\nrunner_pid=%s\n' \
            "$LABEL" "$1" "$failures" "$last_exit_status" "$streak_since" "$started_at" "$(now_utc)" "${2:-}" "$$" \
            > "$tmp" 2>/dev/null || exit 1
        mv -f -- "$tmp" "$STATE_FILE" 2>/dev/null || exit 1
    ); then
        rm -f -- "$tmp" 2>/dev/null
        if [ "$state_unwritable" -eq 0 ]; then
            state_unwritable=1
            echo "[$LABEL] [WARNING] cannot write ${STATE_FILE}; this service's failures are not visible to the health check."
        fi
    fi
}

# The status a run is launched (or waited for) under, from the streak so far.
streak_status() {
    if [ "$failures" -eq 0 ]; then
        echo running
    elif [ "$failures" -ge "$FAILING_AFTER" ]; then
        echo failing
    else
        echo starting
    fi
}

# True at FAILING_AFTER exits and at each doubling after it.
is_failure_milestone() {
    local quotient

    [ "$failures" -ge "$FAILING_AFTER" ] || return 1
    [ $((failures % FAILING_AFTER)) -eq 0 ] || return 1
    quotient=$((failures / FAILING_AFTER))
    [ $((quotient & (quotient - 1))) -eq 0 ]
}

child=''
timer=''
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

    clear_stale_runtime_files "$LABEL"

    ( trap - INT QUIT; exec "$@" ) &
    child=$!
    started_at="$(now_utc)"
    write_state "$(streak_status)" "$child"

    # MAX_DELAY of running ends the streak, and it is marked the moment it happens - not at
    # the next exit, which for a service that recovered may never come. The timer is a
    # measurement, not a deadline: nothing is stopped when it fires.
    sleep "$MAX_DELAY" &
    timer=$!
    settled=0

    # wait returns early (above 128) when a trapped signal arrives; the service is still
    # running then, so wait again until it has really exited and its status is its own.
    while true; do
        if [ -n "$timer" ]; then
            finished=''
            wait -n -p finished "$child" "$timer"
            waited_status=$?

            if [ "${finished:-}" = "$timer" ]; then
                timer=''
                # A timer ended by a signal (a stop to the whole process group) measured
                # nothing.
                if [ "$waited_status" -eq 0 ] && [ "$stopping" -eq 0 ]; then
                    settled=1
                    failures=0
                    streak_since=''
                    write_state running "$child"
                fi
                continue
            fi
        else
            wait "$child"
        fi
        kill -0 "$child" 2>/dev/null || break
    done

    # The service's own status, whichever wait above reaped it: bash keeps it for the pid.
    wait "$child" 2>/dev/null
    status=$?
    child=''

    if [ -n "$timer" ]; then
        kill "$timer" 2>/dev/null
        wait "$timer" 2>/dev/null
        timer=''
    fi

    if [ "$stopping" -eq 1 ]; then
        last_exit_status="$status"
        write_state stopped ''
        exit "$status"
    fi

    if [ "$settled" -eq 1 ]; then
        delay="$MIN_DELAY"
    fi

    failures=$((failures + 1))
    last_exit_status="$status"
    [ -n "$streak_since" ] || streak_since="$(now_utc)"
    write_state "$(streak_status)" ''

    if [ "$status" -eq 0 ]; then
        echo "[$LABEL] exited cleanly; restarting in ${delay}s."
    else
        echo "[$LABEL] exited with status ${status} (attempt ${attempt}); restarting in ${delay}s."
    fi

    if is_failure_milestone; then
        echo "[ERROR] [$LABEL] failing: ${failures} consecutive exits (last status ${status}) since ${streak_since}"
    fi

    # The sleep runs in the background too, so a stop signal during it is taken at once.
    sleep "$delay" &
    sleeper=$!
    wait "$sleeper" 2>/dev/null
    if [ "$stopping" -eq 1 ]; then
        kill "$sleeper" 2>/dev/null
        write_state stopped ''
        exit 0
    fi

    delay=$((delay * 2))
    [ "$delay" -gt "$MAX_DELAY" ] && delay="$MAX_DELAY"
done
