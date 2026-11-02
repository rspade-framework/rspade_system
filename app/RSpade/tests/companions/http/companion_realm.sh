#!/bin/bash
set -e

TEST_NAME="Companion Realm"

# HTTP integration test - runs against the LIVE dev server + dev database (rspade), through
# real php-fpm. Under CLI every session is virtual, so what only a web request can show is
# here: a signed-in browser's cookie is ignored, nothing mints a session or sets a cookie, and
# the verifier's answers survive nginx. It signs by hand, against an instance record it writes
# to Redis itself and removes through a trap, with no daemon running.
#
# Its subject is the template's `hello` companion, which is the one endpoint a live manifest
# holds: fixtures enter the manifest only under rsx:test. An application without it SKIPs.

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$TEST_DIR/../../../../../.." && pwd)"
BASE="http://localhost"
EMAIL="companion-realm-http@test.rspade.test"
LOGIN_PASSWORD="companion-realm-password"

JAR="$(mktemp)"
SCRIPT="$(mktemp /tmp/companion_realm_XXXXXX.php)"
trap 'rm -f "$JAR" "$SCRIPT"' EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

if ! curl -s -o /dev/null --connect-timeout 3 "$BASE/"; then
    echo "SKIP: $TEST_NAME - dev server not reachable on $BASE"
    exit 0
fi

if [ ! -f "$PROJECT_DIR/build/companions/hello/descriptor.json" ]; then
    echo "SKIP: $TEST_NAME - this application declares no hello companion"
    exit 0
fi

echo "[SETUP] Signing in a browser..." >&2
(cd "$PROJECT_DIR" && php artisan tinker --execute='
    \App\RSpade\Core\Session\Session::set_temporary_site_id(1);

    $login = \App\RSpade\Core\Models\Login_User_Model::where("email", "'"$EMAIL"'")->first()
        ?? new \App\RSpade\Core\Models\Login_User_Model();
    $login->email = "'"$EMAIL"'";
    $login->password = \Illuminate\Support\Facades\Hash::make("'"$LOGIN_PASSWORD"'");
    $login->is_activated = true;
    $login->is_verified = true;
    $login->status_id = \App\RSpade\Core\Models\Login_User_Model::STATUS_ACTIVE;
    $login->save();

    $user = \App\RSpade\Core\Models\User_Model::without_site_scope(fn () =>
        \App\RSpade\Core\Models\User_Model::withTrashed()->where("login_user_id", $login->id)->where("site_id", 1)->first())
        ?? new \App\RSpade\Core\Models\User_Model();
    $user->login_user_id = $login->id;
    $user->site_id = 1;
    $user->email = "'"$EMAIL"'";
    $user->first_name = "Companion";
    $user->last_name = "Http";
    $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
    $user->is_enabled = 1;
    $user->deleted_at = null;
    $user->save();
' >/dev/null 2>&1) || fail "could not seed the account"

CSRF="$(curl -s -b "$JAR" -c "$JAR" "$BASE/login" 2>/dev/null | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$LOGIN_PASSWORD" \
    --data-urlencode "__turnstile=inactive" \
    --data-urlencode "_csrf_token=$CSRF" \
    "$BASE/login"

COOKIE="$(grep -w rsx "$JAR" | awk '{print $7}')"
[ -n "$COOKIE" ] || fail "signing in gave the browser no cookie"

# The cookie check below proves nothing unless this browser really is signed in.
SIGNED_IN="$(cd "$PROJECT_DIR" && php artisan db:query "SELECT staff_user_session_id FROM _sessions WHERE session_token = '$COOKIE'" --json 2>/dev/null | grep -oE '"staff_user_session_id": *[0-9]+' || true)"
[ -n "$SIGNED_IN" ] || fail "the browser did not sign in"

cat > "$SCRIPT" <<'PHP'
<?php
$app = require $argv[1] . '/system/script.php';

use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Manifest\Manifest;
use Illuminate\Support\Facades\DB;

$cookie = $argv[2];
$instance = 'http-test-' . bin2hex(random_bytes(6));
$started_at = (string) (int) (microtime(true) * 1000);
$contract = Manifest::get_companions()['hello']['endpoints_hash'];
$uri = '/_companion/hello/hello';

function send(string $uri, array $options = []): array
{
    global $instance, $started_at, $contract;

    $nonce = $options['nonce'] ?? 'http-' . bin2hex(random_bytes(12));
    $timestamp = (string) ($options['timestamp'] ?? time());
    $contract_key = $options['contract'] ?? $contract;
    $body = '';

    $signature = hash_hmac('sha256', Companion_Verifier::canonical_string(
        'POST', $uri, 'hello', $instance, $started_at, $contract_key, '-', $timestamp, $nonce, $body
    ), $options['secret'] ?? Companion_Verifier::instance_secret('hello', $instance, $started_at));

    $handle = curl_init('http://localhost' . $uri);
    $headers = [
        'X-Rsx-Companion: hello',
        'X-Rsx-Companion-Instance: ' . $instance,
        'X-Rsx-Companion-Started-At: ' . $started_at,
        'X-Rsx-Companion-Contract: ' . $contract_key,
        'X-Rsx-Companion-Timestamp: ' . $timestamp,
        'X-Rsx-Companion-Nonce: ' . $nonce,
        'X-Rsx-Companion-Signature: ' . $signature,
    ];

    if (isset($options['cookie'])) {
        $headers[] = 'Cookie: rsx=' . $options['cookie'];
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);

    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $header_size),
        'json' => json_decode(substr($raw, $header_size), true),
    ];
}

function check(bool $condition, string $what, array $answer = []): void
{
    if (!$condition) {
        echo "FAIL: {$what} " . json_encode($answer) . "\n";
        exit(1);
    }

    fwrite(STDERR, "[TEST] OK - {$what}\n");
}

$key = Companion_Store::instance_key($instance);
$redis = Companion_Store::connection();
$redis->hMSet($key, ['name' => 'hello', 'started_at' => $started_at, 'status' => 'running']);

try {
    $sessions = DB::table('_sessions')->count();

    $answer = send($uri);
    check($answer['status'] === 200 && $answer['json']['greeting'] === 'hello', 'a signed call is answered', $answer);
    check($answer['json']['companion'] === 'hello' && (int) $answer['json']['site_id'] === 1, 'it runs as the companion on site 1', $answer);
    check(stripos($answer['headers'], 'set-cookie') === false, 'it sets no cookie', $answer);

    $answer = send($uri, ['cookie' => $cookie]);
    check($answer['status'] === 200 && $answer['json']['signed_in'] === false, "a signed-in browser's cookie is ignored", $answer);
    check(stripos($answer['headers'], 'set-cookie') === false, 'a sent cookie is not answered with one', $answer);
    check(DB::table('_sessions')->count() === $sessions, 'no session row is minted', $answer);

    check(send($uri, ['secret' => str_repeat('a', 64)])['json']['error']['code'] === 'invalid_signature', 'a wrong signature is refused');
    check(send($uri, ['timestamp' => time() - 120])['json']['error']['code'] === 'stale_timestamp', 'a stale timestamp is refused');
    check(send($uri, ['contract' => str_repeat('c', 64)])['status'] === 409, 'a stale contract is a 409');

    $nonce = 'http-replay-' . bin2hex(random_bytes(10));
    check(send($uri, ['nonce' => $nonce])['status'] === 200, 'a nonce is good once');
    check(send($uri, ['nonce' => $nonce])['json']['error']['code'] === 'replayed_nonce', 'and refused the second time');

    $redis->hSet($key, 'status', 'exited');
    check(send($uri)['json']['error']['code'] === 'instance_not_live', 'an ended instance is refused');
    $redis->hSet($key, 'status', 'running');

    check(send($uri . '?x=1')['json']['error']['code'] === 'invalid_uri', 'a query string is refused');
    check(send('/_companion/hello/no_such_endpoint')['json']['error']['code'] === 'not_found', 'an unknown endpoint is a JSON 404');
} finally {
    $redis->del($key);
}

echo "PASSED\n";
PHP

OUTPUT="$(php "$SCRIPT" "$PROJECT_DIR" "$COOKIE" 2>&1)" || { echo "$OUTPUT"; fail "a check failed"; }
echo "$OUTPUT" | grep -q '^PASSED$' || { echo "$OUTPUT"; fail "the checks did not finish"; }
echo "$OUTPUT" | grep -v '^PASSED$' >&2 || true

echo "PASS: $TEST_NAME"
