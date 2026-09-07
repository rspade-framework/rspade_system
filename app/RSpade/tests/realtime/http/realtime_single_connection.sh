#!/bin/bash
set -e

TEST_NAME="Realtime Single Connection Per Tab"

# Proves the client holds exactly ONE WebSocket per tab, through a real browser
# (rsx:debug is Playwright-backed - the established seam for client-side realtime
# behavior in this concern, see HARNESS-01/STALE-01).
#
# Regression guard for the 2026-08-24 connection storm: _connect() guarded on
# Rsx_Realtime._ws, which is only assignable AFTER the connection-token round trip,
# so every subscriber arriving while that request was in flight opened a socket of
# its own. One tab with one page open made the relay report
# "Connections: 10, Subscriptions: 11" - ten authenticated sockets, nine orphaned,
# every subscription riding whichever one happened to be last.
#
# Three assertions:
#   A  page shape - 10 distinct subscriptions in ONE tick produce ONE socket
#   B  unit shape - 10 concurrent _connect() calls over a SLOWED token endpoint
#      (the race widened deliberately) produce ONE socket
#   C  own-socket guard - an orphaned socket closing leaves the live socket alone
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

// --- A: the page shape. N components each subscribing in their own on_create() all
// land in one tick; distinct filters mean distinct watch entries, so each one asks
// the client to connect.
for (let i = 1; i <= 10; i++) {
    Rsx_Realtime.watch('Model_Changed_Topic', {model: 'Client', id: i}, () => {});
}
await sleep(4000);
results.a_sockets = Rsx_Realtime._sockets_opened;
results.a_watches = Rsx_Realtime._watches.size;
results.a_state = Rsx_Realtime._state;

// --- B: the same race, widened. A slowed token endpoint holds every caller inside
// the window the old guard could not cover.
const real_token = Realtime_Controller.get_connection_token;
Realtime_Controller.get_connection_token = async function () {
    await sleep(500);
    return real_token.apply(Realtime_Controller, arguments);
};

// Orphan the live socket (drop it from _ws first, so its close handler takes the
// orphan path and touches no shared state) and reset the connect latch.
const orphan = Rsx_Realtime._ws;
Rsx_Realtime._ws = null;
orphan.close();
Rsx_Realtime._connect_promise = null;

const before_b = Rsx_Realtime._sockets_opened;
const attempts = [];
for (let i = 0; i < 10; i++) attempts.push(Rsx_Realtime._connect());
await Promise.all(attempts);
results.b_sockets = Rsx_Realtime._sockets_opened - before_b;

Realtime_Controller.get_connection_token = real_token;
await sleep(2000);
results.b_state = Rsx_Realtime._state;

// --- C: an orphan's close must never touch the live socket's state.
const first = Rsx_Realtime._ws;
Rsx_Realtime._ws = null;
await Rsx_Realtime._open_socket();
const second = Rsx_Realtime._ws;
results.c_installed_second = !!second && second !== first;
first.close();
await sleep(2500);
results.c_live_socket_survived = Rsx_Realtime._ws === second;
results.c_state = Rsx_Realtime._state;

return JSON.stringify(results);
JS

result="$(timeout 300 php artisan rsx:debug /dashboard --user=1 --eval="$EVAL" 2>&1 \
    | grep -o '{"a_sockets":[^}]*}')"

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - no result returned from the harness"
    exit 1
fi

fail() {
    echo "FAIL: $TEST_NAME - $1: $result"
    exit 1
}

echo "$result" | grep -q '"a_sockets":1,'           || fail "one tab opened more than one WebSocket for 10 same-tick subscriptions"
echo "$result" | grep -q '"a_watches":10,'          || fail "not all 10 subscriptions were established"
echo "$result" | grep -q '"a_state":"connected"'    || fail "client did not reach connected state"
echo "$result" | grep -q '"b_sockets":1,'           || fail "10 concurrent _connect() calls opened more than one socket"
echo "$result" | grep -q '"b_state":"connected"'    || fail "client did not reconnect after the concurrent burst"
echo "$result" | grep -q '"c_installed_second":true'      || fail "second socket was not installed for the orphan test"
echo "$result" | grep -q '"c_live_socket_survived":true'  || fail "an orphaned socket's close knocked out the live socket"
echo "$result" | grep -q '"c_state":"connected"}'   || fail "an orphaned socket's close disturbed the connection state"

echo "PASS: $TEST_NAME"
exit 0
