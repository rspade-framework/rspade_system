#!/bin/bash

TEST_NAME="rsx-lockd FIFO grant order"

# Request order IS grant order. One ordered queue per lock name, grants come off the head
# only, and a run of consecutive readers at the head grants as one batch.
#
# The interesting half is the NEGATIVE: nothing may bypass a queued waiter, even when the
# lock could technically be granted. A reader arriving while a writer sits at the head of
# the queue must wait behind it, or writers starve under sustained read traffic - which is
# exactly the failure mode a "grant whoever fits" scheduler produces.
#
# Determinism: each request is confirmed QUEUED (via stats) before the next one is sent, so
# the queue order under test is the order this test intended and not a race it happened to
# win.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6293; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

/** Wait until the lock's queue has reached `length`, so enqueue order is deterministic. */
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

    // ---- Grant order matches request order -----------------------------------------
    const holder = await h.connect();
    const held = await holder.request({ op: 'acquire', name: 'FIFO', mode: 'write', timeout: null });
    h.check('the head holder took the WRITE lock', held.status === 'granted');

    // Queue W1, R1, R2, W2 - one at a time, each confirmed queued before the next.
    const waiters = [
        { label: 'W1', mode: 'write' },
        { label: 'R1', mode: 'read' },
        { label: 'R2', mode: 'read' },
        { label: 'W2', mode: 'write' },
    ];

    let queued_in_order = true;
    for (let index = 0; index < waiters.length; index++) {
        const waiter = waiters[index];
        waiter.client = await h.connect();
        waiter.granted_at = null;
        waiter.promise = waiter.client
            .request({ op: 'acquire', name: 'FIFO', mode: waiter.mode, timeout: null })
            .then((frame) => {
                waiter.granted_at = Date.now();
                waiter.frame = frame;
                return frame;
            });
        if (!await wait_queue_length(observer, 'FIFO', index + 1, 3000)) {
            queued_in_order = false;
        }
    }
    h.check('all four waiters queued, one at a time, in request order', queued_in_order);

    const queue_stats = await observer.request({ op: 'stats', name: 'FIFO' });
    h.check('stats splits the queue into 2 writers and 2 readers',
        queue_stats.writers_waiting === 2 && queue_stats.readers_waiting === 2);

    await h.sleep(300);
    h.check('nobody is granted while the head holder holds',
        waiters.every((waiter) => waiter.granted_at === null));

    // Release the holder: W1 is at the head, so W1 and ONLY W1 is granted.
    await holder.request({ op: 'release', name: 'FIFO' });
    h.check('W1 (first in) is granted first',
        (await h.answer_within(waiters[0].promise, 3000)) !== null);
    await h.sleep(300);
    h.check('the readers behind W1 are NOT granted alongside it',
        waiters[1].granted_at === null && waiters[2].granted_at === null);
    h.check('W2 at the tail is not granted', waiters[3].granted_at === null);

    // Release W1: R1 and R2 are consecutive readers, so they grant as ONE batch, and W2
    // still waits behind them.
    await waiters[0].client.request({ op: 'release', name: 'FIFO' });
    h.check('R1 is granted after W1 releases',
        (await h.answer_within(waiters[1].promise, 3000)) !== null);
    h.check('R2 is granted in the SAME batch as R1',
        (await h.answer_within(waiters[2].promise, 1000)) !== null);
    h.check('the two readers were granted together (within 100ms)',
        Math.abs(waiters[1].granted_at - waiters[2].granted_at) < 100);

    const both_read = await observer.request({ op: 'stats', name: 'FIFO' });
    h.check('stats confirms two concurrent readers with one writer still queued',
        both_read.readers_active === 2 && both_read.writers_waiting === 1);
    h.check('W2 still waits behind the reader batch', waiters[3].granted_at === null);

    await waiters[1].client.request({ op: 'release', name: 'FIFO' });
    await h.sleep(200);
    h.check('W2 still waits while one reader remains', waiters[3].granted_at === null);

    await waiters[2].client.request({ op: 'release', name: 'FIFO' });
    h.check('W2 is granted last, once the reader batch drains',
        (await h.answer_within(waiters[3].promise, 3000)) !== null);

    h.check('grant order was exactly request order (W1 -> R1/R2 -> W2)',
        waiters[0].granted_at <= waiters[1].granted_at
        && waiters[1].granted_at <= waiters[3].granted_at
        && waiters[2].granted_at <= waiters[3].granted_at);

    await waiters[3].client.request({ op: 'release', name: 'FIFO' });
    for (const waiter of waiters) waiter.client.close();
    holder.close();

    // ---- Nothing bypasses a queued waiter (writer starvation) ----------------------
    const reader = await h.connect();
    await reader.request({ op: 'acquire', name: 'STARVE', mode: 'read', timeout: null });

    const writer = await h.connect();
    let writer_granted_at = null;
    const writer_promise = writer
        .request({ op: 'acquire', name: 'STARVE', mode: 'write', timeout: null })
        .then((frame) => { writer_granted_at = Date.now(); return frame; });
    h.check('the writer queues behind the active reader',
        await wait_queue_length(observer, 'STARVE', 1, 3000));

    // A second reader is COMPATIBLE with the holder, but the writer is ahead of it.
    const latecomer = await h.connect();
    let latecomer_granted_at = null;
    const latecomer_promise = latecomer
        .request({ op: 'acquire', name: 'STARVE', mode: 'read', timeout: null })
        .then((frame) => { latecomer_granted_at = Date.now(); return frame; });
    h.check('the late reader queues rather than joining the active read batch',
        await wait_queue_length(observer, 'STARVE', 2, 3000));

    await h.sleep(300);
    h.check('the late reader is NOT granted despite being compatible with the holder',
        latecomer_granted_at === null);

    await reader.request({ op: 'release', name: 'STARVE' });
    h.check('the queued WRITER is granted next, not the later reader',
        (await h.answer_within(writer_promise, 3000)) !== null);
    h.check('the late reader still waits behind the writer', latecomer_granted_at === null);

    await writer.request({ op: 'release', name: 'STARVE' });
    h.check('the late reader is granted last',
        (await h.answer_within(latecomer_promise, 3000)) !== null);
    h.check('the writer was granted before the later reader',
        writer_granted_at !== null && latecomer_granted_at !== null
        && writer_granted_at <= latecomer_granted_at);

    reader.close();
    writer.close();
    latecomer.close();
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
