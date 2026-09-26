#!/bin/bash

TEST_NAME="rsx-lockd worker pool state machine (pure, no daemon)"

# lib/pool.js driven through the export seam with a collector for deferred frames and a
# deterministic member-id source - the same way locktable.js is meant to be tested. No
# socket is opened and no daemon is started.
#
# Proves the pool invariants at the state-machine level:
#   - FIFO grant order, and a parked pool.lock is answered only when granted;
#   - every op answers one frame that echoes the request id;
#   - Lock_Table.drop_connection() and release_all() both clear pool state (membership,
#     the pool lock with a grant to the next waiter, queued waits), and release_all answers
#     a parked pool wait with an error frame;
#   - pool waits are invisible to the deadlock detector, by design;
#   - pool names are validated and pools are independent;
#   - the default member id is random and never a connection id.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

LOCKD_TMP="$(mktemp -d /tmp/lockd-test-XXXXXX)"
trap lockd_stop EXIT

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);
const { Lock_Table } = h.locktable;

function make_table() {
    const delivered = [];
    let seq = 0;
    const table = new Lock_Table({
        deliver: (conn_id, frame) => delivered.push({ conn_id: conn_id, frame: frame }),
        now: () => 0,
        set_timeout: () => null,
        clear_timeout: () => {},
        pool_random_id: () => { seq++; return 'pm_test' + seq; },
    });
    for (const id of ['a', 'b', 'c', 'd']) table.register_connection(id, { host: 'h', pid: 1 });
    return { table: table, pool: table.pool, delivered: delivered };
}

h.run(async function () {
    // ---- FIFO and deferred delivery ------------------------------------------------
    {
        const { pool, delivered } = make_table();
        const a = pool.lock('a', { id: 1, pool: 'P' });
        h.check('an uncontended pool.lock is granted immediately, echoing the id',
            a.status === 'granted' && a.id === 1 && a.pool === 'P');
        h.check('a second connection parks (null = no frame now)', pool.lock('b', { id: 2, pool: 'P' }) === null);
        h.check('a third connection parks behind it', pool.lock('c', { id: 3, pool: 'P' }) === null);
        h.check('waiting is silence: nothing delivered yet', delivered.length === 0);

        const unlocked = pool.unlock('a', { id: 4, pool: 'P' });
        h.check('pool.unlock answers ok with the id', unlocked.status === 'ok' && unlocked.id === 4);
        h.check('the head waiter (b) is granted, and only b',
            delivered.length === 1 && delivered[0].conn_id === 'b'
            && delivered[0].frame.status === 'granted' && delivered[0].frame.id === 2);

        pool.unlock('b', { id: 5, pool: 'P' });
        h.check('then c, in request order',
            delivered.length === 2 && delivered[1].conn_id === 'c' && delivered[1].frame.id === 3);

        const again = pool.lock('c', { id: 6, pool: 'P' });
        h.check('re-locking a held pool lock is an error, not a counter', again.status === 'error' && again.id === 6);
    }

    // ---- Membership, count, member_alive ----------------------------------------------
    {
        const { pool } = make_table();
        pool.lock('a', { id: 1, pool: 'P' });
        const joined = pool.join('a', { id: 2, pool: 'P' });
        h.check('pool.join answers ok with a member_id', joined.status === 'ok' && joined.member_id === 'pm_test1');
        const twice = pool.join('a', { id: 3, pool: 'P' });
        h.check('joining twice is refused', twice.status === 'error' && twice.id === 3);
        const count_self = pool.count('a', { id: 4, pool: 'P' });
        h.check('count excludes the caller when the caller is a member', count_self.members === 0);
        pool.unlock('a', { id: 5, pool: 'P' });

        pool.lock('b', { id: 6, pool: 'P' });
        pool.join('b', { id: 7, pool: 'P' });
        pool.unlock('b', { id: 8, pool: 'P' });

        pool.lock('c', { id: 9, pool: 'P' });
        h.check('a non-member sees every member', pool.count('c', { id: 10, pool: 'P' }).members === 2);
        const alive = pool.member_alive('c', { id: 11, pool: 'P', member_id: 'pm_test1' });
        h.check('member_alive true for a live member', alive.status === 'ok' && alive.alive === true && alive.id === 11);
        h.check('member_alive false for an unknown id',
            pool.member_alive('c', { id: 12, pool: 'P', member_id: 'pm_nobody' }).alive === false);
        h.check('member_alive refuses a malformed member_id',
            pool.member_alive('c', { id: 13, pool: 'P', member_id: 'bad id!' }).status === 'error');
        const not_member_leave = pool.leave('c', { id: 14, pool: 'P' });
        h.check('leave by a non-member is refused', not_member_leave.status === 'error');
        pool.unlock('c', { id: 15, pool: 'P' });

        // Every lock-requiring op refuses a caller that does not hold the lock.
        for (const op of ['unlock', 'join', 'leave', 'count', 'member_alive']) {
            const frame = pool[op]('a', { id: 'x-' + op, pool: 'P', member_id: 'pm_test1' });
            h.check('pool.' + op + ' without the lock answers error echoing the id',
                frame.status === 'error' && frame.id === 'x-' + op);
        }

        pool.lock('a', { id: 16, pool: 'P' });
        const left = pool.leave('a', { id: 17, pool: 'P' });
        h.check('leave answers ok and names the member', left.status === 'ok' && left.member_id === 'pm_test1');
        h.check('after leave, count drops', pool.count('a', { id: 18, pool: 'P' }).members === 1);
        pool.unlock('a', { id: 19, pool: 'P' });
    }

    // ---- Lock_Table.drop_connection clears pool state -------------------------------
    {
        const { table, pool, delivered } = make_table();
        pool.lock('a', { id: 1, pool: 'P' });
        pool.join('a', { id: 2, pool: 'P' });
        pool.lock('a', { id: 3, pool: 'Q' });   // holds two pool locks at once
        pool.lock('b', { id: 4, pool: 'P' });   // parked behind a on P
        pool.lock('c', { id: 5, pool: 'Q' });   // parked behind a on Q
        pool.lock('a', { id: 6, pool: 'R' });
        pool.unlock('a', { id: 7, pool: 'R' });
        pool.lock('d', { id: 8, pool: 'R' });
        pool.lock('a', { id: 9, pool: 'R' });   // a is parked on R behind d

        const result = table.drop_connection('a');
        h.check('drop_connection reports the pool cleanup',
            result.pool.locks_released === 2 && result.pool.members_removed === 1 && result.pool.waits_cancelled === 1);
        h.check('the next FIFO waiter on each released pool is granted',
            delivered.some((d) => d.conn_id === 'b' && d.frame.id === 4 && d.frame.status === 'granted')
            && delivered.some((d) => d.conn_id === 'c' && d.frame.id === 5 && d.frame.status === 'granted'));
        h.check('the dropped connection itself is sent nothing', !delivered.some((d) => d.conn_id === 'a'));
        h.check('the membership is gone without any leave', pool.count('b', { id: 10, pool: 'P' }).members === 0);
        h.check('its member id now answers not alive',
            pool.member_alive('b', { id: 11, pool: 'P', member_id: 'pm_test1' }).alive === false);
        pool.unlock('d', { id: 12, pool: 'R' });
        h.check('its queued wait was removed (R frees instead of granting a ghost)',
            pool.stats({ id: 13, pool: 'R' }).holder === false);
    }

    // ---- release_all clears pool state and ANSWERS a parked pool wait ------------------
    {
        const { table, pool, delivered } = make_table();
        pool.lock('a', { id: 1, pool: 'P' });
        pool.join('a', { id: 2, pool: 'P' });
        pool.lock('b', { id: 3, pool: 'P' });   // b waits on P
        pool.lock('c', { id: 4, pool: 'Q' });
        pool.lock('a', { id: 5, pool: 'Q' });   // a waits on Q

        const reply = table.release_all('a', { id: 6 });
        h.check('release_all answers ok with the pool cleanup',
            reply.status === 'ok' && reply.id === 6 && reply.pool.locks_released === 1
            && reply.pool.members_removed === 1 && reply.pool.waits_cancelled === 1);
        h.check('the parked pool wait is answered with an error frame echoing its id',
            delivered.some((d) => d.conn_id === 'a' && d.frame.id === 5 && d.frame.status === 'error'
                && d.frame.message === 'Wait cancelled by release_all'));
        h.check('the released pool lock is granted to the next waiter',
            delivered.some((d) => d.conn_id === 'b' && d.frame.id === 3 && d.frame.status === 'granted'));
        h.check('the membership is gone', pool.count('b', { id: 7, pool: 'P' }).members === 0);
    }

    // ---- Pool waits are invisible to the deadlock detector ---------------------------
    // a holds lock L and waits on pool P; b holds pool P and asks for L. Were pool waits in
    // the wait-for graph this would be refused as a cycle. It is NOT - by design: THE RULE
    // forbids a pool-lock holder from taking any other blocking lock, so this shape is a
    // caller bug the rule exists to prevent, and the detector never walks pool waits.
    {
        const { table, pool } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        pool.lock('b', { id: 2, pool: 'P' });
        pool.lock('a', { id: 3, pool: 'P' });
        h.check('a pool wait never enters conn.waits', table.connections.get('a').waits.length === 0);
        h.check('the lock acquire parks rather than being refused as a deadlock',
            table.acquire('b', { id: 4, name: 'L', mode: 'write', timeout: null }) === null
            && table.counters.deadlocked === 0);
    }

    // ---- Names, isolation, stats, gc ---------------------------------------------------
    {
        const { pool } = make_table();
        for (const bad of [undefined, '', 'has space', 'x'.repeat(129), 42]) {
            const label = typeof bad === 'string' && bad.length > 20 ? 'of ' + bad.length + ' chars' : JSON.stringify(bad);
            h.check('pool name ' + label + ' is refused',
                pool.lock('a', { id: 1, pool: bad }).status === 'error');
        }
        h.check('a 128-char name of the safe charset is accepted',
            pool.lock('a', { id: 1, pool: 'a'.repeat(128) }).status === 'granted');

        pool.lock('a', { id: 2, pool: 'tasks:one' });
        pool.join('a', { id: 3, pool: 'tasks:one' });
        const other = pool.lock('b', { id: 4, pool: 'tasks:two' });
        h.check('a different pool name is an independent lock', other.status === 'granted');
        h.check('and an independent member set', pool.count('b', { id: 5, pool: 'tasks:two' }).members === 0);

        const one = pool.stats({ id: 6, pool: 'tasks:one' });
        h.check('stats for a named pool: members, holder, waiting',
            one.status === 'ok' && one.members === 1 && one.holder === true && one.waiting === 0 && one.id === 6);
        const none = pool.stats({ id: 7, pool: 'never-used' });
        h.check('stats for an unknown pool is empty and creates nothing',
            none.members === 0 && none.holder === false && !pool.pools.has('never-used'));
        const all = pool.stats({ id: 8 });
        h.check('stats without a name lists every live pool', Array.isArray(all.pools) && all.pools.length === 3);

        pool.unlock('b', { id: 9, pool: 'tasks:two' });
        h.check('an empty pool record is garbage-collected', !pool.pools.has('tasks:two'));
        h.check('a pool with members survives its unlock',
            pool.unlock('a', { id: 10, pool: 'tasks:one' }).status === 'ok' && pool.pools.has('tasks:one'));
    }

    // ---- Default member ids ----------------------------------------------------------
    {
        const table = new Lock_Table({ now: () => 0, set_timeout: () => null, clear_timeout: () => {} });
        table.register_connection('c1', {});
        table.pool.lock('c1', { id: 1, pool: 'P' });
        const member_id = table.pool.join('c1', { id: 2, pool: 'P' }).member_id;
        h.check('the default member id is random (pm_ + 32 hex), never a connection id',
            /^pm_[0-9a-f]{32}$/.test(member_id));
        h.check('dump carries pool state',
            table.dump({ id: 3 }).pools.length === 1 && table.dump({ id: 3 }).pools[0].members[0].member_id === member_id);
    }
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
