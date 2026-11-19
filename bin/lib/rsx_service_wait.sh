# Waiting for a supervised service, and telling "slow to start" from "cannot start" - for
# bash, sourced, never executed.
#
# Sourced by maintenance-mode.sh AFTER rsx_paths.sh (rsx_env_value) and
# rsx_image_contract.sh (rsx_image_contract_problem). The script runs under whatever image
# the container was built from, which may be older than this checkout, so this file uses
# nothing but supervisorctl (`status`, `pid`), /proc, bash /dev/tcp, grep, sed, tr and awk,
# and needs nothing from /usr/local/bin or /opt/rspade. Runner state files are optional
# evidence: an image older than them has none.
#
# SLOW IS NOT BROKEN. A service that is starting is waited for with no deadline, however
# long it takes - a first boot whose application arrives after its services, a daemon
# replaying state. The wait ends early ONLY on EVIDENCE that the service cannot start, never
# on elapsed time:
#
#   1. supervisor gave up on it: `supervisorctl status <program>` says FATAL;
#   2. the script its live process runs does not exist: the pid comes from supervisor's own
#      `supervisorctl pid <program>` and its /proc/<pid>/cmdline is read at once and trusted
#      only when that process IS the program - a retry runner for the label, or a direct
#      child of supervisord. A pid is never remembered;
#   3. its retry runner recorded a streak of the same "cannot run this" exit: a live runner's
#      state file (<state dir>/<label>.state, retry-run.sh documents it) says failing, with
#      last_exit_status 126 or 127 repeated at least 5 times (last_exit_status_repeats).
#
# An exit status of 1 is NEVER evidence: it is what a service says while the application it
# needs is still arriving (no APP_KEY yet on a first boot), the case the no-deadline wait
# exists for.
#
# Usage:
#   . "$SYSTEM_DIR/bin/lib/rsx_paths.sh"
#   . "$SYSTEM_DIR/bin/lib/rsx_image_contract.sh"
#   . "$SYSTEM_DIR/bin/lib/rsx_service_wait.sh"
#   wait_for_lockd "$IMAGE_CONTRACT_CONF" || exit 1

# The runner state directory retry-run.sh writes (its --state-dir moves it).
RSX_SERVICE_STATE_DIR_DEFAULT=/run/rspade-services

# rsx_supervisor_program <regex> : the first supervisor program whose name matches, else
# empty. Empty too when supervisorctl is absent or supervisor does not answer.
rsx_supervisor_program() {
    command -v supervisorctl >/dev/null 2>&1 || return 0
    supervisorctl status 2>/dev/null | awk '{print $1}' | grep -E "$1" | head -n1
}

# One key of a key=value state file.
rsx_service_state_value() {
    local file="$1" key="$2" line
    line="$(grep -m1 -E "^${key}=" "$file" 2>/dev/null)" || true
    printf '%s' "${line#*=}"
}

# True when <pid> is a live retry runner for <label> (the test rspade-healthcheck uses).
rsx_service_is_live_runner() {
    local pid="$1" label="$2" cmdline

    [ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 1
    cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)"
    case "$cmdline" in
        *retry-run*" ${label} "*) return 0 ;;
    esac

    return 1
}

# The interpreters whose next argument is a script file.
rsx_service_is_interpreter() {
    case "${1##*/}" in
        bash|sh|dash|node|nodejs|python|python3|php) return 0 ;;
    esac
    return 1
}

# rsx_service_missing_script <pid> <token>... : the command tokens a live process runs.
# Prints "<what it runs>|<the missing file>" and returns 0 when the command names a file that
# does not exist: an absolute command, or the script after the first interpreter. Inline code
# (bash -c, node -e, php -r, ...) names no file. A relative script resolves against the
# process's own working directory.
rsx_service_missing_script() {
    local pid="$1" token command='' script='' path
    shift

    [ "$#" -gt 0 ] || return 1
    command="$1"
    shift

    if rsx_service_is_interpreter "$command"; then
        for token in "$@"; do
            case "$token" in
                -c|-e|-r|--eval|--print|-p) return 1 ;;
                -*) continue ;;
            esac
            script="$token"
            break
        done
        [ -n "$script" ] || return 1

        case "$script" in
            /*) path="$script" ;;
            *)  path="/proc/${pid}/cwd/${script}" ;;
        esac
        if [ ! -f "$path" ]; then
            printf '%s|%s' "${command##*/} ${script}" "$script"
            return 0
        fi
        return 1
    fi

    case "$command" in
        /*)
            if [ ! -f "$command" ]; then
                printf '%s|%s' "$command" "$command"
                return 0
            fi
            ;;
    esac

    return 1
}

# rsx_service_cannot_start <supervisor program> [<state dir>]
#
#   return 0  the program CANNOT start: one line naming the cause on stdout
#   return 1  it is running, starting, or there is no evidence either way
rsx_service_cannot_start() {
    local program="$1" state_dir="${2:-$RSX_SERVICE_STATE_DIR_DEFAULT}"
    local label="${1##*:}" status_line state pid ppid parent_cmdline
    local -a tokens
    local i runner_at=-1 found file repeats last since meaning

    command -v supervisorctl >/dev/null 2>&1 || return 1

    # 1. Supervisor gave up.
    status_line="$(supervisorctl status "$program" 2>/dev/null | head -n1)" || true
    state="$(printf '%s' "$status_line" | awk '{print $2}')"
    if [ "$state" = "FATAL" ]; then
        echo "${label} cannot start: supervisor reports it FATAL."
        return 0
    fi

    # 2. The script its live process runs does not exist. supervisor's own live answer for
    # the pid, and the command line read at once - never a remembered pid.
    pid="$(supervisorctl pid "$program" 2>/dev/null | head -n1 | tr -d '[:space:]')" || true
    case "$pid" in
        ''|0|*[!0-9]*) pid='' ;;
    esac

    if [ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ]; then
        mapfile -d '' -t tokens < "/proc/$pid/cmdline" 2>/dev/null || tokens=()

        # The process IS the program when it is a retry runner for this label ...
        for i in "${!tokens[@]}"; do
            case "${tokens[$i]}" in
                *retry-run*) runner_at="$i"; break ;;
            esac
        done

        if [ "$runner_at" -ge 0 ]; then
            i=$((runner_at + 1))
            while [ "$i" -lt "${#tokens[@]}" ]; do
                case "${tokens[$i]}" in
                    --*) i=$((i + 1)) ;;
                    *)   break ;;
                esac
            done
            if [ "$i" -lt "${#tokens[@]}" ] && [ "${tokens[$i]}" = "$label" ]; then
                tokens=("${tokens[@]:$((i + 1))}")
            else
                tokens=()
            fi
        else
            # ... or a direct child of supervisord, running the program's own command.
            ppid="$(awk '{ sub(/.*\) /, ""); print $2 }' "/proc/$pid/stat" 2>/dev/null)" || ppid=''
            parent_cmdline=''
            if [ -n "$ppid" ] && [ -r "/proc/$ppid/cmdline" ]; then
                parent_cmdline="$(tr '\0' ' ' < "/proc/$ppid/cmdline" 2>/dev/null)"
            fi
            case "$parent_cmdline" in
                *supervisord*) : ;;
                *) tokens=() ;;
            esac
        fi

        if [ "${#tokens[@]}" -gt 0 ] && found="$(rsx_service_missing_script "$pid" "${tokens[@]}")"; then
            echo "${label} cannot start: its supervisor program runs ${found%%|*}, and ${found#*|} does not exist."
            return 0
        fi
    fi

    # 3. Its live runner recorded a streak of "cannot run this" exits. A runner without the
    # repeats key (an image older than it) is no evidence.
    file="${state_dir}/${label}.state"
    if [ -f "$file" ] \
        && [ "$(rsx_service_state_value "$file" status)" = "failing" ] \
        && rsx_service_is_live_runner "$(rsx_service_state_value "$file" runner_pid)" "$label"; then
        last="$(rsx_service_state_value "$file" last_exit_status)"
        repeats="$(rsx_service_state_value "$file" last_exit_status_repeats)"
        case "$repeats" in ''|*[!0-9]*) repeats=0 ;; esac

        case "$last" in
            127) meaning='command not found' ;;
            126) meaning='found but not executable' ;;
            *)   meaning='' ;;
        esac

        if [ -n "$meaning" ] && [ "$repeats" -ge 5 ]; then
            since="$(rsx_service_state_value "$file" streak_since)"
            echo "${label} cannot start: its runner reports ${repeats} consecutive exits with status ${last} (${meaning}) since ${since}."
            return 0
        fi
    fi

    return 1
}

# One ping. 0 = the daemon answered ok.
lockd_answers_ping() {
    local host="$1" port="$2" reply=''

    # bash /dev/tcp: no nc, no php, nothing to install. The redirection failure is
    # reported by the SHELL, not the command, so the whole exec has to be grouped to
    # silence it - otherwise a refused connection prints two lines per attempt and the
    # wait becomes a wall of noise instead of one status line every 5s.
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

# What disable has and has not started when the lockd wait ends without a serving daemon.
rsx_lockd_wait_stopped() {
    echo "[ERROR] $1 redis and rsx-lockd were started; php-fpm, realtime and the proxy were NOT. The maintenance flag stays up." >&2
}

# wait_for_lockd [<image contract conf> [<state dir>]]
#
# Wait, with no deadline, until rsx-lockd answers a ping on LOCK_SERVER_HOST:PORT (.env, or
# the environment). Return 0 once it does, or at once when supervisor has no rsx-lockd
# program. Return 1, saying why and what to do, when the address is incomplete or the
# daemon provably cannot start (rsx_service_cannot_start, asked after every ping that goes
# unanswered) - with the minimum-image-version sentence when the image is older than the
# checkout named by <image contract conf>.
wait_for_lockd() {
    local conf="${1:-}" state_dir="${2:-$RSX_SERVICE_STATE_DIR_DEFAULT}"
    local unit host port waited=0 cause sentence

    unit="$(rsx_supervisor_program '^rsx-lockd')"
    # No rsx-lockd on this box (a single-node install may not run one) - nothing to wait for.
    [ -n "$unit" ] || return 0

    host="$(rsx_env_value LOCK_SERVER_HOST 127.0.0.1)"
    port="$(rsx_env_value LOCK_SERVER_PORT 6210)"

    # A wait with no deadline is the documented contract; a wait against an address that
    # cannot exist is a hang. Refuse the malformed case before the first attempt.
    if [ -z "$host" ] || [ -z "$port" ]; then
        echo "[ERROR] rsx-lockd address is incomplete (host='${host}' port='${port}'); set LOCK_SERVER_HOST and LOCK_SERVER_PORT in .env" >&2
        rsx_lockd_wait_stopped "Not waiting."
        return 1
    fi

    while true; do
        # A daemon that answers is serving, whatever else is recorded about it.
        lockd_answers_ping "$host" "$port" && break

        if cause="$(rsx_service_cannot_start "$unit" "$state_dir")"; then
            echo "[ERROR] ${cause}" >&2
            rsx_lockd_wait_stopped "Stopped waiting."
            if [ -n "$conf" ] && sentence="$(rsx_image_contract_problem "$conf")"; then
                echo "  ${sentence}" >&2
            else
                echo "  Its own words: supervisorctl tail ${unit} stderr" >&2
                echo "  If this box runs its own supervisor configuration, point the program at the path this checkout ships: php artisan rsx:framework:breaking_changes" >&2
            fi
            return 1
        fi

        sleep 1
        waited=$((waited + 1))
        if [ $((waited % 5)) -eq 0 ]; then
            echo "  Waiting for rsx-lockd to answer on ${host}:${port} (${waited}s)..."
            echo "    Holding php-fpm back: a request that takes a cluster lock against a"
            echo "    daemon that is not serving waits with no deadline and no error."
        fi
    done

    [ "$waited" -gt 0 ] && echo "  rsx-lockd is serving (${waited}s)."

    return 0
}
