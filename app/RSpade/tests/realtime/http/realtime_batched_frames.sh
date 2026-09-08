#!/bin/bash
set -e

TEST_NAME="Realtime Batched Outgoing Frames"

# Proves the client -> relay wire contract, through a real browser plus the relay's own
# stats line:
#
#   - N messages produced in one tick leave as ONE frame (before batching, ten components
#     subscribing in one tick sent ten frames).
#   - A burst longer than MAX_MESSAGES_PER_FRAME (25) is SPLIT into ceil(N/25) frames, in
#     order, with no remainder dropped - the relay ends up holding all N subscriptions on
#     ONE connection.
#   - The relay logs no protocol violation for anything the client actually sends: the
#     array frame is the only shape it accepts, and the client only ever sends that shape.
#
# Requires the relay to be running (supervisor [program:realtime]); skips with a clear
# message when realtime is disabled.

cd /var/www/html/system

if ! grep -q '^REALTIME_ENABLED=true' .env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi

RELAY_LOG=/var/log/supervisor/realtime.log
log_lines_before=$(wc -l < "$RELAY_LOG")

read -r -d '' EVAL <<'JS' || true
const results = {};

Rsx_Realtime._anchored = true;   // hold the connection for the whole run (no idle close)
await Rsx_Realtime._connect();
for (let i = 0; i < 40 && Rsx_Realtime._state !== 'connected'; i++) await sleep(250);
results.setup_state = Rsx_Realtime._state;

// --- One tick, ten messages, ONE frame. (unsubscribe of a sub_id nobody holds is the
// cheapest message with no reply: the relay just deletes a key that is not there.)
let before = Rsx_Realtime._frames_sent;
for (let i = 0; i < 10; i++) {
    Rsx_Realtime._send({type: 'unsubscribe', sub_id: 'noop-' + i});
}
await sleep(600);
results.ten_in_a_tick_frames = Rsx_Realtime._frames_sent - before;

// --- 60 messages in one tick = ceil(60/25) = 3 frames.
before = Rsx_Realtime._frames_sent;
for (let i = 0; i < 60; i++) {
    Rsx_Realtime._send({type: 'unsubscribe', sub_id: 'chunk-' + i});
}
await sleep(600);
results.sixty_in_a_tick_frames = Rsx_Realtime._frames_sent - before;

// --- The page shape: 30 components each subscribing in their own on_create(), all in one
// tick. Every one of the 30 must reach the relay (the chunking must not drop a remainder).
before = Rsx_Realtime._frames_sent;
const handles = [];
for (let i = 1; i <= 30; i++) {
    handles.push(Rsx_Realtime.watch('Model_Changed_Topic', {model: 'Client', id: i}, () => {}));
}
await Promise.all(handles);
await sleep(2000);
results.thirty_watch_frames = Rsx_Realtime._frames_sent - before;
results.thirty_watches = Rsx_Realtime._watches.size;
results.thirty_sockets = Rsx_Realtime._sockets_opened;
results.thirty_state = Rsx_Realtime._state;

// Hold the subscriptions open across one relay stats tick (60s cadence) so the server's
// own view can be read from its log.
await sleep(65000);
results.final_state = Rsx_Realtime._state;
results.final_watches = Rsx_Realtime._watches.size;

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

echo "$result" | grep -q '"setup_state":"connected"'    || fail "the page never reached connected before the test began"
echo "$result" | grep -q '"ten_in_a_tick_frames":1'     || fail "ten messages produced in one tick did not leave as one frame"
echo "$result" | grep -q '"sixty_in_a_tick_frames":3'   || fail "sixty messages did not chunk into ceil(60/25) = 3 frames"
echo "$result" | grep -q '"thirty_watches":30'          || fail "not all 30 subscriptions were established"
echo "$result" | grep -q '"thirty_sockets":1'           || fail "the 30 subscriptions did not share one socket"
echo "$result" | grep -q '"final_state":"connected"'    || fail "the connection did not survive the run"

# The 30-subscription figure is bounded, not exact, and deliberately so: each subscribe
# waits on its OWN token Ajax call, and DEVELOPMENT MODE DISABLES AJAX BATCHING (see
# Ajax._call_batch / window.rsxapp.ajax_batching), so those 30 replies arrive spread over
# hundreds of ms rather than in one tick. The outbox coalesces whatever is ready together -
# about 2 messages per frame here, one frame in debug/production where the 30 token replies
# come back in a single batched response. What is EXACT is the same-tick assertion above.
frames="$(echo "$result" | grep -o '"thirty_watch_frames":[0-9]*' | cut -d: -f2)"
if [ "$frames" -gt 20 ]; then
    fail "30 same-tick subscriptions produced $frames frames - the outbox is not coalescing them"
fi

# The relay's own view: all 30 subscriptions on ONE connection, and not a single frame
# refused as a protocol violation while the client was talking.
new_log="$(tail -n +$((log_lines_before + 1)) "$RELAY_LOG")"

if echo "$new_log" | grep -q 'Protocol violation'; then
    echo "FAIL: $TEST_NAME - the relay refused a frame the client sent:"
    echo "$new_log" | grep 'Protocol violation'
    exit 1
fi

if ! echo "$new_log" | grep -q 'Connections: 1, Subscriptions: 30'; then
    echo "FAIL: $TEST_NAME - the relay never reported 1 connection holding 30 subscriptions. Relay said:"
    echo "$new_log" | grep 'Connections:' || echo "  (no stats line in the window)"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
