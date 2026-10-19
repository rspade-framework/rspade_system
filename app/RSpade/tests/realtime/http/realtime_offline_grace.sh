#!/bin/bash
set -e

TEST_NAME="Realtime Delayed Offline Announcement"

# A 300ms blip - a relay restart, a wifi handoff - must never flash an offline state at
# the user: watch_connection() reports 'disconnected' only if no reconnect has landed
# within the grace window (_OFFLINE_ANNOUNCE_GRACE_MS, 5s).
#
# Two assertions, both read from a real watch_connection() monitor:
#   BRIEF      a drop that reconnects inside the window never reports 'disconnected' at all.
#   SUSTAINED  an outage that outlasts the window does report it - after the window, not before.
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
for (let i = 0; i < 40 && Rsx_Realtime._connection_state !== 'connected'; i++) await sleep(250);
results.setup_state = Rsx_Realtime._connection_state;

const seen = [];
const monitor = Rsx_Realtime.watch_connection((state) => seen.push(state));
seen.length = 0;   // drop the synchronous 'connected'

// --- BRIEF: close, let the ordinary 1s backoff reconnect land well inside the 5s window.
Rsx_Realtime._ws.close();
await sleep(50);
results.brief_internal_connected = Rsx_Realtime._connected;      // must be false IMMEDIATELY

await sleep(6000);
results.brief_saw_disconnected = seen.includes('disconnected');   // must be false
results.brief_end_state = Rsx_Realtime._connection_state;

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
for (let i = 0; i < 60 && Rsx_Realtime._connection_state !== 'connected'; i++) await sleep(500);
results.recovered_state = Rsx_Realtime._connection_state;

monitor.stop();
return JSON.stringify(results);
JS

result="$(timeout 300 php artisan rsx:debug /_sys --user=1 --timeout=120000 --eval="$EVAL" 2>&1 \
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
echo "$result" | grep -q '"brief_saw_disconnected":false'     || fail "a brief drop flashed 'disconnected' at listeners"
echo "$result" | grep -q '"brief_end_state":"connected"'      || fail "the brief drop did not reconnect"
echo "$result" | grep -q '"sustained_at_3s":false'            || fail "a sustained outage announced 'disconnected' before the grace window expired"
echo "$result" | grep -q '"sustained_at_7s":true'             || fail "a sustained outage never announced 'disconnected'"
echo "$result" | grep -q '"recovered_state":"connected"'      || fail "the client did not recover once the outage ended"

echo "PASS: $TEST_NAME"
exit 0
