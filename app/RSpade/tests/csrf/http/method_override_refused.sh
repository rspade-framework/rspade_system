#!/bin/bash
set -e

TEST_NAME="Method Override Refused"

# HTTP integration test. HTTP method override is switched off (public/index.php) and the
# CSRF seam asks the REAL method, so a cross-site POST that calls itself PUT - through a
# _method field or an X-HTTP-Method-Override header - is refused exactly like a plain
# cross-site POST: 419, the Page Expired screen.

BASE="http://localhost"

post() {
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Origin: https://cross-site.example' "$@" \
        -d email=probe@example.invalid -d password=probe "${BASE}/login"
}

echo "[TEST] 1. A plain cross-site POST is refused (the baseline)..." >&2
status="$(post)"
[ "$status" = "419" ] || { echo "FAIL: $TEST_NAME - plain cross-site POST answered $status, expected 419"; exit 1; }
echo "[TEST] 1. OK" >&2

echo "[TEST] 2. _method=PUT gains nothing..." >&2
status="$(post -d _method=PUT)"
[ "$status" = "419" ] || { echo "FAIL: $TEST_NAME - cross-site POST with _method=PUT answered $status, expected 419"; exit 1; }
echo "[TEST] 2. OK" >&2

echo "[TEST] 3. X-HTTP-Method-Override: PUT gains nothing..." >&2
status="$(post -H 'X-HTTP-Method-Override: PUT')"
[ "$status" = "419" ] || { echo "FAIL: $TEST_NAME - cross-site POST with X-HTTP-Method-Override answered $status, expected 419"; exit 1; }
echo "[TEST] 3. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
