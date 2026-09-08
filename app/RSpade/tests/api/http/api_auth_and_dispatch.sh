#!/bin/bash
set -e

TEST_NAME="API Auth and Dispatch"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# HTTP integration test - runs against the LIVE dev server + dev database (rspade).
# The external API dispatcher can only be exercised over real HTTP: Bearer auth, the
# absence of Set-Cookie, real HTTP status codes, and the _api_request_log side effect.
# This test mints its own temporary API key (bootstrapping the framework), exercises the
# pipeline, and cleans up every row it created via a trap.
#
# EVERY ENDPOINT IT CALLS IS THE FRAMEWORK'S OWN - /api/v1/me (the identity endpoint,
# which declares no parameters, so any query parameter is an undeclared one) and
# /api/v1/files (the upload endpoint, the framework's only POST). No application
# endpoint is named, so this runs in any install.
#
# Loopback requests are exempt from the dev hostname guard, so localhost works without
# extra host setup.

BASE="http://localhost"
DB_ARGS="-h127.0.0.1 -urspade -prspadepass rspade"
KEY=""
KEY_ID=""
LOG_BASELINE=""
MINT_SCRIPT=""

dev_db() { mysql $DB_ARGS -N -e "$1" 2>/dev/null; }

# ---------------------------------------------------------------------------
# Cleanup - remove the minted key and every log row this run produced.
# ---------------------------------------------------------------------------
cleanup() {
    if [ -n "$KEY_ID" ]; then
        dev_db "DELETE FROM _api_keys WHERE id = $KEY_ID" || true
    fi
    if [ -n "$LOG_BASELINE" ]; then
        dev_db "DELETE FROM _api_request_log WHERE id > $LOG_BASELINE" || true
    fi
    if [ -n "$MINT_SCRIPT" ] && [ -f "$MINT_SCRIPT" ]; then
        rm -f "$MINT_SCRIPT"
    fi
}
trap cleanup EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

# ---------------------------------------------------------------------------
# Setup - verify the server, snapshot the log id watermark, mint a key.
# ---------------------------------------------------------------------------
echo "[SETUP] Verifying dev server..." >&2
if ! curl -s -o /dev/null --connect-timeout 3 "$BASE/api/v1/me"; then
    echo "SKIP: $TEST_NAME - dev server not reachable on $BASE"
    exit 0
fi

LOG_BASELINE=$(dev_db "SELECT COALESCE(MAX(id), 0) FROM _api_request_log")
if [ -z "$LOG_BASELINE" ]; then
    echo "SKIP: $TEST_NAME - could not read _api_request_log (dev database unavailable)"
    exit 0
fi

echo "[SETUP] Minting a temporary API key..." >&2
MINT_SCRIPT=$(mktemp /tmp/api_test_mint_XXXXXX.php)
cat > "$MINT_SCRIPT" <<'PHP'
<?php
require '/var/www/html/system/vendor/autoload.php';
$app = require '/var/www/html/system/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$r = App\RSpade\Core\Api\Api_Key_Model::generate(1, 'HTTP test key (temporary)');
echo $r['key'] . '|' . $r['model']->id . "\n";
PHP

MINT_OUT=$(php "$MINT_SCRIPT" 2>/dev/null | tail -1)
KEY="${MINT_OUT%%|*}"
KEY_ID="${MINT_OUT##*|}"

if [ -z "$KEY" ] || [ -z "$KEY_ID" ] || [ "$KEY" = "$KEY_ID" ]; then
    fail "could not mint an API key (got: '$MINT_OUT')"
fi
echo "[SETUP] Minted key id $KEY_ID" >&2

echo "[TEST] Running API dispatch assertions..." >&2

# ---------------------------------------------------------------------------
# Test 1: Unauthenticated -> 401 with error shape and NO Set-Cookie
# ---------------------------------------------------------------------------
echo "[TEST] 1. Unauthenticated request -> 401, no cookie..." >&2
headers=$(curl -s -D - -o /tmp/api_test_401_body.txt -w '' "$BASE/api/v1/me")
status=$(echo "$headers" | grep -iE '^HTTP/' | tail -1 | awk '{print $2}')
[ "$status" = "401" ] || fail "unauth expected 401, got $status"
if echo "$headers" | grep -qi '^set-cookie'; then
    fail "unauth response leaked a Set-Cookie header"
fi
grep -q '"error"' /tmp/api_test_401_body.txt || fail "401 body missing error object"
grep -q '"auth_required"' /tmp/api_test_401_body.txt || fail "401 body missing auth_required code"
rm -f /tmp/api_test_401_body.txt
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# Test 2: Authenticated -> 200 bare JSON (has items, no envelope), no Set-Cookie
# ---------------------------------------------------------------------------
echo "[TEST] 2. Authenticated request -> 200 bare JSON..." >&2
headers=$(curl -s -D - -o /tmp/api_test_200_body.txt -H "Authorization: Bearer $KEY" "$BASE/api/v1/me")
status=$(echo "$headers" | grep -iE '^HTTP/' | tail -1 | awk '{print $2}')
[ "$status" = "200" ] || fail "authed expected 200, got $status"
if echo "$headers" | grep -qi '^set-cookie'; then
    fail "authed response leaked a Set-Cookie header"
fi
grep -q '"user_id"' /tmp/api_test_200_body.txt || fail "200 body missing user_id key"
if grep -q '"success"' /tmp/api_test_200_body.txt; then
    fail "200 body carries a {success} envelope (must be bare JSON)"
fi
rm -f /tmp/api_test_200_body.txt
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# Test 3: PUT -> 405
# ---------------------------------------------------------------------------
echo "[TEST] 3. PUT -> 405..." >&2
status=$(curl -s -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $KEY" "$BASE/api/v1/me")
[ "$status" = "405" ] || fail "PUT expected 405, got $status"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# Test 4: HEAD -> 405 (API deliberately diverges from the main dispatcher)
# ---------------------------------------------------------------------------
echo "[TEST] 4. HEAD -> 405..." >&2
status=$(curl -s -o /dev/null -w '%{http_code}' -I -H "Authorization: Bearer $KEY" "$BASE/api/v1/me")
[ "$status" = "405" ] || fail "HEAD expected 405, got $status"
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# Test 5: Unknown endpoint (authed) -> 404 not_found
# ---------------------------------------------------------------------------
echo "[TEST] 5. Unknown endpoint -> 404..." >&2
curl -s -o /tmp/api_test_404_body.txt -w '' -H "Authorization: Bearer $KEY" "$BASE/api/v9/nope"
status=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $KEY" "$BASE/api/v9/nope")
[ "$status" = "404" ] || fail "unknown endpoint expected 404, got $status"
grep -q '"not_found"' /tmp/api_test_404_body.txt || fail "404 body missing not_found code"
rm -f /tmp/api_test_404_body.txt
echo "[TEST] 5. OK" >&2

# ---------------------------------------------------------------------------
# Test 6: Undeclared parameter -> 422 with fields
# ---------------------------------------------------------------------------
echo "[TEST] 6. Undeclared param -> 422..." >&2
curl -s -o /tmp/api_test_422_body.txt -w '' -H "Authorization: Bearer $KEY" "$BASE/api/v1/me?bogus=1"
status=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $KEY" "$BASE/api/v1/me?bogus=1")
[ "$status" = "422" ] || fail "bogus param expected 422, got $status"
grep -q '"fields"' /tmp/api_test_422_body.txt || fail "422 body missing fields"
grep -q 'bogus' /tmp/api_test_422_body.txt || fail "422 body does not name the bogus field"
rm -f /tmp/api_test_422_body.txt
echo "[TEST] 6. OK" >&2

# ---------------------------------------------------------------------------
# Test 7: Invalid JSON body on POST -> 400 invalid_json
# ---------------------------------------------------------------------------
echo "[TEST] 7. Invalid JSON body -> 400..." >&2
curl -s -o /tmp/api_test_400_body.txt -w '' -X POST -H "Authorization: Bearer $KEY" \
    -H 'Content-Type: application/json' --data '{bad json' "$BASE/api/v1/files"
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $KEY" \
    -H 'Content-Type: application/json' --data '{bad json' "$BASE/api/v1/files")
[ "$status" = "400" ] || fail "invalid JSON expected 400, got $status"
grep -q '"invalid_json"' /tmp/api_test_400_body.txt || fail "400 body missing invalid_json code"
rm -f /tmp/api_test_400_body.txt
echo "[TEST] 7. OK" >&2

# ---------------------------------------------------------------------------
# Test 8: An authed call creates NO _sessions row
# ---------------------------------------------------------------------------
echo "[TEST] 8. Authed call does not create a session..." >&2
sessions_before=$(dev_db "SELECT COUNT(*) FROM _sessions")
curl -s -o /dev/null -H "Authorization: Bearer $KEY" "$BASE/api/v1/me"
sessions_after=$(dev_db "SELECT COUNT(*) FROM _sessions")
[ "$sessions_before" = "$sessions_after" ] || fail "session count changed ($sessions_before -> $sessions_after)"
echo "[TEST] 8. OK" >&2

# ---------------------------------------------------------------------------
# Test 9: Cookie + Bearer -> 200 using the Bearer identity, no Set-Cookie
# ---------------------------------------------------------------------------
echo "[TEST] 9. Cookie + Bearer -> 200, no Set-Cookie..." >&2
headers=$(curl -s -D - -o /dev/null -b 'rsx=bogus_session_token' -H "Authorization: Bearer $KEY" "$BASE/api/v1/me")
status=$(echo "$headers" | grep -iE '^HTTP/' | tail -1 | awk '{print $2}')
[ "$status" = "200" ] || fail "cookie+bearer expected 200, got $status"
if echo "$headers" | grep -qi '^set-cookie'; then
    fail "cookie+bearer response leaked a Set-Cookie header"
fi
echo "[TEST] 9. OK" >&2

# ---------------------------------------------------------------------------
# Test 10: Every request was logged (401 with NULL key, 200 with the key)
# ---------------------------------------------------------------------------
echo "[TEST] 10. Request log rows written..." >&2
unauth_rows=$(dev_db "SELECT COUNT(*) FROM _api_request_log WHERE id > $LOG_BASELINE AND status = 401 AND api_key_id IS NULL")
[ "$unauth_rows" -ge 1 ] || fail "no 401 log row with NULL api_key_id was written"
auth_rows=$(dev_db "SELECT COUNT(*) FROM _api_request_log WHERE id > $LOG_BASELINE AND status = 200 AND api_key_id = $KEY_ID")
[ "$auth_rows" -ge 1 ] || fail "no 200 log row attributed to the minted key was written"
echo "[TEST] 10. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
