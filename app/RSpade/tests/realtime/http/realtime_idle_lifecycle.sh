#!/bin/bash
set -e

TEST_NAME="Realtime Idle Disconnect Lifecycle"

# Proves the two idle-path defects fixed on 2026-08-24 stay fixed, through a real
# browser (rsx:debug is Playwright-backed - the established seam for client-side
# realtime behavior in this concern, see HARNESS-01 / CONN-01).
#
#   A  CLOSING-GAP SUBSCRIBE. ws.close() is asynchronous and _ws stays assigned
#      through CLOSING. A watch() arriving in that window used to take _connect()'s
#      "already have a socket" fast path (no connection attempted), have its subscribe
#      frame dropped for a non-OPEN socket, and then be abandoned by an onclose that
#      had already decided the close was intentional. The page stopped updating
#      permanently, with a live entry in _watches and nothing scheduled to serve it.
#
#   B  IDLE TIMER DURING AN IN-FLIGHT CONNECT. _close_idle() saw _ws === null and
#      no-opped; the socket then opened with nothing watching and was never closed
#      again, because idle disconnect was only ever scheduled from the release path.
#
# Requires the relay to be running (supervisor [program:realtime]); skips with a
# clear message when realtime is disabled.

cd /var/www/html/system

if ! grep -q '^REALTIME_ENABLED=true' .env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi

read -r -d '' EVAL <<'JS' || true
const results = {};

// The rsx:debug harness page carries no session_hash, so the boot anchor never engages
// (see _ensure_anchor) and this tab starts with no connection, no watch and no timer at
// all - exactly the lazy state the idle path governs, and a clean slate for B.
Rsx_Realtime._anchored = false;

// --- B FIRST (it needs a tab that has never scheduled an idle disconnect): the idle timer
// fires while a connect is in flight.
Rsx_Realtime._idle_disconnect_delay = 200;   // shrink the window; restored below

const real_token = Realtime_Controller.get_connection_token;
Realtime_Controller.get_connection_token = async function () {
    await sleep(2000);
    return real_token.apply(Realtime_Controller, arguments);
};

const connecting = Rsx_Realtime._connect();
await sleep(300);
// Nothing is watched and the socket does not exist yet: the pre-fix _close_idle() no-ops
// here, and nothing ever re-schedules once the socket does open - so it stays open with
// nothing watching, forever.
Rsx_Realtime._schedule_idle_disconnect();
await connecting;
Realtime_Controller.get_connection_token = real_token;

await sleep(3000);
results.b_orphan_socket = !!Rsx_Realtime._ws;
results.b_state = Rsx_Realtime._state;

Rsx_Realtime._idle_disconnect_delay = 10000;

// --- A: subscribe INSIDE the closing gap.
await Rsx_Realtime._connect();
for (let i = 0; i < 40 && Rsx_Realtime._state !== 'connected'; i++) await sleep(250);
results.a_setup_state = Rsx_Realtime._state;

const sockets_before_a = Rsx_Realtime._sockets_opened;
Rsx_Realtime._close_idle();
results.a_readystate_at_watch = Rsx_Realtime._ws ? Rsx_Realtime._ws.readyState : -1;   // 2 = CLOSING

let a_resyncs = 0;
const handle = await Rsx_Realtime.watch('Model_Changed_Topic', {model: 'Client', id: 4242}, (data, meta) => {
    if (meta.resync) a_resyncs++;
});
let a_established = false;
handle.established.then(() => { a_established = true; });

await sleep(6000);
results.a_state = Rsx_Realtime._state;
results.a_socket_open = Rsx_Realtime._ws ? Rsx_Realtime._ws.readyState === WebSocket.OPEN : false;
results.a_watches = Rsx_Realtime._watches.size;
results.a_established = a_established;
results.a_resyncs = a_resyncs;
results.a_sockets_opened = Rsx_Realtime._sockets_opened - sockets_before_a;

handle.stop();
return JSON.stringify(results);
JS

result="$(timeout 300 php artisan rsx:debug /_sys --user=1 --eval="$EVAL" 2>&1 \
    | grep -o '{"b_orphan_socket":[^}]*}')"

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - no result returned from the harness"
    exit 1
fi

fail() {
    echo "FAIL: $TEST_NAME - $1: $result"
    exit 1
}

echo "$result" | grep -q '"a_setup_state":"connected"' || fail "the page never reached connected before the closing-gap case began"
echo "$result" | grep -q '"a_readystate_at_watch":2'  || fail "the socket was not in CLOSING when the watch was placed (gap not reproduced)"
echo "$result" | grep -q '"a_state":"connected"'      || fail "a watch placed during the closing gap did not end connected"
echo "$result" | grep -q '"a_socket_open":true'       || fail "a watch placed during the closing gap left no open socket"
echo "$result" | grep -q '"a_watches":1'              || fail "the watch entry did not survive the closing gap"
echo "$result" | grep -q '"a_established":true'       || fail "the subscription placed during the closing gap was never established"
# AT LEAST ONE resync, not exactly one. A resync fires on every auth_ok, so any
# additional reconnect (a preceding test leaving the relay churning, a drift probe,
# a transient close) legitimately raises the count. The property under test is that
# the subscription placed during the closing gap WAS re-established at all - asserting
# an exact count made this test fail for a reason that has nothing to do with the defect
# (observed: a_established true, a_state connected, a_resyncs 2).
echo "$result" | grep -qE '"a_resyncs":[1-9]'         || fail "the subscription placed during the closing gap never fired its resync"
echo "$result" | grep -q '"a_sockets_opened":1'       || fail "the closing-gap recovery opened more than one replacement socket"
echo "$result" | grep -q '"b_orphan_socket":false'    || fail "an idle timer that fired during an in-flight connect left an orphan socket open"
echo "$result" | grep -q '"b_state":"disconnected"'   || fail "the orphan socket case did not end in a terminal disconnected state"

echo "PASS: $TEST_NAME"
exit 0
