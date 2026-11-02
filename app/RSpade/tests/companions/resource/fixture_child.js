/**
 * A scriptable companion for rsx-companiond's process tests.
 *
 *     node fixture_child.js <mode>
 *
 * Writes <FIXTURE_DIR>/<RSX_COMPANION_INSTANCE>.json on start: {pid, env, grandchild}.
 *
 *   sdk               the SDK's ready(), then idles; FIXTURE_STOP_MS delays the clean exit on stop
 *   ignore_term       no SDK, and SIGTERM ignored: only the group SIGKILL ends it
 *   grandchild        no SDK; starts a grandchild in its own group that ignores SIGTERM
 *   exit_<n>          exits with n at once
 *   exit_contract     once <FIXTURE_DIR>/go exists, exits as the SDK does on contract_mismatch
 *   flood             no SDK; writes stdout as fast as it can, counting bytes into
 *                     <FIXTURE_DIR>/<instance>.flood every 100 ms
 */

const child_process = require('child_process');
const fs = require('fs');
const path = require('path');

// Captured before the SDK loads: ready() removes the secret from process.env once read.
const START_ENV = Object.assign({}, process.env);

const mode = process.argv[2];
const directory = process.env.FIXTURE_DIR;
const instance = process.env.RSX_COMPANION_INSTANCE;

function record(extra) {
    fs.writeFileSync(path.join(directory, instance + '.json'), JSON.stringify(Object.assign({
        pid: process.pid,
        env: {
            RSX_COMPANION_NAME: START_ENV.RSX_COMPANION_NAME,
            RSX_COMPANION_INSTANCE: START_ENV.RSX_COMPANION_INSTANCE,
            RSX_COMPANION_STARTED_AT: START_ENV.RSX_COMPANION_STARTED_AT,
            RSX_COMPANION_CONTRACT_KEY: START_ENV.RSX_COMPANION_CONTRACT_KEY,
            RSX_COMPANION_BASE_URL: START_ENV.RSX_COMPANION_BASE_URL,
            has_secret: !!START_ENV.RSX_COMPANION_SECRET,
            has_app_key: !!START_ENV.APP_KEY,
            secret_left_in_env: !!process.env.RSX_COMPANION_SECRET,
        },
    }, extra)));
}

const idle = () => setInterval(() => {}, 1000);

if (mode === 'sdk') {
    const companion = require('rsx-companion-sdk');
    companion.on('stop', () => {
        setTimeout(() => process.exit(0), Number(process.env.FIXTURE_STOP_MS || 0));
    });
    companion.ready().then(() => {
        companion.status('up');
        record({});
        idle();
    });
} else if (mode === 'ignore_term') {
    process.on('SIGTERM', () => {});
    record({});
    idle();
} else if (mode === 'grandchild') {
    const grandchild = child_process.spawn(process.execPath, [
        '-e', "process.on('SIGTERM', () => {}); setInterval(() => {}, 1000);",
    ], { stdio: 'ignore' });
    record({ grandchild: grandchild.pid });
    idle();
} else if (mode === 'exit_contract') {
    record({});
    setInterval(() => {
        if (fs.existsSync(path.join(directory, 'go'))) process.exit(75);
    }, 50);
} else if (mode.startsWith('exit_')) {
    record({});
    setTimeout(() => process.exit(Number(mode.slice(5))), 50);
} else if (mode === 'flood') {
    record({});
    let bytes = 0;
    const line = 'x'.repeat(1023) + '\n';
    setInterval(() => fs.writeFileSync(path.join(directory, instance + '.flood'), String(bytes)), 100);
    const write = () => {
        for (let i = 0; i < 64; i++) {
            fs.writeSync(1, line);
            bytes += line.length;
        }
        setImmediate(write);
    };
    write();
} else {
    process.stderr.write('unknown mode ' + mode + '\n');
    process.exit(2);
}
