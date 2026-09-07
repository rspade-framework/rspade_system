#!/bin/bash
set -e

TEST_NAME="Dispatch abort() status"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# HTTP integration test - runs against the live web server, no database switching.
#
# An RSX action runs inside Laravel's handling of its own NotFoundHttpException, so a
# handler calling abort(404) used to throw a second HttpException that escaped as an
# uncaught fatal: every abort() in RSX code arrived on the wire as HTTP 500. The
# Dispatcher now converts a coded HTTP outcome at the seam that invoked the action.
#
# /_preview/pdf/<unknown key> is the real-world case that exposed it: an unknown
# rendition key is rejected with abort(404) before any content is served. No database
# seeding or auth is needed - an unknown key is unknown to everybody.

BASE="http://localhost"

echo "[SETUP] Preparing test..." >&2

if ! curl -s -o /dev/null --connect-timeout 2 "$BASE/" 2>/dev/null; then
    echo "SKIP: $TEST_NAME - web server not reachable on localhost"
    exit 0
fi

echo "[TEST] Running abort() status assertions..." >&2

# ---------------------------------------------------------------------------
# Test 1: an unknown preview rendition key is 404 on the wire, not 500.
# ---------------------------------------------------------------------------
echo "[TEST] 1. /_preview/pdf/<unknown> is 404..." >&2
status=$(curl -s --head -o /dev/null -w '%{http_code}' "$BASE/_preview/pdf/doesnotexist" 2>/dev/null)
if [ "$status" != "404" ]; then
    echo "FAIL: $TEST_NAME - expected 404 for an unknown rendition key, got: $status"
    exit 1
fi
echo "[TEST] 1. OK - unknown rendition key returns 404" >&2

# ---------------------------------------------------------------------------
# Test 2: the same URL fetched the way an <img> fetches it is also 404, and the
# body is NOT a themed HTML page - markup cannot use one.
# ---------------------------------------------------------------------------
echo "[TEST] 2. asset-channel fetch is 404 with a plain body..." >&2
body_file=$(mktemp)
status=$(curl -s -H 'Accept: image/webp,image/*' -o "$body_file" -w '%{http_code}' "$BASE/_preview/pdf/doesnotexist" 2>/dev/null)
if [ "$status" != "404" ]; then
    rm -f "$body_file"
    echo "FAIL: $TEST_NAME - expected 404 on the asset channel, got: $status"
    exit 1
fi
if grep -qi '<html' "$body_file"; then
    rm -f "$body_file"
    echo "FAIL: $TEST_NAME - the asset channel received a themed HTML page"
    exit 1
fi
rm -f "$body_file"
echo "[TEST] 2. OK - asset channel returns a plain 404" >&2

# ---------------------------------------------------------------------------
# Test 3: a status with no Error_Screens page keeps its own status and message.
# ---------------------------------------------------------------------------
echo "[TEST] 3. abort(418) keeps its status..." >&2
status=$(curl -s --head -o /dev/null -w '%{http_code}' "$BASE/_test/dispatch/abort-418" 2>/dev/null)
if [ "$status" != "418" ]; then
    echo "FAIL: $TEST_NAME - expected 418 from the abort fixture route, got: $status"
    exit 1
fi
echo "[TEST] 3. OK - abort(418) arrives as 418" >&2

echo "PASS: $TEST_NAME"
exit 0
