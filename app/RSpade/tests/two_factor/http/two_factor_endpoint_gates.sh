#!/bin/bash
set -e

TEST_NAME="Two-Factor Endpoint Gates"

# HTTP integration test - runs against the live web server (dev DB), because the thing under
# test is the DISPATCHER, and the dispatcher is the only thing that evaluates #[Auth].
#
# WHY THIS EXISTS ALONGSIDE THE PHP TIER. Two_Factor_Controller_Test calls the endpoints as
# static methods, which is what the dispatcher does AFTER the gate has passed - so it can
# assert what each endpoint DECLARES (through Auth_Gates::surface_gates) but never that the
# declaration is ENFORCED. That is a real gap for this controller in particular, because its
# two gate populations are opposites and getting either backwards is silent: enrollment would
# become anonymous, or the challenge screen would become unreachable by the very session it
# exists to serve.
#
# Three assertions, all through real HTTP:
#   1. challenge_state is PUBLIC and answers null for a caller with nothing pending. It must
#      not demand a login: between a correct password and a correct second factor the session
#      is deliberately logged OUT (Rsx_Two_Factor::begin_challenge).
#   2. totp_begin REFUSES an anonymous caller, at the gate, before the facade is reached.
#   3. totp_begin ACCEPTS a signed-in caller and returns the enrollment payload - the accept
#      side of the same split.
#
# No CSRF token is needed for 1 and 2: a session-less POST is not token-gated (see
# tests/csrf/http/csrf_roundtrip.sh, whose login step relies on the same rule). Step 3 has a
# session, so it reads window.rsxapp.csrf exactly as that test does.
#
# NOTHING IS WRITTEN. begin_totp_enrollment() parks a seed in a session value and creates no
# credential row, so this test leaves the dev database as it found it. It deliberately stops
# short of confirming.
#
# Uses the dev default credentials. If a site overrides RSPADE_DEFAULT_EMAIL /
# RSPADE_DEFAULT_PASSWORD, adjust below.

LOGIN_EMAIL="admin@test.com"
LOGIN_PASSWORD="admintest99"
BASE="http://localhost"
AJAX="${BASE}/_ajax/Rsx_Two_Factor_Controller"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

# ---------------------------------------------------------------------------
# Test 1: challenge_state is public, and answers null with nothing pending.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Anonymous challenge_state answers null..." >&2
response="$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' "${AJAX}/challenge_state" 2>/dev/null)"

if ! printf '%s' "$response" | grep -q '"_success":true'; then
    echo "FAIL: $TEST_NAME - anonymous challenge_state was refused: $response"
    exit 1
fi
if ! printf '%s' "$response" | grep -q '"_ajax_return_value":null'; then
    echo "FAIL: $TEST_NAME - anonymous challenge_state did not answer null: $response"
    exit 1
fi
echo "[TEST] 1. OK - public gate, null answer" >&2

# ---------------------------------------------------------------------------
# Test 2: totp_begin refuses an anonymous caller at the gate.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Anonymous totp_begin is refused..." >&2
response="$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' "${AJAX}/totp_begin" 2>/dev/null)"

if printf '%s' "$response" | grep -q '"_success":true'; then
    echo "FAIL: $TEST_NAME - anonymous totp_begin SUCCEEDED; the is_logged_in gate is not enforced: $response"
    exit 1
fi
if ! printf '%s' "$response" | grep -q '"error_code":"unauthorized"'; then
    echo "FAIL: $TEST_NAME - anonymous totp_begin failed for the wrong reason: $response"
    exit 1
fi
# The gate runs first, so the facade's own refusal must never be what answered here.
if printf '%s' "$response" | grep -qi 'requires a signed-in identity'; then
    echo "FAIL: $TEST_NAME - the facade refused, not the gate; the endpoint is reachable while logged out: $response"
    exit 1
fi
echo "[TEST] 2. OK - refused at the gate" >&2

# ---------------------------------------------------------------------------
# Test 3: totp_begin accepts a signed-in caller and returns the enrollment payload.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Signing in..." >&2
login_status="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" \
    --data-urlencode "email=${LOGIN_EMAIL}" \
    --data-urlencode "password=${LOGIN_PASSWORD}" \
    --data-urlencode "__turnstile=inactive" \
    "${BASE}/login" 2>/dev/null)"
if [ "$login_status" != "302" ]; then
    echo "FAIL: $TEST_NAME - login returned $login_status (expected 302)"
    exit 1
fi

page="$(curl -s -b "$JAR" "${BASE}/dashboard" 2>/dev/null)"
token="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
if [ -z "$token" ]; then
    echo "FAIL: $TEST_NAME - could not read window.rsxapp.csrf from the authenticated page"
    exit 1
fi

echo "[TEST] 3. Authenticated totp_begin returns the enrollment payload..." >&2
response="$(curl -s -b "$JAR" -X POST \
    -H 'Content-Type: application/json' \
    -H "X-CSRF-TOKEN: ${token}" \
    -d '{}' "${AJAX}/totp_begin" 2>/dev/null)"

if ! printf '%s' "$response" | grep -q '"_success":true'; then
    echo "FAIL: $TEST_NAME - authenticated totp_begin was refused: $response"
    exit 1
fi
if ! printf '%s' "$response" | grep -q '"qr_svg"'; then
    echo "FAIL: $TEST_NAME - authenticated totp_begin returned no qr_svg: $response"
    exit 1
fi
echo "[TEST] 3. OK - accepted, payload returned" >&2

echo "PASS: $TEST_NAME"
exit 0
