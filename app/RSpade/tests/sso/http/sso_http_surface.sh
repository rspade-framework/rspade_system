#!/bin/bash
set -e

TEST_NAME="SSO HTTP Surface"

# HTTP integration test - runs against the live web server (dev DB), because everything under
# test here is decided by the DISPATCHER and the CSRF seam, neither of which exists when a
# controller method is called as a static function.
#
# WHY THIS EXISTS ALONGSIDE THE PHP TIER. Sso_Controller_Test drives the same methods
# directly, which is what happens AFTER a gate has passed and AFTER Rsx_Csrf::enforce() has
# allowed the request - so it can assert what each surface DECIDES but never that the
# decisions are ENFORCED. Two of them are only real over HTTP:
#
#   THE APPLE EXEMPTION IS PATH-EXACT. Rsx_Csrf::enforce() exempts exactly one path -
#   Rsx_Sso::APPLE_CALLBACK_PATH - because Sign in with Apple returns its authorization as a
#   cross-site form POST that arrives with Origin: https://appleid.apple.com and WITHOUT the
#   SameSite=Lax cookie. An exemption that leaked to the sibling paths would turn every other
#   provider's callback into an unauthenticated POST endpoint. Steps 3 and 4 are the proof
#   that it does not, and they are written as a matched PAIR on purpose: the same cross-site
#   POST, one path apart, with opposite outcomes.
#
#   THE GATES. #[Auth('is_logged_in')] on the settings endpoints is evaluated by the
#   dispatcher and nowhere else. Getting it backwards would make the connected-accounts
#   surface anonymous, which is silent.
#
# NOTHING IS WRITTEN. No provider is enabled on this box (the shipped config ships every one
# switched off), so every ceremony URL 404s and no ceremony is ever started. That is also why
# the begin-redirect SHAPE is pinned in the php tier instead, through the custom-provider seam
# and a fake provider - a bash test cannot enable a provider in the server's config, and
# committing an enabled one to make a test pass would be a live misconfiguration.
#
# No CSRF token is needed anywhere below: every POST here is session-less, and a session-less
# POST is not token-gated (see tests/csrf/http/csrf_roundtrip.sh, whose login step relies on
# the same rule).

BASE="http://localhost"
AJAX="${BASE}/_ajax/Rsx_Sso_Controller"

# ---------------------------------------------------------------------------
# Test 1: an UNKNOWN provider key is a 404.
# ---------------------------------------------------------------------------
echo "[TEST] 1. An unknown provider key is refused..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/_sso/nonesuch/begin" 2>/dev/null)"
if [ "$status" != "404" ]; then
    echo "FAIL: $TEST_NAME - /_sso/nonesuch/begin returned $status (expected 404)"
    exit 1
fi
echo "[TEST] 1. OK - 404" >&2

# ---------------------------------------------------------------------------
# Test 2: a DISABLED provider key is the SAME 404, never a 500 and never a
# message distinguishing it. "google is configured here but switched off" is a
# fact about the install a stranger has no reason to collect.
# ---------------------------------------------------------------------------
echo "[TEST] 2. A configured-but-disabled provider key is the same refusal..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/_sso/google/begin" 2>/dev/null)"
if [ "$status" != "404" ]; then
    echo "FAIL: $TEST_NAME - /_sso/google/begin returned $status (expected 404 - a disabled provider must not be distinguishable, and must not 500)"
    exit 1
fi
echo "[TEST] 2. OK - indistinguishable from unknown" >&2

# ---------------------------------------------------------------------------
# Test 3: THE APPLE EXEMPTION. A cross-site POST to /_sso/apple/callback passes
# the CSRF origin check and 303s to the same path as a GET, carrying the
# ceremony on the query string. This is the leg Rsx_Csrf's exemption docblock
# promises does no work.
# ---------------------------------------------------------------------------
echo "[TEST] 3. A cross-site POST to the Apple callback is exempt and 303s to the GET leg..." >&2
out="$(curl -s -i -X POST -H 'Origin: https://appleid.apple.com' \
    --data-urlencode 'code=APPLE-CODE' \
    --data-urlencode 'state=APPLE-STATE' \
    --data-urlencode 'user={"name":{"firstName":"A"}}' \
    "${BASE}/_sso/apple/callback" 2>/dev/null)"

if ! printf '%s' "$out" | head -1 | grep -q '303'; then
    echo "FAIL: $TEST_NAME - the Apple callback POST did not answer 303; the CSRF exemption is not in force. Got: $(printf '%s' "$out" | head -1)"
    exit 1
fi
location="$(printf '%s' "$out" | grep -i '^location:' | tr -d '\r' | head -1)"
if ! printf '%s' "$location" | grep -q '/_sso/apple/callback?'; then
    echo "FAIL: $TEST_NAME - the POST leg did not redirect to its own path as a GET; got: $location"
    exit 1
fi
if ! printf '%s' "$location" | grep -q 'code=APPLE-CODE'; then
    echo "FAIL: $TEST_NAME - the redirect did not carry the code; got: $location"
    exit 1
fi
if ! printf '%s' "$location" | grep -q 'state=APPLE-STATE'; then
    echo "FAIL: $TEST_NAME - the redirect did not carry the state; got: $location"
    exit 1
fi
if ! printf '%s' "$location" | grep -q 'user='; then
    echo "FAIL: $TEST_NAME - the redirect dropped Apple's one-shot user blob; got: $location"
    exit 1
fi
echo "[TEST] 3. OK - exempt, 303, ceremony carried" >&2

# ---------------------------------------------------------------------------
# Test 4: THE OTHER HALF OF THE PAIR. The IDENTICAL cross-site POST, one path
# over, is still rejected. The exemption is path-exact or it is a hole.
# ---------------------------------------------------------------------------
echo "[TEST] 4. The same cross-site POST to another provider's callback is still rejected..." >&2
for path in "/_sso/google/callback" "/_sso/apple/callbackx" "/_sso/apple/begin"; do
    out="$(curl -s -w '\n%{http_code}' -X POST -H 'Origin: https://appleid.apple.com' \
        --data-urlencode 'code=APPLE-CODE' \
        --data-urlencode 'state=APPLE-STATE' \
        "${BASE}${path}" 2>/dev/null || true)"
    status="$(printf '%s' "$out" | tail -1)"
    body="$(printf '%s' "$out" | sed '$d')"

    if [ "$status" = "303" ]; then
        echo "FAIL: $TEST_NAME - ${path} answered 303; the Apple CSRF exemption is NOT path-exact"
        exit 1
    fi
    if ! printf '%s' "$body" | grep -q "CSRF token mismatch"; then
        echo "FAIL: $TEST_NAME - a cross-site POST to ${path} was not rejected by the CSRF origin check (HTTP $status); got: $body"
        exit 1
    fi
done
echo "[TEST] 4. OK - path-exact" >&2

# ---------------------------------------------------------------------------
# Test 5: the settings endpoints refuse a logged-out caller AT THE GATE.
#
# The facade's own refusals must never be what answers here: reaching them would
# mean the endpoint is dispatchable while logged out, which is the failure this
# step exists to catch.
# ---------------------------------------------------------------------------
echo "[TEST] 5. The settings endpoints refuse an anonymous caller..." >&2
for method in identities_list identity_unlink link_begin; do
    response="$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' "${AJAX}/${method}" 2>/dev/null)"

    if printf '%s' "$response" | grep -q '"_success":true'; then
        echo "FAIL: $TEST_NAME - anonymous ${method} SUCCEEDED; the is_logged_in gate is not enforced: $response"
        exit 1
    fi
    if ! printf '%s' "$response" | grep -q '"error_code":"unauthorized"'; then
        echo "FAIL: $TEST_NAME - anonymous ${method} failed for the wrong reason: $response"
        exit 1
    fi
    if printf '%s' "$response" | grep -qi 'no login identity behind the is_logged_in gate'; then
        echo "FAIL: $TEST_NAME - ${method} reached its own body while logged out; the gate did not run: $response"
        exit 1
    fi
done
echo "[TEST] 5. OK - refused at the gate" >&2

echo "PASS: $TEST_NAME"
exit 0
