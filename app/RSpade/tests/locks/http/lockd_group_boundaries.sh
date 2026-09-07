#!/bin/bash

TEST_NAME="rsx-lockd: lock groups do not leak exclusion across their boundaries"

# Inheritance is a deliberate hole in mutual exclusion, so the edges of that hole are the
# part worth testing. A group is one process tree and nothing more.
#
# Asserts:
#   - a DIFFERENT group is a stranger: it queues exactly as before;
#   - a group that holds READ does not inherit WRITE (that would be a cross-process
#     upgrade, and would let one group hold read and write at once) - the parent should
#     take WRITE before spawning; documented in the rsx-lockd README;
#   - a group that holds READ does grant READ to a member;
#   - reentrancy on ONE connection is still refused, group or not (the PHP client keeps
#     those counts, so a second acquire on the same socket is a client bug);
#   - a malformed group id is rejected at hello rather than silently ignored.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6298; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

h.run(async function () {
    // ---- A different group is a stranger ---------------------------------------------
    const owner = h.spawn_holder(['--name=BOUNDARY', '--mode=write', '--group=TREE_C']);
    h.check('group TREE_C holds BOUNDARY',
        (await owner.wait_for('ACQUIRED', 5000)) !== null);

    const other_group = h.spawn_holder(['--name=BOUNDARY', '--mode=write', '--group=TREE_D']);
    h.check('a member of group TREE_D requests BOUNDARY',
        (await other_group.wait_for('REQUESTED', 5000)) !== null);
    await h.sleep(500);
    h.check('a DIFFERENT group is excluded exactly like any stranger',
        other_group.saw('ACQUIRED') === false);

    owner.kill();
    h.check('TREE_D is granted once TREE_C is gone',
        (await other_group.wait_for('ACQUIRED', 5000)) !== null);
    other_group.kill();

    // ---- READ held, WRITE wanted: not inherited ---------------------------------------
    const reader = h.spawn_holder(['--name=RW', '--mode=read', '--group=TREE_E']);
    h.check('the group holds a READ lock',
        (await reader.wait_for('ACQUIRED', 5000)) !== null);

    const wants_write = h.spawn_holder(['--name=RW', '--mode=write', '--group=TREE_E']);
    h.check('a group member requests WRITE on the read-held lock',
        (await wants_write.wait_for('REQUESTED', 5000)) !== null);
    await h.sleep(500);
    h.check('read-held is NOT inherited as write (no cross-process upgrade)',
        wants_write.saw('ACQUIRED') === false);
    wants_write.kill();

    // ---- READ held, READ wanted: inherited --------------------------------------------
    const wants_read = h.spawn_holder(['--name=RW', '--mode=read', '--group=TREE_E']);
    h.check('a group member IS granted READ on a read-held lock',
        (await wants_read.wait_for('ACQUIRED', 5000)) !== null);
    wants_read.kill();
    reader.kill();

    // ---- One connection, two acquires: still refused ----------------------------------
    const conn = await h.connect({ group_id: 'TREE_F' });
    const first = await conn.request({ op: 'acquire', name: 'REENTRANT', mode: 'write', timeout: null });
    h.check('the first acquire on the connection is granted', first.status === 'granted');
    const second = await conn.request({ op: 'acquire', name: 'REENTRANT', mode: 'write', timeout: null });
    h.check('a second acquire on the SAME connection is still an error',
        second.status === 'error');
    conn.close();

    // ---- A malformed group id is refused, not ignored ---------------------------------
    let rejected = false;
    try {
        const bad = await h.connect({ group_id: 'not a valid group id!' });
        bad.close();
    } catch (err) {
        rejected = true;
    }
    h.check('a malformed group id is rejected at hello', rejected === true);
});
NODE

node "$LOCKD_TMP/harness.js"
exit $?
