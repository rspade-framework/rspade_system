#!/bin/bash

TEST_NAME="rsx-lockd protocol + PHP/node HMAC parity"

# The wire contract of the lock daemon, exercised through its export seam (the pure modules
# require() with no port bound), plus the one thing no unit test can prove on its own: that
# PHP and node agree, byte for byte, on how a hello is signed.
#
# Three layers, weakest to strongest:
#   1. protocol.js in isolation - frame encode/decode, the newline splitter, hello
#      sign/verify, the verbatim timeout message.
#   2. BYTE PARITY - PHP's hash_hmac over host:pid:ts (exactly what Lockd_Client::__hello
#      computes, with the framework's own env('APP_KEY')) equals protocol.sign_hello(), and
#      the daemon's verifier accepts the PHP-produced signature.
#   3. LIVE INTEROP - the real PHP client completes a handshake with a real daemon and takes
#      and releases a real lock over the socket.
#
# A scratch daemon on a scratch port serves layer 3; the supervised daemon is never touched.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../../_lib/lockd_test_lib.sh"

lockd_skip_unless_available "$TEST_NAME"

if ! command -v php >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - php not available"
    exit 0
fi

trap lockd_stop EXIT

if ! lockd_start 6297; then
    echo "FAIL: $TEST_NAME - could not start the scratch daemon"
    exit 1
fi

# A fixed tuple, so both languages sign literally the same bytes.
SIG_HOST="rsxtest-host"
SIG_PID=4242
SIG_TS=1786000000

cat > "$LOCKD_TMP/php_sig.php" <<'PHP'
<?php
// The signature Lockd_Client::__hello() builds: HMAC-SHA256 over host:pid:ts, keyed by the
// RAW APP_KEY string the framework resolves (never a base64-decoded form of it).
require '/var/www/html/system/vendor/autoload.php';
$app = require '/var/www/html/system/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$host = (string) $argv[1];
$pid = (int) $argv[2];
$ts = (int) $argv[3];
$key = (string) env('APP_KEY');

echo hash_hmac('sha256', "{$host}:{$pid}:{$ts}", $key), "\n";
PHP

PHP_SIG="$(php "$LOCKD_TMP/php_sig.php" "$SIG_HOST" "$SIG_PID" "$SIG_TS" 2>&1 | tail -n 1)"

cat > "$LOCKD_TMP/harness.js" <<'NODE'
'use strict';

const h = require(process.env.LOCKD_HARNESS_LIB);
const p = h.protocol;

h.run(async function () {
    // ---- Frames -------------------------------------------------------------------
    const frame = { op: 'acquire', name: 'SITE_1', mode: 'write', timeout: null, id: 'r2' };
    const line = p.encode_frame(frame);
    h.check('encode_frame terminates with exactly one newline', line.endsWith('\n')
        && line.indexOf('\n') === line.length - 1);

    const decoded = p.decode_frame(line.trim());
    h.check('decode_frame round-trips a frame', decoded.ok
        && decoded.value.op === 'acquire'
        && decoded.value.name === 'SITE_1'
        && decoded.value.timeout === null
        && decoded.value.id === 'r2');

    h.check('decode_frame rejects malformed JSON without throwing', p.decode_frame('{nope').ok === false);
    h.check('decode_frame rejects the JSON literal null', p.decode_frame('null').ok === false);
    h.check('decode_frame rejects an array frame', p.decode_frame('[1,2]').ok === false);

    // ---- The newline splitter -----------------------------------------------------
    const reader = new p.Frame_Reader();
    let out = reader.push(Buffer.from('{"op":"pi'));
    h.check('splitter yields nothing for a partial line', out.lines.length === 0 && out.error === null);

    out = reader.push(Buffer.from('ng"}\n{"op":"stats","name":"X"}\n\n'));
    h.check('splitter reassembles the split frame and yields both lines', out.lines.length === 2);
    h.check('splitter drops the empty line', out.lines[1] === '{"op":"stats","name":"X"}');

    const capped = new p.Frame_Reader(64);
    const overflow = capped.push(Buffer.from('x'.repeat(200)));
    h.check('splitter refuses a frame over the cap instead of buffering forever',
        overflow.error !== null && /without a newline/.test(overflow.error));
    h.check('splitter stays in error once it has overflowed',
        capped.push(Buffer.from('anything')).error !== null);

    // ---- hello --------------------------------------------------------------------
    const key = 'a-shared-key';
    const ts = 1786000000;
    const hello = p.build_hello('web-01', 900, ts, key);
    h.check('build_hello signs the frame it builds', p.verify_hello(hello, key, ts).ok === true);
    h.check('hello_payload is host:pid:ts', p.hello_payload('web-01', 900, ts) === 'web-01:900:1786000000');
    h.check('a hello signed with another key is rejected',
        p.verify_hello(hello, 'a-different-key', ts).ok === false);
    h.check('a hello outside the replay window is rejected',
        p.verify_hello(hello, key, ts + p.HELLO_WINDOW_SECONDS + 1).ok === false);

    const hostile = Object.assign({}, hello, { sig: 'g'.repeat(64) });
    let threw = null;
    let result = null;
    try {
        result = p.verify_hello(hostile, key, ts);
    } catch (err) {
        threw = err;
    }
    h.check('verify_hello never throws on a 64-char non-hex sig', threw === null);
    h.check('verify_hello rejects a 64-char non-hex sig', result !== null && result.ok === false);

    // ---- Normalization + THE timeout message --------------------------------------
    h.check("mode defaults to write when absent", p.normalize_mode(undefined) === 'write');
    h.check('mode accepts read/write case-insensitively', p.normalize_mode('READ') === 'read');
    h.check('mode rejects anything else', p.normalize_mode('exclusive') === null);
    h.check('timeout defaults to null (wait forever)', p.normalize_timeout(undefined).value === null);
    h.check('timeout rejects a negative', p.normalize_timeout(-1).ok === false);
    h.check('THE timeout message is verbatim',
        p.timeout_message('cluster:SITE_1', 'write', 30)
        === 'Failed to acquire WRITE lock for cluster:SITE_1 after 30 seconds');
    h.check('the timeout message matches the updater classification pattern',
        /Failed to acquire.*lock/.test(p.timeout_message('X', 'read', 5)));

    // ---- PHP/node HMAC parity ------------------------------------------------------
    const php_sig = process.env.PHP_SIG || '';
    const node_sig = p.sign_hello(process.env.SIG_HOST, parseInt(process.env.SIG_PID, 10),
        parseInt(process.env.SIG_TS, 10), process.env.APP_KEY || '');

    h.check('PHP produced a 64-char hex signature (got: ' + php_sig.slice(0, 16) + '...)',
        /^[0-9a-f]{64}$/.test(php_sig));
    h.check('PHP and node sign the hello identically, byte for byte', php_sig === node_sig);
    h.check("the daemon's verifier accepts the PHP-produced signature",
        p.verify_hello({
            op: 'hello',
            host: process.env.SIG_HOST,
            pid: parseInt(process.env.SIG_PID, 10),
            ts: parseInt(process.env.SIG_TS, 10),
            sig: php_sig,
        }, process.env.APP_KEY || '', parseInt(process.env.SIG_TS, 10)).ok === true);

    // ---- The export seam itself ----------------------------------------------------
    h.check('requiring lockd.js starts nothing (parse_argv is exported)',
        typeof h.lockd.parse_argv === 'function');
    const parsed = h.lockd.parse_argv(['exec', '--name=X', '--quiet', '--', 'bash', '-c', '--not-mine']);
    h.check('parse_argv keeps everything after -- verbatim',
        parsed.command === 'exec' && parsed.flags.name === 'X' && parsed.flags.quiet === true
        && parsed.rest.join(' ') === 'bash -c --not-mine');
});
NODE

output="$(PHP_SIG="$PHP_SIG" SIG_HOST="$SIG_HOST" SIG_PID="$SIG_PID" SIG_TS="$SIG_TS" \
    node "$LOCKD_TMP/harness.js" 2>&1)"
status=$?
echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "FAIL: $TEST_NAME - protocol assertions failed"
    exit 1
fi

# ---- Live interop: the REAL PHP client against a REAL daemon --------------------------
cat > "$LOCKD_TMP/php_live.php" <<PHP
<?php
require '/var/www/html/system/vendor/autoload.php';
\$app = require '/var/www/html/system/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\RsxLocks;

// The test seam: talk to the scratch daemon, never the configured one.
Lockd_Client::_use_endpoint('127.0.0.1', $LOCKD_PORT);

\$ping = Lockd_Client::request(['op' => 'ping']);
echo (\$ping['status'] ?? '') === 'ok' ? "PHP_HELLO_OK\n" : "PHP_HELLO_BAD\n";

\$token = RsxLocks::named_write_lock('rsxtest_interop', 5);
\$stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_interop');
echo (\$stats['writer_active'] && \$stats['writer_conn'] !== null) ? "PHP_HELD_OK\n" : "PHP_HELD_BAD\n";

echo RsxLocks::release_lock(\$token) ? "PHP_RELEASE_OK\n" : "PHP_RELEASE_BAD\n";

\$after = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_interop');
echo \$after['writer_active'] ? "PHP_FREE_BAD\n" : "PHP_FREE_OK\n";
PHP

live="$(php "$LOCKD_TMP/php_live.php" 2>&1)"
echo "  live interop:"
echo "$live" | sed 's/^/    /'

live_failed=0
for marker in PHP_HELLO_OK PHP_HELD_OK PHP_RELEASE_OK PHP_FREE_OK; do
    if echo "$live" | grep -q "^${marker}$"; then
        echo "  ok   live interop: $marker"
    else
        echo "  FAIL live interop: $marker missing"
        live_failed=1
    fi
done

if [ $live_failed -ne 0 ]; then
    echo "FAIL: $TEST_NAME - the PHP client could not talk to the daemon"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
