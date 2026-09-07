#!/bin/bash

TEST_NAME="rsx-lockd grants a lock to another connection in the same group"

# The fix for a REAL 12-hour production hang (2026-08-11). A process took
# cluster:SITE_1 write, then shelled out to `php artisan migrate`, which is a separate
# process and therefore a separate connection - so it queued behind its own parent while
# the parent sat in waitpid on it. Neither could ever move, and the deadlock detector
# structurally could not see it: the parent's half of the cycle is an OS wait, not a lock
# wait.
#
# A GROUP is that process tree. A connection whose group already holds the lock is a
# subprocess of the holder, so it is granted immediately.
#
# Asserts:
#   - a same-group connection is granted a WRITE lock its group already holds;
#   - it is granted even with a FOREIGN writer already queued (bypassing the queue is
#     required - queueing behind that waiter deadlocks exactly as hard, because the
#     waiter cannot be granted until the parent releases and the parent is waiting on us);
#   - the foreign waiter is NOT granted while any group member still holds;
#   - the foreign waiter IS granted once the whole group has released;
#   - a connection that declared no group is still a stranger (the default is unchanged).

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

h.run(async function () {
    const observer = await h.connect();

    // ---- The parent takes the lock --------------------------------------------------
    const parent = h.spawn_holder(['--name=SITE_1', '--mode=write', '--group=TREE_A']);
    h.check('the parent holds the WRITE lock',
        (await parent.wait_for('ACQUIRED', 5000)) !== null);

    // ---- A foreign writer queues behind it ------------------------------------------
    const stranger = h.spawn_holder(['--name=SITE_1', '--mode=write']);
    h.check('a foreign writer parks behind the parent',
        (await stranger.wait_for('REQUESTED', 5000)) !== null);
    await h.sleep(400);
    h.check('the foreign writer is genuinely blocked',
        stranger.saw('ACQUIRED') === false);

    // ---- The child inherits, bypassing that queued stranger --------------------------
    const child = h.spawn_holder(['--name=SITE_1', '--mode=write', '--group=TREE_A']);
    const granted = await child.wait_for('ACQUIRED', 5000);
    h.check('the child in the parent group is granted the lock its group holds',
        granted !== null);
    h.check('the child was granted even though a foreign writer was queued ahead of it',
        granted !== null && stranger.saw('ACQUIRED') === false);

    const during = await observer.request({ op: 'dump' });
    const holders = (during.locks.find((l) => l.name === 'SITE_1') || {}).holders || [];
    h.check('both group members appear as holders of the one lock', holders.length === 2);

    // ---- Group membership does not weaken exclusion for anyone else ------------------
    child.release();
    h.check('the child released', (await child.wait_for('RELEASED', 5000)) !== null);
    await h.sleep(400);
    h.check('the foreign writer STILL waits - the parent has not released',
        stranger.saw('ACQUIRED') === false);

    parent.release();
    h.check('the parent released', (await parent.wait_for('RELEASED', 5000)) !== null);
    h.check('the foreign writer is granted once the whole group is done',
        (await stranger.wait_for('ACQUIRED', 5000)) !== null);

    stranger.release();
    await stranger.wait_for('RELEASED', 5000);

    // ---- No group declared means no inheritance --------------------------------------
    const solo = h.spawn_holder(['--name=OTHER', '--mode=write']);
    h.check('an ungrouped holder takes OTHER',
        (await solo.wait_for('ACQUIRED', 5000)) !== null);

    const solo_two = h.spawn_holder(['--name=OTHER', '--mode=write']);
    h.check('a second ungrouped connection requests OTHER',
        (await solo_two.wait_for('REQUESTED', 5000)) !== null);
    await h.sleep(400);
    h.check('two ungrouped connections still exclude each other (default unchanged)',
        solo_two.saw('ACQUIRED') === false);

    solo.kill();
    solo_two.kill();
    observer.close();
});
NODE

node "$LOCKD_TMP/harness.js"
exit $?
