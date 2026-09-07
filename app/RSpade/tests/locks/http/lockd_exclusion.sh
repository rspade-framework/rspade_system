#!/bin/bash

TEST_NAME="rsx-lockd cross-process mutual exclusion (RWL-01)"

# THE test the lock system never had: two SEPARATE processes, one lock, and a proof that the
# second cannot hold it while the first does. Everything else in this concern (semaphores,
# the reentrancy counts, the flock degradation) was testable in one process; exclusion is
# not, because a single process's reentrancy layer answers before the backend ever hears
# about the second acquire.
#
# Covered here:
#   - a second process's WRITE acquire parks, silently, for as long as the first holds;
#   - it is granted the moment the holder releases, and not before;
#   - stats/dump report the truth while it waits (writer_active + one queued writer);
#   - READ locks share, and a writer waits for the readers to drain (readers-writer
#     semantics, which the flock backend cannot express at all).
#
# Against a SCRATCH daemon on a scratch port; the supervised one is never touched.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6291; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

h.run(async function () {
    const observer = await h.connect();

    // ---- Two processes, one WRITE lock --------------------------------------------
    const first = h.spawn_holder(['--name=EXCL', '--mode=write']);
    const acquired = await first.wait_for('ACQUIRED', 5000);
    h.check('process 1 is granted the WRITE lock', acquired !== null);
    if (!acquired) return;

    const second = h.spawn_holder(['--name=EXCL', '--mode=write']);
    h.check('process 2 connected and sent its acquire',
        (await second.wait_for('REQUESTED', 5000)) !== null);

    // Waiting is silence: a full second with no answer is the observable form of "parked".
    await h.sleep(1000);
    h.check('process 2 is NOT holding the lock while process 1 does', second.saw('ACQUIRED') === false);
    h.check('process 1 has not been disturbed', first.exit_code() === null);

    const stats = await observer.request({ op: 'stats', name: 'EXCL' });
    h.check('stats reports one active writer', stats.writer_active === true);
    h.check('stats names the holding connection', typeof stats.writer_conn === 'string');
    h.check('stats reports exactly one queued writer',
        stats.writers_waiting === 1 && stats.queue_length === 1);
    h.check('stats reports no readers', stats.readers_active === 0 && stats.readers_waiting === 0);

    const dump = await observer.request({ op: 'dump' });
    const lock = dump.locks.find((entry) => entry.name === 'EXCL');
    h.check('dump shows one holder and one queued waiter',
        !!lock && lock.holders.length === 1 && lock.queue.length === 1);
    h.check('dump shows the waiter waiting with no timeout',
        !!lock && lock.queue[0].timeout === null);

    // ---- The handover --------------------------------------------------------------
    const before_release = Date.now();
    first.release();
    h.check('process 1 still held the lock at release time (held=true)',
        (await first.wait_for('RELEASED true', 5000)) !== null);

    const handover = await second.wait_for('ACQUIRED', 5000);
    h.check('process 2 is granted as soon as process 1 releases', handover !== null);
    h.check('the grant happened AFTER the release, not before',
        handover !== null && handover.at >= before_release);

    const after = await observer.request({ op: 'stats', name: 'EXCL' });
    h.check('exactly one holder at a time throughout',
        after.writer_active === true && after.queue_length === 0);

    second.release();
    await second.wait_for('RELEASED', 5000);

    // ---- Readers share, a writer drains them ---------------------------------------
    const reader_a = h.spawn_holder(['--name=SHARED', '--mode=read']);
    const reader_b = h.spawn_holder(['--name=SHARED', '--mode=read']);
    h.check('reader A granted', (await reader_a.wait_for('ACQUIRED', 5000)) !== null);
    h.check('reader B granted CONCURRENTLY (readers share)',
        (await reader_b.wait_for('ACQUIRED', 5000)) !== null);

    const shared = await observer.request({ op: 'stats', name: 'SHARED' });
    h.check('stats reports two active readers and no writer',
        shared.readers_active === 2 && shared.writer_active === false);

    const writer = h.spawn_holder(['--name=SHARED', '--mode=write']);
    await writer.wait_for('REQUESTED', 5000);
    await h.sleep(600);
    h.check('a writer waits while readers hold', writer.saw('ACQUIRED') === false);

    reader_a.release();
    await reader_a.wait_for('RELEASED', 5000);
    await h.sleep(300);
    h.check('the writer still waits while ONE reader remains', writer.saw('ACQUIRED') === false);

    reader_b.release();
    await reader_b.wait_for('RELEASED', 5000);
    h.check('the writer is granted once the last reader drains',
        (await writer.wait_for('ACQUIRED', 5000)) !== null);

    writer.release();
    await writer.wait_for('RELEASED', 5000);

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
