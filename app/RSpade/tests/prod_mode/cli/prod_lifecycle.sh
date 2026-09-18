#!/bin/bash
#
# prod_mode/cli - the production lifecycle, end to end, on this box.
#
# Everything else in this concern is a unit: a predicate asked in isolation, with the
# mode forced through a test seam and the build root pointed at a scratch directory.
# This script is the other half, and it is the only place the real contract can be
# observed at all:
#
#   1. rsx:prod:enable produces a SEALED build and the site serves from it - the page
#      and the compiled bundle it names both answer 200.
#   2. A sealed box WRITES NOTHING under build/, system/ or rsx/ while it serves, runs
#      rsx:health (which reports the seal intact), and runs migrate. `find -newer` over the three trees is the proof,
#      and it is the assertion the downstream field report that started this epic was
#      about.
#   3. The refusals hold: rsx:build and rsx:clean both demand --force.
#   4. Remove the seal and the box STOPS - a web request answers 500 and rsx:health
#      exits non-zero naming the remedy - rather than quietly rebuilding itself.
#   5. rsx:build --force reseals and the site serves again.
#   6. rsx:prod:disable returns the box to development, and a real page renders.
#
# THE BOX ENDS IN DEVELOPMENT MODE WITH A WORKING BUILD. The EXIT trap reads RSX_MODE
# through the pre-boot resolver and runs rsx:prod:disable if anything left the box in a
# production mode - on a failed assertion, on an interrupt, on anything.
#
# It runs against the DEVELOPMENT database and the live web server, like the http tests:
# it changes no data (migrate has nothing pending), and switching the box's database
# underneath a build would prove less, not more.
#
# Not fast: three full builds, roughly four minutes of wall clock. It is a lifecycle
# test, and the lifecycle is what takes the time.

TEST_NAME="prod_mode/cli prod lifecycle"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$DIR/../../../../../.." && pwd)"
SYSTEM_DIR="$PROJECT_ROOT/system"

RSX_PATHS_PROJECT_ROOT_DIR="$PROJECT_ROOT"
. "$SYSTEM_DIR/bin/lib/rsx_paths.sh"

BUILD_ROOT="$(rsx_build_root)"
SEAL_FILE="$BUILD_ROOT/prod_seal.json"
LOG="/tmp/rsx_prod_lifecycle_$$.log"

# The declared host. APP_URL is the single hostname source; its $HOSTNAME token
# resolves to the OS hostname exactly as Rsx_App_Url resolves it at boot. Requests go
# to the loopback web server carrying that Host header, so the hostname guard sees the
# host it declared whatever mode the box is in.
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

# Run an artisan command, tee its FULL output into the log, and echo it.
# Never truncated: on migrate in particular the snapshot narrative IS the diagnostic.
artisan() {
    echo "### php artisan $*" >> "$LOG"
    php artisan "$@" > /tmp/rsx_prod_step_$$.out 2>&1
    local rc=$?
    cat /tmp/rsx_prod_step_$$.out >> "$LOG"
    cat /tmp/rsx_prod_step_$$.out
    rm -f /tmp/rsx_prod_step_$$.out
    return $rc
}

# HTTP status of one path, through the loopback server with the declared Host.
http_status() {
    curl -s -o /tmp/rsx_prod_body_$$.html -w '%{http_code}' -H "Host: $APP_HOST" "http://localhost$1"
}

# Restore the box no matter how this script ends.
restore_development() {
    local rc=$?

    if [ "$(current_mode)" != "development" ]; then
        echo "[TEST] restoring development mode..." >&2
        php artisan rsx:prod:disable >> "$LOG" 2>&1
        if [ "$(current_mode)" != "development" ]; then
            echo "[TEST] rsx:prod:disable did NOT restore development mode. Log: $LOG" >&2
            echo "[TEST] Run it yourself: php artisan rsx:prod:disable" >&2
        fi
    fi

    rm -f /tmp/rsx_prod_body_$$.html /tmp/rsx_prod_step_$$.out
    exit $rc
}

trap restore_development EXIT

: > "$LOG"

# ---------------------------------------------------------------------------
# 0. Precondition: a development box.
# ---------------------------------------------------------------------------

if [ "$(current_mode)" != "development" ]; then
    echo "SKIP: $TEST_NAME - this box is in $(current_mode) mode; the test starts from development"
    exit 0
fi

# ---------------------------------------------------------------------------
# 1. rsx:prod:enable produces a sealed build.
# ---------------------------------------------------------------------------

note "1. php artisan rsx:prod:enable (this builds everything - minutes, not seconds)"
enable_output="$(artisan rsx:prod:enable)" || fail "rsx:prod:enable exited non-zero"

case "$enable_output" in
    *"Sealed production build"*) ;;
    *) fail "rsx:prod:enable printed no seal summary" ;;
esac

case "$enable_output" in
    *"Build key:"*) ;;
    *) fail "the seal summary names no build key" ;;
esac

[ "$(current_mode)" = "production" ] || fail "RSX_MODE is '$(current_mode)' after rsx:prod:enable"
[ -f "$SEAL_FILE" ] || fail "no seal file at $SEAL_FILE"

# ---------------------------------------------------------------------------
# 2. The sealed box serves: the page, and the compiled bundle the page names.
# ---------------------------------------------------------------------------

note "2. the sealed build serves /login and its bundle"
status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status on a sealed production box"

bundle_path="$(grep -oE '/_compiled/[A-Za-z0-9_.-]+\.js' /tmp/rsx_prod_body_$$.html | head -1)"
[ -n "$bundle_path" ] || fail "/login named no compiled bundle - the page rendered without its assets"

status="$(http_status "$bundle_path")"
[ "$status" = "200" ] || fail "the compiled bundle $bundle_path answered $status"

# ---------------------------------------------------------------------------
# 3. A sealed box writes NOTHING under build/, system/ or rsx/.
#
# The marker is the reference mtime. Everything that follows is what a production box
# does all day - serve pages, report health, run migrations - and none of it may leave
# a file behind in a tree the seal covers or a deployed source tree.
# ---------------------------------------------------------------------------

note "3. serving + health + migrate leave the three trees untouched"
MARKER="/tmp/rsx_prod_marker_$$"
: > "$MARKER"

http_status /login > /dev/null
http_status "$bundle_path" > /dev/null
http_status / > /dev/null

# rsx:health runs on a sealed box and reports the seal intact. Its EXIT CODE is not
# asserted: the exit code sums every row, and rows unrelated to the seal - the hostname,
# the scheduler, login auto-fill - are facts about the box the script runs on, not about
# the lifecycle. Step 4 asserts the exit code, where the seal itself is the failing row.
health_output="$(artisan rsx:health)" || true
echo "$health_output" | grep -Eq 'Production Seal[[:space:]|]+OK' \
    || fail "rsx:health does not report the Production Seal row OK on a sealed box"
artisan migrate > /dev/null || fail "migrate exited non-zero on a sealed box"

written="$(find build system rsx -newer "$MARKER" -type f 2>/dev/null)"
rm -f "$MARKER"

if [ -n "$written" ]; then
    echo "$written" >> "$LOG"
    fail "a sealed box wrote files under build/, system/ or rsx/: $(echo "$written" | head -5 | tr '\n' ' ')"
fi

# ---------------------------------------------------------------------------
# 4. The refusals. Discarding or rebuilding a live build is deliberate, never incidental.
# ---------------------------------------------------------------------------

note "4. rsx:build and rsx:clean both demand --force"
build_output="$(artisan rsx:build)" && fail "rsx:build rebuilt a sealed box without --force"
case "$build_output" in
    *"--force"*) ;;
    *) fail "the rsx:build refusal does not name --force" ;;
esac

clean_output="$(artisan rsx:clean)" && fail "rsx:clean discarded a sealed build tree without --force"
case "$clean_output" in
    *"--force"*) ;;
    *) fail "the rsx:clean refusal does not name --force" ;;
esac

# ---------------------------------------------------------------------------
# 5. The seal describes what is on disk.
# ---------------------------------------------------------------------------

note "5. rsx:prod:verify"
artisan rsx:prod:verify > /dev/null || fail "rsx:prod:verify reported drift on a build it had just made"

# ---------------------------------------------------------------------------
# 6. Without its seal the box STOPS - loudly, naming the remedy.
#
# A raw rm, deliberately: the guard is a guardrail and never claimed to stop one. What
# is being proven is what the box does about it afterwards.
# ---------------------------------------------------------------------------

note "6. an unsealed production box refuses to serve and says why"
rm -f "$SEAL_FILE"

status="$(http_status /login)"
[ "$status" = "500" ] || fail "an unsealed production box answered $status to /login, not 500"

health_output="$(artisan rsx:health)" && fail "rsx:health exited 0 on an unsealed production box"
case "$health_output" in
    *unsealed*) ;;
    *) fail "the health output does not say the build is unsealed" ;;
esac
case "$health_output" in
    *"rsx:build --force"*) ;;
    *) fail "the health output does not name the command that repairs it" ;;
esac

# ---------------------------------------------------------------------------
# 7. The build is the repair.
# ---------------------------------------------------------------------------

note "7. php artisan rsx:build --force reseals"
artisan rsx:build --force > /dev/null || fail "rsx:build --force did not rebuild the unsealed box"
[ -f "$SEAL_FILE" ] || fail "rsx:build --force wrote no seal"

status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status after the rebuild"

# ---------------------------------------------------------------------------
# 8. Back to development, with assets that work.
# ---------------------------------------------------------------------------

note "8. php artisan rsx:prod:disable"
artisan rsx:prod:disable > /dev/null || fail "rsx:prod:disable exited non-zero"
[ "$(current_mode)" = "development" ] || fail "RSX_MODE is '$(current_mode)' after rsx:prod:disable"
[ -f "$SEAL_FILE" ] && fail "the seal survived rsx:prod:disable - a development box is never sealed"

debug_output="$(artisan rsx:debug /)" || fail "rsx:debug / exited non-zero after returning to development"
case "$debug_output" in
    200*) ;;
    *) fail "rsx:debug / did not render 200: $(echo "$debug_output" | head -1)" ;;
esac
case "$debug_output" in
    *"JavaScript Console Errors: None"*) ;;
    *) fail "the development page rendered with console errors" ;;
esac

rm -f "$LOG"
echo "PASS: $TEST_NAME"
