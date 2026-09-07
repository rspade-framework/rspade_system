#!/bin/bash
set -e

TEST_NAME="Realtime Delayed Offline Announcement"

# The client used to tell the whole app 'disconnected' the instant a socket closed, so a
# 300ms blip - a relay restart, a wifi handoff - flashed an offline state at the user. Now
# an unintentional close announces 'reconnecting' IMMEDIATELY (something is happening) and
# 'disconnected' only if no reconnect has landed within OFFLINE_ANNOUNCE_GRACE_MS.
#
# Two assertions, both read from a real on_state_change listener:
#   BRIEF      a drop that reconnects inside the window never emits 'disconnected' at all,
#              and does emit 'reconnecting' at once.
#   SUSTAINED  an outage that outlasts the window does emit it - after the window, not before.
#
# Internal state is NOT delayed: _connected/_authenticated/_ws go false the moment the
# socket closes, which is asserted directly.

cd /var/www/html/system

if ! grep -q '^REALTIME_ENABLED=true' .env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi

read -r -d '' EVAL <<'JS' || true
const results = {};

Rsx_Realtime._anchored = true;   // hold the connection; this test is about drops, not idling
await Rsx_Realtime._connect();
for (let i = 0; i < 40 && Rsx_Realtime._state !== 'connected'; i++) await sleep(250);
results.setup_state = Rsx_Realtime._state;

const seen = [];
const unsub = Rsx_Realtime.on_state_change((state) => seen.push(state));
seen.length = 0;   // drop the immediate current-state call

// --- BRIEF: close, let the ordinary 1s backoff reconnect land well inside the 5s window.
Rsx_Realtime._ws.close();
await sleep(50);
results.brief_internal_connected = Rsx_Realtime._connected;      // must be false IMMEDIATELY
results.brief_first_announced = seen[0] || null;                 // must be 'reconnecting'

await sleep(6000);
results.brief_saw_disconnected = seen.includes('disconnected');   // must be false
results.brief_end_state = Rsx_Realtime._state;

// --- SUSTAINED: break the token endpoint so no reconnect can succeed.
seen.length = 0;
const real_token = Realtime_Controller.get_connection_token;
Realtime_Controller.get_connection_token = async function () {
    throw new Error('simulated outage');
};

Rsx_Realtime._ws.close();
await sleep(3000);
results.sustained_at_3s = seen.includes('disconnected');    // still inside the grace window
await sleep(4000);
results.sustained_at_7s = seen.includes('disconnected');    // window expired - announced

Realtime_Controller.get_connection_token = real_token;
for (let i = 0; i < 60 && Rsx_Realtime._state !== 'connected'; i++) await sleep(500);
results.recovered_state = Rsx_Realtime._state;

unsub();
return JSON.stringify(results);
JS

result="$(timeout 300 php artisan rsx:debug /dashboard --user=1 --timeout=120000 --eval="$EVAL" 2>&1 \
    | grep -o '{"setup_state":[^}]*}')"

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - no result returned from the harness"
    exit 1
fi

fail() {
    echo "FAIL: $TEST_NAME - $1: $result"
    exit 1
}

echo "$result" | grep -q '"setup_state":"connected"'          || fail "the page never reached connected before the test began"
echo "$result" | grep -q '"brief_internal_connected":false'   || fail "internal connection state was not updated immediately on close"
echo "$result" | grep -q '"brief_first_announced":"reconnecting"' || fail "an unintentional close did not announce 'reconnecting' immediately"
echo "$result" | grep -q '"brief_saw_disconnected":false'     || fail "a brief drop flashed 'disconnected' at listeners"
echo "$result" | grep -q '"brief_end_state":"connected"'      || fail "the brief drop did not reconnect"
echo "$result" | grep -q '"sustained_at_3s":false'            || fail "a sustained outage announced 'disconnected' before the grace window expired"
echo "$result" | grep -q '"sustained_at_7s":true'             || fail "a sustained outage never announced 'disconnected'"
echo "$result" | grep -q '"recovered_state":"connected"'      || fail "the client did not recover once the outage ended"

echo "PASS: $TEST_NAME"
exit 0
