#!/bin/bash
# t1: php-fpm-workers.sh - the production container sizes its php-fpm pools to the machine.
#
# The script is the whole rule: a total worker count from cores, memory and swap (or the
# one the container was started with), split one share web to three Ajax with floors of
# 2 and 1, and a standby per pool. It is driven here through `plan`, which prints the
# decision for a given machine, and through `apply` against COPIES of the shipped
# production pool files - never the pool files of the box running the test.

TEST_NAME="docker/cli t1 php-fpm pool sizing"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(cd "$DIR/../../../resource/docker" && pwd)"
SCRIPT="$DOCKER_DIR/php-fpm-workers.sh"

fail() { echo "FAIL: $TEST_NAME - $*"; exit 1; }

plan_is() {
    local expected="$1"; shift
    local actual
    actual="$(bash "$SCRIPT" plan "$@")" || fail "plan $* exited non-zero"
    [ "$actual" = "$expected" ] || fail "plan $*: expected '$expected', got '$actual'"
}

# ---- 1. The formula, on machines where CPU is not the limit. -------------------------
# (memory - 1000) * 0.9 / 320 - 4, floored, never below 1.
plan_is "total=1 web=2 web_standby=1 ajax=1 ajax_standby=1"    64 2048 0
plan_is "total=4 web=2 web_standby=1 ajax=3 ajax_standby=1"    64 4096 0
plan_is "total=16 web=4 web_standby=2 ajax=12 ajax_standby=4"  64 8192 0
plan_is "total=39 web=9 web_standby=4 ajax=30 ajax_standby=4"  64 16384 0

# A box too small for the arithmetic still gets its floors.
plan_is "total=1 web=2 web_standby=1 ajax=1 ajax_standby=1"    64 512 0

# ---- 2. Swap earns at most two more workers: one per 450 MB, counting 1000 MB of it. --
plan_is "total=4 web=2 web_standby=1 ajax=3 ajax_standby=1"    64 4096 449
plan_is "total=5 web=2 web_standby=1 ajax=4 ajax_standby=2"    64 4096 450
plan_is "total=6 web=2 web_standby=1 ajax=5 ajax_standby=2"    64 4096 900
plan_is "total=6 web=2 web_standby=1 ajax=5 ajax_standby=2"    64 4096 240000

# ---- 3. Three workers a core is the other ceiling. -----------------------------------
plan_is "total=3 web=2 web_standby=1 ajax=3 ajax_standby=1"    1 8192 0
plan_is "total=6 web=2 web_standby=1 ajax=5 ajax_standby=2"    2 8192 0

# ---- 4. An explicit total replaces the calculation; the split is still derived. ------
plan_is "total=10 web=2 web_standby=1 ajax=8 ajax_standby=4"   1 1 0 10
plan_is "total=40 web=10 web_standby=4 ajax=30 ajax_standby=4" 1 1 0 40
plan_is "total=1 web=2 web_standby=1 ajax=1 ajax_standby=1"    64 65536 0 1

# ---- 5. apply writes both pools, dynamic, with the planned numbers. ------------------
POOLS="$(mktemp -d)"
trap 'rm -rf "$POOLS"' EXIT
cp "$DOCKER_DIR/php/www-prod.conf" "$POOLS/www.conf"
cp "$DOCKER_DIR/php/ajax-prod.conf" "$POOLS/ajax.conf"

OUT="$(PHP_FPM_WORKER_COUNT=10 bash "$SCRIPT" apply "$POOLS")" || fail "apply exited non-zero"
echo "$OUT" | grep -q "PHP_FPM_WORKER_COUNT: 10 worker(s) -> web 2 (1 standby), ajax 8 (4 standby)" \
    || fail "apply did not report what it chose: $OUT"

value_of() { grep -E "^$2 = " "$POOLS/$1.conf" | sed 's/.* = //'; }

[ "$(value_of www pm)" = "dynamic" ]              || fail "www pm"
[ "$(value_of www pm.max_children)" = "2" ]       || fail "www max_children"
[ "$(value_of www pm.start_servers)" = "1" ]      || fail "www start_servers"
[ "$(value_of www pm.min_spare_servers)" = "1" ]  || fail "www min_spare_servers"
[ "$(value_of www pm.max_spare_servers)" = "1" ]  || fail "www max_spare_servers"
[ "$(value_of ajax pm.max_children)" = "8" ]      || fail "ajax max_children"
[ "$(value_of ajax pm.start_servers)" = "4" ]     || fail "ajax start_servers"
[ "$(value_of ajax pm.min_spare_servers)" = "4" ] || fail "ajax min_spare_servers"
[ "$(value_of ajax pm.max_spare_servers)" = "4" ] || fail "ajax max_spare_servers"

# Every key is set exactly once, and nothing else in the pool file moved.
for key in pm pm.max_children pm.start_servers pm.min_spare_servers pm.max_spare_servers; do
    [ "$(grep -cE "^${key//./\\.} = " "$POOLS/ajax.conf")" = "1" ] || fail "ajax.conf sets $key more than once"
done
[ "$(value_of ajax user)" = "www-data" ] || fail "apply changed a line it does not own"

# ---- 6. With no explicit total it measures the machine it is on. ---------------------
OUT="$(env -u PHP_FPM_WORKER_COUNT bash "$SCRIPT" apply "$POOLS")" || fail "a measured apply exited non-zero"
echo "$OUT" | grep -qE "sized from [0-9]+ cores, [0-9]+ MB memory, [0-9]+ MB swap: [0-9]+ worker" \
    || fail "a measured apply did not report its measurements: $OUT"

# ---- 7. A total that is not a whole number of 1 or more is refused, loudly. ----------
for bad in abc 0 -3 2.5; do
    if PHP_FPM_WORKER_COUNT="$bad" bash "$SCRIPT" apply "$POOLS" >/dev/null 2>"$POOLS/err"; then
        fail "PHP_FPM_WORKER_COUNT=$bad was accepted"
    fi
    grep -q "PHP_FPM_WORKER_COUNT must be a whole number" "$POOLS/err" || fail "refusing '$bad' did not say why"
done

# ---- 8. Missing pool files stop the start; they are not silently skipped. ------------
EMPTY="$(mktemp -d)"
if PHP_FPM_WORKER_COUNT=4 bash "$SCRIPT" apply "$EMPTY" >/dev/null 2>"$POOLS/err"; then
    rmdir "$EMPTY"
    fail "apply succeeded with no pool files to write"
fi
rmdir "$EMPTY"
grep -q "php-fpm pools were not sized" "$POOLS/err" || fail "a missing pool file was not named"

exit 0
