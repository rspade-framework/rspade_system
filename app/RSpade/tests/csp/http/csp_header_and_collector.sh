#!/bin/bash
set -e

TEST_NAME="CSP Header + Collector"

# HTTP integration test - runs against the live web server (dev DB). It is the web-branch
# counterpart to Csp_Compose_Test, which composes policies in-process and therefore never
# proves the two things only a real response can show:
#
#   1. A dispatched HTML page CARRIES the policy, and the nonce in the header is the SAME
#      value stamped on the inline window.rsxapp script. A mismatch is invisible to the
#      composer (both halves are individually correct) and fatal the day enforcement is on.
#   2. The collector accepts a browser-shaped report - no session, no CSRF token, a vendor
#      content type - answers 204, and appends exactly one line to the violation log, which
#      it does under ENFORCEMENT: report-uri means blocked AND reported.
#
# The header NAME is asserted, both ways: the policy always enforces, so
# Content-Security-Policy must be present and Content-Security-Policy-Report-Only must not.

BASE="http://localhost"
LOG="/var/www/html/storage/logs/csp_violations.log"

# ---------------------------------------------------------------------------
# Step 1: an HTML page carries a policy, with a nonce and a report destination.
# ---------------------------------------------------------------------------
echo "[TEST] 1. GET /login carries an ENFORCING Content-Security-Policy..." >&2
headers="$(curl -s -D - -o /tmp/csp_body_$$.html "${BASE}/login" 2>/dev/null)"
policy="$(printf '%s' "$headers" | grep -i '^content-security-policy:' | head -1 || true)"
report_only="$(printf '%s' "$headers" | grep -ic '^content-security-policy-report-only' || true)"

if [ -z "$policy" ]; then
    echo "FAIL: $TEST_NAME - /login carried no Content-Security-Policy header"
    rm -f "/tmp/csp_body_$$.html"
    exit 1
fi

if [ "$report_only" != "0" ]; then
    echo "FAIL: $TEST_NAME - /login carried a Content-Security-Policy-Report-Only header"
    rm -f "/tmp/csp_body_$$.html"
    exit 1
fi

case "$policy" in
    *"report-uri /_csp-report"*) ;;
    *)
        echo "FAIL: $TEST_NAME - the policy names no report-uri: $policy"
        rm -f "/tmp/csp_body_$$.html"
        exit 1
        ;;
esac
echo "[TEST] 1. OK - enforcing policy present with a report destination" >&2

# ---------------------------------------------------------------------------
# Step 2: the header nonce is the nonce stamped on the page's inline script.
# ---------------------------------------------------------------------------
echo "[TEST] 2. The header nonce matches the inline script's nonce attribute..." >&2
header_nonce="$(printf '%s' "$policy" | grep -oE "'nonce-[^']+'" | head -1 | sed "s/'nonce-//; s/'$//")"
page_nonce="$(grep -oE '<script nonce="[^"]+"' "/tmp/csp_body_$$.html" | head -1 | sed 's/.*nonce="//; s/"$//')"
rm -f "/tmp/csp_body_$$.html"

if [ -z "$header_nonce" ]; then
    echo "FAIL: $TEST_NAME - the policy carries no nonce"
    exit 1
fi

if [ "$header_nonce" != "$page_nonce" ]; then
    echo "FAIL: $TEST_NAME - header nonce '$header_nonce' != inline script nonce '$page_nonce'"
    exit 1
fi
echo "[TEST] 2. OK - one nonce, both halves (${header_nonce:0:8}...)" >&2

# ---------------------------------------------------------------------------
# Step 3: a JSON response carries no policy (the header is HTML-only).
# ---------------------------------------------------------------------------
echo "[TEST] 3. A JSON response carries no policy..." >&2
json_policy="$(curl -s -D - -o /dev/null -X POST "${BASE}/_ajax/Csp_No_Such_Controller/noop" 2>/dev/null \
    | grep -ic '^content-security-policy' || true)"
if [ "$json_policy" != "0" ]; then
    echo "FAIL: $TEST_NAME - a JSON response was stamped with a policy"
    exit 1
fi
echo "[TEST] 3. OK - HTML only" >&2

# ---------------------------------------------------------------------------
# Step 4: the collector takes a report, answers 204, and logs one line.
# ---------------------------------------------------------------------------
echo "[TEST] 4. POST /_csp-report is accepted and logged (the collector runs under enforcement)..." >&2
before=0
if [ -f "$LOG" ]; then
    before="$(wc -l < "$LOG")"
fi

marker="csp-http-test-$$"
status="$(curl -s -o /dev/null -w '%{http_code}' -X POST \
    -H 'Content-Type: application/csp-report' \
    --data "{\"csp-report\":{\"document-uri\":\"${BASE}/login\",\"violated-directive\":\"script-src\",\"blocked-uri\":\"https://${marker}.example.com/x.js\"}}" \
    "${BASE}/_csp-report" 2>/dev/null)"

if [ "$status" != "204" ]; then
    echo "FAIL: $TEST_NAME - the collector answered $status (expected 204)"
    exit 1
fi

after="$(wc -l < "$LOG")"
if [ "$after" -ne $((before + 1)) ]; then
    echo "FAIL: $TEST_NAME - the log gained $((after - before)) lines (expected exactly 1)"
    exit 1
fi

if ! tail -1 "$LOG" | grep -q "$marker"; then
    echo "FAIL: $TEST_NAME - the appended line does not carry the posted report"
    exit 1
fi
echo "[TEST] 4. OK - 204 and exactly one line appended" >&2

# ---------------------------------------------------------------------------
# Step 5: a malformed body is recorded, not rejected. A collector that 4xx'd would
# teach browsers to retry and turn reporting into an error loop.
# ---------------------------------------------------------------------------
echo "[TEST] 5. A malformed body still answers 204..." >&2
before="$(wc -l < "$LOG")"
status="$(curl -s -o /dev/null -w '%{http_code}' -X POST \
    -H 'Content-Type: application/csp-report' \
    --data "not json at all ${marker}-invalid" \
    "${BASE}/_csp-report" 2>/dev/null)"

if [ "$status" != "204" ]; then
    echo "FAIL: $TEST_NAME - a malformed report answered $status (expected 204)"
    exit 1
fi

if ! tail -1 "$LOG" | grep -q "${marker}-invalid"; then
    echo "FAIL: $TEST_NAME - the malformed body was not recorded verbatim"
    exit 1
fi
echo "[TEST] 5. OK - recorded as invalid, still 204" >&2

echo "PASS: $TEST_NAME"
exit 0
