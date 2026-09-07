'use strict';

/**
 * A lock holder in its OWN process, for the rsx-lockd tests that must prove something
 * across a process boundary: mutual exclusion between two processes, and release-on-death.
 *
 * One connection, one lock, line-oriented so the orchestrating harness can follow it:
 *
 *     stdout: READY                       connected and authenticated
 *             REQUESTED <ms>              the acquire went out (may park silently)
 *             ACQUIRED  <token> <ms>      granted
 *             RELEASED  <held>            release answered (held=true|false)
 *             ERROR     <message>
 *     stdin:  release                     release the lock and exit 0
 *
 * Usage: node lockd_holder.js --name=X [--mode=read|write] [--timeout=N] [--group=ID]
 *
 * --group joins a lock group, standing in for a subprocess that inherited its parent's
 * locks. Two holders sharing a group are a parent and its child, not two strangers.
 *
 * It never exits on its own while holding a lock - that is the point: the test decides
 * whether it releases politely or is killed outright.
 */

const h = require(process.env.LOCKD_HARNESS_LIB);

function say(line) {
    process.stdout.write(line + '\n');
}

function flag(name, fallback) {
    for (const arg of process.argv.slice(2)) {
        if (arg.startsWith('--' + name + '=')) return arg.slice(name.length + 3);
    }
    return fallback;
}

async function main() {
    const name = flag('name', null);
    const mode = flag('mode', 'write');
    const timeout_raw = flag('timeout', null);
    const timeout = timeout_raw === null ? null : parseInt(timeout_raw, 10);
    const group_id = flag('group', null);

    if (!name) {
        say('ERROR --name is required');
        process.exit(1);
    }

    const client = await h.connect({ group_id: group_id });
    say('READY');

    say('REQUESTED ' + Date.now());
    const response = await client.request({ op: 'acquire', name: name, mode: mode, timeout: timeout });

    if (response.status !== 'granted') {
        say('ERROR ' + response.status + ' ' + (response.message || ''));
        process.exit(1);
    }

    say('ACQUIRED ' + response.token + ' ' + Date.now());

    // Hold until told otherwise. A kill -9 here is a supported outcome, not an error:
    // the daemon releases on the socket close.
    process.stdin.setEncoding('utf8');
    let buffer = '';
    process.stdin.on('data', async (chunk) => {
        buffer += chunk;
        if (buffer.indexOf('\n') === -1) return;
        const command = buffer.split('\n')[0].trim();
        buffer = '';

        if (command === 'release') {
            const released = await client.request({ op: 'release', token: response.token });
            say('RELEASED ' + (released.held === true));
            client.close();
            process.exit(0);
        }
    });
}

main().catch((err) => {
    say('ERROR ' + (err && err.message ? err.message : err));
    process.exit(1);
});
