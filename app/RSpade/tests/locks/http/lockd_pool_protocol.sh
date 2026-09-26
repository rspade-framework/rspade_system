#!/bin/bash

TEST_NAME="rsx-lockd worker pool ops over the wire"

# The pool ops against a real (scratch) daemon, through its real socket:
#   - EVERY pool op is acknowledged with exactly one frame echoing its request id - proven
#     on a raw socket that records every inbound frame, since the ordinary client drops
#     frames nobody asked for;
#   - the pool lock grants in FIFO order across connections;
#   - count excludes the caller; member_alive answers true/false;
#   - pools with different names are independent;
#   - the lock-requiring ops answer error to a caller that does not hold the lock, and a
#     second join is refused;
#   - pool.stats is readable without the lock, and dump/stats carry pool state.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6299; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

// Bound on waiting for a frame from the scratch daemon this test started: a wait on
// another process, sanctioned by the contention principle (tests/CLAUDE.md). Expiry FAILS
// the check that asked; it truncates no work.
const WAIT_MS = 120000;

async function pool_stats(client, name) {
    return await client.request({ op: 'pool.stats', pool: name });
}

h.run(async function () {
    // ---- Every op is acknowledged, exactly once, echoing its id --------------------
    {
        const raw = await h.connect_raw();
        const script = [
            { op: 'pool.lock', pool: 'ACK', id: 'k1' },
            { op: 'pool.join', pool: 'ACK', id: 'k2' },
            { op: 'pool.count', pool: 'ACK', id: 'k3' },
            { op: 'pool.member_alive', pool: 'ACK', member_id: 'pm_nobody', id: 'k4' },
            { op: 'pool.stats', pool: 'ACK', id: 'k5' },
            { op: 'pool.stats', id: 'k6' },
            { op: 'pool.leave', pool: 'ACK', id: 'k7' },
            { op: 'pool.unlock', pool: 'ACK', id: 'k8' },
            // Errors are acknowledgements too.
            { op: 'pool.unlock', pool: 'ACK', id: 'k9' },
            { op: 'pool.join', pool: 'bad name', id: 'k10' },
        ];
        for (const frame of script) raw.send(frame);
        raw.send({ op: 'ping', id: 'fence' });

        h.check('every request was answered', await raw.wait_frames(script.length + 1, WAIT_MS));
        await h.sleep(200);
        h.check('exactly one frame per request - nothing extra, nothing missing',
            raw.frames.length === script.length + 1);
        h.check('the answers echo the request ids, in order',
            raw.frames.map((f) => f.id).join(',') === script.map((f) => f.id).concat(['fence']).join(','));

        const by_id = {};
        for (const frame of raw.frames) by_id[frame.id] = frame;
        h.check('pool.lock -> granted', by_id.k1.status === 'granted' && by_id.k1.pool === 'ACK');
        h.check('pool.join -> ok with a member_id', by_id.k2.status === 'ok' && /^pm_[0-9a-f]{32}$/.test(by_id.k2.member_id));
        h.check('pool.count -> ok, 0 (the caller excluded)', by_id.k3.status === 'ok' && by_id.k3.members === 0);
        h.check('pool.member_alive -> ok, false for an unknown id', by_id.k4.status === 'ok' && by_id.k4.alive === false);
        h.check('pool.stats (named) -> ok with members/holder/waiting',
            by_id.k5.status === 'ok' && by_id.k5.members === 1 && by_id.k5.holder === true && by_id.k5.waiting === 0);
        h.check('pool.stats (all) -> ok with a pools list', by_id.k6.status === 'ok' && Array.isArray(by_id.k6.pools));
        h.check('pool.leave -> ok', by_id.k7.status === 'ok' && by_id.k7.member_id === by_id.k2.member_id);
        h.check('pool.unlock -> ok', by_id.k8.status === 'ok');
        h.check('pool.unlock when not the holder -> error', by_id.k9.status === 'error');
        h.check('an invalid pool name -> error', by_id.k10.status === 'error');
        raw.close();
    }

    // ---- FIFO grant order across connections ---------------------------------------
    const observer = await h.connect();
    {
        const holder = await h.connect();
        h.check('the first connection takes the pool lock',
            (await holder.request({ op: 'pool.lock', pool: 'FIFO' })).status === 'granted');

        const waiters = [];
        for (let index = 0; index < 3; index++) {
            const waiter = { client: await h.connect(), granted_at: null };
            waiter.promise = waiter.client.request({ op: 'pool.lock', pool: 'FIFO' }).then((frame) => {
                waiter.granted_at = h.now_ms();
                return frame;
            });
            // Confirm each is queued before sending the next, so the order under test is
            // the order this test intended.
            const deadline = Date.now() + WAIT_MS;
            while ((await pool_stats(observer, 'FIFO')).waiting !== index + 1 && Date.now() < deadline) {
                await h.sleep(20);
            }
            waiters.push(waiter);
        }
        h.check('three waiters are queued', (await pool_stats(observer, 'FIFO')).waiting === 3);
        await h.sleep(300);
        h.check('waiting is silence: nobody granted while the holder holds',
            waiters.every((w) => w.granted_at === null));

        await holder.request({ op: 'pool.unlock', pool: 'FIFO' });
        for (let index = 0; index < waiters.length; index++) {
            const frame = await h.answer_within(waiters[index].promise, WAIT_MS);
            h.check('waiter ' + (index + 1) + ' is granted in turn', frame !== null && frame.status === 'granted');
            await h.sleep(200);
            h.check('and the ones behind it are not',
                waiters.slice(index + 1).every((w) => w.granted_at === null));
            await waiters[index].client.request({ op: 'pool.unlock', pool: 'FIFO' });
        }
        h.check('grant order was request order',
            waiters[0].granted_at <= waiters[1].granted_at && waiters[1].granted_at <= waiters[2].granted_at);

        holder.close();
        for (const w of waiters) w.client.close();
    }

    // ---- count excludes the caller; member_alive -------------------------------------
    {
        const a = await h.connect();
        const b = await h.connect();

        await a.request({ op: 'pool.lock', pool: 'COUNT' });
        const a_join = await a.request({ op: 'pool.join', pool: 'COUNT' });
        const twice = await a.request({ op: 'pool.join', pool: 'COUNT' });
        h.check('a second join by the same connection is refused', twice.status === 'error');
        await a.request({ op: 'pool.unlock', pool: 'COUNT' });

        await b.request({ op: 'pool.lock', pool: 'COUNT' });
        h.check('a non-member counts every member', (await b.request({ op: 'pool.count', pool: 'COUNT' })).members === 1);
        const b_join = await b.request({ op: 'pool.join', pool: 'COUNT' });
        h.check('a member counts every OTHER member', (await b.request({ op: 'pool.count', pool: 'COUNT' })).members === 1);
        h.check('member_alive is true for a live member',
            (await b.request({ op: 'pool.member_alive', pool: 'COUNT', member_id: a_join.member_id })).alive === true);
        h.check('two joins mint two different member ids', a_join.member_id !== b_join.member_id);
        await b.request({ op: 'pool.unlock', pool: 'COUNT' });

        h.check('pool.stats is readable WITHOUT the lock and counts everyone',
            (await pool_stats(observer, 'COUNT')).members === 2);

        // Errors for a caller that does not hold the lock.
        for (const op of ['pool.unlock', 'pool.join', 'pool.leave', 'pool.count', 'pool.member_alive']) {
            const frame = await observer.request({ op: op, pool: 'COUNT', member_id: a_join.member_id });
            h.check(op + ' without the lock answers error', frame.status === 'error');
        }
        h.check('those refusals changed nothing', (await pool_stats(observer, 'COUNT')).members === 2);

        // ---- Name isolation --------------------------------------------------------
        await a.request({ op: 'pool.lock', pool: 'COUNT' });
        const other = await b.request({ op: 'pool.lock', pool: 'COUNT_OTHER' });
        h.check('another pool name is a different lock (granted while COUNT is held)', other.status === 'granted');
        h.check('and a different member set',
            (await b.request({ op: 'pool.count', pool: 'COUNT_OTHER' })).members === 0);
        h.check('a member id from one pool is not alive in another',
            (await b.request({ op: 'pool.member_alive', pool: 'COUNT_OTHER', member_id: a_join.member_id })).alive === false);

        // ---- dump / stats carry pool state -------------------------------------------
        const dump = await observer.request({ op: 'dump' });
        const count_pool = dump.pools.find((p) => p.pool === 'COUNT');
        h.check('dump lists the pool with its holder and members',
            count_pool && count_pool.holder === a.conn_id && count_pool.members.length === 2);
        const a_view = dump.connections.find((c) => c.conn_id === a.conn_id);
        h.check('dump shows the pool per connection',
            a_view && a_view.pools.some((p) => p.pool === 'COUNT' && p.holds_lock && p.member_id === a_join.member_id));
        h.check('dump carries pool counters', dump.pool_counters && dump.pool_counters.joined >= 3);
        const stats = await observer.request({ op: 'stats', name: 'anything' });
        h.check('stats reports the live pool count', stats.pools >= 2);

        a.close();
        b.close();
    }

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
