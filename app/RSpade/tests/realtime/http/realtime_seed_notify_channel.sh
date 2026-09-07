#!/bin/bash
set -e

TEST_NAME="Realtime Seed Notify Channel (relay diff + HMAC parity)"

# The relay->PHP subscribe-seed channel has two pure pieces the rest of the design rests on:
#
#   diff_new_members(prev, current)  - the substitution for "SADD returned 1", which cannot
#       be built: the registry write is a full-state DEL + SADD-all rewrite, so redis reports
#       every member as new on every rewrite. The relay instead diffs an in-memory baseline
#       that advances only after a SUCCESSFUL write.
#   sign_notify_body(body, APP_KEY)  - the HMAC PHP verifies with hash_hmac('sha256', raw,
#       config('app.key')). Node and PHP must agree byte for byte or every notify 403s.
#
# Both are exercised through the relay's existing export seam (require.main === module), so
# requiring the module binds no port and opens no redis connection - the live supervised
# relay is never touched.
#
# Asserts:
#   - empty baseline  => every member is new (relay restart == full reseed),
#   - identical rewrite => nothing new (the 60s registry refresh must not re-notify),
#   - added member => only the addition is reported,
#   - removed member => nothing new, and re-adding it reports it again (unsubscribe ->
#     resubscribe re-seeds and refreshes the baseline TTL),
#   - array inputs behave like Set inputs (the exported contract),
#   - node's signature equals php's hash_hmac over the same body (both directions of the
#     wire: node signs, php verifies).

RELAY="/var/www/html/system/bin/realtime-server.js"

if ! command -v node >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - node not available"
    exit 0
fi
if ! command -v php >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - php not available"
    exit 0
fi
if ! node -e "require(require('path').join('$(dirname "$RELAY")', '..', 'node_modules', 'ws'))" >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - ws module not available"
    exit 0
fi
if ! grep -q '^APP_KEY=' /var/www/html/.env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - APP_KEY not set in .env"
    exit 0
fi

HARNESS="$(mktemp /tmp/realtime_seed_notify-XXXXXX.js)"
SIGFILE="$(mktemp /tmp/realtime_seed_sig-XXXXXX.txt)"
trap 'rm -f "$HARNESS" "$SIGFILE"' EXIT

cat > "$HARNESS" <<'NODE'
'use strict';

// require() must NOT start the relay (main() runs only when executed as a program).
// It DOES load .env at module scope, so process.env.APP_KEY is populated here.
const relay = require(process.env.RELAY_PATH);
const { diff_new_members, sign_notify_body } = relay;

const APP_KEY = process.env.APP_KEY;
if (!APP_KEY) { console.log('  FAIL harness: APP_KEY missing after require'); process.exit(2); }
if (typeof diff_new_members !== 'function' || typeof sign_notify_body !== 'function') {
    console.log('  FAIL harness: seed-notify helpers not exported'); process.exit(2);
}

let fails = 0;
function check(name, cond) {
    if (cond) { console.log('  ok   ' + name); }
    else { console.log('  FAIL ' + name); fails++; }
}
function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

const A = JSON.stringify({ site_id: 1, topic: 'Alpha_Topic', filter: { id: 5 } });
const B = JSON.stringify({ site_id: 1, topic: 'Beta_Topic', filter: {} });
const C = JSON.stringify({ site_id: 2, topic: 'Alpha_Topic', filter: { id: 5 } });

// 1) Empty baseline: everything is new (relay restart / post-maintenance full reseed).
check('empty baseline reports every member new',
    same(diff_new_members(new Set(), new Set([A, B])), [A, B]));

// 2) Identical rewrite: nothing new. The registry is rewritten on a 60s tick even when
//    nothing changed - re-notifying there would be a poll in disguise.
check('identical rewrite reports nothing',
    same(diff_new_members(new Set([A, B]), new Set([A, B])), []));

// 3) One addition is reported alone.
check('only the added member is reported',
    same(diff_new_members(new Set([A, B]), new Set([A, B, C])), [C]));

// 4) Removal reports nothing; re-adding reports it again (resubscribe re-seeds).
check('a removal reports nothing new',
    same(diff_new_members(new Set([A, B]), new Set([A])), []));
check('re-adding a removed member reports it again',
    same(diff_new_members(new Set([A]), new Set([A, B])), [B]));

// 5) Array inputs behave like Sets (the documented exported contract).
check('array inputs behave like sets', same(diff_new_members([A], [A, B]), [B]));
check('null/empty inputs are safe', same(diff_new_members(null, null), []));

// 6) Signature: emit node's HMAC for a fixed body so the shell can compare it to PHP's.
const body = JSON.stringify({ ts: 1754400000, members: [A, B] });
const sig = sign_notify_body(body, APP_KEY);
check('signature is 64 lowercase hex chars', /^[0-9a-f]{64}$/.test(sig));

require('fs').writeFileSync(process.env.SIG_OUT, body + '\n' + sig + '\n');

if (fails > 0) { console.log('RESULT: FAIL (' + fails + ' assertion(s))'); process.exit(1); }
console.log('RESULT: PASS');
process.exit(0);
NODE

output="$(RELAY_PATH="$RELAY" SIG_OUT="$SIGFILE" node "$HARNESS" 2>&1)"
status=$?

echo "$output"

if [ $status -ne 0 ] || ! echo "$output" | grep -q '^RESULT: PASS'; then
    echo "FAIL: $TEST_NAME - node assertions failed"
    exit 1
fi

# PHP side of the parity check: hash_hmac over the SAME body with the SAME key must equal
# what node produced. This is the exact comparison Realtime_Controller::subs_changed makes.
php_result="$(RSX_SIGFILE="$SIGFILE" php -r '
$lines = file(getenv("RSX_SIGFILE"), FILE_IGNORE_NEW_LINES);
$body = $lines[0];
$node_sig = $lines[1];
$env = file_get_contents("/var/www/html/.env");
$app_key = "";
foreach (explode("\n", $env) as $line) {
    $line = trim($line);
    if (strpos($line, "APP_KEY=") === 0) { $app_key = substr($line, 8); break; }
}
$app_key = trim($app_key, "\"'"'"'");
$php_sig = hash_hmac("sha256", $body, $app_key);
echo hash_equals($php_sig, $node_sig) ? "MATCH" : "MISMATCH node=$node_sig php=$php_sig";
')"

if [ "$php_result" != "MATCH" ]; then
    echo "  FAIL node/php HMAC parity: $php_result"
    echo "FAIL: $TEST_NAME"
    exit 1
fi
echo "  ok   node signature verifies with php hash_hmac (wire parity)"

echo "PASS: $TEST_NAME"
exit 0
