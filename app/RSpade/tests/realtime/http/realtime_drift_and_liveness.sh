#!/bin/bash
set -e

TEST_NAME="Realtime Drift And Liveness Recovery"

# Two ways a socket can be a lie, and the one recovery both use (tear down, reconnect, let
# the auth_ok resync re-establish and refetch every watch):
#
#   DRIFT     A wall-clock gap much larger than the tick cadence means the machine was
#             suspended. The socket often still reports OPEN afterwards while being a dead
#             half-open TCP connection, and anything published during the gap is gone. The
#             drift tick now forces a reconnect instead of only recording the gap.
#   STALE     The pre-existing 60-minute layer still fires THROUGH that forced reconnect:
#             the reconnect produces an auth_ok, which is where _check_stale_reconnect runs.
#   LIVENESS  An awake machine whose path died silently keeps an OPEN socket receiving
#             nothing. The client pings on the drift timer's existing cadence; an
#             unanswered ping is the same forced reconnect.
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
for (let i = 0; i < 40 && Rsx_Realtime._state !== 'connected'; i++) await sleep(250);
results.setup_state = Rsx_Realtime._state;

let resyncs = 0;
await Rsx_Realtime.watch('Model_Changed_Topic', {model: 'Client', id: 77}, (data, meta) => {
    if (meta.resync) resyncs++;
});
await sleep(1500);
results.setup_resyncs = resyncs;

const seen = [];
const unsub = Rsx_Realtime.on_state_change((state) => seen.push(state));

// --- DRIFT: a 10-minute freeze observed while the socket is held.
const socket_before = Rsx_Realtime._ws;
const sockets_before = Rsx_Realtime._sockets_opened;
Rsx_Realtime._last_tick_ts = Date.now() - 600000;
Rsx_Realtime._drift_tick();
results.drift_socket_dropped = Rsx_Realtime._ws === null;   // immediate, synchronous

await sleep(5000);
results.drift_new_sockets = Rsx_Realtime._sockets_opened - sockets_before;
results.drift_socket_replaced = !!Rsx_Realtime._ws && Rsx_Realtime._ws !== socket_before;
results.drift_state = Rsx_Realtime._state;
results.drift_resynced = resyncs > results.setup_resyncs;
results.drift_no_offline_flash = !seen.includes('disconnected');

// --- STALE: the 60-minute reload layer, reached through the forced reconnect.
let reloads = 0;
const real_reload = Rsx_Realtime._do_reload;
Rsx_Realtime._do_reload = function () { reloads++; };
Rsx_Realtime._last_activity_ts = 0;          // idle gate satisfied
Rsx_Realtime._reload_pending = false;

Rsx_Realtime._last_tick_ts = Date.now() - 3700000;   // 61+ minutes
Rsx_Realtime._drift_tick();
await sleep(18000);                                   // 500ms floor + up to 10s jitter
results.stale_reloads = reloads;
results.stale_state = Rsx_Realtime._state;
Rsx_Realtime._do_reload = real_reload;

// --- LIVENESS, healthy link: a quiet cadence sends a ping and the relay answers.
Rsx_Realtime._last_inbound_ts = Date.now() - 120000;
Rsx_Realtime._liveness_tick(Date.now());
results.liveness_ping_sent = Rsx_Realtime._liveness_ping_ts > 0;
await sleep(1500);
results.liveness_pong_seen = Rsx_Realtime._last_inbound_ts >= Rsx_Realtime._liveness_ping_ts;

// --- LIVENESS, dead link: a ping that was never answered by the next tick.
const sockets_before_dead = Rsx_Realtime._sockets_opened;
Rsx_Realtime._liveness_ping_ts = Date.now();
Rsx_Realtime._last_inbound_ts = Date.now() - 120000;
Rsx_Realtime._liveness_tick(Date.now());
await sleep(5000);
results.dead_new_sockets = Rsx_Realtime._sockets_opened - sockets_before_dead;
results.dead_state = Rsx_Realtime._state;

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

echo "$result" | grep -q '"setup_state":"connected"'        || fail "the page never reached connected before the test began"
echo "$result" | grep -q '"setup_resyncs":1'                || fail "the watch under test never established"
echo "$result" | grep -q '"drift_socket_dropped":true'      || fail "an observed suspend gap did not drop the socket"
echo "$result" | grep -q '"drift_new_sockets":1'            || fail "the drift-forced reconnect did not open exactly one replacement socket"
echo "$result" | grep -q '"drift_socket_replaced":true'     || fail "the socket was not replaced after the drift-forced reconnect"
echo "$result" | grep -q '"drift_state":"connected"'        || fail "the client did not come back connected after a drift-forced reconnect"
echo "$result" | grep -q '"drift_resynced":true'            || fail "the drift-forced reconnect did not resync the active watch"
echo "$result" | grep -q '"drift_no_offline_flash":true'    || fail "a drift-forced reconnect flashed 'disconnected' at listeners"
echo "$result" | grep -q '"stale_reloads":1'                || fail "the 60-minute stale-code reload did not fire exactly once through the forced reconnect"
echo "$result" | grep -q '"stale_state":"connected"'        || fail "the client did not come back connected after the stale-gap reconnect"
echo "$result" | grep -q '"liveness_ping_sent":true'        || fail "a quiet link did not produce a liveness ping"
echo "$result" | grep -q '"liveness_pong_seen":true'        || fail "the relay did not answer the liveness ping"
echo "$result" | grep -q '"dead_new_sockets":1'             || fail "an unanswered liveness ping did not force a reconnect"
echo "$result" | grep -q '"dead_state":"connected"'         || fail "the client did not recover after the dead-link reconnect"

echo "PASS: $TEST_NAME"
exit 0
