#!/bin/bash
set -e

TEST_NAME="Portal Site Mismatch Sign-Out"

# HTTP integration test - runs against the live web server (development mode, plain http to
# port 80, so the session cookie is the unprefixed `rsx`).
#
# THE URL IS THE AUTHORITY for a portal request's site (Portal_Site_Resolver). When the
# browser's portal sign-in belongs to a different site than the one the URL resolves to,
# the framework signs that sign-in out (Session::SIGN_OUT_SITE_SWITCHED) and the request
# continues SIGNED OUT - a redirect to the portal's sign-in page, never a 500.
#
#   1. rsx:debug signs a portal user of the served site in on its harness row
#   2. CONTROL: that row's cookie opens the portal dashboard (200, still signed in)
#   3. the row's portal user session is moved to another site (site 0) in the database -
#      the shape a sign-in for another tenant has on a path-mode install
#   4. the same cookie on the same URL: 302 to the sign-in page; the user session is signed
#      out with reason 9 (SIGN_OUT_SITE_SWITCHED) and the browser's portal pointer is cleared
#
# The served site is whatever the box resolves for /_portal/ (site 1 on a single-tenant
# install with a blank PORTAL_URL); the test refuses to run on any other layout.

BASE="http://localhost"
SIGN_OUT_SITE_SWITCHED=9

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"

sql() {
    (cd "$PROJECT_DIR" && php artisan db:query "$1" --json 2>/dev/null)
}

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

field() {
    # field <json> <name> - the first integer value of "name" in a db:query --json answer
    echo "$1" | grep -oE "\"$2\": *[0-9]+" | head -1 | grep -oE '[0-9]+$' || true
}

PORTAL_USER_ID="$(field "$(sql "SELECT id FROM portal_users WHERE site_id = 1 AND status_id = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")" id)"
[ -n "$PORTAL_USER_ID" ] || fail "no active portal user on site 1 to sign in as"

# 1. A signed-in harness row for that portal user.
(cd "$PROJECT_DIR" && php artisan rsx:debug /_portal/ --portal --portal-user="$PORTAL_USER_ID" >/dev/null 2>&1) \
    || fail "rsx:debug could not open /_portal/ as portal user $PORTAL_USER_ID"

ROW="$(sql "SELECT s.id, s.portal_user_session_id FROM _sessions s JOIN _user_sessions u ON u.id = s.portal_user_session_id WHERE s.type_id = 2 AND u.portal_user_id = $PORTAL_USER_ID AND u.signed_out_at IS NULL ORDER BY s.id DESC LIMIT 1")"
SESSION_ID="$(field "$ROW" id)"
USER_SESSION_ID="$(field "$ROW" portal_user_session_id)"
[ -n "$SESSION_ID" ] && [ -n "$USER_SESSION_ID" ] || fail "no signed-in harness row for portal user $PORTAL_USER_ID"

TOKEN="$(sql "SELECT session_token FROM _sessions WHERE id = $SESSION_ID" | grep -oE '"session_token": *"[a-f0-9]+"' | grep -oE '[a-f0-9]{32,}')"
[ -n "$TOKEN" ] || fail "could not read the harness row's token"

# 2. Control: the cookie is a signed-in portal browser.
CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "rsx=$TOKEN" "${BASE}/_portal/")"
[ "$CODE" = "200" ] || fail "control: the signed-in cookie should open /_portal/ (200), got $CODE"

# 3. The sign-in now belongs to another site.
sql "UPDATE _user_sessions SET portal_site_id = 0 WHERE id = $USER_SESSION_ID" >/dev/null

# 4. The URL wins: signed out, and the request continues signed out.
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "rsx=$TOKEN" "${BASE}/_portal/")"
case "$OUT" in
    "302 "*"/_portal/login"*) ;;
    *) fail "a sign-in for another site should be ended and the request sent to sign in (302 to /_portal/login), got: $OUT" ;;
esac

AFTER="$(sql "SELECT sign_out_reason_id FROM _user_sessions WHERE id = $USER_SESSION_ID AND signed_out_at IS NOT NULL")"
[ "$(field "$AFTER" sign_out_reason_id)" = "$SIGN_OUT_SITE_SWITCHED" ] \
    || fail "user session $USER_SESSION_ID should be signed out with SIGN_OUT_SITE_SWITCHED ($SIGN_OUT_SITE_SWITCHED): $AFTER"

POINTER="$(sql "SELECT id FROM _sessions WHERE id = $SESSION_ID AND portal_user_session_id IS NULL")"
[ "$(field "$POINTER" id)" = "$SESSION_ID" ] || fail "the browser's portal pointer should be cleared"

echo "PASS: $TEST_NAME"
