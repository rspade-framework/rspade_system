'use strict';

/**
 * A worker-pool member in its OWN process, for the rsx-lockd pool tests that must prove
 * something across a process boundary: that a `kill -9` removes the membership and frees
 * the pool lock with no leave and no unlock ever sent.
 *
 * One connection, line-oriented so the orchestrating harness can follow it:
 *
 *     stdout: READY                       connected and authenticated
 *             REQUESTED                   pool.lock went out (may park silently)
 *             LOCKED                      pool.lock granted
 *             JOINED <member_id>          pool.join answered
 *             UNLOCKED                    pool.unlock answered (only without --stay-locked)
 *             ERROR <message>
 *
 * Usage: node lockd_pool_member.js --pool=NAME [--stay-locked] [--no-join]
 *
 * --stay-locked keeps the pool lock after joining (the "died while holding the lock" case).
 * --no-join takes the lock and nothing else (the "died waiting / holding" cases).
 *
 * It never exits on its own while the harness lives - the test kills it.
 */

const h = require(process.env.LOCKD_HARNESS_LIB);

function say(line) {
    process.stdout.write(line + '\n');
}

function flag(name, fallback) {
    for (const arg of process.argv.slice(2)) {
        if (arg === '--' + name) return true;
        if (arg.startsWith('--' + name + '=')) return arg.slice(name.length + 3);
    }
    return fallback;
}

async function expect(client, frame, status) {
    const response = await client.request(frame);
    if (response.status !== status) {
        say('ERROR ' + frame.op + ' answered ' + response.status + ' ' + (response.message || ''));
        process.exit(1);
    }
    return response;
}

async function main() {
    const pool = flag('pool', null);
    if (!pool) {
        say('ERROR --pool is required');
        process.exit(1);
    }

    const client = await h.connect();
    say('READY');

    say('REQUESTED');
    await expect(client, { op: 'pool.lock', pool: pool }, 'granted');
    say('LOCKED');

    if (!flag('no-join', false)) {
        const joined = await expect(client, { op: 'pool.join', pool: pool }, 'ok');
        say('JOINED ' + joined.member_id);
    }

    if (!flag('stay-locked', false) && !flag('no-join', false)) {
        await expect(client, { op: 'pool.unlock', pool: pool }, 'ok');
        say('UNLOCKED');
    }

    // Stay connected until killed. The open socket IS the membership. A closed stdin means
    // the orchestrating harness is gone, so exit rather than linger as an orphan.
    process.stdin.on('end', () => process.exit(0));
    process.stdin.resume();
}

main().catch((err) => {
    say('ERROR ' + (err && err.message ? err.message : err));
    process.exit(1);
});
