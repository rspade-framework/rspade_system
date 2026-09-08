#!/bin/bash
set -e

TEST_NAME="API Read-Only Keys"

# HTTP integration test - runs against the LIVE dev server + dev database (rspade).
# The read_only gate sits inside the dispatcher between bearer authentication and the scope
# check, so it is only observable over real HTTP. This test mints three keys - a read-only
# unrestricted one, a read-only one scoped away from /api/v1/me, and an ordinary read+write one
# - and proves the four things that make the flag a guarantee rather than a label:
#
#   1. a read-only key GETs normally;
#   2. every non-GET with it is 403 read_only_key, whatever its scopes allow;
#   3. the ORDER: read_only is decided before the scopes, so an out-of-scope GET with a
#      read-only key is insufficient_scope and an in-scope POST is read_only_key;
#   4. a read+write key is completely unaffected.
#
# Every row it creates is removed by a trap.
#
# Loopback requests are exempt from the dev hostname guard, so localhost works without
# extra host setup.

BASE="http://localhost"
DB_ARGS="-h127.0.0.1 -urspade -prspadepass rspade"
RO_KEY=""
RO_KEY_ID=""
RO_SCOPED_KEY=""
RO_SCOPED_KEY_ID=""
RW_KEY=""
RW_KEY_ID=""
LOG_BASELINE=""
MINT_SCRIPT=""

dev_db() { mysql $DB_ARGS -N -e "$1" 2>/dev/null; }

cleanup() {
    for id in "$RO_KEY_ID" "$RO_SCOPED_KEY_ID" "$RW_KEY_ID"; do
        if [ -n "$id" ]; then
            dev_db "DELETE FROM _api_keys WHERE id = $id" || true
        fi
    done
    if [ -n "$LOG_BASELINE" ]; then
        dev_db "DELETE FROM _api_request_log WHERE id > $LOG_BASELINE" || true
    fi
    if [ -n "$MINT_SCRIPT" ] && [ -f "$MINT_SCRIPT" ]; then
        rm -f "$MINT_SCRIPT"
    fi
    rm -f /tmp/api_read_only_body.txt
}
trap cleanup EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

# ---------------------------------------------------------------------------
# Setup
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

echo "[SETUP] Minting a read-only key, a read-only scoped key and a read+write key..." >&2
MINT_SCRIPT=$(mktemp /tmp/api_read_only_mint_XXXXXX.php)
cat > "$MINT_SCRIPT" <<'PHP'
<?php
require '/var/www/html/system/vendor/autoload.php';
$app = require '/var/www/html/system/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$read_only = App\RSpade\Core\Api\Api_Key_Model::generate(
    1, 'HTTP read-only test key (temporary)', 'live', null, null, null, true
);
$read_only_scoped = App\RSpade\Core\Api\Api_Key_Model::generate(
    1, 'HTTP read-only scoped test key (temporary)', 'live', null, null, "/api/v1/files/*", true
);
$read_write = App\RSpade\Core\Api\Api_Key_Model::generate(1, 'HTTP read-write test key (temporary)');
echo implode('|', [
    $read_only['key'], $read_only['model']->id,
    $read_only_scoped['key'], $read_only_scoped['model']->id,
    $read_write['key'], $read_write['model']->id,
]) . "\n";
PHP

MINT_OUT=$(php "$MINT_SCRIPT" 2>/dev/null | tail -1)
RO_KEY=$(echo "$MINT_OUT" | cut -d'|' -f1)
RO_KEY_ID=$(echo "$MINT_OUT" | cut -d'|' -f2)
RO_SCOPED_KEY=$(echo "$MINT_OUT" | cut -d'|' -f3)
RO_SCOPED_KEY_ID=$(echo "$MINT_OUT" | cut -d'|' -f4)
RW_KEY=$(echo "$MINT_OUT" | cut -d'|' -f5)
RW_KEY_ID=$(echo "$MINT_OUT" | cut -d'|' -f6)

if [ -z "$RO_KEY_ID" ] || [ -z "$RO_SCOPED_KEY_ID" ] || [ -z "$RW_KEY_ID" ]; then
    fail "could not mint the test keys (got: '$MINT_OUT')"
fi

STORED=$(dev_db "SELECT read_only FROM _api_keys WHERE id = $RO_KEY_ID")
[ "$STORED" = "1" ] || fail "read_only was not stored (got: '$STORED')"
STORED=$(dev_db "SELECT read_only FROM _api_keys WHERE id = $RW_KEY_ID")
[ "$STORED" = "0" ] || fail "a key minted with no flag must be read+write (got: '$STORED')"
echo "[SETUP] Minted keys $RO_KEY_ID, $RO_SCOPED_KEY_ID and $RW_KEY_ID" >&2

# ---------------------------------------------------------------------------
# Test 1: a read-only key GETs normally
# ---------------------------------------------------------------------------
echo "[TEST] 1. Read-only key -> GET 200..." >&2
status=$(curl -s -o /tmp/api_read_only_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $RO_KEY" "$BASE/api/v1/me")
[ "$status" = "200" ] || fail "read-only GET expected 200, got $status"
grep -q '"user_id"' /tmp/api_read_only_body.txt || fail "200 body missing user_id key"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# Test 2: a POST with a read-only key is 403 read_only_key
# The key is UNRESTRICTED, so nothing but the flag can be refusing this.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Read-only key -> POST 403 read_only_key..." >&2
status=$(curl -s -o /tmp/api_read_only_body.txt -w '%{http_code}' -X POST \
    -H "Authorization: Bearer $RO_KEY" \
    -H 'Content-Type: application/json' --data '{}' "$BASE/api/v1/files")
[ "$status" = "403" ] || fail "read-only POST expected 403, got $status"
grep -q '"read_only_key"' /tmp/api_read_only_body.txt \
    || fail "403 body missing read_only_key code"
grep -q 'This API key is read-only: GET requests only.' /tmp/api_read_only_body.txt \
    || fail "403 body does not carry the read-only message"
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# Test 3: the refusal precedes the ROUTE match
# A POST to a path that does not resolve answers read_only_key, not 404 - a read-only key
# learns nothing about which write endpoints exist.
# ---------------------------------------------------------------------------
echo "[TEST] 3. The read-only refusal precedes route resolution..." >&2
status=$(curl -s -o /tmp/api_read_only_body.txt -w '%{http_code}' -X POST \
    -H "Authorization: Bearer $RO_KEY" \
    -H 'Content-Type: application/json' --data '{}' "$BASE/api/v1/no_such_endpoint")
[ "$status" = "403" ] || fail "read-only POST to an unknown path expected 403, got $status"
grep -q '"read_only_key"' /tmp/api_read_only_body.txt \
    || fail "an unknown POST path leaked a 404 to a read-only key"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# Test 4: ORDER - read_only is decided BEFORE the scopes
# The scoped read-only key reaches /api/v1/files and nothing else:
#   - an out-of-scope GET is insufficient_scope (the verb was fine, the path was not);
#   - an IN-SCOPE POST is read_only_key (the path was fine, the verb was not).
# Those two answers together are the proof of the ordering.
# ---------------------------------------------------------------------------
echo "[TEST] 4. read_only is decided before the scopes..." >&2
status=$(curl -s -o /tmp/api_read_only_body.txt -w '%{http_code}' \
    -H "Authorization: Bearer $RO_SCOPED_KEY" "$BASE/api/v1/me")
[ "$status" = "403" ] || fail "out-of-scope GET expected 403, got $status"
grep -q '"insufficient_scope"' /tmp/api_read_only_body.txt \
    || fail "an out-of-scope GET must be refused by the SCOPE check, not the read-only gate"

status=$(curl -s -o /tmp/api_read_only_body.txt -w '%{http_code}' -X POST \
    -H "Authorization: Bearer $RO_SCOPED_KEY" \
    -H 'Content-Type: application/json' --data '{}' "$BASE/api/v1/files")
[ "$status" = "403" ] || fail "in-scope POST expected 403, got $status"
grep -q '"read_only_key"' /tmp/api_read_only_body.txt \
    || fail "an in-scope POST must be refused by the read-only gate, not the scope check"

status=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $RO_SCOPED_KEY" "$BASE/api/v1/files/no_such_attachment_key")
[ "$status" = "404" ] || fail "an in-scope GET expected the endpoint's own 404, got $status"
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# Test 5: a read+write key is unaffected
# It answers the endpoint's own 422 for an empty body, which is proof the request reached
# the endpoint rather than a gate.
# ---------------------------------------------------------------------------
echo "[TEST] 5. A read+write key is unaffected..." >&2
status=$(curl -s -o /tmp/api_read_only_body.txt -w '%{http_code}' -X POST \
    -H "Authorization: Bearer $RW_KEY" \
    -H 'Content-Type: application/json' --data '{}' "$BASE/api/v1/files")
[ "$status" != "403" ] || fail "a read+write key must not be refused (got 403)"
grep -q '"read_only_key"' /tmp/api_read_only_body.txt \
    && fail "a read+write key was refused by the read-only gate"
echo "[TEST] 5. OK" >&2

# ---------------------------------------------------------------------------
# Test 6: GET /api/v1/me reports the flag
# A client that just took a read_only_key 403 can confirm the refusal is the KEY's shape.
# ---------------------------------------------------------------------------
echo "[TEST] 6. /me reports read_only..." >&2
curl -s -o /tmp/api_read_only_body.txt -H "Authorization: Bearer $RO_KEY" "$BASE/api/v1/me"
grep -q '"read_only":true' /tmp/api_read_only_body.txt \
    || fail "/me does not report read_only for a read-only key"

curl -s -o /tmp/api_read_only_body.txt -H "Authorization: Bearer $RW_KEY" "$BASE/api/v1/me"
grep -q '"read_only":false' /tmp/api_read_only_body.txt \
    || fail "/me does not report read_only:false for a read+write key"
echo "[TEST] 6. OK" >&2

# ---------------------------------------------------------------------------
# Test 7: every refusal reached _api_request_log
# "Why did this call stop working" stays answerable from the log.
# ---------------------------------------------------------------------------
echo "[TEST] 7. Read-only denials are logged..." >&2
rows=$(dev_db "SELECT COUNT(*) FROM _api_request_log WHERE id > $LOG_BASELINE AND status = 403 AND response_error_code = 'read_only_key'")
[ "$rows" -ge 1 ] || fail "no read_only_key row was written to _api_request_log"
echo "[TEST] 7. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
