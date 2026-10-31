#!/bin/bash

TEST_NAME="rsx-lockd concurrent upgrade does not deadlock"

# upgrade drops the READ hold first and queues for WRITE at the tail - non-atomic by
# design. The property that design exists for: two readers upgrading at the same time do
# NOT deadlock. Each has already given up its read, so the first upgrader is granted as
# soon as the other's read is gone, and the second waits behind it rather than either
# being refused or both hanging (which an atomic upgrade would do).
#
# Determinism: A's upgrade is confirmed QUEUED (via stats) before B upgrades.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6296; then
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
    const a = await h.connect();
    const b = await h.connect();

    const a_read = await a.request({ op: 'acquire', name: 'UPG', mode: 'read', timeout: null });
    const b_read = await b.request({ op: 'acquire', name: 'UPG', mode: 'read', timeout: null });
    h.check('both readers hold UPG', a_read.status === 'granted' && b_read.status === 'granted');

    // A upgrades first: its read is dropped, and it waits for B's read to go.
    let a_frame = null;
    const a_promise = a.request({ op: 'upgrade', name: 'UPG', timeout: null })
        .then((frame) => { a_frame = frame; return frame; });
    h.check('A\'s upgrade queues behind B\'s read', await wait_queue_length(observer, 'UPG', 1, 3000));
    h.check('A is not granted while B still reads', a_frame === null);

    // B upgrades too - the classic deadlock shape. B's read is dropped, which frees A.
    let b_frame = null;
    const b_promise = b.request({ op: 'upgrade', name: 'UPG', timeout: null })
        .then((frame) => { b_frame = frame; return frame; });

    const a_answer = await h.answer_within(a_promise, 3000);
    h.check('A is granted WRITE once B\'s read is gone',
        a_answer !== null && a_answer.status === 'granted' && a_answer.mode === 'write');
    h.check('A\'s grant says it was an upgrade', a_answer !== null && a_answer.upgraded === true);

    await h.sleep(300);
    h.check('B is not refused as a deadlock', b_frame === null || b_frame.status !== 'deadlock');
    h.check('B waits behind A rather than being granted alongside it', b_frame === null);

    const stats = await observer.request({ op: 'stats', name: 'UPG' });
    h.check('stats: one writer active, one writer waiting',
        stats.writer_active === true && stats.writers_waiting === 1 && stats.readers_active === 0);

    await a.request({ op: 'release', name: 'UPG' });
    const b_answer = await h.answer_within(b_promise, 3000);
    h.check('B is granted WRITE when A releases',
        b_answer !== null && b_answer.status === 'granted' && b_answer.mode === 'write' && b_answer.upgraded === true);

    // An upgrade of a lock already held for WRITE is a no-op answering the same token.
    const again = await b.request({ op: 'upgrade', name: 'UPG', timeout: null });
    h.check('upgrading a held WRITE lock is a no-op grant',
        again.status === 'granted' && again.upgraded === false && again.token === b_answer.token);

    await b.request({ op: 'release', name: 'UPG' });
    a.close();
    b.close();
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
