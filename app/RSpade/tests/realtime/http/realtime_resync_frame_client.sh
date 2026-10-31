#!/bin/bash
set -e

TEST_NAME="Realtime Resync Frame Client"

# The relay sends {type:'resync'} when Redis lost realtime data (realtime_redis_loss_resync.sh
# proves when). The client answers by refetching every watch exactly once, as a resync
# (meta.resync === true), after a random spread delay; a second frame while one is pending is
# absorbed into it. Driven in a real page: the frames are fed to the client's own message
# handler, with the spread set to 0 so the refetch is immediate.
#
# Requires the relay to be running (supervisor [program:realtime]).

cd /var/www/html/system

if ! grep -q '^REALTIME_ENABLED=true' .env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi

read -r -d '' EVAL <<'JS' || true
const results = {};

Rsx_Realtime._anchored = true;
await Rsx_Realtime._connect();
for (let i = 0; i < 40 && Rsx_Realtime._connection_state !== 'connected'; i++) await sleep(250);
results.setup_state = Rsx_Realtime._connection_state;

const counts = {a: 0, b: 0, a_live: 0};
await Rsx_Realtime.watch('Model_Changed_Topic', {model: 'Client', id: 91}, (data, meta) => {
    if (meta.resync) counts.a++; else counts.a_live++;
});
await Rsx_Realtime.watch('Model_Changed_Topic', {model: 'Client', id: 92}, (data, meta) => {
    if (meta.resync) counts.b++;
});
for (let i = 0; i < 40 && (counts.a < 1 || counts.b < 1); i++) await sleep(250);
results.setup_a = counts.a;
results.setup_b = counts.b;

// Two frames back to back: one refetch per watch.
Rsx_Realtime._resync_spread_ms = 0;
Rsx_Realtime._on_message({type: 'resync'});
Rsx_Realtime._on_message({type: 'resync'});
await sleep(1000);
results.after_a = counts.a;
results.after_b = counts.b;
results.after_live = counts.a_live;
results.timer_cleared = Rsx_Realtime._resync_timer === null;

// A frame waits for its spread: pending until the delay passes.
Rsx_Realtime._resync_spread_ms = 1500;
Rsx_Realtime._on_message({type: 'resync'});
results.pending_before = Rsx_Realtime._resync_timer !== null && counts.a === results.after_a;
for (let i = 0; i < 40 && Rsx_Realtime._resync_timer !== null; i++) await sleep(250);
results.spread_a = counts.a;

return JSON.stringify(results);
JS

result="$(php artisan rsx:debug /_sys --user=1 --eval="$EVAL" 2>&1 | grep -o '{"setup_state":[^}]*}')"

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - no result returned from the harness"
    exit 1
fi

fail() {
    echo "FAIL: $TEST_NAME - $1: $result"
    exit 1
}

echo "$result" | grep -q '"setup_state":"connected"' || fail "the page never reached connected before the test began"
echo "$result" | grep -q '"setup_a":1,"setup_b":1'    || fail "the two watches did not each establish once"
echo "$result" | grep -q '"after_a":2,"after_b":2'    || fail "two resync frames did not refetch each watch exactly once"
echo "$result" | grep -q '"after_live":0'             || fail "a resync reached a callback as a live message"
echo "$result" | grep -q '"timer_cleared":true'       || fail "the resync timer was left pending"
echo "$result" | grep -q '"pending_before":true'      || fail "a resync did not wait for its spread delay"
echo "$result" | grep -q '"spread_a":3'               || fail "the delayed resync did not refetch once the delay passed"

echo "PASS: $TEST_NAME"
exit 0
