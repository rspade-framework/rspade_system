#!/bin/bash
set -e

TEST_NAME="Session Token Immutability"

# HTTP integration test - runs against the live web server (dev DB).
#
# Proves the owner ruling (2026-07-24): a session token STRING is minted EXACTLY
# ONCE, at session creation, and is IMMUTABLE for the life of the session. Logging in
# is a pure record update on the existing row - it never mints a new token. The SAME
# token cookie IS re-emitted on every response (sliding expiry refresh) - what must
# never change is the VALUE. This is the web-branch counterpart to the CLI-only PHP
# session tests (which cannot exercise the DB/cookie path).
#
# Uses the dev default credentials (admin@test.com / admintest99, the config
# rsx.default_user defaults auto-filled on a debug site). If a site overrides
# RSPADE_DEFAULT_EMAIL/RSPADE_DEFAULT_PASSWORD, adjust below.

LOGIN_EMAIL="admin@test.com"
LOGIN_PASSWORD="admintest99"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

echo "[SETUP] Preparing test..." >&2

# ---------------------------------------------------------------------------
# Step 1: Create an anonymous session. The create-new-session branch is the ONE
# sanctioned cookie site, so this MUST emit a Set-Cookie: rsx=.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Anonymous session creation sets rsx cookie..." >&2
step1_headers="$(curl -s -D - -o /dev/null -c "$JAR" "http://localhost/ssr-test/session-get-session-id" 2>/dev/null)"
if ! echo "$step1_headers" | grep -qi '^Set-Cookie: rsx='; then
    echo "FAIL: $TEST_NAME - session creation did not set the rsx cookie"
    exit 1
fi
token_before="$(grep -w rsx "$JAR" | awk '{print $7}')"
if [ -z "$token_before" ]; then
    echo "FAIL: $TEST_NAME - could not capture rsx token from cookie jar"
    exit 1
fi
echo "[TEST] 1. OK - anonymous session token minted" >&2

# ---------------------------------------------------------------------------
# Step 2: Log in on the EXISTING session. This is a pure record update - the
# response re-emits the SAME token (sliding expiry) but must never carry a
# DIFFERENT one, and the cookie-jar value must be byte-identical afterward.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Login does not rotate the rsx token (same-value re-emission OK)..." >&2

# Step 1 gave this browser a session, so the CSRF seam REQUIRES a token on the login
# POST (a session-less first-visit login does not - see tests/csrf/http). Read the
# page's own token, the way the form would.
login_page="$(mktemp)"
curl -s -b "$JAR" -c "$JAR" -o "$login_page" "http://localhost/login" 2>/dev/null
csrf_token="$(grep -oE '"csrf": *"[a-f0-9]+"' "$login_page" | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
rm -f "$login_page"
if [ -z "$csrf_token" ]; then
    echo "FAIL: $TEST_NAME - could not read window.rsxapp.csrf from the login page"
    exit 1
fi

login_headers="$(curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" \
    --data-urlencode "email=${LOGIN_EMAIL}" \
    --data-urlencode "password=${LOGIN_PASSWORD}" \
    --data-urlencode "_csrf_token=${csrf_token}" \
    "http://localhost/login" 2>/dev/null)"

login_status="$(echo "$login_headers" | head -1 | awk '{print $2}')"
if [ "$login_status" != "302" ]; then
    echo "FAIL: $TEST_NAME - login returned $login_status (expected a 302 redirect); credentials or the csrf seam"
    exit 1
fi

reissued="$(echo "$login_headers" | grep -i '^Set-Cookie: rsx=' | grep -v "rsx=${token_before}" || true)"
if [ -n "$reissued" ]; then
    echo "FAIL: $TEST_NAME - login emitted an rsx cookie with a DIFFERENT token (rotation):"
    echo "$reissued"
    exit 1
fi

token_after="$(grep -w rsx "$JAR" | awk '{print $7}')"
if [ "$token_before" != "$token_after" ]; then
    echo "FAIL: $TEST_NAME - session token changed across login (before=$token_before after=$token_after)"
    exit 1
fi
echo "[TEST] 2. OK - token value stable across login" >&2

# ---------------------------------------------------------------------------
# Step 3: The SAME cookie now authenticates. A protected SPA route returns 200
# with the logged-in cookie (an anonymous request would 302 to /login), proving
# the login took effect on the unchanged token.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Same token now serves an authenticated page..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "http://localhost/dashboard" 2>/dev/null)"
if [ "$status" != "200" ]; then
    echo "FAIL: $TEST_NAME - authenticated /dashboard returned $status (expected 200)"
    exit 1
fi
echo "[TEST] 3. OK - authenticated page served on the original token" >&2

echo "PASS: $TEST_NAME"
exit 0
