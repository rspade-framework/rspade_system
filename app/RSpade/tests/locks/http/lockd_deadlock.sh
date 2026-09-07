#!/bin/bash

TEST_NAME="rsx-lockd deadlock detection at enqueue"

# Wait-forever is the default, so an undetected wait-for cycle is a PERMANENT hang rather
# than a timeout. The daemon therefore walks the wait-for graph before parking any waiter
# and refuses the request that would close a cycle - with the cycle spelled out, because
# "deadlock" without the parties is not a diagnostic.
#
# Asserts:
#   - the classic AB/BA cycle is refused with status "deadlock" (never granted, never parked);
#   - the cycle text names BOTH parties and both locks, host:pid included;
#   - the refusal is textually distinct from a TIMEOUT (the updater greps
#     "Failed to acquire.*lock" to classify a retryable failure - a deadlock is not one);
#   - the refused caller keeps what it already held;
#   - a QUEUED waiter counts as a blocker (FIFO forbids bypassing it), so a cycle through
#     the queue is caught too;
#   - THE CONTROL: ordinary contention that is not a cycle must PARK, not be refused. A
#     detector that cried deadlock on a busy lock would be worse than none.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6294; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

async function wait_queue_length(observer, name, length, ms) {
    const deadline = Date.now() + ms;
    while (Date.now() < deadline) {
        const stats = await observer.request({ op: 'stats', name: name });
        if (stats.queue_length === length) return true;
        await h.sleep(20);
    }
    return false;
}

h.run(async function () {
    const observer = await h.connect();

    // ---- The classic AB/BA cycle ---------------------------------------------------
    // This is the real ordering hazard in the tree: FILE_WRITE -> FILE_BLOB_DISPOSAL taken
    // in one order by an upload and the other by a delete.
    const a = await h.connect();
    const b = await h.connect();

    const a_first = await a.request({ op: 'acquire', name: 'FILE_WRITE', mode: 'write', timeout: null });
    const b_first = await b.request({ op: 'acquire', name: 'FILE_BLOB_DISPOSAL', mode: 'write', timeout: null });
    h.check('A holds FILE_WRITE and B holds FILE_BLOB_DISPOSAL',
        a_first.status === 'granted' && b_first.status === 'granted');

    // B now wants what A holds: legitimate contention, so it parks.
    let b_second_answered = false;
    b.request({ op: 'acquire', name: 'FILE_WRITE', mode: 'write', timeout: null })
        .then(() => { b_second_answered = true; });
    h.check('B parks waiting for FILE_WRITE',
        await wait_queue_length(observer, 'FILE_WRITE', 1, 3000));
    h.check('B received no frame while parked (waiting is silence)', b_second_answered === false);

    // A now wants what B holds. That closes the cycle and must be REFUSED, immediately.
    const refused = await a.request({
        op: 'acquire', name: 'FILE_BLOB_DISPOSAL', mode: 'write', timeout: null,
    });

    h.check('the cycle-closing acquire is refused with status deadlock',
        refused.status === 'deadlock');
    h.check('it names the lock it refused', refused.name === 'FILE_BLOB_DISPOSAL');
    h.check('the message says wait-for cycle',
        typeof refused.message === 'string' && /wait-for cycle/.test(refused.message));
    h.check('the refusal is NOT worded as a timeout (the updater must not retry it)',
        !/Failed to acquire.*lock/.test(refused.message || ''));

    const cycle = refused.cycle || [];
    h.check('the cycle is described hop by hop (2 hops)', cycle.length === 2);
    const cycle_text = cycle.join(' | ');
    h.note('cycle: ' + cycle_text);
    h.check('the cycle names BOTH connections',
        cycle_text.includes(a.conn_id) && cycle_text.includes(b.conn_id));
    h.check('the cycle names BOTH locks',
        cycle_text.includes('FILE_WRITE') && cycle_text.includes('FILE_BLOB_DISPOSAL'));
    h.check('the cycle identifies the parties by host:pid',
        cycle.every((hop) => /\[[^\]]+:\d+\]/.test(hop)));

    // A refusal must not cost the caller anything it already had.
    const still_held = await observer.request({ op: 'stats', name: 'FILE_WRITE' });
    h.check('the refused caller still holds the lock it already had',
        still_held.writer_active === true && still_held.writer_conn === a.conn_id);
    h.check('the refused request did NOT join the queue',
        (await observer.request({ op: 'stats', name: 'FILE_BLOB_DISPOSAL' })).queue_length === 0);

    // Unwind: A releases, B's parked request completes, nothing is stuck.
    await a.request({ op: 'release', name: 'FILE_WRITE' });
    await h.sleep(200);
    h.check('releasing unblocks the parked waiter', b_second_answered === true);

    a.close();
    b.close();

    // ---- A QUEUED waiter is a blocker too ------------------------------------------
    // C holds L; D queues for L; C then asks for what D holds. D holds nothing yet - the
    // cycle runs through D's QUEUED wait, which counts because FIFO forbids bypassing it.
    const c = await h.connect();
    const d = await h.connect();

    await c.request({ op: 'acquire', name: 'QL1', mode: 'write', timeout: null });
    await d.request({ op: 'acquire', name: 'QL2', mode: 'write', timeout: null });

    d.request({ op: 'acquire', name: 'QL1', mode: 'write', timeout: null });
    h.check('D is queued on QL1', await wait_queue_length(observer, 'QL1', 1, 3000));

    const queued_cycle = await c.request({ op: 'acquire', name: 'QL2', mode: 'write', timeout: null });
    h.check('a cycle running through a QUEUED waiter is refused',
        queued_cycle.status === 'deadlock');

    await c.request({ op: 'release', name: 'QL1' });
    await h.sleep(200);
    c.close();
    d.close();

    // ---- CONTROL: ordinary contention must PARK, not be refused --------------------
    const holder = await h.connect();
    const plain = await h.connect();

    await holder.request({ op: 'acquire', name: 'BUSY', mode: 'write', timeout: null });

    let plain_frame = null;
    const plain_promise = plain
        .request({ op: 'acquire', name: 'BUSY', mode: 'write', timeout: null })
        .then((frame) => { plain_frame = frame; return frame; });

    await h.sleep(700);
    h.check('CONTROL: a non-cycle contended acquire parks instead of being refused',
        plain_frame === null);
    h.check('CONTROL: it is in the queue, not rejected',
        (await observer.request({ op: 'stats', name: 'BUSY' })).queue_length === 1);

    await holder.request({ op: 'release', name: 'BUSY' });
    const eventually = await h.answer_within(plain_promise, 3000);
    h.check('CONTROL: it is granted normally once the holder releases',
        eventually !== null && eventually.status === 'granted');

    const counters = (await observer.request({ op: 'stats', name: 'BUSY' })).counters;
    h.check('exactly two deadlocks were counted over the whole run',
        counters.deadlocked === 2);
    h.check('no waiter ever timed out (nothing here asked for a timeout)',
        counters.timed_out === 0);

    holder.close();
    plain.close();
    observer.close();
});
NODE

output="$(node "$LOCKD_TMP/harness.js" 2>&1)"
status=$?
echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "FAIL: $TEST_NAME"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
