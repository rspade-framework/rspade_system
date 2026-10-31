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
#      content type - answers 204, and logs exactly one [CSP_VIOLATION] line to the
#      application log, which it does under ENFORCEMENT: report-uri means blocked AND
#      reported; the same report again from the same address is not logged twice.
#
# Each run reports from an address of its own (X-Forwarded-For, believed from loopback), so
# the per-address quota and the 30-minute dedup never carry over from an earlier run.
#
# The header NAME is asserted, both ways: the policy always enforces, so
# Content-Security-Policy must be present and Content-Security-Policy-Report-Only must not.

BASE="http://localhost"
LOGS_DIR="/var/www/html/storage/logs"
CLIENT="198.51.100.$(( (RANDOM % 250) + 1 ))"
RUN="$$-$RANDOM"

# The [CSP_VIOLATION] lines the application log holds that carry $1.
csp_lines() {
    cat "$LOGS_DIR"/laravel*.log 2>/dev/null | grep -F '[CSP_VIOLATION]' | grep -F -- "$1" || true
}
csp_count() {
    csp_lines "$1" | grep -c . || true
}
post_report() {
    curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Forwarded-For: ${CLIENT}" "$@" "${BASE}/_csp-report" 2>/dev/null
}

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
marker="csp-http-test-${RUN}"
report="{\"csp-report\":{\"document-uri\":\"${BASE}/login\",\"violated-directive\":\"script-src\",\"blocked-uri\":\"https://${marker}.example.com/x.js\"}}"
status="$(post_report -H 'Content-Type: application/csp-report' --data "$report")"

if [ "$status" != "204" ]; then
    echo "FAIL: $TEST_NAME - the collector answered $status (expected 204)"
    exit 1
fi

if [ "$(csp_count "$marker")" != "1" ]; then
    echo "FAIL: $TEST_NAME - the log holds $(csp_count "$marker") lines for the report (expected exactly 1)"
    exit 1
fi
echo "[TEST] 4. OK - 204 and exactly one [CSP_VIOLATION] line" >&2

# ---------------------------------------------------------------------------
# Step 4b: the same report again from the same address is not logged again.
# ---------------------------------------------------------------------------
echo "[TEST] 4b. A repeat of the same report is not logged twice..." >&2
status="$(post_report -H 'Content-Type: application/csp-report' --data "$report")"
if [ "$status" != "204" ] || [ "$(csp_count "$marker")" != "1" ]; then
    echo "FAIL: $TEST_NAME - a repeated report answered $status and the log holds $(csp_count "$marker") lines (expected 204, 1)"
    exit 1
fi
echo "[TEST] 4b. OK - deduplicated" >&2

# ---------------------------------------------------------------------------
# Step 5: a malformed body is recorded, not rejected. A collector that 4xx'd would
# teach browsers to retry and turn reporting into an error loop.
# ---------------------------------------------------------------------------
echo "[TEST] 5. A malformed body still answers 204..." >&2
status="$(post_report -H 'Content-Type: application/csp-report' --data "not json at all ${marker}-invalid")"

if [ "$status" != "204" ]; then
    echo "FAIL: $TEST_NAME - a malformed report answered $status (expected 204)"
    exit 1
fi

if ! csp_lines "${marker}-invalid" | grep -q '"invalid"'; then
    echo "FAIL: $TEST_NAME - the malformed body was not recorded under invalid"
    exit 1
fi
echo "[TEST] 5. OK - recorded as invalid, still 204" >&2

# ---------------------------------------------------------------------------
# Step 6: an oversized body is one line naming its size, never parsed or stored.
# ---------------------------------------------------------------------------
echo "[TEST] 6. An oversized body logs one 'oversized' line..." >&2
big="/tmp/csp_big_$$.json"
head -c 70000 /dev/zero | tr '\0' 'a' > "$big"
status="$(post_report -H 'Content-Type: application/csp-report' -H "User-Agent: ${marker}-oversized" --data-binary "@${big}")"
rm -f "$big"

if [ "$status" != "204" ]; then
    echo "FAIL: $TEST_NAME - an oversized body answered $status (expected 204)"
    exit 1
fi

if [ "$(csp_count "${marker}-oversized")" != "1" ] || ! csp_lines "${marker}-oversized" | grep -q '"oversized":70000'; then
    echo "FAIL: $TEST_NAME - an oversized body was not recorded as one oversized line"
    exit 1
fi
echo "[TEST] 6. OK - one line, size only" >&2

# ---------------------------------------------------------------------------
# Step 7: a batch longer than the cap logs the cap plus one 'dropped' line.
# ---------------------------------------------------------------------------
echo "[TEST] 7. A long Reporting API batch is capped..." >&2
batch="["
for i in $(seq 1 30); do
    [ "$i" -gt 1 ] && batch="${batch},"
    batch="${batch}{\"type\":\"csp-violation\",\"age\":${i},\"body\":{\"n\":${i},\"m\":\"${marker}-batch\"}}"
done
batch="${batch}]"
status="$(post_report -H 'Content-Type: application/reports+json' -H "User-Agent: ${marker}-batch" --data "$batch")"

if [ "$status" != "204" ]; then
    echo "FAIL: $TEST_NAME - a long batch answered $status (expected 204)"
    exit 1
fi

if [ "$(csp_count "${marker}-batch")" != "21" ] || ! csp_lines "${marker}-batch" | grep -q '"dropped":10'; then
    echo "FAIL: $TEST_NAME - the batch logged $(csp_count "${marker}-batch") lines (expected 20 plus one dropped line)"
    exit 1
fi
if csp_lines "${marker}-batch" | grep -q '"age"'; then
    echo "FAIL: $TEST_NAME - an envelope's age was recorded (it would defeat the dedup)"
    exit 1
fi
echo "[TEST] 7. OK - 20 lines plus dropped:10, no age" >&2

echo "PASS: $TEST_NAME"
exit 0
