#!/bin/bash
set -e

TEST_NAME="Session Cookie Behavior"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# HTTP integration test - runs against live web server, no database switching.
# Session creation tests need the real database to insert session records.
#
# Needs a FRAMEWORK-DEVELOPMENT box (IS_FRAMEWORK_DEVELOPER=true): the /ssr-test probes
# are gated #[Auth('is_framework_developer')] and are refused everywhere else.
#
# Port 80 is plain http (development: the unprefixed `rsx` cookie, not Secure); port 8000
# is the image's TLS-terminated listener (X-Forwarded-Proto https: the Secure
# `__Host-rsx` cookie). See Rsx_Session_Cookie.

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

# ---------------------------------------------------------------------------
# Test 6: a SECURE request names the cookie __Host-rsx: Secure, Path=/, no Domain
# ---------------------------------------------------------------------------
echo "[TEST] 6. Secure request - __Host-rsx cookie..." >&2
secure_cookie=$(curl -s -D - -o /dev/null "http://localhost:8000/ssr-test/session-get-session-id" 2>/dev/null | grep -i '^Set-Cookie: __Host-rsx=' | tr -d '\r' || true)
if [ -z "$secure_cookie" ]; then
    echo "FAIL: $TEST_NAME - a secure request did not set __Host-rsx"
    exit 1
fi
if ! echo "$secure_cookie" | grep -qi "; secure"; then
    echo "FAIL: $TEST_NAME - __Host-rsx without Secure (a browser rejects it): $secure_cookie"
    exit 1
fi
if ! echo "$secure_cookie" | grep -qi "; path=/;"; then
    echo "FAIL: $TEST_NAME - __Host-rsx without Path=/ (a browser rejects it): $secure_cookie"
    exit 1
fi
if echo "$secure_cookie" | grep -qi "domain="; then
    echo "FAIL: $TEST_NAME - __Host-rsx carries a Domain (a browser rejects it): $secure_cookie"
    exit 1
fi
echo "[TEST] 6. OK - __Host-rsx is Secure, Path=/, host-only" >&2

# ---------------------------------------------------------------------------
# Test 7: a secure request IGNORES a plain `rsx` cookie - a planted unprefixed token is
# never resumed, so a new session (a different token) is minted instead
# ---------------------------------------------------------------------------
echo "[TEST] 7. Secure request ignores a planted plain rsx cookie..." >&2
planted=$(echo "$secure_cookie" | sed -E 's/^Set-Cookie: __Host-rsx=([^;]+);.*/\1/I')
reply=$(curl -s -D - -o /dev/null -b "rsx=$planted" "http://localhost:8000/ssr-test/session-get-session-id" 2>/dev/null | grep -i '^Set-Cookie: __Host-rsx=' | tr -d '\r' || true)
reply_token=$(echo "$reply" | sed -E 's/^Set-Cookie: __Host-rsx=([^;]+);.*/\1/I')
if [ -z "$reply_token" ] || [ "$reply_token" = "$planted" ]; then
    echo "FAIL: $TEST_NAME - a plain rsx cookie was resumed on a secure request: $reply"
    exit 1
fi
echo "[TEST] 7. OK - plain rsx ignored; a fresh __Host-rsx session was minted" >&2

echo "PASS: $TEST_NAME"
exit 0
