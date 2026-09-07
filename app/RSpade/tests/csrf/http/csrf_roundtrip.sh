#!/bin/bash
set -e

TEST_NAME="CSRF Round-Trip"

# HTTP integration test - runs against the live web server (dev DB). This is the
# web-branch counterpart to the CLI-only PHP tests (Csrf_Enforce_Test), which cannot
# exercise the ACCEPT path: under CLI Session::$_session is null, so verify_csrf_token()
# can never return true. Here a real _sessions row + cookie carries a real csrf token, so
# we can prove BOTH that a valid token is accepted AND that a missing one is rejected,
# through the real dispatcher POST seam (Dispatcher::dispatch -> Rsx_Csrf::enforce).
#
# The CSRF check runs BEFORE route resolution, so the target endpoint need not exist: a
# reject renders the ajax error contract containing "CSRF token mismatch"; an accept lets
# the request proceed to (failed) dispatch, whose response never contains that string.
# Presence/absence of "CSRF token mismatch" is therefore the discriminator.
#
# Uses the dev default credentials (admin@test.com / admintest99). If a site overrides
# RSPADE_DEFAULT_EMAIL/RSPADE_DEFAULT_PASSWORD, adjust below.

LOGIN_EMAIL="admin@test.com"
LOGIN_PASSWORD="admintest99"
BASE="http://localhost"
PROBE="${BASE}/_ajax/Csrf_Test_Probe/noop"
MISMATCH="CSRF token mismatch"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

# ---------------------------------------------------------------------------
# Step 1: Log in on a fresh (session-less) request. The login POST carries no
# csrf token and no Origin header - the session-gated token is not required
# without a session, and a header-less POST passes the Origin gate - so this
# must succeed (302) and mint an authenticated session.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Session-less login POST succeeds without a csrf token..." >&2
login_status="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" \
    --data-urlencode "email=${LOGIN_EMAIL}" \
    --data-urlencode "password=${LOGIN_PASSWORD}" \
    --data-urlencode "__turnstile=inactive" \
    "${BASE}/login" 2>/dev/null)"
if [ "$login_status" != "302" ]; then
    echo "FAIL: $TEST_NAME - login returned $login_status (expected 302); csrf must not block the session-less login POST"
    exit 1
fi
echo "[TEST] 1. OK - logged in" >&2

# ---------------------------------------------------------------------------
# Step 2: Read the csrf token the authenticated page exposes as window.rsxapp.csrf.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Extract window.rsxapp.csrf from an authenticated page..." >&2
page="$(curl -s -b "$JAR" "${BASE}/dashboard" 2>/dev/null)"
token="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
if [ -z "$token" ]; then
    echo "FAIL: $TEST_NAME - could not read window.rsxapp.csrf from the authenticated page"
    exit 1
fi
echo "[TEST] 2. OK - csrf token present (${token:0:12}...)" >&2

# ---------------------------------------------------------------------------
# Step 3: A session-bearing POST with NO token is REJECTED (the reject body
# carries the ajax csrf-mismatch contract).
# ---------------------------------------------------------------------------
echo "[TEST] 3. Session POST without a token is rejected..." >&2
no_token_body="$(curl -s -b "$JAR" -X POST -H 'Content-Type: application/json' -d '{}' "$PROBE" 2>/dev/null || true)"
if ! printf '%s' "$no_token_body" | grep -q "$MISMATCH"; then
    echo "FAIL: $TEST_NAME - a token-less session POST was NOT rejected (expected '$MISMATCH'); got: $no_token_body"
    exit 1
fi
echo "[TEST] 3. OK - token-less POST rejected" >&2

# ---------------------------------------------------------------------------
# Step 4: The SAME POST with the valid token in the X-CSRF-Token header is
# ACCEPTED by the csrf seam (response no longer carries the mismatch contract).
# ---------------------------------------------------------------------------
echo "[TEST] 4. Session POST with a valid X-CSRF-Token header is accepted..." >&2
header_body="$(curl -s -b "$JAR" -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: ${token}" -d '{}' "$PROBE" 2>/dev/null || true)"
if printf '%s' "$header_body" | grep -q "$MISMATCH"; then
    echo "FAIL: $TEST_NAME - a valid X-CSRF-Token header was still rejected; got: $header_body"
    exit 1
fi
echo "[TEST] 4. OK - header-token POST passed the csrf seam" >&2

# ---------------------------------------------------------------------------
# Step 5: The valid token also works via the _csrf_token body field (native
# form transport).
# ---------------------------------------------------------------------------
echo "[TEST] 5. Session POST with a valid _csrf_token body field is accepted..." >&2
field_body="$(curl -s -b "$JAR" -X POST --data-urlencode "_csrf_token=${token}" "$PROBE" 2>/dev/null || true)"
if printf '%s' "$field_body" | grep -q "$MISMATCH"; then
    echo "FAIL: $TEST_NAME - a valid _csrf_token body field was still rejected; got: $field_body"
    exit 1
fi
echo "[TEST] 5. OK - body-field-token POST passed the csrf seam" >&2

# ---------------------------------------------------------------------------
# Step 6: THE REJECTION CONTRACT, over real HTTP, on BOTH channels.
#
# A rejection is an already-rendered response thrown inside an
# HttpResponseException. RSX dispatch runs INSIDE Laravel's exception rendering,
# so the throw re-enters the handler chain, where an unrecognised Throwable is
# rendered as SOME error surface (fatal screen outside development+app.debug,
# Playwright plain-text dump, an app handler) - each of which would turn the
# documented contract into an HTTP 500. Rsx_Exception_Handler short-circuits on
# HttpResponseException before the chain runs; these checks are the field-level
# proof, and they need no session: a foreign Origin rejects on layer 1.
#
# Portal channel: prefix mode serves the same handler at <prefix>/_ajax/...
# (config rsx.portal.prefix, default /_portal), which is the spelling a portal
# page's Ajax actually uses.
# ---------------------------------------------------------------------------
# The framework default and the template's config value. Override for a site that
# repointed rsx.portal.prefix (a portal DOMAIN deployment would use "" instead).
PORTAL_PREFIX="${PORTAL_PREFIX:-/_portal}"

# $1 = label, $2 = url, $3 = expected status, $4 = expected body substring, $5.. = extra curl args
assert_reject() {
    local label="$1"; local url="$2"; local want_status="$3"; local want_body="$4"; shift 4
    local out status body
    out="$(curl -s -w '\n%{http_code}' -X POST -H 'Origin: https://evil.example.com' "$@" -d '{}' "$url" 2>/dev/null || true)"
    status="$(printf '%s' "$out" | tail -1)"
    body="$(printf '%s' "$out" | sed '$d')"
    if [ "$status" != "$want_status" ]; then
        echo "FAIL: $TEST_NAME - $label returned HTTP $status (expected $want_status); body: $body"
        exit 1
    fi
    if ! printf '%s' "$body" | grep -q "$want_body"; then
        echo "FAIL: $TEST_NAME - $label body did not carry '$want_body'; got: $body"
        exit 1
    fi
}

echo "[TEST] 6. Staff /_ajax rejection returns the ajax error contract (200 + JSON)..." >&2
assert_reject "staff ajax reject" "${BASE}/_ajax/Csrf_Test_Probe/noop" "200" '"error_code":"unauthorized"'
echo "[TEST] 6. OK" >&2

echo "[TEST] 7. Portal ${PORTAL_PREFIX}/_ajax rejection returns the same contract..." >&2
assert_reject "portal ajax reject" "${BASE}${PORTAL_PREFIX}/_ajax/Csrf_Test_Probe/noop" "200" '"error_code":"unauthorized"'
echo "[TEST] 7. OK" >&2

echo "[TEST] 8. Native form POST rejection returns 419..." >&2
assert_reject "native form reject" "${BASE}/login" "419" "CSRF token mismatch"
echo "[TEST] 8. OK" >&2

echo "[TEST] 9. A Playwright-flagged rejection is NOT converted to a 500 dump..." >&2
assert_reject "playwright ajax reject" "${BASE}/_ajax/Csrf_Test_Probe/noop" "200" '"error_code":"unauthorized"' -H 'X-Playwright-Test: 1'
echo "[TEST] 9. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
