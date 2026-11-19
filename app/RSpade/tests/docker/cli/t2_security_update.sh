#!/bin/bash
# t2: automatic security updates - the schedule, the off switch and the status report.
#
# The two scripts are driven without touching apt: the scheduling rule through the runner's
# `plan`; the updater along the paths that end before apt is called (switched off, an
# unknown mode); and the scheduled php-fpm restart with apt-get, unattended-upgrade,
# dpkg-query, proxority, supervisorctl, curl and pgrep replaced by stubs on PATH that log
# each call - the hold, restart, wait, release order, the release on a failed restart and on
# a signal, and the reload when Proxority is not running. Every status goes into a scratch
# directory. The real apt path - the allow-list, the install - is verified by hand in a built
# image (see the catalog).

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
    out="$(PHP_SECURITY_UPDATES="$value" bash "$UPDATER" startup "$SCRATCH")" \
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

# ---- 5. The scheduled restart: stubs on PATH stand in for apt, Proxority and supervisor.
STUBS="$SCRATCH/stubs"
CALLS="$SCRATCH/calls"
mkdir -p "$STUBS"

# stub <name> <body>: a command that logs "<name> <args>" and then runs <body>.
stub() {
    printf '#!/bin/bash\necho "%s $*" >> "%s"\n%s\n' "$1" "$CALLS" "$2" > "$STUBS/$1"
    chmod +x "$STUBS/$1"
}

# A package list that changes once unattended-upgrade has run: php8.5-fpm 8.5.1 -> 8.5.2.
stub apt-get 'exit 0'
stub unattended-upgrade "touch '$SCRATCH/upgraded'; exit 0"
stub dpkg-query "if [ -e '$SCRATCH/upgraded' ]; then echo 'php8.5-fpm 8.5.2'; else echo 'php8.5-fpm 8.5.1'; fi"
stub pgrep 'exit 1'

# reset_run <proxority status exit> <supervisorctl exit> <curl answers after N failures>
reset_run() {
    rm -f "$CALLS" "$SCRATCH/upgraded" "$SCRATCH/curl_count" "$SCRATCH/security-updates.json"
    stub proxority "[ \"\$1\" = status ] && exit $1; exit 0"
    stub supervisorctl "exit $2"
    stub curl "n=\$(cat '$SCRATCH/curl_count' 2>/dev/null || echo 0); echo \$((n + 1)) > '$SCRATCH/curl_count'; [ \"\$n\" -ge $3 ]"
}

# The calls that matter, in order, one word each: hold, restart, probe, release, reload.
call_sequence() {
    awk '$1 == "proxority" && $2 == "hold" { print "hold" }
         $1 == "proxority" && $2 == "release" { print "release" }
         $1 == "supervisorctl" { print "restart" }
         $1 == "curl" { print "probe" }
         $1 == "pgrep" { print "reload" }' "$CALLS" | tr '\n' ' ' | sed 's/ $//'
}

run_scheduled() {
    PATH="$STUBS:$PATH" PHP_SECURITY_UPDATES=1 bash "$UPDATER" scheduled "$SCRATCH"
}

# 5a. Held: hold, restart, probe until php-fpm answers (third probe), release.
reset_run 0 0 2
out="$(run_scheduled)" || fail "held restart: exited non-zero"
[ "$(call_sequence)" = "hold restart probe probe probe release" ] \
    || fail "held restart: expected 'hold restart probe probe probe release', got '$(call_sequence)': $out"
grep -qE '^proxority hold --reason=php security update --holder-pid=[0-9]+$' "$CALLS" \
    || fail "held restart: the hold does not name a reason and a holder pid: $(grep hold "$CALLS")"
grep -q '^supervisorctl restart php-fpm$' "$CALLS" || fail "held restart: php-fpm was not restarted through supervisor"
php -r '$s = json_decode(file_get_contents($argv[1]), true); exit(($s["result"] ?? "") === "updated" ? 0 : 1);' \
    "$SCRATCH/security-updates.json" || fail "held restart: status is not 'updated': $(cat "$SCRATCH/security-updates.json" 2>/dev/null)"

# 5b. A restart that fails still releases, and nothing waits on php-fpm.
reset_run 0 1 0
out="$(run_scheduled)" || fail "failed restart: exited non-zero"
[ "$(call_sequence)" = "hold restart release" ] \
    || fail "failed restart: expected 'hold restart release', got '$(call_sequence)': $out"

# 5c. A signal while waiting for php-fpm still releases (php-fpm never answers here).
reset_run 0 0 1000000
PATH="$STUBS:$PATH" PHP_SECURITY_UPDATES=1 bash "$UPDATER" scheduled "$SCRATCH" > "$SCRATCH/signal.out" &
updater_pid=$!
# A bounded poll for the first probe: about 1 s on an idle box, so 200 s (100x) bounds it;
# reaching the bound fails the test naming the condition.
for _ in $(seq 1 2000); do
    grep -q '^curl' "$CALLS" 2>/dev/null && break
    sleep 0.1
done
grep -q '^curl' "$CALLS" 2>/dev/null || { kill "$updater_pid" 2>/dev/null; fail "signalled restart: the updater never started waiting for php-fpm"; }
grep -qE "^proxority hold .*--holder-pid=$updater_pid\$" "$CALLS" || fail "signalled restart: the holder pid is not the updater's ($updater_pid)"
kill -TERM "$updater_pid"
wait "$updater_pid"
sequence="$(call_sequence)"
[ "${sequence%% *}" = hold ] && [ "${sequence##* }" = release ] \
    || fail "signalled restart: expected a hold ... release, got '$sequence'"

# 5d. Proxority not running: php-fpm is reloaded in place, with no hold.
reset_run 1 0 0
out="$(run_scheduled)" || fail "no proxority: exited non-zero"
[ "$(call_sequence)" = "reload" ] || fail "no proxority: expected 'reload', got '$(call_sequence)': $out"

echo "PASS: $TEST_NAME"
exit 0
