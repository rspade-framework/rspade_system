#!/bin/bash

TEST_NAME="rsx-lockd worker pool state machine (pure, no daemon)"

# lib/pool.js driven through the export seam with a collector for deferred frames and an
# injected generation and first worker id - the same way locktable.js is meant to be tested. No
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
#   - worker ids: one daemon-wide counter, +1 per join across pools, wrapping from
#     1,000,000,000 to 0, skipping a wid a live member holds; the default first wid is in
#     [0, 10,000,000) and the default generation a positive safe integer;
#   - member_alive / members_alive: known:false for another generation, alive per the pool
#     for this one; malformed wids and generations are refused.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

LOCKD_TMP="$(mktemp -d /tmp/lockd-test-XXXXXX)"
trap lockd_stop EXIT

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);
const { Lock_Table } = h.locktable;

const GEN = 777;

function make_table(first_wid = 100) {
    const delivered = [];
    const table = new Lock_Table({
        deliver: (conn_id, frame) => delivered.push({ conn_id: conn_id, frame: frame }),
        now: () => 0,
        set_timeout: () => null,
        clear_timeout: () => {},
        pool_generation: GEN,
        pool_first_wid: first_wid,
    });
    for (const id of ['a', 'b', 'c', 'd', 'e']) table.register_connection(id, { host: 'h', pid: 1 });
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
        h.check('pool.join answers ok with the wid and the generation',
            joined.status === 'ok' && joined.wid === 100 && joined.generation === GEN);
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
        const alive = pool.member_alive('c', { id: 11, pool: 'P', wid: 100, generation: GEN });
        h.check('member_alive true and known for a live member of this generation',
            alive.status === 'ok' && alive.alive === true && alive.known === true && alive.id === 11
            && alive.wid === 100 && alive.generation === GEN);
        const unknown_wid = pool.member_alive('c', { id: 12, pool: 'P', wid: 999, generation: GEN });
        h.check('member_alive false but known for a wid of this generation that is not a member',
            unknown_wid.alive === false && unknown_wid.known === true);
        const malformed = [
            { wid: 'x', generation: GEN }, { wid: -1, generation: GEN }, { wid: 1.5, generation: GEN },
            { wid: 1000000000, generation: GEN }, { generation: GEN },
            { wid: 100, generation: 0 }, { wid: 100, generation: '777' }, { wid: 100 },
        ];
        for (const bad of malformed) {
            const frame = pool.member_alive('c', Object.assign({ id: 13, pool: 'P' }, bad));
            h.check('member_alive refuses ' + JSON.stringify(bad), frame.status === 'error' && frame.id === 13);
        }
        const not_member_leave = pool.leave('c', { id: 14, pool: 'P' });
        h.check('leave by a non-member is refused', not_member_leave.status === 'error');
        pool.unlock('c', { id: 15, pool: 'P' });

        // Every lock-requiring op refuses a caller that does not hold the lock.
        for (const op of ['unlock', 'join', 'leave', 'count', 'member_alive', 'members_alive']) {
            const frame = pool[op]('a', { id: 'x-' + op, pool: 'P', wid: 100, generation: GEN, items: [] });
            h.check('pool.' + op + ' without the lock answers error echoing the id',
                frame.status === 'error' && frame.id === 'x-' + op);
        }

        pool.lock('a', { id: 16, pool: 'P' });
        const left = pool.leave('a', { id: 17, pool: 'P' });
        h.check('leave answers ok and echoes the wid', left.status === 'ok' && left.wid === 100);
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
        h.check('its wid now answers not alive (known: this generation)',
            pool.member_alive('b', { id: 11, pool: 'P', wid: 100, generation: GEN }).alive === false);
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

    // ---- Worker ids: one counter, monotonic across pools --------------------------------
    {
        const { pool } = make_table(500);
        const join = (conn, name) => {
            pool.lock(conn, { id: 'l', pool: name });
            const frame = pool.join(conn, { id: 'j', pool: name });
            pool.unlock(conn, { id: 'u', pool: name });
            return frame.wid;
        };
        const wids = [join('a', 'P'), join('b', 'Q'), join('c', 'P')];
        h.check('each join takes the next wid, across pools (one daemon-wide counter)',
            wids.join(',') === '500,501,502');

        pool.lock('b', { id: 1, pool: 'Q' });
        pool.leave('b', { id: 2, pool: 'Q' });
        pool.unlock('b', { id: 3, pool: 'Q' });
        h.check('a freed wid is not reissued: the counter moves on', join('d', 'Q') === 503);
        h.check('and the member that left rejoins with a new wid', join('b', 'Q') === 504);
    }

    // ---- Worker ids: wraparound and skip-in-use --------------------------------------------
    {
        const { table, pool } = make_table(999999998);
        const join = (conn, name) => {
            pool.lock(conn, { id: 'l', pool: name });
            const frame = pool.join(conn, { id: 'j', pool: name });
            pool.unlock(conn, { id: 'u', pool: name });
            return frame.wid;
        };
        h.check('the counter runs up to the top of the range',
            join('a', 'P') === 999999998 && join('b', 'P') === 999999999);
        h.check('and wraps from 1,000,000,000 to 0', join('c', 'Q') === 0);

        // a holds 999999998 and b 999999999. Coming round a billion joins later is simulated
        // by setting the counter back (the same field the constructor's first_wid seeds).
        table.drop_connection('c');
        pool.next_wid = 999999998;
        h.check('a wid a live member holds is skipped (in any pool), wrapping as it goes',
            join('d', 'Q') === 0);
        pool.next_wid = 999999999;
        h.check('skipping carries through the wrap point', join('e', 'R') === 1);
        h.check('no two live members share a wid',
            new Set(pool.dump().flatMap((p) => p.members.map((m) => m.wid))).size === 4);
    }

    // ---- Generations: known:false, and the batch form -----------------------------------
    {
        const { pool } = make_table();
        pool.lock('a', { id: 1, pool: 'P' });
        const a = pool.join('a', { id: 2, pool: 'P' });
        pool.unlock('a', { id: 3, pool: 'P' });

        pool.lock('b', { id: 4, pool: 'P' });
        const other_gen = pool.member_alive('b', { id: 5, pool: 'P', wid: a.wid, generation: GEN + 1 });
        h.check('another generation answers known:false, alive:false - even for a live wid',
            other_gen.status === 'ok' && other_gen.known === false && other_gen.alive === false);

        const batch = pool.members_alive('b', { id: 6, pool: 'P', items: [
            { wid: a.wid, generation: GEN },
            { wid: 12345, generation: GEN },
            { wid: a.wid, generation: 1 },
        ] });
        h.check('members_alive answers ok echoing the id', batch.status === 'ok' && batch.id === 6);
        h.check('one result per item, in order, echoing wid and generation',
            batch.results.length === 3
            && batch.results.every((r, i) => r.wid === [a.wid, 12345, a.wid][i] && r.generation === [GEN, GEN, 1][i]));
        h.check('live member: alive, known', batch.results[0].alive === true && batch.results[0].known === true);
        h.check('absent wid: not alive, known', batch.results[1].alive === false && batch.results[1].known === true);
        h.check('other generation: not alive, unknown', batch.results[2].alive === false && batch.results[2].known === false);
        h.check('an empty batch answers an empty result list',
            pool.members_alive('b', { id: 7, pool: 'P', items: [] }).results.length === 0);
        const bad_item = pool.members_alive('b', { id: 8, pool: 'P', items: [{ wid: 1, generation: GEN }, { wid: 'x', generation: GEN }] });
        h.check('one malformed item refuses the whole batch, naming it',
            bad_item.status === 'error' && bad_item.id === 8 && /item 1 /.test(bad_item.message));
        h.check('a missing items array is refused',
            pool.members_alive('b', { id: 9, pool: 'P' }).status === 'error');
        pool.lock('c', { id: 11, pool: 'Q' });
        h.check('a wid that is a member of another pool is not alive in this one',
            pool.member_alive('c', { id: 12, pool: 'Q', wid: a.wid, generation: GEN }).alive === false);

        const stats = pool.stats({ id: 13, pool: 'P' });
        h.check('pool.stats carries the generation (named and listing forms)',
            stats.generation === GEN && pool.stats({ id: 14 }).generation === GEN);
    }

    // ---- Defaults: random generation and first wid; dump ----------------------------------
    {
        const generations = new Set();
        let first_wids_in_range = true;
        for (let index = 0; index < 20; index++) {
            const table = new Lock_Table({ now: () => 0, set_timeout: () => null, clear_timeout: () => {} });
            table.register_connection('c1', {});
            generations.add(table.pool.generation);
            if (!(Number.isSafeInteger(table.pool.generation) && table.pool.generation > 0)) generations.add('bad');
            table.pool.lock('c1', { id: 1, pool: 'P' });
            const wid = table.pool.join('c1', { id: 2, pool: 'P' }).wid;
            if (!(Number.isInteger(wid) && wid >= 0 && wid < 10000000)) first_wids_in_range = false;
            if (index === 0) {
                const dump = table.dump({ id: 3 });
                h.check('dump carries the pool generation and each member\'s wid',
                    dump.pool_generation === table.pool.generation && dump.pools.length === 1
                    && dump.pools[0].members[0].wid === wid
                    && dump.connections[0].pools[0].wid === wid);
            }
        }
        h.check('the default generation is a positive safe integer, fresh per daemon',
            !generations.has('bad') && generations.size === 20);
        h.check('the default first wid is in [0, 10,000,000)', first_wids_in_range);

        let refused = 0;
        for (const options of [{ pool_generation: 0 }, { pool_generation: 2 ** 53 }, { pool_first_wid: 1000000000 }, { pool_first_wid: -1 }]) {
            try {
                new Lock_Table(Object.assign({ now: () => 0, set_timeout: () => null, clear_timeout: () => {} }, options));
            } catch (err) {
                refused++;
            }
        }
        h.check('an injected generation or first wid out of range throws', refused === 4);
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
