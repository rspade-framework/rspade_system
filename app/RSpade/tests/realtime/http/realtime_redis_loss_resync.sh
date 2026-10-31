#!/bin/bash
set -e

TEST_NAME="Realtime Redis Loss Resync"

# Redis holds nothing durable for realtime, so its data can vanish without the relay seeing
# an error: a restart, an LRU eviction or a flush takes the subscriber registry, and a Redis
# disconnect takes the frames PHP published meanwhile. The relay notices and sends every
# connection {type:'resync'}, which makes each page refetch every watch:
#   - its epoch key missing (or another relay's)          -> resync, epoch rewritten
#   - its registry missing while subscriptions are held   -> resync, registry rewritten
#   - a Redis restart (a reconnect of its clients)        -> resync
#   - nothing lost                                        -> no resync
# The pure decision, redis_data_lost(), is asked directly too.
#
# A PRIVATE relay on a scratch port, against a PRIVATE redis-server on another, with a
# one-second tick (--tick-seconds=1) so the continuity check runs often. The supervised
# relay and the shared redis are never touched.

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

WORK_DIR="$(mktemp -d /tmp/realtime_redis_loss-XXXXXX)"
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

start_redis() {
    redis-server --port "$REDIS_PORT" --bind 127.0.0.1 --save '' --appendonly no \
        --dir "$WORK_DIR" >"$WORK_DIR/redis.log" 2>&1 &
    REDIS_PID=$!
    # The harness kills this one to simulate a restart: no job notice for it.
    disown "$REDIS_PID"
    wait_for_line "$WORK_DIR/redis.log" "Ready to accept connections" "$REDIS_PID" "redis-server"
}
start_redis
echo "$REDIS_PID" > "$WORK_DIR/redis.pid"

REDIS_HOST=127.0.0.1 REDIS_PORT="$REDIS_PORT" REDIS_PASSWORD=null \
REALTIME_WS_PORT="$WS_PORT" REALTIME_PHP_ORIGIN="http://127.0.0.1:1" \
    node "$RELAY" --tick-seconds=1 >"$WORK_DIR/relay.log" 2>&1 &
RELAY_PID=$!
wait_for_line "$WORK_DIR/relay.log" "Connected to Redis" "$RELAY_PID" "relay"
wait_for_line "$WORK_DIR/relay.log" "listening on port" "$RELAY_PID" "relay"

cat > "$WORK_DIR/harness.js" <<'NODE'
'use strict';
const crypto = require('crypto');
const { spawn } = require('child_process');
const fs = require('fs');
const WebSocket = require(process.env.NODE_MODULES + '/ws');
const { createClient } = require(process.env.NODE_MODULES + '/redis');

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

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// A bounded POLL of a condition that depends on the relay, another process: 120 s is the
// house bound, and reaching it fails the test naming the condition.
async function poll_until(what, condition) {
    const deadline = Date.now() + 120000;
    while (Date.now() < deadline) {
        if (await condition()) return true;
        await sleep(100);
    }
    console.log('  FAIL never reached: ' + what);
    fails++;
    return false;
}

// --- pure half ------------------------------------------------------------------------------
check('an intact epoch and registry is no loss', relay.redis_data_lost('e', 'e', true, 2) === false);
check('a missing epoch is a loss', relay.redis_data_lost(null, 'e', true, 0) === true);
check('another relay\'s epoch is a loss', relay.redis_data_lost('x', 'e', true, 0) === true);
check('a missing registry with members registered is a loss', relay.redis_data_lost('e', 'e', false, 3) === true);
check('a missing registry with nothing registered is no loss', relay.redis_data_lost('e', 'e', false, 0) === false);
check('the registry is not judged while a write is in flight', relay.redis_data_lost('e', 'e', false, null) === false);

// --- live half ------------------------------------------------------------------------------
const exp = Math.floor(Date.now() / 1000) + 600;
const redis_port = parseInt(process.env.REDIS_PORT, 10);

(async () => {
    const redis = createClient({ socket: { host: '127.0.0.1', port: redis_port, reconnectStrategy: () => 100 } });
    redis.on('error', () => {});
    await redis.connect();

    const frames = [];
    const ws = new WebSocket('ws://127.0.0.1:' + process.env.WS_PORT);
    ws.on('error', () => {});
    ws.on('message', (raw) => {
        const msg = JSON.parse(raw.toString());
        frames.push(msg);
        if (msg.type === 'auth_ok') {
            ws.send(JSON.stringify([{ type: 'subscribe', sub_id: 'w1', token: mk_token({ site_id: 1, topic: 'Loss_Test_Topic', filter: { id: 5 }, exp }) }]));
        }
    });
    ws.on('open', () => ws.send(JSON.stringify([{ type: 'auth', token: mk_token({ site_id: 1, user_id: null, session_id: 1, exp }) }])));

    await poll_until('the subscribe is acknowledged', () => frames.some((m) => m.type === 'subscribed'));
    await poll_until('the relay registered the subscription', async () => (await redis.exists('rsx_rt:subs')) === 1);
    const resyncs = () => frames.filter((m) => m.type === 'resync').length;

    // Nothing lost: three ticks of the relay's own cadence pass with no resync.
    await sleep(3500);
    check('with nothing lost, no resync is sent', resyncs() === 0);
    const epoch = await redis.get('rsx_rt:epoch');
    check('the relay wrote its epoch', typeof epoch === 'string' && epoch.length > 0);

    await redis.del('rsx_rt:epoch');
    await poll_until('a resync after the epoch was deleted', () => resyncs() >= 1);
    check('the epoch is written again, the same one', (await redis.get('rsx_rt:epoch')) === epoch);

    const before_registry = resyncs();
    await redis.del('rsx_rt:subs');
    await poll_until('a resync after the registry was deleted', () => resyncs() > before_registry);
    check('the registry is written again', (await redis.exists('rsx_rt:subs')) === 1);

    // A Redis restart: every key is gone and the relay's clients reconnect.
    const before_restart = resyncs();
    const pid = parseInt(fs.readFileSync(process.env.WORK_DIR + '/redis.pid', 'utf8'), 10);
    process.kill(pid, 'SIGKILL');
    const restarted = spawn('redis-server', ['--port', String(redis_port), '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--dir', process.env.WORK_DIR], { stdio: 'ignore', detached: false });
    fs.writeFileSync(process.env.WORK_DIR + '/redis.pid', String(restarted.pid));
    await poll_until('a resync after Redis restarted', () => resyncs() > before_restart);
    await poll_until('the epoch is back after the restart', async () => { try { return (await redis.get('rsx_rt:epoch')) === epoch; } catch { return false; } });
    check('the connection survived the Redis restart', ws.readyState === WebSocket.OPEN);

    ws.close();
    await redis.quit().catch(() => {});

    if (fails > 0) { console.log('RESULT: FAIL (' + fails + ' assertion(s))'); process.exit(1); }
    console.log('RESULT: PASS');
    process.exit(0);
})();
NODE

set +e
output="$(RELAY_PATH="$RELAY" NODE_MODULES="$NODE_MODULES" WS_PORT="$WS_PORT" REDIS_PORT="$REDIS_PORT" WORK_DIR="$WORK_DIR" node "$WORK_DIR/harness.js" 2>&1)"
status=$?
set -e

# The harness restarted redis under a new pid; the cleanup stops that one.
REDIS_PID="$(cat "$WORK_DIR/redis.pid")"

echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "--- relay log ---"
    cat "$WORK_DIR/relay.log"
    echo "FAIL: $TEST_NAME"
    exit 1
fi

if ! grep -q "Redis lost realtime data" "$WORK_DIR/relay.log" || ! grep -q "Reconnected to Redis" "$WORK_DIR/relay.log"; then
    echo "--- relay log ---"
    cat "$WORK_DIR/relay.log"
    echo "FAIL: $TEST_NAME - the relay did not log the loss and the reconnect"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
