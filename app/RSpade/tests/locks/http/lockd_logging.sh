#!/bin/bash

TEST_NAME="rsx-lockd event logging (the injected table log, the logger, the live log op)"

# Three layers, weakest to strongest:
#   1. THE STATE MACHINE, pure - lib/locktable.js driven on a fake clock with a collector
#      for its injected log(build, level). Proves what each event says and the ORDER rules:
#      a TIMEOUT line names the holder (so it is built before the waiter is removed), and a
#      DROP / FORCE_CLEAR line precedes the GRANT lines it causes. No socket, no file.
#   2. THE SINK, Lockd_Logger - gating by level, a build that is never called while events
#      are off, and the guarantee that nothing a log destination does can throw into the
#      daemon: an unopenable path, a build that throws.
#   3. THE LIVE OP on a scratch daemon - `log` is refused before hello, toggles recording
#      on and off without a restart, validates every key, and a real contended acquire
#      leaves a TIMEOUT line naming the real holder in the file.
#
# A scratch daemon on a scratch port serves layer 3; the supervised daemon is never touched.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

trap lockd_stop EXIT

if ! lockd_start 6331; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

export LOCKD_TMP LOCKD_CONF

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const h = require(process.env.LOCKD_HARNESS_LIB);
const { Lock_Table } = h.locktable;
const { Lockd_Logger } = h.lockd;
const config_lib = require(path.join(h.LOCKD_DIR, 'lib', 'config.js'));

const TMP = process.env.LOCKD_TMP;
const DAEMON_LOG = path.join(TMP, 'lockd.log');

/** A table on a fake clock, with every log() call collected as {line, level}. */
function make_table() {
    const state = { now: 0, timers: [], lines: [], delivered: [] };
    const table = new Lock_Table({
        deliver: (conn_id, frame) => {
            state.lines.push({ line: 'DELIVER ' + frame.status + ' to ' + conn_id, level: 'delivery' });
            state.delivered.push({ conn_id: conn_id, frame: frame });
        },
        now: () => state.now,
        set_timeout: (fn, ms) => { state.timers.push({ fn: fn, ms: ms }); return state.timers.length; },
        clear_timeout: () => {},
        log: (build, level) => state.lines.push({ line: build(), level: level }),
    });
    table.register_connection('a', { host: 'web-1', pid: 101 });
    table.register_connection('b', { host: 'web-2', pid: 202 });
    table.register_connection('c', { host: 'web-3', pid: 303 });
    return { table: table, state: state };
}

function events(state, level) {
    return state.lines.filter((entry) => entry.level === level).map((entry) => entry.line);
}

function index_of(state, prefix) {
    return state.lines.findIndex((entry) => entry.line.startsWith(prefix));
}

async function wait_for_log(pattern, ms) {
    const deadline = Date.now() + ms;
    while (Date.now() < deadline) {
        if (fs.existsSync(DAEMON_LOG) && pattern.test(fs.readFileSync(DAEMON_LOG, 'utf8'))) return true;
        await h.sleep(20);
    }
    return false;
}

function daemon_log() {
    return fs.existsSync(DAEMON_LOG) ? fs.readFileSync(DAEMON_LOG, 'utf8') : '';
}

h.run(async function () {
    // =====================================================================================
    // 1. The state machine
    // =====================================================================================

    // ---- No sink at all ------------------------------------------------------------------
    {
        const table = new Lock_Table({ deliver: () => {}, now: () => 0, set_timeout: () => null, clear_timeout: () => {} });
        table.register_connection('a', { host: 'h', pid: 1 });
        table.register_connection('b', { host: 'h', pid: 2 });
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: null });
        table.drop_connection('a');
        h.check('a table given no log runs every event path without one', table.counters.granted === 2);
    }

    // ---- Levels ----------------------------------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        state.now = 2500;
        table.release('a', { id: 2, name: 'L' });

        const verbose = events(state, 'verbose');
        h.check('an uncontended acquire and release are VERBOSE events only',
            verbose.length === 2 && events(state, undefined).length === 0);
        h.check('ACQUIRE names the mode, the lock and the connection with host and pid',
            verbose[0] === 'ACQUIRE WRITE L by a [web-1:101]');
        h.check('RELEASE says how long it was held',
            verbose[1] === 'RELEASE WRITE L by a [web-1:101] after 2.5s');
    }

    // ---- WAIT, TIMEOUT: the holder is named -------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        state.now = 5100;
        const parked = table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: 2 });
        h.check('a contended acquire parks', parked === null && state.timers.length === 1);

        const contention = events(state, undefined);
        h.check('WAIT is a default-level event naming who holds the lock and for how long',
            contention.length === 1
            && contention[0] === 'WAIT WRITE L by b [web-2:202] - held by a [web-1:101] WRITE for 5.1s, queue depth 1, timeout 2s');

        state.now = 7100;
        state.timers[0].fn();

        const timeout_line = events(state, undefined)[1];
        h.check('TIMEOUT names the holder that was still in place',
            timeout_line === 'TIMEOUT WRITE L for b [web-2:202] after 2s (waited 2.0s) - still held by a [web-1:101] WRITE for 7.1s');
        h.check('the TIMEOUT line is emitted before the timeout frame is delivered',
            index_of(state, 'TIMEOUT ') < index_of(state, 'DELIVER timeout'));
        h.check('and the waiter did time out', state.delivered.length === 1
            && state.delivered[0].frame.status === 'timeout' && table.counters.timed_out === 1);
    }

    // ---- A wait with no timeout says so -----------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'read', timeout: null });
        table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: null });
        h.check('a wait-forever request is labelled, and arms no timer',
            events(state, undefined)[0].endsWith(', queue depth 1, waiting forever') && state.timers.length === 0);
    }

    // ---- GRANT after a wait ---------------------------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: null });
        state.now = 3000;
        table.release('a', { id: 3, name: 'L' });
        h.check('GRANT closes the WAIT with how long the queue cost',
            events(state, undefined)[1] === 'GRANT WRITE L to b [web-2:202] after waiting 3.0s');
    }

    // ---- DROP precedes the grants it causes -----------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: null });
        table.acquire('c', { id: 3, name: 'M', mode: 'write', timeout: null });
        table.drop_connection('c');
        h.check('a dropped connection that held something is a default-level DROP line',
            events(state, undefined).includes('DROP c [web-3:303] released 1 hold(s), cancelled 0 wait(s)'));

        table.drop_connection('a');
        const drop = index_of(state, 'DROP a ');
        const grant = index_of(state, 'GRANT WRITE L to b');
        h.check('the DROP line comes BEFORE the GRANT it caused', drop !== -1 && grant !== -1 && drop < grant);

        const before = state.lines.length;
        table.register_connection('d', { host: 'web-4', pid: 404 });
        table.drop_connection('d');
        h.check('a connection that held and wanted nothing drops in silence', state.lines.length === before);
    }

    // ---- DEADLOCK -------------------------------------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        table.acquire('b', { id: 2, name: 'M', mode: 'write', timeout: null });
        table.acquire('a', { id: 3, name: 'M', mode: 'write', timeout: null });
        const refused = table.acquire('b', { id: 4, name: 'L', mode: 'write', timeout: null });
        const line = events(state, undefined).find((entry) => entry.startsWith('DEADLOCK'));
        h.check('a refused deadlock is logged with the requester and the cycle',
            refused.status === 'deadlock' && line !== undefined
            && line.startsWith('DEADLOCK refused: b [web-2:202] acquiring WRITE L would close a cycle -- '));
    }

    // ---- FORCE_CLEAR ---------------------------------------------------------------------------
    {
        const { table, state } = make_table();
        table.acquire('a', { id: 1, name: 'L', mode: 'write', timeout: null });
        table.acquire('b', { id: 2, name: 'L', mode: 'write', timeout: null });
        table.force_clear({ id: 3, name: 'L' });
        const clear = index_of(state, 'FORCE_CLEAR L - was held by a [web-1:101] WRITE');
        const grant = index_of(state, 'GRANT WRITE L to b');
        h.check('FORCE_CLEAR names the holder it broke, before the grant it caused',
            clear !== -1 && grant !== -1 && clear < grant);
    }

    // ---- Semaphores log the same events ---------------------------------------------------------
    {
        const { table, state } = make_table();
        table.sem_acquire('a', { id: 1, name: 'S', max_slots: 1, timeout: null });
        table.sem_acquire('b', { id: 2, name: 'S', max_slots: 1, timeout: 4 });
        table.sem_acquire('c', { id: 3, name: 'S', max_slots: 1, timeout: null });
        state.now = 4000;
        state.timers[0].fn();
        state.now = 6000;
        table.drop_connection('a');

        const contention = events(state, undefined);
        h.check('a semaphore WAIT names the slot holders',
            contention[0] === 'WAIT semaphore S by b [web-2:202] - held by 1/1 slots: a [web-1:101] slot 0 for 0.0s, queue depth 1, timeout 4s');
        h.check('a semaphore TIMEOUT names the slot holders',
            contention.includes('TIMEOUT semaphore S for b [web-2:202] after 4s (waited 4.0s) - still held by 1/1 slots: a [web-1:101] slot 0 for 4.0s'));
        h.check('a semaphore wait ends in a GRANT line too',
            contention.includes('GRANT semaphore S to c [web-3:303] after waiting 6.0s'));
        h.check('semaphore acquires are verbose', events(state, 'verbose').includes('ACQUIRE semaphore S slot 0 by a [web-1:101]'));
    }

    // =====================================================================================
    // 2. The sink
    // =====================================================================================
    {
        const file = path.join(TMP, 'unit.log');
        const logger = new Lockd_Logger({ logfile: file });
        let built = 0;
        const build = () => { built++; return 'EVENT-LINE'; };

        logger.event(build);
        logger.event(build, 'verbose');
        h.check('with events off no line is built at all', built === 0);

        h.check('enable() answers true and keeps the configured file', logger.enable({}) === true && logger.status().path === file);
        logger.event(build);
        logger.event(build, 'verbose');
        h.check('the default level builds contention lines and skips verbose ones', built === 1);

        logger.set_verbose(true);
        logger.event(build, 'verbose');
        h.check('verbose builds the per-operation lines too', built === 2);

        let threw = false;
        try {
            logger.event(() => { throw new Error('a broken description'); });
        } catch (err) {
            threw = true;
        }
        h.check('a build that throws is counted, never thrown into the caller',
            threw === false && logger.status().write_errors === 1 && logger.status().last_error === 'a broken description');

        let opened;
        try {
            opened = logger.enable({ path: path.join(TMP, 'no-such-directory', 'x.log') });
        } catch (err) {
            opened = 'threw';
        }
        const status = logger.status();
        h.check('an unopenable destination answers false, is counted, and leaves the log where it was',
            opened === false && status.write_errors === 2 && status.path === file && status.writable === true);

        logger.disable();
        h.check('disable() turns both switches off', logger.status().events === false && logger.status().verbose === false);

        const bare = new Lockd_Logger({});
        h.check('a logger with no file reports the daemon stdout as its destination',
            bare.status().path === null && bare.status().writable === false);
    }

    // ---- Config ---------------------------------------------------------------------------------
    {
        const defaults = config_lib.validate_config({});
        h.check('event logging starts off by default', defaults.log_events === false && defaults.log_verbose === false);

        let message = '';
        try {
            config_lib.validate_config({ log_verbose: true });
        } catch (err) {
            message = err.message;
        }
        h.check('log_verbose without log_events is a startup error', message.includes("'log_verbose' is true but 'log_events' is false"));
        h.check('both together validate', config_lib.validate_config({ log_events: true, log_verbose: true }).log_verbose === true);
    }

    // =====================================================================================
    // 3. The live op
    // =====================================================================================

    // ---- Unreachable before hello -------------------------------------------------------------
    {
        const socket = await h.client_lib.connect_once({ host: '127.0.0.1', port: h.PORT });
        const reader = new h.protocol.Frame_Reader();
        const frames = [];
        socket.on('data', (chunk) => {
            for (const line of reader.push(chunk).lines) frames.push(h.protocol.decode_frame(line).value);
        });
        socket.on('error', () => {});

        const sneaky = path.join(TMP, 'pre-hello.log');
        socket.write(h.protocol.encode_frame({ op: 'log', id: 'x1', enabled: true, path: sneaky }));
        socket.write(h.protocol.encode_frame({ op: 'log', id: 'x2' }));
        socket.write(h.protocol.encode_frame({ op: 'ping', id: 'x3' }));

        const deadline = Date.now() + 3000;
        while (frames.length < 3 && Date.now() < deadline) await h.sleep(10);
        socket.destroy();

        h.check('a log switch before hello is refused as unauthenticated',
            frames.length === 3 && frames[0].status === 'error' && /Not authenticated/.test(frames[0].message));
        h.check('so is the bare query - the op discloses nothing pre-hello',
            frames[1].status === 'error' && frames[1].logging === undefined);
        h.check('and the file it named was never created', !fs.existsSync(sneaky));
    }

    const admin = await h.connect();
    const holder = await h.connect();
    const waiter = await h.connect();

    // ---- Query, and the off state -------------------------------------------------------------
    {
        const state = await admin.request({ op: 'log' });
        h.check('a bare log frame is a pure query: off, at the configured logfile',
            state.status === 'ok' && state.logging.events === false && state.logging.verbose === false
            && state.logging.path === DAEMON_LOG && state.logging.writable === true);

        await holder.request({ op: 'acquire', name: 'QUIET', mode: 'write', timeout: null });
        const timed_out = await waiter.request({ op: 'acquire', name: 'QUIET', mode: 'write', timeout: 0.2 });
        await holder.request({ op: 'release', name: 'QUIET' });
        await admin.request({ op: 'ping' });
        h.check('with logging off, real contention writes no event line',
            timed_out.status === 'timeout' && !/WAIT|TIMEOUT|ACQUIRE/.test(daemon_log()));
    }

    // ---- Validation: nothing is silently ignored ----------------------------------------------
    {
        const cases = [
            [{ op: 'log', enabled: 'yes' }, /enabled must be true or false/],
            [{ op: 'log', enabled: true, verbose: 1 }, /verbose must be true or false/],
            [{ op: 'log', enabled: true, path: 'relative.log' }, /absolute path/],
            [{ op: 'log', path: path.join(TMP, 'orphan.log') }, /only accepted together with enabled:true/],
            [{ op: 'log', verbose: true }, /Event logging is off/],
            [{ op: 'log', enabled: false, verbose: true }, /contradicts/],
            [{ op: 'log', enabled: true, path: path.join(TMP, 'no-such-directory', 'x.log') }, /Cannot open the log destination/],
        ];
        let refused = 0;
        for (const [frame, pattern] of cases) {
            const answer = await admin.request(frame);
            if (answer.status === 'error' && pattern.test(answer.message)) refused++;
            else h.note('not refused as expected: ' + JSON.stringify(frame) + ' -> ' + JSON.stringify(answer));
        }
        h.check('every malformed or do-nothing log frame is an error frame', refused === cases.length);

        const state = await admin.request({ op: 'log' });
        h.check('and none of them changed anything, the unopenable path included',
            state.logging.events === false && state.logging.path === DAEMON_LOG && state.logging.writable === true
            && state.logging.write_errors === 1);
    }

    // ---- On: a real TIMEOUT names the real holder ------------------------------------------------
    {
        const on = await admin.request({ op: 'log', enabled: true });
        h.check('log on answers the new state', on.status === 'ok' && on.logging.events === true && on.logging.verbose === false);
        h.check('and marks the moment in the file', await wait_for_log(/Event logging ON - switched at runtime/, 3000));

        await holder.request({ op: 'acquire', name: 'CONTENDED', mode: 'write', timeout: null });
        const timed_out = await waiter.request({ op: 'acquire', name: 'CONTENDED', mode: 'write', timeout: 0.3 });
        h.check('the waiter timed out behind the holder', timed_out.status === 'timeout');

        const me = '\\[' + require('os').hostname().replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ':' + process.pid + '\\]';
        h.check('the WAIT line names the holder by connection, host and pid',
            await wait_for_log(new RegExp('WAIT WRITE CONTENDED by c\\d+ ' + me + ' - held by c\\d+ ' + me + ' WRITE for '), 3000));
        h.check('the TIMEOUT line says who still held it',
            await wait_for_log(new RegExp('TIMEOUT WRITE CONTENDED for c\\d+ ' + me + ' after 0\\.3s \\(waited 0\\.3s\\) - still held by c\\d+ ' + me + ' WRITE for '), 3000));
        h.check('the default level records no per-operation line', !/ACQUIRE|RELEASE/.test(daemon_log()));

        // A dropped holder: the DROP line, then the GRANT it caused.
        const parked = waiter.request({ op: 'acquire', name: 'CONTENDED', mode: 'write', timeout: null });
        await h.sleep(100);
        holder.close();
        const granted = await parked;
        h.check('the waiter is granted when the holder disconnects', granted.status === 'granted');
        await wait_for_log(/GRANT WRITE CONTENDED/, 3000);
        const text = daemon_log();
        h.check('DROP is written before the GRANT it caused',
            text.indexOf('DROP c') !== -1 && text.indexOf('DROP c') < text.indexOf('GRANT WRITE CONTENDED'));
        await waiter.request({ op: 'release', name: 'CONTENDED' });
    }

    // ---- Verbose, on a running log, and a new destination ------------------------------------------
    {
        const louder = await admin.request({ op: 'log', verbose: true });
        h.check('verbose alone adjusts a log that is recording', louder.status === 'ok' && louder.logging.verbose === true);

        await waiter.request({ op: 'acquire', name: 'TRACED', mode: 'read', timeout: null });
        await waiter.request({ op: 'release', name: 'TRACED' });
        h.check('verbose records every acquire and release',
            await wait_for_log(/ACQUIRE READ TRACED by c\d+/, 3000) && await wait_for_log(/RELEASE READ TRACED by c\d+/, 3000));

        const elsewhere = path.join(TMP, 'elsewhere.log');
        const moved = await admin.request({ op: 'log', enabled: true, path: elsewhere });
        h.check('log on with a path moves the destination and resets verbosity to what was asked',
            moved.status === 'ok' && moved.logging.path === elsewhere && moved.logging.verbose === false);
    }

    // ---- Off -------------------------------------------------------------------------------------
    {
        const off = await admin.request({ op: 'log', enabled: false });
        h.check('log off answers the off state', off.status === 'ok' && off.logging.events === false && off.logging.verbose === false);

        const elsewhere = path.join(TMP, 'elsewhere.log');
        const second = await h.connect();
        await second.request({ op: 'acquire', name: 'AFTER_OFF', mode: 'write', timeout: null });
        const timed_out = await waiter.request({ op: 'acquire', name: 'AFTER_OFF', mode: 'write', timeout: 0.2 });
        await admin.request({ op: 'ping' });
        const text = fs.readFileSync(elsewhere, 'utf8');
        h.check('after log off, contention is not recorded', timed_out.status === 'timeout' && !/AFTER_OFF/.test(text));
        h.check('and the switch-off is in the file', /Event logging OFF - switched at runtime/.test(text));
        second.close();
    }

    // ---- The CLI ---------------------------------------------------------------------------------
    {
        const lockd_js = path.join(h.LOCKD_DIR, 'lockd.js');
        const cli = (args) => execFileSync('node', [lockd_js, 'log'].concat(args, ['--config=' + process.env.LOCKD_CONF]), { encoding: 'utf8' });

        h.check('`lockd.js log` reports the state', /rsx-lockd event logging: OFF/.test(cli([])));
        h.check('`lockd.js log on --verbose` switches the running daemon',
            /rsx-lockd event logging: ON \(verbose/.test(cli(['on', '--verbose'])));
        const json = JSON.parse(cli(['--json']));
        h.check('`lockd.js log --json` is the raw state', json.events === true && json.verbose === true);

        let exit_code = 0;
        try {
            execFileSync('node', [lockd_js, 'log', 'on', '--path=relative.log', '--config=' + process.env.LOCKD_CONF], { encoding: 'utf8', stdio: 'pipe' });
        } catch (err) {
            exit_code = err.status;
        }
        h.check('a refused request exits 1', exit_code === 1);
        h.check('`lockd.js log off` switches it back', /rsx-lockd event logging: OFF/.test(cli(['off'])));
    }

    const alive = await admin.request({ op: 'ping' });
    h.check('the daemon is still answering after all of it', alive.status === 'ok' && alive.pong === true);

    admin.close();
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
