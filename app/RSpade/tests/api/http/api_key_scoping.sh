#!/bin/bash
set -e

TEST_NAME="API Key Scoping"

# HTTP integration test - runs against the LIVE dev server + dev database (rspade).
# The scope check sits inside the dispatcher between route resolution and param validation,
# and inside Rsx_Api_Bearer on the file-serving web routes, so both are only observable over
# real HTTP. This test mints two keys - one scoped to /api/v1/files/*, one with NULL
# scopes - exercises the reachable / not-reachable / unrestricted / web-download paths, the
# malformed-scope fail-closed rule and the absence of a by-name addressing channel, and
# cleans up every row it created via a trap.
#
# EVERY ENDPOINT IT ADDRESSES IS THE FRAMEWORK'S OWN: /api/v1/files, /api/v1/files/:key and
# /api/v1/me. The two are on opposite sides of the scope grant, which is all the subject
# needs, so this runs in an application that declares no API endpoints at all.
#
# Loopback requests are exempt from the dev hostname guard, so localhost works without
# extra host setup.

BASE="http://localhost"
DB_ARGS="-h127.0.0.1 -urspade -prspadepass rspade"
SCOPED_KEY=""
SCOPED_KEY_ID=""
OPEN_KEY=""
OPEN_KEY_ID=""
LOG_BASELINE=""
MINT_SCRIPT=""

dev_db() { mysql $DB_ARGS -N -e "$1" 2>/dev/null; }

cleanup() {
    if [ -n "$SCOPED_KEY_ID" ]; then
        dev_db "DELETE FROM _api_keys WHERE id = $SCOPED_KEY_ID" || true
    fi
    if [ -n "$OPEN_KEY_ID" ]; then
        dev_db "DELETE FROM _api_keys WHERE id = $OPEN_KEY_ID" || true
    fi
    if [ -n "$LOG_BASELINE" ]; then
        dev_db "DELETE FROM _api_request_log WHERE id > $LOG_BASELINE" || true
    fi
    if [ -n "$MINT_SCRIPT" ] && [ -f "$MINT_SCRIPT" ]; then
        rm -f "$MINT_SCRIPT"
    fi
    rm -f /tmp/api_scope_body.txt
}
trap cleanup EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

# ---------------------------------------------------------------------------
# Setup - verify the server, snapshot the log id watermark, mint both keys.
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

echo "[SETUP] Minting a scoped key and an unrestricted key..." >&2
MINT_SCRIPT=$(mktemp /tmp/api_scope_mint_XXXXXX.php)
cat > "$MINT_SCRIPT" <<'PHP'
<?php
require '/var/www/html/system/vendor/autoload.php';
$app = require '/var/www/html/system/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$scoped = App\RSpade\Core\Api\Api_Key_Model::generate(
    1,
    'HTTP scope test key (temporary)',
    'live',
    null,
    null,
    "/api/v1/files/*"
);
$open = App\RSpade\Core\Api\Api_Key_Model::generate(1, 'HTTP unscoped test key (temporary)');
echo $scoped['key'] . '|' . $scoped['model']->id . '|' . $open['key'] . '|' . $open['model']->id . "\n";
PHP

MINT_OUT=$(php "$MINT_SCRIPT" 2>/dev/null | tail -1)
SCOPED_KEY=$(echo "$MINT_OUT" | cut -d'|' -f1)
SCOPED_KEY_ID=$(echo "$MINT_OUT" | cut -d'|' -f2)
OPEN_KEY=$(echo "$MINT_OUT" | cut -d'|' -f3)
OPEN_KEY_ID=$(echo "$MINT_OUT" | cut -d'|' -f4)

if [ -z "$SCOPED_KEY" ] || [ -z "$SCOPED_KEY_ID" ] || [ -z "$OPEN_KEY" ] || [ -z "$OPEN_KEY_ID" ]; then
    fail "could not mint the test keys (got: '$MINT_OUT')"
fi

STORED_SCOPES=$(dev_db "SELECT scopes FROM _api_keys WHERE id = $SCOPED_KEY_ID")
[ "$STORED_SCOPES" = "/api/v1/files/*" ] \
    || fail "scopes were not stored canonically (got: '$STORED_SCOPES')"
echo "[SETUP] Minted scoped key $SCOPED_KEY_ID and unrestricted key $OPEN_KEY_ID" >&2

# ---------------------------------------------------------------------------
# Test 1: the scoped key reaches an endpoint its scopes name
# ---------------------------------------------------------------------------
echo "[TEST] 1. Scoped key -> reachable endpoint reaches the handler..." >&2
status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/api/v1/files/no_such_attachment_key")
[ "$status" = "404" ] || fail "reachable endpoint expected the handler's own 404, got $status"
grep -q '"not_found"' /tmp/api_scope_body.txt \
    || fail "the reachable endpoint did not answer its own not_found (the scope check intercepted it)"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# Test 2: an endpoint the scopes do not reach -> 403 insufficient_scope + required
# 'required' is the matched ROUTE PATTERN, not the request path.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Scoped key -> unreachable endpoint 403 insufficient_scope..." >&2
status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/api/v1/me")
[ "$status" = "403" ] || fail "unreachable endpoint expected 403, got $status"
grep -q '"insufficient_scope"' /tmp/api_scope_body.txt \
    || fail "403 body missing insufficient_scope code"
grep -q '"required"' /tmp/api_scope_body.txt || fail "403 body missing required key"
# json_encode escapes the slashes, so compare against the unescaped text.
tr -d '\\' < /tmp/api_scope_body.txt | grep -q '"required":"/api/v1/me"' \
    || fail "403 body does not name the required route pattern"
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# Test 3: the method is NOT part of a scope - a path scope covers both verbs
# '/api/v1/files/*' is prefix-inclusive and method-blind, so the POST upload endpoint under
# it is reachable. It answers 422 for a body with no file part, which is the endpoint's own
# answer and therefore proof the scope check let it through.
# ---------------------------------------------------------------------------
echo "[TEST] 3. A path scope covers POST as well as GET..." >&2
status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' -X POST \
    -H "Authorization: Bearer $SCOPED_KEY" \
    -H 'Content-Type: application/json' --data '{}' "$BASE/api/v1/files")
[ "$status" != "403" ] || fail "a path scope must not refuse POST (got 403)"
grep -q '"insufficient_scope"' /tmp/api_scope_body.txt \
    && fail "the POST was refused by the scope check"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# Test 4: the scope check precedes param validation
# An undeclared param on an UNGRANTED endpoint answers 403, not 422 - a key that may
# not reach an endpoint learns nothing about its parameters.
# ---------------------------------------------------------------------------
echo "[TEST] 4. Scope check precedes param validation..." >&2
status=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/api/v1/me?bogus=1")
[ "$status" = "403" ] || fail "unreachable endpoint with a bad param expected 403, got $status"
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# Test 4b: the query string is not part of the match
# The scoped key's own endpoint still gets past the scope check with a query string
# attached (whatever the endpoint then makes of the parameter) - the scope sees the path
# only. Test 4 is the other half: the unreachable one still answers 403 with one.
# ---------------------------------------------------------------------------
echo "[TEST] 4b. The query string is ignored by the scope check..." >&2
status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/api/v1/files/no_such_attachment_key?page=1")
[ "$status" != "403" ] || fail "a query string must not change the scope answer, got $status"
grep -q '"insufficient_scope"' /tmp/api_scope_body.txt \
    && fail "a query string was folded into the scope match"

echo "[TEST] 4b. OK" >&2

# ---------------------------------------------------------------------------
# Test 5: a NULL-scope key is completely unchanged
# ---------------------------------------------------------------------------
echo "[TEST] 5. Unrestricted key is unchanged..." >&2
status=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $OPEN_KEY" "$BASE/api/v1/me")
[ "$status" = "200" ] || fail "unrestricted key expected 200 on /api/v1/me, got $status"
status=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $OPEN_KEY" "$BASE/api/v1/me?bogus=1")
[ "$status" = "422" ] || fail "unrestricted key expected 422 for a bad param, got $status"
echo "[TEST] 5. OK" >&2

# ---------------------------------------------------------------------------
# Test 6: the web byte-serving routes honor the scope too
# A key scoped away from files is refused on /_download BEFORE the attachment is even
# looked up; the unrestricted key gets the ordinary 404 for the same bogus key.
# ---------------------------------------------------------------------------
echo "[TEST] 6. Web download path refuses a key scoped away from files..." >&2
dev_db "UPDATE _api_keys SET scopes = '/api/v1/me' WHERE id = $SCOPED_KEY_ID"
status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/_download/no_such_attachment_key")
[ "$status" = "403" ] || fail "scoped key on /_download expected 403, got $status"
grep -q '"insufficient_scope"' /tmp/api_scope_body.txt \
    || fail "/_download 403 body missing insufficient_scope code"

status=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $OPEN_KEY" "$BASE/_download/no_such_attachment_key")
[ "$status" = "404" ] || fail "unrestricted key on /_download expected 404, got $status"
echo "[TEST] 6. OK" >&2

# ---------------------------------------------------------------------------
# Test 7: a files grant restores the web download path
# ---------------------------------------------------------------------------
echo "[TEST] 7. A files scope reopens the web download path..." >&2
dev_db "UPDATE _api_keys SET scopes = '/api/v1/files/*' WHERE id = $SCOPED_KEY_ID"
status=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/_download/no_such_attachment_key")
[ "$status" = "404" ] || fail "files-scoped key on /_download expected 404, got $status"
echo "[TEST] 7. OK" >&2

# ---------------------------------------------------------------------------
# Test 8: every scope denial reached _api_request_log, handler and all
# ---------------------------------------------------------------------------
echo "[TEST] 8. Scope denials are logged..." >&2
rows=$(dev_db "SELECT COUNT(*) FROM _api_request_log WHERE id > $LOG_BASELINE AND status = 403 AND api_key_id = $SCOPED_KEY_ID AND response_error_code = 'insufficient_scope'")
[ "$rows" -ge 1 ] || fail "no insufficient_scope log row was written for the scoped key"
handler=$(dev_db "SELECT handler FROM _api_request_log WHERE id > $LOG_BASELINE AND status = 403 AND response_error_code = 'insufficient_scope' AND path = '/api/v1/me' LIMIT 1")
[ -n "$handler" ] || fail "the scope-denial log row records no handler"
echo "[TEST] 8. OK" >&2

# ---------------------------------------------------------------------------
# Test 9: GET /api/v1/me reports the key's own scopes
# A client that has just been refused with insufficient_scope can read here exactly what
# its key IS allowed to call, without an operator describing the key over chat.
# ---------------------------------------------------------------------------
echo "[TEST] 9. /me reports the key scopes..." >&2
dev_db "UPDATE _api_keys SET scopes = '/api/v1/me' WHERE id = $SCOPED_KEY_ID"
status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $SCOPED_KEY" "$BASE/api/v1/me")
[ "$status" = "200" ] || fail "/me under a matching scope expected 200, got $status"
# json_encode escapes the slashes, so compare against the unescaped text.
tr -d '\\' < /tmp/api_scope_body.txt | grep -q '"scopes":"/api/v1/me"' \
    || fail "/me does not report the key's scopes"
dev_db "UPDATE _api_keys SET scopes = '/api/v1/files/*' WHERE id = $SCOPED_KEY_ID"

curl -s -o /tmp/api_scope_body.txt -H "Authorization: Bearer $OPEN_KEY" "$BASE/api/v1/me"
grep -q '"scopes":null' /tmp/api_scope_body.txt \
    || fail "/me should report scopes null for an unrestricted key"
echo "[TEST] 9. OK" >&2

# ---------------------------------------------------------------------------
# Test 10: a key whose ONLY scope is malformed denies everything, and says so in the log
# Planted with raw SQL, because every write path validates - which is exactly how an
# operator hand-editing the column would produce one.
# ---------------------------------------------------------------------------
echo "[TEST] 10. A malformed-only scope set fails closed and warns..." >&2
LARAVEL_LOG="/var/www/html/storage/logs/laravel.log"
LOG_SIZE_BEFORE=0
if [ -f "$LARAVEL_LOG" ]; then
    LOG_SIZE_BEFORE=$(stat -c%s "$LARAVEL_LOG")
fi

dev_db "UPDATE _api_keys SET scopes = '/api/v1/files*' WHERE id = $SCOPED_KEY_ID"

for path in /api/v1/me /api/v1/files/no_such_attachment_key; do
    status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
        -H "Authorization: Bearer $SCOPED_KEY" "$BASE$path")
    [ "$status" = "403" ] || fail "a malformed-only scope set must deny $path, got $status"
    grep -q '"insufficient_scope"' /tmp/api_scope_body.txt \
        || fail "$path denial is not insufficient_scope"
done

if [ -f "$LARAVEL_LOG" ]; then
    tail -c +$((LOG_SIZE_BEFORE + 1)) "$LARAVEL_LOG" \
        | grep -q "API key #$SCOPED_KEY_ID: ignoring malformed scope '/api/v1/files\*'" \
        || fail "no malformed-scope warning was logged for key $SCOPED_KEY_ID"
else
    fail "storage/logs/laravel.log does not exist, so the warning cannot be verified"
fi

dev_db "UPDATE _api_keys SET scopes = '/api/v1/files/*' WHERE id = $SCOPED_KEY_ID"
echo "[TEST] 10. OK" >&2

# ---------------------------------------------------------------------------
# Test 11: there is NO by-name addressing channel
# A scope is a path pattern, so a second way to address a handler would be a scope bypass.
# Class/method style URLs must be ordinary 404s, for the unrestricted key too.
# ---------------------------------------------------------------------------
echo "[TEST] 11. By-name addressing does not exist..." >&2
for path in \
    "/api/v1/Files_Api_Controller/info" \
    "/api/v1/Files_Api_Controller::info" \
    "/api/v1/Identity_Api_Controller/me"; do
    status=$(curl -s -o /tmp/api_scope_body.txt -w '%{http_code}' \
        -H "Authorization: Bearer $OPEN_KEY" "$BASE$path")
    [ "$status" = "404" ] || fail "$path should not resolve (got $status)"
    grep -q '"not_found"' /tmp/api_scope_body.txt \
        || fail "$path did not answer the ordinary not_found envelope"
done
echo "[TEST] 11. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
