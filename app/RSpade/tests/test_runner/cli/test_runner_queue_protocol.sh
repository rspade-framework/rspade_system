#!/bin/bash

TEST_NAME="test_runner cli queue protocol"

# The orchestrator's unix-socket work queue is the ONLY thing that stands between a
# container and the class it is supposed to run, and its wire format is a contract with a
# PHP client in another language (Rsx_Test_Command::queue_request()). This asserts that
# contract directly, against a real Queue_Server on a scratch socket:
#
#   - ping is answered pre-anything, so a worker can prove the socket is serving;
#   - queue.next hands out classes in the seeded order and then answers {class:null};
#   - the holder map tracks which worker holds which class (dead-worker attribution);
#   - queue.result acknowledges {ok:true} for an empty-object, empty-array and errored
#     result alike - PHP sends all three shapes, and JSON tells {} from [] only by which
#     one the encoder chose;
#   - a results.jsonl line carries EXACTLY class/short/results/duration (+error), and
#     never worker_id: the file is merge_and_report()'s input, and a stray key there is a
#     key PHP has to be taught to ignore;
#   - an unknown method and a malformed frame are errors, not silence;
#   - an oversized frame is refused AND the server keeps serving - a worker that goes
#     wrong must not take the queue down with it.
#
# NO DOCKER, no database, no .env: this is node talking to node over a socket in a temp
# directory. It is deterministic and it runs anywhere node does.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTD_DIR="$(cd "$SCRIPT_DIR/../../../../../bin/rsx-testd" 2>/dev/null && pwd)"

if ! command -v node > /dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - node is not installed"
    exit 0
fi

if [ ! -f "$TESTD_DIR/lib/queue_server.js" ]; then
    echo "FAIL: $TEST_NAME - $TESTD_DIR/lib/queue_server.js not found"
    exit 1
fi

WORK_DIR="$(mktemp -d)"
cleanup() { rm -rf "$WORK_DIR"; }
trap cleanup EXIT

cat > "$WORK_DIR/protocol_check.js" <<'PROTOCOL_CHECK'
// Drives a real Queue_Server through the exact request shape the PHP worker uses: one
// connection per request, one line written, one line read, connection closed.
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

const testd_dir = process.argv[2];
const { Queue_Server } = require(path.join(testd_dir, 'lib', 'queue_server.js'));
const { MAX_FRAME_BYTES } = require(path.join(testd_dir, 'lib', 'protocol.js'));

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'rsx-queue-check-'));
const socket_path = path.join(dir, 'orchestrator.sock');
const results_path = path.join(dir, 'results.jsonl');

const classes = [
    { fqcn: 'A\\B\\One_Test', short: 'One_Test', requires_db_reset: true, class_matches: false },
    { fqcn: 'A\\B\\Two_Test', short: 'Two_Test', requires_db_reset: false, class_matches: false },
    { fqcn: 'A\\B\\Three_Test', short: 'Three_Test', requires_db_reset: false, class_matches: false },
];

function request(obj, raw) {
    return new Promise((resolve, reject) => {
        const socket = net.createConnection(socket_path, () => {
            socket.write(raw !== undefined ? raw : JSON.stringify(obj) + '\n');
        });
        let buffer = '';
        socket.on('data', (chunk) => {
            buffer += chunk.toString();
            const nl = buffer.indexOf('\n');
            if (nl !== -1) {
                socket.destroy();
                resolve(JSON.parse(buffer.slice(0, nl)));
            }
        });
        socket.on('error', reject);
        socket.on('close', () => {
            if (buffer.indexOf('\n') === -1) {
                reject(new Error('connection closed with no answer'));
            }
        });
    });
}

let failures = 0;
function check(name, condition, detail) {
    if (condition) {
        console.log('  ok   ' + name);
    } else {
        failures++;
        console.log('  FAIL ' + name + (detail ? ' -- ' + detail : ''));
    }
}

(async () => {
    const queue = new Queue_Server({ socket_path, classes, results_path, log: () => {} });
    await queue.start();

    const pong = await request({ id: 1, method: 'ping' });
    check('ping is answered', pong.id === 1 && pong.result === 'pong', JSON.stringify(pong));

    const n1 = await request({ id: 2, method: 'queue.next', worker_id: 1 });
    const n2 = await request({ id: 3, method: 'queue.next', worker_id: 2 });
    const n3 = await request({ id: 4, method: 'queue.next', worker_id: 1 });
    check('queue.next drains in the seeded order, carrying short + requires_db_reset',
        n1.class === 'A\\B\\One_Test' && n1.short === 'One_Test' && n1.requires_db_reset === true
        && n2.class === 'A\\B\\Two_Test' && n2.requires_db_reset === false
        && n3.class === 'A\\B\\Three_Test' && n3.id === 4,
        JSON.stringify([n1, n2, n3]));

    check('the holder map attributes each class to the worker that took it',
        queue.held_by(1).length === 2 && queue.held_by(2).length === 1);

    const drained = await request({ id: 5, method: 'queue.next', worker_id: 2 });
    check('a drained queue answers {class:null}', drained.class === null && drained.id === 5,
        JSON.stringify(drained));

    const r1 = await request({ id: 6, method: 'queue.result', worker_id: 1,
        class: 'A\\B\\One_Test', short: 'One_Test', results: {}, duration: 1.5 });
    const r2 = await request({ id: 7, method: 'queue.result', worker_id: 2,
        class: 'A\\B\\Two_Test', short: 'Two_Test', results: [], duration: 0.25 });
    const r3 = await request({ id: 8, method: 'queue.result', worker_id: 1,
        class: 'A\\B\\Three_Test', short: 'Three_Test', results: [], duration: 0.1, error: 'boom' });
    check('queue.result acknowledges {}, [] and an errored record alike',
        r1.ok === true && r2.ok === true && r3.ok === true && r3.id === 8,
        JSON.stringify([r1, r2, r3]));

    check('a reported class is no longer held by its worker',
        queue.held_by(1).length === 0 && queue.held_by(2).length === 0);

    const lines = fs.readFileSync(results_path, 'utf8').trim().split('\n');
    const records = lines.map((line) => JSON.parse(line));
    check('results.jsonl has one line per reported class', records.length === 3);
    check('no worker_id reaches results.jsonl',
        lines.every((line) => line.indexOf('worker_id') === -1), lines.join(' | '));
    check('an empty results object stays an object', lines[0].indexOf('"results":{}') !== -1, lines[0]);
    check('an empty results array stays an array', lines[1].indexOf('"results":[]') !== -1, lines[1]);
    check('error appears only on the record that sent one',
        records[0].error === undefined && records[1].error === undefined && records[2].error === 'boom');
    check('the record key set is exactly class/short/results/duration(+error)',
        JSON.stringify(Object.keys(records[0])) === '["class","short","results","duration"]'
        && JSON.stringify(Object.keys(records[2])) === '["class","short","results","duration","error"]',
        JSON.stringify(Object.keys(records[0])) + ' / ' + JSON.stringify(Object.keys(records[2])));

    const unknown = await request({ id: 9, method: 'queue.nope' });
    check('an unknown method is an error, not silence',
        typeof unknown.error === 'string' && unknown.id === 9, JSON.stringify(unknown));

    const malformed = await request(null, '{not json}\n');
    check('a malformed frame is an error', typeof malformed.error === 'string', JSON.stringify(malformed));

    // No newline and past the cap: the server must refuse rather than buffer forever.
    const oversized = '{"id":10,"method":"ping","pad":"' + 'x'.repeat(MAX_FRAME_BYTES + 1024) + '"';
    const refused = await request(null, oversized);
    check('an oversized frame is refused',
        typeof refused.error === 'string' && /exceed/i.test(refused.error), JSON.stringify(refused));

    const pong_after = await request({ id: 11, method: 'ping' });
    check('the server still serves after a refused frame', pong_after.result === 'pong');

    await queue.close();
    check('the socket file is removed on close', !fs.existsSync(socket_path));

    fs.rmSync(dir, { recursive: true, force: true });
    process.exit(failures === 0 ? 0 : 1);
})().catch((err) => {
    console.log('  FAIL harness threw: ' + err.stack);
    process.exit(1);
});
PROTOCOL_CHECK

node "$WORK_DIR/protocol_check.js" "$TESTD_DIR"
STATUS=$?

if [ "$STATUS" -eq 0 ]; then
    echo "PASS: $TEST_NAME"
    exit 0
fi

echo "FAIL: $TEST_NAME"
exit 1
