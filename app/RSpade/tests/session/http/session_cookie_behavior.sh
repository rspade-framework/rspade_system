#!/bin/bash
set -e

TEST_NAME="Session Cookie Behavior"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# HTTP integration test - runs against live web server, no database switching.
# Session creation tests need the real database to insert session records.

echo "[SETUP] Preparing test..." >&2
echo "[TEST] Running session cookie assertions..." >&2

# Helper: extract Set-Cookie from response headers
# Uses port 80 (direct to PHP via nginx) to isolate PHP behavior
get_set_cookie() {
    curl -s -D - -o /dev/null "http://localhost$1" 2>/dev/null | grep -i '^Set-Cookie:' || true
}

# ---------------------------------------------------------------------------
# Test 1: Noop endpoint should NOT set any cookies
# ---------------------------------------------------------------------------
echo "[TEST] 1. Noop endpoint - no session interaction..." >&2
cookies=$(get_set_cookie "/ssr-test/session-noop")
if [ -n "$cookies" ]; then
    echo "FAIL: $TEST_NAME - Noop endpoint set cookies: $cookies"
    exit 1
fi
echo "[TEST] 1. OK - No cookies on noop endpoint" >&2

# ---------------------------------------------------------------------------
# Test 2: get_user_id endpoint should NOT set cookies
# Session::init() + Session::get_user_id() do not trigger __activate()
# ---------------------------------------------------------------------------
echo "[TEST] 2. get_user_id endpoint - init only..." >&2
cookies=$(get_set_cookie "/ssr-test/session-get-user-id")
if [ -n "$cookies" ]; then
    echo "FAIL: $TEST_NAME - get_user_id endpoint set cookies: $cookies"
    exit 1
fi
echo "[TEST] 2. OK - No cookies on get_user_id endpoint" >&2

# ---------------------------------------------------------------------------
# Test 3: get_session_id endpoint SHOULD set rsx cookie
# Session::get_session_id() triggers __activate() which creates session
# ---------------------------------------------------------------------------
echo "[TEST] 3. get_session_id endpoint - triggers session creation..." >&2
cookies=$(get_set_cookie "/ssr-test/session-get-session-id")
if [ -z "$cookies" ]; then
    echo "FAIL: $TEST_NAME - get_session_id endpoint did NOT set cookies"
    exit 1
fi
if ! echo "$cookies" | grep -q "rsx="; then
    echo "FAIL: $TEST_NAME - get_session_id set cookie but not 'rsx': $cookies"
    exit 1
fi
echo "[TEST] 3. OK - get_session_id sets rsx cookie" >&2

# ---------------------------------------------------------------------------
# Test 4: SSR test page (with #[FPC]) should NOT set cookies for anonymous
# ---------------------------------------------------------------------------
echo "[TEST] 4. FPC-marked page - no cookies for anonymous..." >&2
cookies=$(get_set_cookie "/ssr-test")
if [ -n "$cookies" ]; then
    echo "FAIL: $TEST_NAME - FPC page set cookies for anonymous: $cookies"
    exit 1
fi
echo "[TEST] 4. OK - No cookies on FPC-marked page" >&2

# ---------------------------------------------------------------------------
# Test 5: Verify rsx cookie has correct security attributes
# ---------------------------------------------------------------------------
echo "[TEST] 5. Cookie security attributes..." >&2
full_cookie=$(curl -s -D - -o /dev/null "http://localhost/ssr-test/session-get-session-id" 2>/dev/null | grep -i '^Set-Cookie: rsx=' || true)

if [ -z "$full_cookie" ]; then
    echo "FAIL: $TEST_NAME - Could not get rsx cookie for attribute check"
    exit 1
fi

# Check httponly flag
if ! echo "$full_cookie" | grep -qi "httponly"; then
    echo "FAIL: $TEST_NAME - rsx cookie missing HttpOnly flag"
    exit 1
fi

# Check samesite flag
if ! echo "$full_cookie" | grep -qi "samesite"; then
    echo "FAIL: $TEST_NAME - rsx cookie missing SameSite flag"
    exit 1
fi

echo "[TEST] 5. OK - Cookie has HttpOnly and SameSite flags" >&2

echo "PASS: $TEST_NAME"
exit 0
