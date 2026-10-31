#!/bin/bash
# t2: automatic security updates - the schedule, the off switch and the status report.
#
# The two scripts are driven without touching apt: the scheduling rule through the runner's
# `plan`, and the updater only along the paths that end before apt is called (switched off,
# an unknown mode), writing its status into a scratch directory. The apt path itself - the
# allow-list, the retry, the php-fpm reload - is verified by hand in a built image (see the
# catalog).

TEST_NAME="docker/cli t2 automatic security updates"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(cd "$DIR/../../../resource/docker" && pwd)"
RUNNER="$DOCKER_DIR/security-updates-run.sh"
UPDATER="$DOCKER_DIR/security-update.sh"

fail() { echo "FAIL: $TEST_NAME - $*"; exit 1; }

plan_is() {
    local expected="$1" now="$2" random="$3" actual
    actual="$(bash "$RUNNER" plan "$now" "$random")" || fail "plan $now $random exited non-zero"
    [ "$actual" = "$expected" ] || fail "plan $now $random: expected $expected, got $actual"
}

# ---- 1. The schedule: the next 4-hour UTC slot, within 600 s either side. --------------
# random 600 is no move; 0 is the earliest (-600 s); 1200 the latest (+600 s).
plan_is 14400 14000 600
plan_is 13800 13000 0
plan_is 15000 14000 1200
# A draw that lands at or before now takes the slot after.
plan_is 28200 14399 0
plan_is 28200 14400 0
# Draws wrap within the window.
plan_is 14400 14000 1801

# ---- 2. Switched off: nothing is called, and the status says so. -----------------------
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT

for value in 0 false NO Off; do
    rm -f "$SCRATCH/security-updates.json"
    out="$(RSX_AUTO_SECURITY_UPDATES="$value" bash "$UPDATER" startup "$SCRATCH")" \
        || fail "switched off ($value): exited non-zero"
    printf '%s' "$out" | grep -q 'are off' || fail "switched off ($value): did not say so: $out"
    php -r '$s = json_decode(file_get_contents($argv[1]), true); exit(($s["result"] ?? "") === "disabled" && $s["mode"] === "startup" ? 0 : 1);' \
        "$SCRATCH/security-updates.json" || fail "switched off ($value): status is not 'disabled': $(cat "$SCRATCH/security-updates.json" 2>/dev/null)"
done

# ---- 3. An unknown mode does nothing and never fails a caller. -------------------------
rm -f "$SCRATCH/security-updates.json"
bash "$UPDATER" sometimes "$SCRATCH" >/dev/null || fail "an unknown mode exited non-zero"
[ ! -e "$SCRATCH/security-updates.json" ] || fail "an unknown mode wrote a status"

# ---- 4. The off switch idles the runner rather than exiting (supervisor would restart it).
grep -q 'exec sleep infinity' "$RUNNER" || fail "the runner no longer idles when switched off"

echo "PASS: $TEST_NAME"
exit 0
