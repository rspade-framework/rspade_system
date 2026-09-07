#!/bin/bash

TEST_NAME="rsx-lockd holds and waits with no upper bound (the 30s lease is gone)"

# THE regression test for this whole change. The retired redis backend put a hardcoded
# 30-second lease on every lock: at t=30s the key expired, mutual exclusion silently stopped
# being true, and a second holder could be granted while the first was still inside its
# critical section. Callers who exceeded it routinely - the bundle compiler, a manifest
# build, a storage cleanup, an hours-long external sync - were all living on that fault.
#
# So: hold a lock for LONGER than the retired lease with a waiter parked behind it, and
# prove that at the far side of that window
#   - the holder is STILL the holder (its release reports held=true),
#   - the waiter is STILL waiting, with no frame ever sent to it,
#   - nothing timed out (no timer exists: the waiter asked to wait forever),
#   - and the handover happens on the RELEASE, not on any clock.
#
# The opposite direction is checked too: a caller that explicitly asks for a timeout still
# gets one, with the verbatim message the updater greps.
#
# Runs for a little over the hold window by construction (default 33s).

HOLD_SECONDS="${LOCKD_TEST_HOLD_SECONDS:-33}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6295; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

const HOLD_MS = parseInt(process.env.HOLD_SECONDS || '33', 10) * 1000;
const RETIRED_LEASE_MS = 30000;

h.run(async function () {
    const observer = await h.connect();

    h.check('the hold window is longer than the retired 30s lease', HOLD_MS > RETIRED_LEASE_MS);

    const holder = h.spawn_holder(['--name=LONGHOLD', '--mode=write']);
    const acquired = await holder.wait_for('ACQUIRED', 5000);
    h.check('the holder took the WRITE lock', acquired !== null);
    if (!acquired) return;

    const waiter = h.spawn_holder(['--name=LONGHOLD', '--mode=write']);
    h.check('a waiter is parked behind it, asking to wait forever',
        (await waiter.wait_for('REQUESTED', 5000)) !== null);

    const holding = await observer.request({ op: 'stats', name: 'LONGHOLD' });
    const original_writer = holding.writer_conn;
    h.check('one writer holds and one waits at the start',
        holding.writer_active === true && holding.writers_waiting === 1);

    // Sit on it for longer than the lease that used to exist. Sampling as we go, because
    // "it was fine at the end" would not distinguish a lock that was lost and reacquired.
    const started = Date.now();
    let sampled = 0;
    let sample_failures = 0;
    while (Date.now() - started < HOLD_MS) {
        await h.sleep(3000);
        sampled++;
        const sample = await observer.request({ op: 'stats', name: 'LONGHOLD' });
        if (sample.writer_active !== true
            || sample.writer_conn !== original_writer
            || sample.queue_length !== 1
            || sample.counters.timed_out !== 0
            || waiter.saw('ACQUIRED')) {
            sample_failures++;
        }
    }
    const held_ms = Date.now() - started;
    h.note('held for ' + Math.round(held_ms / 1000) + 's across ' + sampled + ' samples');

    h.check('the SAME connection held the lock for the whole window, with the waiter parked',
        sample_failures === 0);
    h.check('the hold really did outlast the retired lease', held_ms > RETIRED_LEASE_MS);
    h.check('the waiter received NO frame in all that time', waiter.saw('ACQUIRED') === false);
    h.check('the holder process is alive and unbothered', holder.exit_code() === null);

    const late = await observer.request({ op: 'stats', name: 'LONGHOLD' });
    h.check('nothing timed out (a wait-forever request arms no timer)',
        late.counters.timed_out === 0);
    h.check('the daemon still reports exactly one holder', late.writer_active === true);

    // The lease bug in one assertion: after 33 seconds the holder must still BE the holder.
    const released_at = Date.now();
    holder.release();
    h.check('the holder still held the lock at release time (held=true after '
        + Math.round(held_ms / 1000) + 's)',
        (await holder.wait_for('RELEASED true', 5000)) !== null);

    const granted = await waiter.wait_for('ACQUIRED', 5000);
    h.check('the waiter is granted on the RELEASE, not on any clock', granted !== null);
    h.check('the waiter had been waiting longer than the retired lease',
        granted !== null && (granted.at - started) > RETIRED_LEASE_MS);
    h.check('the grant followed the release', granted !== null && granted.at >= released_at);

    waiter.release();
    await waiter.wait_for('RELEASED', 5000);

    // ---- The opposite direction: an EXPLICIT timeout is still honoured -------------
    const blocker = await h.connect();
    await blocker.request({ op: 'acquire', name: 'BOUNDED', mode: 'write', timeout: null });

    const impatient = await h.connect();
    const timed_out = await impatient.request({
        op: 'acquire', name: 'BOUNDED', mode: 'write', timeout: 1,
    });
    h.check('a caller that asks for a timeout gets one', timed_out.status === 'timeout');
    h.check('the timeout message is the verbatim one the updater classifies on',
        /Failed to acquire WRITE lock for BOUNDED after 1 seconds/.test(timed_out.message || ''));

    await blocker.request({ op: 'release', name: 'BOUNDED' });
    blocker.close();
    impatient.close();
    observer.close();
});
NODE

output="$(HOLD_SECONDS="$HOLD_SECONDS" node "$LOCKD_TMP/harness.js" 2>&1)"
status=$?
echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "FAIL: $TEST_NAME"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
