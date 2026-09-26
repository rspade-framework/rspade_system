#!/bin/bash

TEST_NAME="rsx-lockd worker pool: the connection is the membership"

# The owner requirement the pool exists for: a worker's pool membership AND its hold on the
# pool lock are released by the daemon when its connection drops, whether or not it ever
# sent leave or unlock.
#
# Asserts, against a scratch daemon:
#   - a SIGKILLed member process stops being a member (count drops, member_alive false)
#     with no leave ever sent;
#   - a SIGKILLed process HOLDING the pool lock frees it and the next FIFO waiter is granted;
#     its membership goes with it;
#   - a waiter that disconnects leaves the queue, so the lock passes over it;
#   - release_all frees pool state on a live connection: the lock (granted onward), the
#     membership, and a parked pool.lock (answered with an error frame, never abandoned);
#   - `lockd.js dump` renders pool state.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6301; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);

// Bound on waiting for a condition the scratch daemon or a helper process must produce: a
// wait on another process, sanctioned by the contention principle (tests/CLAUDE.md).
// Expiry FAILS the check that asked; it truncates no work.
const WAIT_MS = 120000;

async function until(fn) {
    const deadline = Date.now() + WAIT_MS;
    while (Date.now() < deadline) {
        if (await fn()) return true;
        await h.sleep(20);
    }
    return false;
}

async function stats(client, name) {
    return await client.request({ op: 'pool.stats', pool: name });
}

h.run(async function () {
    const observer = await h.connect();

    // ---- A SIGKILLed member is no longer a member ---------------------------------
    {
        const member = h.spawn_helper('lockd_pool_member.js', ['--pool=DEATH']);
        const joined = await member.wait_for('UNLOCKED', WAIT_MS);
        h.check('the member process joined and released the lock', joined !== null);
        const member_id = member.lines.find((l) => l.line.startsWith('JOINED ')).line.slice(7);

        const checker = await h.connect();
        await checker.request({ op: 'pool.lock', pool: 'DEATH' });
        h.check('it counts as a member',
            (await checker.request({ op: 'pool.count', pool: 'DEATH' })).members === 1);
        h.check('member_alive says so',
            (await checker.request({ op: 'pool.member_alive', pool: 'DEATH', member_id: member_id })).alive === true);
        await checker.request({ op: 'pool.unlock', pool: 'DEATH' });

        // kill -9: no leave, no unlock, no last words.
        member.kill('SIGKILL');
        h.check('the membership disappears on its own',
            await until(async () => (await stats(observer, 'DEATH')).members === 0));

        await checker.request({ op: 'pool.lock', pool: 'DEATH' });
        h.check('count drops to 0 with no leave ever sent',
            (await checker.request({ op: 'pool.count', pool: 'DEATH' })).members === 0);
        h.check('member_alive answers false for the dead member',
            (await checker.request({ op: 'pool.member_alive', pool: 'DEATH', member_id: member_id })).alive === false);
        await checker.request({ op: 'pool.unlock', pool: 'DEATH' });
        checker.close();
    }

    // ---- A SIGKILLed LOCK HOLDER frees the lock to the next waiter ---------------------
    {
        const holder = h.spawn_helper('lockd_pool_member.js', ['--pool=HOLDER', '--stay-locked']);
        h.check('the doomed process holds the pool lock and is a member',
            (await holder.wait_for('JOINED', WAIT_MS)) !== null);

        const waiter = await h.connect();
        let granted_at = null;
        const waiting = waiter.request({ op: 'pool.lock', pool: 'HOLDER' }).then((frame) => {
            granted_at = Date.now();
            return frame;
        });
        h.check('a waiter is queued behind it',
            await until(async () => (await stats(observer, 'HOLDER')).waiting === 1));
        await h.sleep(300);
        h.check('the waiter is genuinely blocked while the holder lives', granted_at === null);

        holder.kill('SIGKILL');
        const frame = await h.answer_within(waiting, WAIT_MS);
        h.check('the waiter is granted once the holder is SIGKILLed, with no unlock sent',
            frame !== null && frame.status === 'granted');
        h.check('the dead holder\'s membership went with it',
            (await waiter.request({ op: 'pool.count', pool: 'HOLDER' })).members === 0);
        await waiter.request({ op: 'pool.unlock', pool: 'HOLDER' });
        waiter.close();
    }

    // ---- A disconnected WAITER leaves the queue ---------------------------------------
    {
        const holder = await h.connect();
        await holder.request({ op: 'pool.lock', pool: 'QUIT' });

        const quitter = await h.connect();
        quitter.request({ op: 'pool.lock', pool: 'QUIT' }).catch(() => {});
        h.check('the quitter is queued',
            await until(async () => (await stats(observer, 'QUIT')).waiting === 1));

        const patient = await h.connect();
        const patient_wait = patient.request({ op: 'pool.lock', pool: 'QUIT' });
        h.check('a patient waiter is queued behind it',
            await until(async () => (await stats(observer, 'QUIT')).waiting === 2));

        quitter.socket.destroy();
        h.check('the quitter\'s wait is removed',
            await until(async () => (await stats(observer, 'QUIT')).waiting === 1));

        await holder.request({ op: 'pool.unlock', pool: 'QUIT' });
        const frame = await h.answer_within(patient_wait, WAIT_MS);
        h.check('the lock passes over the departed waiter to the next one',
            frame !== null && frame.status === 'granted');
        await patient.request({ op: 'pool.unlock', pool: 'QUIT' });
        holder.close();
        patient.close();
    }

    // ---- release_all frees pool state on a live connection ----------------------------
    {
        const worker = await h.connect();
        await worker.request({ op: 'pool.lock', pool: 'RALL' });
        await worker.request({ op: 'pool.join', pool: 'RALL' });

        const next = await h.connect();
        const next_wait = next.request({ op: 'pool.lock', pool: 'RALL' });
        h.check('a waiter is queued behind the worker',
            await until(async () => (await stats(observer, 'RALL')).waiting === 1));

        const reply = await worker.request({ op: 'release_all' });
        h.check('release_all reports the pool lock and membership it freed',
            reply.status === 'ok' && reply.pool.locks_released === 1 && reply.pool.members_removed === 1);
        const frame = await h.answer_within(next_wait, WAIT_MS);
        h.check('the pool lock is granted onward', frame !== null && frame.status === 'granted');
        h.check('the membership is gone',
            (await next.request({ op: 'pool.count', pool: 'RALL' })).members === 0);

        // A PARKED pool.lock on the connection that sends release_all is answered, not abandoned.
        const raw = await h.connect_raw();
        raw.send({ op: 'pool.lock', pool: 'RALL', id: 'parked' });
        h.check('the raw connection is queued',
            await until(async () => (await stats(observer, 'RALL')).waiting === 1));
        raw.send({ op: 'release_all', id: 'rall' });
        h.check('two frames come back', await raw.wait_frames(2, WAIT_MS));
        const parked = raw.frames.find((f) => f.id === 'parked');
        const rall = raw.frames.find((f) => f.id === 'rall');
        h.check('the parked pool.lock is answered with an error frame',
            parked && parked.status === 'error' && parked.message === 'Wait cancelled by release_all');
        h.check('release_all itself answers ok, counting the cancelled pool wait',
            rall && rall.status === 'ok' && rall.pool.waits_cancelled === 1);
        h.check('the queue is empty again', (await stats(observer, 'RALL')).waiting === 0);
        raw.close();

        await next.request({ op: 'pool.unlock', pool: 'RALL' });
        next.close();
        worker.close();
    }

    // ---- The CLI dump renders pool state ----------------------------------------------
    {
        const member = h.spawn_helper('lockd_pool_member.js', ['--pool=SHOWN', '--stay-locked']);
        await member.wait_for('JOINED', WAIT_MS);
        const child_process = require('child_process');
        const dump = child_process.spawnSync(process.execPath, [
            require('path').join(h.LOCKD_DIR, 'lockd.js'), 'dump', '--host=127.0.0.1', '--port=' + h.PORT,
        ], { encoding: 'utf8', env: process.env });
        h.check('lockd.js dump exits 0', dump.status === 0);
        h.check('the dump renders a pools section naming the pool',
            /^pools$/m.test(dump.stdout) && /^  SHOWN$/m.test(dump.stdout));
        h.check('the dump shows the pool lock held and the membership per connection',
            /HELD     POOL  SHOWN/.test(dump.stdout) && /MEMBER   POOL  SHOWN   as pm_[0-9a-f]{32}/.test(dump.stdout));
        if (dump.status !== 0) h.note(dump.stdout + dump.stderr);
        member.kill('SIGKILL');
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
