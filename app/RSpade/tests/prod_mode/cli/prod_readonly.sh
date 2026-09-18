#!/bin/bash
#
# prod_mode/cli - a correctly configured production box is READ-ONLY, and works.
#
# The recommended production posture is that build/, system/ and rsx/ carry no write
# permission for the user the application runs as. That is only a recommendation worth
# making if the application actually runs that way, so this script puts the real trees
# into that state and exercises the things a production box does: serve a page and its
# compiled bundle, run migrate with nothing pending, run a framework task, report
# health. Then it proves the stronger property directly - that none of it WROTE
# anything into those trees, permissions or no permissions.
#
# WHO THIS TEST RUNS AS. root ignores the write bits, so on a box whose test user is
# root (this framework's development container is one) the chmod cannot refuse the
# artisan commands themselves; what it still proves there is that the bits are really
# gone, that an UNPRIVILEGED process is refused by the OS (the setpriv probe), and that
# the framework wrote nothing regardless. Run as an unprivileged user - the posture a
# real deployment has - the same script additionally exercises the refusal on every
# command it runs. Both paths are honest; only the second is complete, and the script
# says which one it took.
#
# THE TREES ARE RESTORED, exactly. Every file and directory mode under the three trees
# is recorded before the chmod and re-applied in the EXIT trap, together with
# rsx:prod:disable, on failure and on interrupt alike.

TEST_NAME="prod_mode/cli prod read-only posture"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$DIR/../../../../../.." && pwd)"
SYSTEM_DIR="$PROJECT_ROOT/system"

RSX_PATHS_PROJECT_ROOT_DIR="$PROJECT_ROOT"
. "$SYSTEM_DIR/bin/lib/rsx_paths.sh"

BUILD_ROOT="$(rsx_build_root)"
SEAL_FILE="$BUILD_ROOT/prod_seal.json"
LOG="/tmp/rsx_prod_readonly_$$.log"
MODES="/tmp/rsx_prod_readonly_modes_$$"
TREES="build system rsx"

APP_HOST="$(rsx_env_value APP_URL https://localhost)"
APP_HOST="${APP_HOST#*://}"
APP_HOST="${APP_HOST%%/*}"
if [ "$APP_HOST" = '$HOSTNAME' ]; then
    APP_HOST="$(hostname)"
fi

cd "$PROJECT_ROOT" || { echo "FAIL: $TEST_NAME - cannot enter $PROJECT_ROOT"; exit 1; }

# ---------------------------------------------------------------------------
# Harness
# ---------------------------------------------------------------------------

current_mode() { rsx_env_value RSX_MODE development; }

note() { echo "[TEST] $*" >&2; }

fail() {
    echo "" >&2
    echo "---- last command output ----" >&2
    tail -n 40 "$LOG" >&2
    echo "-----------------------------" >&2
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

artisan() {
    echo "### php artisan $*" >> "$LOG"
    php artisan "$@" > /tmp/rsx_prod_ro_step_$$.out 2>&1
    local rc=$?
    cat /tmp/rsx_prod_ro_step_$$.out >> "$LOG"
    cat /tmp/rsx_prod_ro_step_$$.out
    rm -f /tmp/rsx_prod_ro_step_$$.out
    return $rc
}

http_status() {
    curl -s -o /tmp/rsx_prod_ro_body_$$.html -w '%{http_code}' -H "Host: $APP_HOST" "http://localhost$1"
}

# Record every mode under the three trees, one "<octal> <path>" line each.
record_modes() {
    find $TREES \( -type f -o -type d \) -printf '%m %p\n' > "$MODES" 2>/dev/null
}

# Re-apply them, one chmod per DISTINCT mode rather than one per file.
restore_modes() {
    [ -s "$MODES" ] || return 0

    local mode
    for mode in $(awk '{print $1}' "$MODES" | sort -u); do
        awk -v m="$mode" '$1 == m { sub(/^[0-7]+ /, ""); print }' "$MODES" \
            | tr '\n' '\0' | xargs -0 --no-run-if-empty chmod "$mode"
    done
}

cleanup() {
    local rc=$?

    restore_modes
    rm -f "$MODES" /tmp/rsx_prod_ro_body_$$.html /tmp/rsx_prod_ro_step_$$.out /tmp/rsx_prod_ro_probe_$$

    if [ "$(current_mode)" != "development" ]; then
        echo "[TEST] restoring development mode..." >&2
        php artisan rsx:prod:disable >> "$LOG" 2>&1
        if [ "$(current_mode)" != "development" ]; then
            echo "[TEST] rsx:prod:disable did NOT restore development mode. Log: $LOG" >&2
            echo "[TEST] Run it yourself: php artisan rsx:prod:disable" >&2
        fi
    fi

    exit $rc
}

trap cleanup EXIT

: > "$LOG"

# ---------------------------------------------------------------------------
# 0. Preconditions.
# ---------------------------------------------------------------------------

if [ "$(current_mode)" != "development" ]; then
    echo "SKIP: $TEST_NAME - this box is in $(current_mode) mode; the test starts from development"
    exit 0
fi

RUNNING_AS_ROOT=false
[ "$(id -u)" = "0" ] && RUNNING_AS_ROOT=true

if [ "$RUNNING_AS_ROOT" = true ]; then
    note "running as root: the write bits cannot refuse this process, so the OS refusal is proven by an unprivileged probe"
else
    note "running as uid $(id -u): every command below faces the read-only trees itself"
fi

# ---------------------------------------------------------------------------
# 1. A sealed build to serve.
# ---------------------------------------------------------------------------

note "1. php artisan rsx:prod:enable (minutes, not seconds)"
artisan rsx:prod:enable > /dev/null || fail "rsx:prod:enable exited non-zero"
[ -f "$SEAL_FILE" ] || fail "no seal file at $SEAL_FILE"

status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status before the trees were made read-only"

bundle_path="$(grep -oE '/_compiled/[A-Za-z0-9_.-]+\.js' /tmp/rsx_prod_ro_body_$$.html | head -1)"
[ -n "$bundle_path" ] || fail "/login named no compiled bundle"

# ---------------------------------------------------------------------------
# 2. Take every write bit away.
# ---------------------------------------------------------------------------

note "2. chmod -R a-w build system rsx"
record_modes
[ -s "$MODES" ] || fail "recorded no modes - refusing to chmod a tree this script could not restore"

chmod -R a-w $TREES 2>/dev/null

for sample in "$BUILD_ROOT/prod_seal.json" "$PROJECT_ROOT/system/artisan" "$PROJECT_ROOT/rsx/main.php"; do
    [ -e "$sample" ] || continue
    [ -w "$sample" ] && [ "$RUNNING_AS_ROOT" = false ] && fail "$sample is still writable"
    case "$(stat -c %A "$sample")" in
        *w*) fail "$sample still carries a write bit: $(stat -c %A "$sample")" ;;
    esac
done

# The OS refusal itself, from a process that does not outrank the permission bits.
if [ "$RUNNING_AS_ROOT" = true ] && command -v setpriv > /dev/null 2>&1; then
    if setpriv --reuid=65534 --regid=65534 --clear-groups \
        bash -c "echo probe > '$BUILD_ROOT/rsx_readonly_probe'" 2> /tmp/rsx_prod_ro_probe_$$; then
        rm -f "$BUILD_ROOT/rsx_readonly_probe"
        fail "an unprivileged process wrote into a read-only build tree"
    fi
    note "   unprivileged write into build/ refused: $(tr -d '\n' < /tmp/rsx_prod_ro_probe_$$ | tail -c 80)"
fi

# ---------------------------------------------------------------------------
# 3. Everything a production box does, against read-only trees.
# ---------------------------------------------------------------------------

note "3. serving, migrate, a framework task and health against read-only trees"
MARKER="/tmp/rsx_prod_ro_marker_$$"
: > "$MARKER"

status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status with the trees read-only"

status="$(http_status "$bundle_path")"
[ "$status" = "200" ] || fail "the compiled bundle $bundle_path answered $status with the trees read-only"

artisan migrate > /dev/null || fail "migrate exited non-zero with the trees read-only"
artisan rsx:task:run Session_Cleanup_Service cleanup_sessions > /dev/null \
    || fail "a framework task exited non-zero with the trees read-only"
# The exit code is not asserted - rows unrelated to the trees (hostname, scheduler) are
# facts about the box - only that rsx:health ran and reported the seal intact.
health_output="$(artisan rsx:health)" || true
echo "$health_output" | grep -Eq 'Production Seal[[:space:]|]+OK' \
    || fail "rsx:health does not report the Production Seal row OK with the trees read-only"

written="$(find $TREES -newer "$MARKER" -type f 2>/dev/null)"
rm -f "$MARKER"

if [ -n "$written" ]; then
    echo "$written" >> "$LOG"
    fail "something wrote under build/, system/ or rsx/: $(echo "$written" | head -5 | tr '\n' ' ')"
fi

# ---------------------------------------------------------------------------
# 4. Give the trees back, and leave a working development box.
# ---------------------------------------------------------------------------

note "4. restoring modes and returning to development"
restore_modes
rm -f "$MODES"

for sample in "$PROJECT_ROOT/system/artisan" "$PROJECT_ROOT/rsx/main.php"; do
    [ -e "$sample" ] || continue
    [ -w "$sample" ] || fail "$sample was not restored to a writable mode"
done

artisan rsx:prod:disable > /dev/null || fail "rsx:prod:disable exited non-zero"
[ "$(current_mode)" = "development" ] || fail "RSX_MODE is '$(current_mode)' after rsx:prod:disable"

status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status after returning to development"

rm -f "$LOG"

if [ "$RUNNING_AS_ROOT" = true ]; then
    echo "PASS: $TEST_NAME (as root: OS refusal proven by the unprivileged probe)"
else
    echo "PASS: $TEST_NAME (as uid $(id -u): every command faced the read-only trees)"
fi
