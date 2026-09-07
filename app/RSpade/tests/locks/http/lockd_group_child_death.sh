#!/bin/bash

TEST_NAME="rsx-lockd: a dead group member releases only what IT acquired"

# Inheritance must not weaken the connection-is-the-lock model. The rule is asymmetric on
# purpose:
#
#   - a lock the child INHERITED is still the parent's, so the child dying must not
#     release it (the parent is mid-critical-section and would silently lose exclusion -
#     precisely the failure the retired 30-second redis lease used to produce);
#   - a lock the child acquired ITSELF is the child's, so it must die with the child, like
#     any other holder.
#
# Both come from one implementation fact: each connection gets its own holder entry, and
# drop_connection() removes only that connection's entries. This test is what proves the
# fact, including under kill -9 where no clean release can possibly be sent.
#
# Asserts:
#   - the parent's grant survives a SIGKILLed child that had inherited it;
#   - the child's OWN lock is released by that same kill;
#   - a waiter parked on the child's own lock is granted immediately;
#   - a waiter parked on the inherited lock is NOT granted until the parent releases.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6297; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

h.run(async function () {
    const observer = await h.connect();

    const parent = h.spawn_holder(['--name=SHARED', '--mode=write', '--group=TREE_B']);
    h.check('the parent holds SHARED',
        (await parent.wait_for('ACQUIRED', 5000)) !== null);

    const child = h.spawn_holder(['--name=SHARED', '--mode=write', '--group=TREE_B']);
    h.check('the child inherits SHARED',
        (await child.wait_for('ACQUIRED', 5000)) !== null);

    // The child also takes something of its very own.
    const child_own = h.spawn_holder(['--name=CHILD_ONLY', '--mode=write', '--group=TREE_B']);
    h.check('the child takes CHILD_ONLY, which no one in the group held',
        (await child_own.wait_for('ACQUIRED', 5000)) !== null);

    // Two strangers park: one on each lock.
    const wants_shared = h.spawn_holder(['--name=SHARED', '--mode=write']);
    const wants_child_only = h.spawn_holder(['--name=CHILD_ONLY', '--mode=write']);
    h.check('a stranger parks on SHARED',
        (await wants_shared.wait_for('REQUESTED', 5000)) !== null);
    h.check('a stranger parks on CHILD_ONLY',
        (await wants_child_only.wait_for('REQUESTED', 5000)) !== null);
    await h.sleep(400);
    h.check('neither stranger has been granted yet',
        wants_shared.saw('ACQUIRED') === false && wants_child_only.saw('ACQUIRED') === false);

    // ---- Kill both child connections outright ---------------------------------------
    child.kill('SIGKILL');
    child_own.kill('SIGKILL');

    h.check("the child's OWN lock is released by its death - the stranger gets it",
        (await wants_child_only.wait_for('ACQUIRED', 5000)) !== null);

    await h.sleep(600);
    h.check("the INHERITED lock is NOT released by the child's death",
        wants_shared.saw('ACQUIRED') === false);

    const during = await observer.request({ op: 'dump' });
    const shared = during.locks.find((l) => l.name === 'SHARED') || { holders: [] };
    h.check('exactly one holder remains on SHARED - the parent', shared.holders.length === 1);

    // ---- Only the parent releasing frees it ------------------------------------------
    parent.release();
    h.check('the parent released', (await parent.wait_for('RELEASED', 5000)) !== null);
    h.check('the stranger is granted SHARED once the parent lets go',
        (await wants_shared.wait_for('ACQUIRED', 5000)) !== null);

    wants_shared.kill();
    wants_child_only.kill();
    observer.close();
});
NODE

node "$LOCKD_TMP/harness.js"
exit $?
