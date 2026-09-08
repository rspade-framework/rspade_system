#!/bin/bash
set -e

TEST_NAME="Dev Auth URL Fragment (rsx:debug signs the fragment-free URL)"

# HTTP integration test - drives the real rsx:debug harness against the live web
# server. Regression guard for the 2026-08-17 CR: a #fragment in the rsx:debug url
# argument was signed into the dev-auth HMAC, but a browser never transmits a
# fragment, so the verifier re-signed a different payload and the page rendered
# ANONYMOUS with a 200 status - silently reporting the login page as the route.
#
# The fix splits the fragment off before signing and hands the full URL (fragment
# included) to Playwright, so client-side tab selection still works.
#
# The URL is the framework control panel, so this test names no application screen and
# runs in any install.

cd /var/www/html/system

echo "[TEST] 1. rsx:debug /_sys --user=1 renders authenticated..." >&2
plain="$(php artisan rsx:debug "/_sys" --user=1 2>&1)"
if ! echo "$plain" | grep -q '"is_auth": true'; then
    echo "FAIL: $TEST_NAME - fragment-free URL did not render authenticated"
    echo "$plain" | head -n 20
    exit 1
fi
echo "[TEST] 1. OK" >&2

echo "[TEST] 2. rsx:debug '/_sys#foo=bar' --user=1 renders authenticated..." >&2
fragment="$(php artisan rsx:debug "/_sys#foo=bar" --user=1 2>&1)"
if ! echo "$fragment" | grep -q '"is_auth": true'; then
    echo "FAIL: $TEST_NAME - fragmented URL rendered anonymous (dev auth token rejected)"
    echo "$fragment" | head -n 20
    exit 1
fi
echo "[TEST] 2. OK" >&2

echo "[TEST] 3. the fragment still reaches the browser..." >&2
if ! echo "$fragment" | grep -q '/_sys#foo=bar'; then
    echo "FAIL: $TEST_NAME - the harness did not navigate to the fragmented URL"
    echo "$fragment" | head -n 5
    exit 1
fi
echo "[TEST] 3. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
