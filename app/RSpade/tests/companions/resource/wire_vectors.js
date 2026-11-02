/**
 * Prints what the daemon's and the SDK's shared modules compute for fixed inputs, so a PHP
 * test can compare them with Companion_Verifier and Companion_ManifestSupport.
 *
 *     echo '{"app_key": "...", "directory": "..."}' | node wire_vectors.js
 */

const path = require('path');

const bin = path.join(__dirname, '..', '..', '..', '..', '..', 'bin', 'rsx-companiond');
const wire = require(path.join(bin, 'lib', 'wire.js'));
const backoff = require(path.join(bin, 'lib', 'backoff.js'));
const fingerprint = require(path.join(bin, 'lib', 'fingerprint.js'));
const redis = require(path.join(bin, 'lib', 'redis_client.js'));

let input = '';
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', async () => {
    const given = JSON.parse(input);

    const parsed = redis.parse_reply(Buffer.from('*3\r\n$5\r\nhello\r\n:42\r\n$-1\r\n'), 0);
    const partial = redis.parse_reply(Buffer.from('*2\r\n$5\r\nhel'), 0);

    process.stdout.write(JSON.stringify({
        instance_secret: wire.instance_secret(given.app_key, 'hello', 'i-1', '1700000000000'),
        daemon_key: wire.daemon_key(given.app_key),
        canonical: wire.canonical_string('POST', '/_companion/hello/hello', 'hello', 'i-1', '1700000000000', 'abc', '-', '1700000000', 'n0123456789abcdef', '{}'),
        daemon_canonical: wire.daemon_canonical_string('POST', '/_companion/_daemon/event', '1700000000', 'n0123456789abcdef', '{}'),
        scope_token: wire.scope_token(given.database, given.host),
        source_hash: await fingerprint.source_hash(given.directory),
        exit_contract_mismatch: wire.EXIT_CONTRACT_MISMATCH,
        backoff: [1, 2, 3, 4, 9, 40].map((streak) => backoff.restart_delay_ms(streak, 1, 300)),
        streak_after_short_run: backoff.next_streak(3, 1000),
        streak_after_stable_run: backoff.next_streak(3, backoff.STABLE_RUN_MS),
        resp_command: redis.encode_command(['SET', 'k', 'v']),
        resp_parsed: parsed.value,
        resp_partial: partial,
    }));
});
