#!/bin/bash
set -e

TEST_NAME="Turnstile Live Verify"

# HTTP integration test - runs against the live web server. It is the web-branch
# counterpart to the CLI-only PHP tests (Turnstile_Validate_Test), which substitute the
# siteverify VERDICT through $force_verify_result_for_tests and therefore never touch a
# real dispatch, a real form, or Cloudflare.
#
# The feature is OFF on a stock install, and turning it on is an .env edit plus two keys,
# so this test SKIPS unless the environment has actually enabled it. That is deliberate:
# a test that silently rewrites .env to make itself runnable would leave the box in a
# state nobody asked for.
#
# To run it, set the documented dev recipe in .env (Cloudflare's published dummy keys,
# which work on any domain including localhost):
#
#     TURNSTILE_ENABLED=true
#     TURNSTILE_SITE_KEY=1x00000000000000000000AA
#     TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA
#
# WHAT IS ASSERTED, and why it is exactly this:
#
#   1. An enabled install REJECTS a login POST that carries no __turnstile field at all.
#   2. It also rejects one carrying the disabled-state sentinel 'inactive'.
#
# Both reject BEFORE any outbound call, so both are deterministic whatever secret is
# configured. A BOGUS token is not asserted on: Cloudflare's always-passes dummy secret
# accepts any token and the always-fails one rejects any token, so the verdict is a
# property of the .env, not of the framework. Step 3 instead probes siteverify directly
# with the configured secret - the one thing that genuinely needs the network - and
# reports the credential round trip without pinning a verdict.
#
# See: php artisan rsx:man turnstile

ENV_FILE="/var/www/html/.env"
BASE="http://localhost"
LOGIN_URL="${BASE}/login"
MISSING_MESSAGE="Please complete the verification challenge."
SITEVERIFY_URL="https://challenges.cloudflare.com/turnstile/v0/siteverify"

# ---------------------------------------------------------------------------
# Applicability: only run where Turnstile is actually switched on.
# ---------------------------------------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
    echo "SKIP: $TEST_NAME - no .env at $ENV_FILE"
    exit 0
fi

if ! grep -q '^TURNSTILE_ENABLED=true' "$ENV_FILE"; then
    echo "SKIP: $TEST_NAME - TURNSTILE_ENABLED is not true in .env (feature off; nothing to verify)"
    exit 0
fi

SECRET_KEY="$(grep -m1 '^TURNSTILE_SECRET_KEY=' "$ENV_FILE" | cut -d= -f2- | tr -d "\"' \r")"
if [ -z "$SECRET_KEY" ]; then
    echo "FAIL: $TEST_NAME - TURNSTILE_ENABLED=true but TURNSTILE_SECRET_KEY is empty (half-configured install)"
    exit 1
fi

# ---------------------------------------------------------------------------
# Step 1: A login POST with NO __turnstile field is rejected.
#
# The POST is made from a cookie-less jar so the csrf synchronizer token is not
# required (no session = no victim session to forge against), exactly as
# csrf_roundtrip.sh's first step does. The rejection flashes its message against
# the session it creates, so the message is read back on the following GET.
# ---------------------------------------------------------------------------
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

echo "[TEST] 1. Login POST with no __turnstile field is rejected..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" \
    --data-urlencode "email=nobody@example.com" \
    --data-urlencode "password=wrong-on-purpose" \
    "$LOGIN_URL" 2>/dev/null || true)"

if [ "$status" != "302" ]; then
    echo "FAIL: $TEST_NAME - field-less login POST returned HTTP $status (expected 302 back to /login)"
    exit 1
fi

page="$(curl -s -b "$JAR" -c "$JAR" "$LOGIN_URL" 2>/dev/null || true)"
if ! printf '%s' "$page" | grep -qF "$MISSING_MESSAGE"; then
    echo "FAIL: $TEST_NAME - the rejection message was not delivered to the page; expected '$MISSING_MESSAGE'"
    exit 1
fi
echo "[TEST] 1. OK - rejected, message delivered" >&2

# ---------------------------------------------------------------------------
# Step 2: The disabled-state sentinel is rejected while the feature is ENABLED.
# This is the stale-page case (rendered before the switch was flipped on).
# ---------------------------------------------------------------------------
JAR2="$(mktemp)"
trap 'rm -f "$JAR" "$JAR2"' EXIT

echo "[TEST] 2. Login POST carrying the 'inactive' sentinel is rejected..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR2" -b "$JAR2" \
    --data-urlencode "email=nobody@example.com" \
    --data-urlencode "password=wrong-on-purpose" \
    --data-urlencode "__turnstile=inactive" \
    "$LOGIN_URL" 2>/dev/null || true)"

if [ "$status" != "302" ]; then
    echo "FAIL: $TEST_NAME - sentinel login POST returned HTTP $status (expected 302 back to /login)"
    exit 1
fi

page="$(curl -s -b "$JAR2" -c "$JAR2" "$LOGIN_URL" 2>/dev/null || true)"
if ! printf '%s' "$page" | grep -qF "$MISSING_MESSAGE"; then
    echo "FAIL: $TEST_NAME - the sentinel POST was not rejected with '$MISSING_MESSAGE'"
    exit 1
fi
echo "[TEST] 2. OK - sentinel rejected while enabled" >&2

# ---------------------------------------------------------------------------
# Step 3: The configured secret reaches Cloudflare's siteverify endpoint.
#
# No verdict is asserted (see the header note); what is proven is that the
# credential is accepted as a credential and a JSON verdict comes back. A box
# with no outbound network reports that and moves on rather than failing - the
# framework contract is not what is unavailable.
# ---------------------------------------------------------------------------
echo "[TEST] 3. siteverify round trip with the configured secret..." >&2
verify_body="$(curl -s --data-urlencode "secret=${SECRET_KEY}" --data-urlencode "response=probe" \
    "$SITEVERIFY_URL" 2>/dev/null || true)"

if [ -z "$verify_body" ]; then
    echo "[TEST] 3. NOTE - siteverify unreachable from this host; round trip not verified" >&2
elif ! printf '%s' "$verify_body" | grep -q '"success"'; then
    echo "FAIL: $TEST_NAME - siteverify answered without a success field; got: $verify_body"
    exit 1
else
    echo "[TEST] 3. OK - siteverify answered: $verify_body" >&2
fi

echo "PASS: $TEST_NAME"
exit 0
