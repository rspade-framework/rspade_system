#!/bin/bash

TEST_NAME="rsx-lockd releases a killed holder's locks (connection is the lock)"

# The property the whole daemon exists for, and the one a clock-based lock could never
# provide: a holder that dies releases IMMEDIATELY.
#
# Under the retired redis backend a SIGKILLed holder left its key behind and the next waiter
# blocked until a 30-second lease expired - and every OTHER holder of a long critical section
# silently lost mutual exclusion at that same 30-second mark. Here the kernel reports the
# closed socket and the daemon hands the lock to the head of the queue.
#
# Asserts:
#   - a waiter parked behind a SIGKILLed holder is granted within a second, with no timeout
#     involved anywhere (it asked to wait forever);
#   - the daemon accounts for it as a dropped connection, not as a release;
#   - it applies to EVERY lock the dead process held, not just the contended one;
#   - a semaphore slot held by the dead process is freed the same way.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6292; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

h.run(async function () {
    const observer = await h.connect();

    // ---- A SIGKILLed holder frees its lock -----------------------------------------
    const doomed = h.spawn_holder(['--name=DOOMED', '--mode=write']);
    h.check('the doomed process holds the WRITE lock',
        (await doomed.wait_for('ACQUIRED', 5000)) !== null);

    const waiter = h.spawn_holder(['--name=DOOMED', '--mode=write']);
    h.check('a second process is parked behind it',
        (await waiter.wait_for('REQUESTED', 5000)) !== null);
    await h.sleep(500);
    h.check('the waiter is genuinely blocked (no answer while the holder lives)',
        waiter.saw('ACQUIRED') === false);

    const before = await observer.request({ op: 'stats', name: 'DOOMED' });
    h.check('one writer holds and one waits before the kill',
        before.writer_active === true && before.writers_waiting === 1);
    const dropped_before = before.counters.dropped_connections;

    // kill -9: no chance to release, no chance to close politely, no last words.
    const killed_at = Date.now();
    doomed.kill('SIGKILL');

    const granted = await waiter.wait_for('ACQUIRED', 5000);
    h.check('the waiter is granted after the holder is SIGKILLed', granted !== null);
    h.check('the handover took under a second (no lease expiry was involved)',
        granted !== null && (granted.at - killed_at) < 1000);
    if (granted) {
        h.note('handover latency: ' + (granted.at - killed_at) + 'ms after kill -9');
    }

    const after = await observer.request({ op: 'stats', name: 'DOOMED' });
    h.check('exactly one holder afterwards, with an empty queue',
        after.writer_active === true && after.queue_length === 0);
    h.check('the daemon accounts for it as a dropped connection',
        after.counters.dropped_connections === dropped_before + 1);

    waiter.release();
    await waiter.wait_for('RELEASED', 5000);

    // ---- Death releases EVERYTHING that connection held ----------------------------
    // One connection, several things held: two locks and a semaphore slot. Killing it must
    // free all of them, or a crash would leak whatever the test did not happen to check.
    const multi = await h.connect();
    await multi.request({ op: 'acquire', name: 'MULTI_A', mode: 'write', timeout: null });
    await multi.request({ op: 'acquire', name: 'MULTI_B', mode: 'read', timeout: null });
    const slot = await multi.request({ op: 'sem_acquire', name: 'MULTI_SEM', max_slots: 1, timeout: null });
    h.check('the multi-holder took two locks and a semaphore slot', slot.status === 'granted');

    // Destroying the socket is what a crash looks like from the daemon's side.
    multi.socket.destroy();
    await h.sleep(300);

    const a = await observer.request({ op: 'stats', name: 'MULTI_A' });
    const b = await observer.request({ op: 'stats', name: 'MULTI_B' });
    const sem = await observer.request({ op: 'stats', name: 'MULTI_SEM' });
    h.check('the dead connection\'s WRITE lock is free', a.writer_active === false);
    h.check('the dead connection\'s READ lock is free', b.readers_active === 0);
    h.check('the dead connection\'s semaphore slot is free',
        sem.semaphore === undefined || sem.semaphore.slots_used === 0);

    // And the freed semaphore slot is genuinely reusable by someone else.
    const taker = await h.connect();
    const retaken = await taker.request({ op: 'sem_acquire', name: 'MULTI_SEM', max_slots: 1, timeout: 2 });
    h.check('the freed slot can be taken by another connection', retaken.status === 'granted');
    taker.close();

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
