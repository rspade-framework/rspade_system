#!/bin/bash
set -e

TEST_NAME="Realtime Relay Resource Caps"

# The relay is ONE shared process reachable by any token holder (anonymous public-topic
# connections included), so two resources must be bounded per connection:
#   - the size of one inbound frame (REALTIME_MAX_FRAME_BYTES, ws maxPayload): an oversized
#     frame is closed with 1009 before it is buffered and parsed;
#   - the number of distinct sub_ids one connection holds
#     (REALTIME_MAX_SUBSCRIPTIONS_PER_CONNECTION): a subscribe past the cap is refused with an
#     error frame naming its sub_id, the connection stays open, and re-subscribing a held
#     sub_id is a replace that is never counted twice. A sub_id that is not a short string is
#     refused and never echoed back.
#
# Two halves. The pure half requires the relay module (no port, no redis - main() runs only
# as a program) and asks subscribe_refusal() directly. The live half starts a PRIVATE relay
# on a scratch port against a PRIVATE redis-server on another scratch port, with the caps
# lowered through the environment, drives it over a real websocket, and stops both. The
# supervised relay and the shared redis are never touched: a second relay on the shared
# redis would DEL the live subscriber registry at boot.

RELAY="/var/www/html/system/bin/realtime-server.js"
NODE_MODULES="/var/www/html/system/node_modules"

if ! command -v node >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - node not available"
    exit 0
fi
if ! command -v redis-server >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - redis-server not available"
    exit 0
fi
if ! node -e "require('$NODE_MODULES/ws')" >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - ws module not available"
    exit 0
fi
if ! grep -q '^APP_KEY=' /var/www/html/.env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - APP_KEY not set in .env"
    exit 0
fi

WORK_DIR="$(mktemp -d /tmp/realtime_caps-XXXXXX)"
REDIS_PID=""
RELAY_PID=""

cleanup() {
    [ -n "$RELAY_PID" ] && kill "$RELAY_PID" 2>/dev/null || true
    [ -n "$REDIS_PID" ] && kill "$REDIS_PID" 2>/dev/null || true
    [ -n "$RELAY_PID" ] && wait "$RELAY_PID" 2>/dev/null || true
    [ -n "$REDIS_PID" ] && wait "$REDIS_PID" 2>/dev/null || true
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

free_port() {
    node -e "const s=require('net').createServer().listen(0,'127.0.0.1',()=>{console.log(s.address().port);s.close();})"
}

# Wait until a line appears in a log, failing the moment the process that writes it dies.
# A readiness wait on our own child, not a deadline: a child that never gets ready dies or
# is a hang to see.
wait_for_line() {
    local log="$1" needle="$2" pid="$3" what="$4"
    until grep -q "$needle" "$log" 2>/dev/null; do
        if ! kill -0 "$pid" 2>/dev/null; then
            echo "FAIL: $TEST_NAME - $what exited before it was ready:"
            cat "$log"
            exit 1
        fi
        sleep 0.1
    done
}

REDIS_PORT="$(free_port)"
WS_PORT="$(free_port)"

redis-server --port "$REDIS_PORT" --bind 127.0.0.1 --save '' --appendonly no \
    --dir "$WORK_DIR" >"$WORK_DIR/redis.log" 2>&1 &
REDIS_PID=$!
wait_for_line "$WORK_DIR/redis.log" "Ready to accept connections" "$REDIS_PID" "redis-server"

# REALTIME_PHP_ORIGIN points at a closed port so the private relay's subscriber-change notify
# never reaches this box's PHP (a refused POST is log-only in the relay).
REDIS_HOST=127.0.0.1 REDIS_PORT="$REDIS_PORT" REDIS_PASSWORD=null \
REALTIME_WS_PORT="$WS_PORT" REALTIME_PHP_ORIGIN="http://127.0.0.1:1" \
REALTIME_MAX_FRAME_BYTES=4096 REALTIME_MAX_SUBSCRIPTIONS_PER_CONNECTION=3 \
    node "$RELAY" >"$WORK_DIR/relay.log" 2>&1 &
RELAY_PID=$!
wait_for_line "$WORK_DIR/relay.log" "Connected to Redis" "$RELAY_PID" "relay"
wait_for_line "$WORK_DIR/relay.log" "listening on port" "$RELAY_PID" "relay"

cat > "$WORK_DIR/harness.js" <<'NODE'
'use strict';
const crypto = require('crypto');
const WebSocket = require(process.env.NODE_MODULES + '/ws');

// Loads .env at module scope (APP_KEY) without starting a relay.
const relay = require(process.env.RELAY_PATH);
const APP_KEY = process.env.APP_KEY;

let fails = 0;
function check(name, cond) {
    if (cond) { console.log('  ok   ' + name); }
    else { console.log('  FAIL ' + name); fails++; }
}

function mk_token(payload) {
    const json = JSON.stringify(payload);
    const sig = crypto.createHmac('sha256', APP_KEY).update(json).digest('hex');
    return Buffer.from(json).toString('base64') + '.' + sig;
}

// --- pure half ------------------------------------------------------------------------------
const held = new Map([['a', {}], ['b', {}]]);
check('a new sub_id under the cap is accepted', relay.subscribe_refusal(held, 'c', 3) === null);
check('a new sub_id at the cap is refused naming the cap',
    /at most 2 subscriptions/.test(relay.subscribe_refusal(held, 'c', 2) || ''));
check('re-subscribing a held sub_id at the cap is a replace, not refused', relay.subscribe_refusal(held, 'a', 2) === null);
check('a non-string sub_id is refused', relay.subscribe_refusal(held, 42, 3) !== null);
check('an empty sub_id is refused', relay.subscribe_refusal(held, '', 3) !== null);
check('a 1025-character sub_id is refused', relay.subscribe_refusal(held, 'x'.repeat(1025), 3) !== null);
check('a 1024-character sub_id is accepted', relay.subscribe_refusal(held, 'x'.repeat(1024), 3) === null);

// --- live half ------------------------------------------------------------------------------
const url = 'ws://127.0.0.1:' + process.env.WS_PORT;
const exp = Math.floor(Date.now() / 1000) + 60;

function oversized_frame_is_closed() {
    return new Promise((resolve) => {
        const ws = new WebSocket(url);
        ws.on('open', () => ws.send(JSON.stringify([{ type: 'auth', token: 'x'.repeat(8192) }])));
        ws.on('error', () => {});
        ws.on('close', (code) => resolve(code));
    });
}

function subscription_cap() {
    return new Promise((resolve) => {
        const ws = new WebSocket(url);
        const received = [];
        const sub_token = mk_token({ site_id: 1, topic: 'Caps_Test_Topic', filter: {}, exp });
        let step = 0;

        ws.on('open', () => {
            ws.send(JSON.stringify([{ type: 'auth', token: mk_token({ site_id: 1, user_id: null, session_id: 1, exp }) }]));
        });
        ws.on('error', () => {});
        ws.on('close', (code) => resolve({ received, closed: code }));
        ws.on('message', (raw) => {
            const msg = JSON.parse(raw.toString());
            received.push(msg);

            if (msg.type === 'auth_ok') {
                step = 1;
                ws.send(JSON.stringify(['s1', 's2', 's3', 's4'].map((sub_id) => ({ type: 'subscribe', token: sub_token, sub_id }))));
                return;
            }
            // Four answers to the four subscribes, then a held sub_id again and an oversized one.
            if (step === 1 && received.filter((m) => m.type === 'subscribed' || m.type === 'error').length === 4) {
                step = 2;
                ws.send(JSON.stringify([
                    { type: 'subscribe', token: sub_token, sub_id: 's1' },
                    { type: 'subscribe', token: sub_token, sub_id: 'y'.repeat(2000) },
                    { type: 'ping' },
                ]));
                return;
            }
            if (step === 2 && msg.type === 'pong') {
                ws.close();
            }
        });
    });
}

(async () => {
    const close_code = await oversized_frame_is_closed();
    check('a frame over REALTIME_MAX_FRAME_BYTES is closed with 1009 (message too big), got ' + close_code, close_code === 1009);

    const { received, closed } = await subscription_cap();
    const first = received.slice(1, 5);
    check('the first three subscribes are acknowledged',
        first.filter((m) => m.type === 'subscribed').map((m) => m.sub_id).join(',') === 's1,s2,s3');
    const refusal = first.find((m) => m.type === 'error');
    check('the fourth is refused by an error frame naming its sub_id', !!refusal && refusal.sub_id === 's4');
    check('the refusal names the cap', !!refusal && /at most 3 subscriptions per connection/.test(refusal.message));
    const later = received.slice(5);
    check('re-subscribing a held sub_id at the cap is acknowledged (a replace)',
        later.some((m) => m.type === 'subscribed' && m.sub_id === 's1'));
    const long_refusal = later.find((m) => m.type === 'error');
    check('an oversized sub_id is refused and not echoed back', !!long_refusal && long_refusal.sub_id === null);
    check('the connection stays open after the refusals (the ping is answered)', later.some((m) => m.type === 'pong'));
    check('the connection was closed by the client, normally (1000/1005), got ' + closed, closed === 1000 || closed === 1005);

    if (fails > 0) { console.log('RESULT: FAIL (' + fails + ' assertion(s))'); process.exit(1); }
    console.log('RESULT: PASS');
    process.exit(0);
})();
NODE

set +e
output="$(RELAY_PATH="$RELAY" NODE_MODULES="$NODE_MODULES" WS_PORT="$WS_PORT" node "$WORK_DIR/harness.js" 2>&1)"
status=$?
set -e

echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "--- relay log ---"
    cat "$WORK_DIR/relay.log"
    echo "FAIL: $TEST_NAME"
    exit 1
fi

if ! grep -q "over 4096 bytes" "$WORK_DIR/relay.log"; then
    echo "--- relay log ---"
    cat "$WORK_DIR/relay.log"
    echo "FAIL: $TEST_NAME - the relay did not log the oversized frame"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
