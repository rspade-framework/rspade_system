'use strict';

/**
 * Shared node-side helper for the rsx-lockd shell tests.
 *
 * Requiring the daemon's own modules IS the export seam: lockd.js binds no port and reads
 * no config until main() runs, and main() only runs under require.main === module. So this
 * file can load the real protocol, the real client and the real state machine without
 * touching - or even noticing - the supervised daemon.
 *
 * Every test drives a SCRATCH daemon whose port arrives in LOCKD_PORT (see
 * _lib/lockd_test_lib.sh). Nothing here ever dials the configured production port.
 */

const path = require('path');

const LOCKD_DIR = process.env.LOCKD_DIR || '/var/www/html/system/bin/rsx-lockd';
const PROJECT_ROOT = process.env.LOCKD_PROJECT_ROOT || '/var/www/html';

const lockd = require(path.join(LOCKD_DIR, 'lockd.js'));
const protocol = require(path.join(LOCKD_DIR, 'lib', 'protocol.js'));
const client_lib = require(path.join(LOCKD_DIR, 'lib', 'client.js'));
const locktable = require(path.join(LOCKD_DIR, 'lib', 'locktable.js'));

// The daemon reads the same file the same way, so client and server share one key.
lockd.load_env_file(path.join(PROJECT_ROOT, '.env'));

const PORT = parseInt(process.env.LOCKD_PORT || '0', 10);

let failures = 0;

function check(name, condition) {
    if (condition) {
        console.log('  ok   ' + name);
    } else {
        console.log('  FAIL ' + name);
        failures++;
    }
    return !!condition;
}

function note(message) {
    console.log('  ..   ' + message);
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * A client connection to the SCRATCH daemon, hello completed.
 *
 * `options.group_id` joins a lock group, which is how a test stands in for a subprocess
 * that inherited its parent's locks.
 */
async function connect(options) {
    if (!PORT) {
        throw new Error('LOCKD_PORT is not set - the scratch daemon was not started');
    }

    const config = {
        unix: { enabled: false, path: '' },
        tcp: { enabled: true, port: PORT, bind: '127.0.0.1' },
    };

    return await client_lib.connect(config, {
        hmac_key: process.env.APP_KEY || '',
        host: '127.0.0.1',
        port: PORT,
        retries: 5,
        retry_delay_ms: 200,
        group_id: (options && options.group_id) || null,
    });
}

/**
 * Resolve to the frame if the request answered within ms, or null if it is still parked.
 *
 * "Waiting is silence" is a protocol guarantee, so proving a request is STILL WAITING means
 * proving no frame arrived - which is exactly what a null here says.
 */
async function answer_within(promise, ms) {
    const pending = Symbol('pending');
    const result = await Promise.race([
        promise.then((frame) => ({ frame: frame })),
        sleep(ms).then(() => pending),
    ]);

    return result === pending ? null : result.frame;
}

/**
 * Start a lock holder in a SEPARATE process (lockd_holder.js) and follow its output.
 *
 * A separate process is what makes an exclusion or death test mean anything: two
 * connections from one process would prove only that the daemon keys on connections, and a
 * kill -9 has to have something of its own to kill.
 */
function spawn_holder(args) {
    const child_process = require('child_process');
    const holder_path = path.join(__dirname, 'lockd_holder.js');

    const proc = child_process.spawn(process.execPath, [holder_path].concat(args), {
        env: process.env,
        stdio: ['pipe', 'pipe', 'pipe'],
    });

    const lines = [];
    let stdout_buffer = '';
    let stderr = '';

    proc.stdout.setEncoding('utf8');
    proc.stdout.on('data', (chunk) => {
        stdout_buffer += chunk;
        let index = stdout_buffer.indexOf('\n');
        while (index !== -1) {
            const line = stdout_buffer.slice(0, index).trim();
            stdout_buffer = stdout_buffer.slice(index + 1);
            if (line.length > 0) lines.push({ line: line, at: Date.now() });
            index = stdout_buffer.indexOf('\n');
        }
    });
    proc.stderr.setEncoding('utf8');
    proc.stderr.on('data', (chunk) => { stderr += chunk; });

    let exit_code = null;
    proc.on('exit', (code) => { exit_code = code; });

    return {
        proc: proc,
        pid: proc.pid,
        lines: lines,
        stderr: () => stderr,
        exit_code: () => exit_code,

        /** The first line starting with `prefix`, or null if it never arrived in time. */
        wait_for: async function (prefix, ms) {
            const deadline = Date.now() + ms;
            while (Date.now() < deadline) {
                const hit = lines.find((entry) => entry.line.startsWith(prefix));
                if (hit) return hit;
                await sleep(25);
            }
            return null;
        },

        saw: function (prefix) {
            return lines.some((entry) => entry.line.startsWith(prefix));
        },

        release: function () {
            proc.stdin.write('release\n');
        },

        kill: function (signal) {
            try {
                proc.kill(signal || 'SIGKILL');
            } catch (err) {
                // Already gone.
            }
        },
    };
}

/** Milliseconds since the process started, monotonic - safe for ordering assertions. */
function now_ms() {
    return Number(process.hrtime.bigint() / 1000000n);
}

function summary() {
    if (failures > 0) {
        console.log('RESULT: FAIL (' + failures + ' assertion(s))');
        process.exit(1);
    }
    console.log('RESULT: PASS');
    process.exit(0);
}

/** Any throw out of a harness is a test failure, never a silent zero-assertion pass. */
function run(main_fn) {
    main_fn().then(() => summary()).catch((err) => {
        console.log('  FAIL harness threw: ' + (err && err.stack ? err.stack : err));
        console.log('RESULT: FAIL (harness error)');
        process.exit(1);
    });
}

module.exports = {
    LOCKD_DIR,
    PROJECT_ROOT,
    PORT,
    lockd,
    protocol,
    client_lib,
    locktable,
    check,
    note,
    sleep,
    connect,
    spawn_holder,
    answer_within,
    now_ms,
    summary,
    run,
};
