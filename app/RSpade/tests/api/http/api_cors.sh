#!/bin/bash
set -e

TEST_NAME="API CORS"

# HTTP integration test - runs against the LIVE dev server + dev database (rspade).
# CORS (config/cors.php) covers the external API and nothing else: /api/vN answers any
# origin with no credentials, and every internal path - Ajax, pages, the retired
# Sanctum cookie route - sends no Access-Control-* header at all. The 401 rows the API
# probes write to _api_request_log are removed by a trap.

BASE="http://localhost"
DB_ARGS="-h127.0.0.1 -urspade -prspadepass rspade"
LOG_BASELINE=""

dev_db() { mysql $DB_ARGS -N -e "$1" 2>/dev/null; }

cleanup() {
    if [ -n "$LOG_BASELINE" ]; then
        dev_db "DELETE FROM _api_request_log WHERE id > $LOG_BASELINE" || true
    fi
}
trap cleanup EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

headers() {
    curl -s -o /dev/null -D - -X "$1" -H 'Origin: https://other-origin.example' \
        -H 'Access-Control-Request-Method: GET' "${BASE}$2"
}

LOG_BASELINE=$(dev_db "SELECT COALESCE(MAX(id), 0) FROM _api_request_log")

echo "[TEST] 1. An API preflight answers any origin, without credentials..." >&2
out="$(headers OPTIONS /api/v1/me)"
printf '%s' "$out" | grep -qi '^access-control-allow-origin: \*' || fail "API preflight carries no Access-Control-Allow-Origin: *"
printf '%s' "$out" | grep -qi '^access-control-allow-credentials' && fail "API preflight allows credentials"
echo "[TEST] 1. OK" >&2

echo "[TEST] 2. An API GET answers any origin..." >&2
headers GET /api/v1/me | grep -qi '^access-control-allow-origin: \*' || fail "API GET carries no Access-Control-Allow-Origin: *"
echo "[TEST] 2. OK" >&2

echo "[TEST] 3. Internal paths send no CORS header..." >&2
for probe in "POST /_ajax/Nonexistent_Probe_Class/x" "OPTIONS /_ajax/Nonexistent_Probe_Class/x" "GET /login" "GET /sanctum/csrf-cookie"; do
    set -- $probe
    if headers "$1" "$2" | grep -qi '^access-control-'; then
        fail "$probe sent an Access-Control-* header"
    fi
done
echo "[TEST] 3. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
