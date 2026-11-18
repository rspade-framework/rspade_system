#!/bin/bash

TEST_NAME="rsx-lockd progress watchdog: stall, progress, notice (fake clock + live daemon)"

# The progress watchdog, first on the pure state machine with a FAKE CLOCK and a timer
# queue (every rule, deterministically, no waiting), then against a scratch daemon over the
# wire (the frames really arrive, in order, with the right shapes).
#
# Proves:
#   - a request with no `stall` / `notice` arms no timer (wait forever is untouched);
#   - a waiter behind a silent holder is answered `stalled` once the window passes, naming
#     the holder with held_ms / progress_ms; the holder keeps its lock;
#   - a `progress` frame from the holder restarts the window (re-armed for the remainder);
#   - a grant is progress: a moving queue never stalls the waiter at its back;
#   - a lock waits on its LEAST-recently progressing holder, a semaphore on its MOST-recent;
#   - a holder that is itself parked takes its progress from what it waits on;
#   - a shorter `timeout` answers `timeout` and the stall never fires;
#   - `notice` sends exactly one `waiting` frame and the wait continues to its grant;
#   - stall / notice of 0 or a non-number are refused; hello `start` is validated;
#   - dump carries start, progress_ms, stall; ping and hello answer protocol 2.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6302; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);
const { Lock_Table } = h.locktable;

/** A Lock_Table on a fake clock whose timers fire only when advance() reaches them. */
function make_table() {
    const clock = { now: 0 };
    const timers = [];
    const delivered = [];
    const table = new Lock_Table({
        deliver: (conn_id, frame) => delivered.push({ conn_id: conn_id, frame: frame, at: clock.now }),
        now: () => clock.now,
        set_timeout: (fn, ms) => {
            const timer = { fn: fn, at: clock.now + ms, live: true };
            timers.push(timer);
            return timer;
        },
        clear_timeout: (timer) => { if (timer) timer.live = false; },
    });
    for (const [id, pid] of [['a', 1], ['b', 2], ['c', 3], ['d', 4]]) {
        table.register_connection(id, { host: 'h' + pid, pid: pid, start: 1000 + pid });
    }

    /** Move the clock to `to`, firing every live timer due on the way, in order. */
    const advance = (to) => {
        while (true) {
            const due = timers.filter((t) => t.live && t.at <= to).sort((x, y) => x.at - y.at)[0];
            if (!due) break;
            due.live = false;
            clock.now = due.at;
            due.fn();
        }
        clock.now = to;
    };
    const live_timers = () => timers.filter((t) => t.live).length;
    const frames_for = (conn_id) => delivered.filter((d) => d.conn_id === conn_id).map((d) => d.frame);

    return { table, clock, advance, live_timers, frames_for, delivered };
}

h.run(async function () {
    // ---- No watch keys: nothing armed ---------------------------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        h.check('a plain parked acquire returns null', t.table.acquire('b', { id: 2, name: 'L', mode: 'write' }) === null);
        h.check('a request with no stall / notice / timeout arms no timer', t.live_timers() === 0);
        t.advance(10 * 60 * 60 * 1000);
        h.check('ten hours later it has received nothing', t.frames_for('b').length === 0);
    }

    // ---- A silent holder stalls its waiter ----------------------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        t.table.acquire('b', { id: 2, name: 'L', mode: 'write', stall: 10 });
        t.advance(9999);
        h.check('no answer before the window closes', t.frames_for('b').length === 0);
        t.advance(10000);
        const f = t.frames_for('b');
        h.check('stalled exactly at the window', f.length === 1 && f[0].status === 'stalled' && f[0].id === 2);
        h.check('the frame names the holder with host, pid, mode, held and progress',
            f[0].holders.length === 1 && f[0].holders[0].conn_id === 'a' && f[0].holders[0].host === 'h1'
            && f[0].holders[0].pid === 1 && f[0].holders[0].mode === 'write'
            && f[0].holders[0].held_ms === 10000 && f[0].holders[0].progress_ms === 10000);
        h.check('the frame carries waited_ms and the window', f[0].waited_ms === 10000 && f[0].stall === 10);
        h.check('the message is not the load-bearing timeout wording', !/Failed to acquire.*lock/.test(f[0].message));
        const stats = t.table.stats({ name: 'L' });
        h.check('the holder still holds; the waiter is gone from the queue',
            stats.writer_active === true && stats.writer_conn === 'a' && stats.queue_length === 0);
        h.check('counted as stalled', stats.counters.stalled === 1 && stats.counters.timed_out === 0);
    }

    // ---- Progress restarts the window ---------------------------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        t.table.acquire('b', { id: 2, name: 'L', mode: 'write', stall: 10 });
        t.advance(8000);
        const p = t.table.progress('a', { id: 3 });
        h.check('progress is answered at once, counting the holds', p.status === 'ok' && p.id === 3 && p.holds === 1);
        t.advance(17999);
        h.check('the window restarted from the progress signal', t.frames_for('b').length === 0);
        t.advance(18000);
        h.check('and closes ten seconds after it', t.frames_for('b').length === 1 && t.frames_for('b')[0].status === 'stalled');
        h.check('the stalled frame reports progress 10s ago and held 18s',
            t.frames_for('b')[0].holders[0].progress_ms === 10000 && t.frames_for('b')[0].holders[0].held_ms === 18000);
    }

    // ---- A grant is progress -------------------------------------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        t.table.acquire('b', { id: 2, name: 'L', mode: 'write', stall: 10 });
        t.table.acquire('c', { id: 3, name: 'L', mode: 'write', stall: 10 });
        t.advance(5000);
        t.table.release('a', { id: 4, name: 'L' });
        h.check('b is granted at 5s', t.frames_for('b').length === 1 && t.frames_for('b')[0].status === 'granted');
        t.advance(14999);
        h.check('c, waiting since 0, is not stalled at 10s: the grant at 5s was progress', t.frames_for('c').length === 0);
        t.advance(15000);
        h.check('c stalls ten seconds after the grant, naming b', t.frames_for('c').length === 1
            && t.frames_for('c')[0].status === 'stalled' && t.frames_for('c')[0].holders[0].conn_id === 'b');
    }

    // ---- Lock: least-recent holder; semaphore: most-recent -------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'read' });
        t.table.acquire('b', { id: 2, name: 'L', mode: 'read' });
        t.table.acquire('c', { id: 3, name: 'L', mode: 'write', stall: 10 });
        t.advance(8000);
        t.table.progress('b', { id: 4 });
        t.advance(10000);
        h.check('a writer stalls behind one silent reader however busy the other is',
            t.frames_for('c').length === 1 && t.frames_for('c')[0].status === 'stalled'
            && t.frames_for('c')[0].holders.length === 2);
    }
    {
        const t = make_table();
        t.table.sem_acquire('a', { id: 1, name: 'S', max_slots: 2 });
        t.table.sem_acquire('b', { id: 2, name: 'S', max_slots: 2 });
        t.table.sem_acquire('c', { id: 3, name: 'S', max_slots: 2, stall: 10 });
        t.advance(8000);
        t.table.progress('b', { id: 4 });
        t.advance(17999);
        h.check('a semaphore waiter keeps waiting while any slot holder progresses', t.frames_for('c').length === 0);
        t.advance(18000);
        const f = t.frames_for('c');
        h.check('and stalls once every slot holder is silent, naming the slots',
            f.length === 1 && f[0].status === 'stalled' && f[0].holders.length === 2
            && f[0].holders.every((x) => typeof x.slot === 'number'));
    }

    // ---- A parked holder borrows the progress of what it waits on ------------------
    {
        const t = make_table();
        t.table.acquire('b', { id: 1, name: 'Y', mode: 'write' });
        t.table.acquire('a', { id: 2, name: 'X', mode: 'write' });
        t.table.acquire('a', { id: 3, name: 'Y', mode: 'write' });          // a holds X, waits for Y
        t.table.acquire('c', { id: 4, name: 'X', mode: 'write', stall: 10 });
        for (let at = 5000; at <= 30000; at += 5000) {
            t.advance(at);
            t.table.progress('b', { id: 10 + at });
        }
        h.check('a waiter behind a holder that is itself waiting on a progressing holder keeps waiting',
            t.frames_for('c').length === 0);
        t.advance(40000);
        h.check('and stalls once that chain goes silent', t.frames_for('c').length === 1
            && t.frames_for('c')[0].status === 'stalled');
    }

    // ---- A shorter explicit timeout keeps its own semantics ------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        t.table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: 2, stall: 10 });
        t.advance(60000);
        const f = t.frames_for('b');
        h.check('the timeout answers, verbatim, and the stall never fires',
            f.length === 1 && f[0].status === 'timeout'
            && f[0].message === 'Failed to acquire WRITE lock for L after 2 seconds');
        h.check('no timer is left armed', t.live_timers() === 0);
    }

    // ---- The notice ----------------------------------------------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        t.table.acquire('b', { id: 2, name: 'L', mode: 'write', notice: 3 });
        t.advance(2999);
        h.check('no notice before its time', t.frames_for('b').length === 0);
        t.advance(60000);
        let f = t.frames_for('b');
        h.check('exactly one waiting notice, echoing the id, naming the holder',
            f.length === 1 && f[0].status === 'waiting' && f[0].id === 2 && f[0].waited_ms === 3000
            && f[0].holders[0].conn_id === 'a');
        t.table.release('a', { id: 3, name: 'L' });
        f = t.frames_for('b');
        h.check('the wait continued to its grant', f.length === 2 && f[1].status === 'granted' && f[1].id === 2);
    }
    {
        const t = make_table();
        t.table.acquire('b', { id: 2, name: 'L', mode: 'write', notice: 3 });
        t.advance(60000);
        h.check('an immediate grant gets no notice', t.frames_for('b').length === 0 && t.live_timers() === 0);
    }

    // ---- Validation and dump -------------------------------------------------------
    {
        const t = make_table();
        t.table.acquire('a', { id: 1, name: 'L', mode: 'write' });
        h.check('stall 0 is refused', t.table.acquire('b', { id: 2, name: 'L', stall: 0 }).status === 'error');
        h.check('a non-number notice is refused', t.table.acquire('b', { id: 3, name: 'L', notice: '30' }).status === 'error');
        h.check('sem_acquire validates too',
            t.table.sem_acquire('b', { id: 4, name: 'S', max_slots: 1, stall: -1 }).status === 'error');

        t.table.acquire('b', { id: 5, name: 'L', mode: 'write', stall: 600 });
        t.advance(4000);
        t.table.progress('a', { id: 6 });
        t.advance(5000);
        const dump = t.table.dump({ id: 7 });
        const a = dump.connections.find((c) => c.conn_id === 'a');
        const b = dump.connections.find((c) => c.conn_id === 'b');
        h.check('dump carries each connection start and last progress',
            a.start === 1001 && a.progress_ms === 1000 && b.progress_ms === null);
        h.check('dump carries a hold progress_ms', a.holds[0].progress_ms === 1000 && a.holds[0].held_ms === 5000);
        h.check('dump carries a wait stall', b.waiting[0].stall === 600 && dump.locks[0].queue[0].stall === 600);
        h.check('dump counters include stalled', dump.counters.stalled === 0);
    }
    {
        const p = h.protocol;
        const key = 'k';
        const ok = p.verify_hello(Object.assign(p.build_hello('h', 7, 1000, key, null, 4242)), key, 1000);
        h.check('a hello start is carried into the meta', ok.ok && ok.meta.start === 4242);
        const bad = Object.assign(p.build_hello('h', 7, 1000, key, null), { start: -1 });
        h.check('a negative start is refused', p.verify_hello(bad, key, 1000).ok === false);
        h.check('no start is null', p.verify_hello(p.build_hello('h', 7, 1000, key, null), key, 1000).meta.start === null);
        h.check('the protocol version is 2', p.PROTOCOL_VERSION === 2);
    }

    // ---- Live daemon ---------------------------------------------------------------
    const holder = await h.connect();
    const waiter = await h.connect();

    const pong = await holder.request({ op: 'ping' });
    h.check('ping announces protocol 2', pong.protocol === 2);

    const granted = await holder.request({ op: 'acquire', name: 'STALL_LIVE', mode: 'write' });
    h.check('the holder is granted', granted.status === 'granted');

    // The event takes ~1 s on an idle box; the bound is 100x that, and reaching it fails.
    const notices = [];
    const answer = await h.answer_within(
        waiter.request({ op: 'acquire', name: 'STALL_LIVE', mode: 'write', stall: 1, notice: 0.3 }, (f) => notices.push(f)),
        120000
    );
    h.check('the live waiter got one waiting notice first', notices.length === 1 && notices[0].status === 'waiting');
    h.check('then a stalled answer naming the holder pid',
        answer !== null && answer.status === 'stalled' && answer.holders[0].pid === process.pid);

    const progress = await holder.request({ op: 'progress' });
    h.check('the live progress op answers ok', progress.status === 'ok' && progress.holds === 1);

    const dump = await holder.request({ op: 'dump' });
    const mine = dump.connections.find((c) => c.conn_id === holder.conn_id);
    h.check('the node client sent its process start time at hello',
        mine && Number.isInteger(mine.start) && mine.start === h.client_lib.process_start_time(process.pid));

    holder.close();
    waiter.close();
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
