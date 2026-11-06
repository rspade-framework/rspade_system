#!/bin/bash
set -e

TEST_NAME="Portal Dev Auth With A Query String (rsx:debug --portal signs path + query)"

# HTTP integration test - drives the real rsx:debug harness against the live web server.
#
# The dev-auth credential signs the request URI: path plus query, no fragment, and for the
# portal realm with the portal's prefix removed (Dev_Auth_Token). The portal verifier must
# check the same string the minter signed - the query included - or a portal URL carrying
# a query string renders ANONYMOUS (the portal's sign-in page) with a 200 status.
#
#   1. CONTROL: rsx:debug /_portal/ --portal renders signed in
#   2. rsx:debug '/_portal/?rsx_probe=1' --portal renders signed in, at that URL
#
# The served site is whatever the box resolves for /_portal/ (site 1 on a single-tenant
# install with a blank PORTAL_URL), and the portal root is the only URL named, so the test
# names no application screen.

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

PORTAL_USER_ID="$(cd "$PROJECT_DIR" && php artisan db:query "SELECT id FROM portal_users WHERE site_id = 1 AND status_id = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1" --json 2>/dev/null \
    | grep -oE '"id": *[0-9]+' | head -1 | grep -oE '[0-9]+$' || true)"
[ -n "$PORTAL_USER_ID" ] || fail "no active portal user on site 1 to sign in as"

echo "[TEST] 1. rsx:debug /_portal/ --portal renders signed in..." >&2
plain="$(cd "$PROJECT_DIR" && php artisan rsx:debug /_portal/ --portal --portal-user="$PORTAL_USER_ID" \
    --eval="return 'portal-user=' + (window.rsxapp.is_auth ? 'yes' : 'no')" 2>&1)"
echo "$plain" | grep -q 'portal-user=yes' || { echo "$plain" | head -n 20; fail "the query-free URL did not render signed in"; }
echo "[TEST] 1. OK" >&2

echo "[TEST] 2. rsx:debug '/_portal/?rsx_probe=1' --portal renders signed in..." >&2
query="$(cd "$PROJECT_DIR" && php artisan rsx:debug '/_portal/?rsx_probe=1' --portal --portal-user="$PORTAL_USER_ID" \
    --eval="return 'portal-user=' + (window.rsxapp.is_auth ? 'yes' : 'no') + ' at ' + location.pathname + location.search" 2>&1)"
echo "$query" | grep -q 'portal-user=yes at /_portal/?rsx_probe=1' \
    || { echo "$query" | head -n 20; fail "the URL with a query string rendered signed out (the dev-auth credential was rejected)"; }
echo "[TEST] 2. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
