#!/bin/bash
set -e

TEST_NAME="Session Lifetime"

# HTTP integration test - runs against the live web server.
#
# Proves the session lifetime rules (rsx:man session, SESSION LIFETIME) with the shipped
# defaults - 30 days from sign-in, 30 days from the last page view, 2 hours of grace for
# requests that are not page navigations - by moving a real session's clocks back:
#
#   1. inside the grace window an Ajax call and a page sub-request still answer
#   2. a page NAVIGATION at the same moment signs the browser out, redirects to the
#      sign-in screen and says why
#   3. past the grace window an Ajax call signs out and answers session_expired
#   4. the page-view report is judged like a navigation - no grace
#   5. the page-view report stamps the session and last_seen on the account
#   6. an Ajax call does not advance the page-view stamp
#   7. rendering a page does

LOGIN_EMAIL="session-lifetime-http@rspade.test"
LOGIN_PASSWORD="session-http-test-password"
BASE="http://localhost"

# 30 days, the default for both limits, in minutes.
LIMIT=43200

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

sql() {
    (cd "$PROJECT_DIR" && php artisan db:query "$1" --json 2>/dev/null)
}

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

SESSION_ID=""
CSRF=""

# Sign in on a fresh browser, and learn the session row and the page's CSRF token.
sign_in() {
    rm -f "$JAR"
    local status page token
    status="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" \
        --data-urlencode "email=${LOGIN_EMAIL}" \
        --data-urlencode "password=${LOGIN_PASSWORD}" \
        --data-urlencode "__turnstile=inactive" \
        "${BASE}/login" 2>/dev/null)"
    [ "$status" = "302" ] || fail "login returned $status (expected 302)"
    page="$(curl -s -b "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null)"
    CSRF="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
    [ -n "$CSRF" ] || fail "could not read window.rsxapp.csrf from /dashboard"
    token="$(grep -w rsx "$JAR" | awk '{print $7}')"
    SESSION_ID="$(sql "SELECT id FROM _sessions WHERE session_token = '${token}'" | grep -oE '"id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
    [ -n "$SESSION_ID" ] || fail "could not find the session row"
}

ajax() {
    curl -s -b "$JAR" -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: ${CSRF}" -d '{}' "${BASE}/_ajax/$1" 2>/dev/null || true
}

# Move one of the session's clocks back by N minutes.
age() {
    (cd "$PROJECT_DIR" && php artisan db:query "UPDATE _sessions SET $1 = $1 - INTERVAL $2 MINUTE WHERE id = ${SESSION_ID}" >/dev/null 2>&1)
}

signed_in_user() {
    sql "SELECT login_user_id FROM _sessions WHERE id = ${SESSION_ID}"
}

# An expired session row is RETIRED: inactive, still recording whose it was, and pointing
# at the successor row the browser was moved to - which is signed out.
assert_retired() {
    local row successor
    row="$(sql "SELECT login_user_id, active, superseded_by_id FROM _sessions WHERE id = ${SESSION_ID}")"
    echo "$row" | grep -qE '"login_user_id": *[0-9]+' || fail "$1: the expired row no longer records whose it was: $row"
    echo "$row" | grep -qE '"active": *(0|false)' || fail "$1: the expired row is still active: $row"
    successor="$(echo "$row" | grep -oE '"superseded_by_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
    [ -n "$successor" ] || fail "$1: the expired row names no successor: $row"
    sql "SELECT login_user_id, active FROM _sessions WHERE id = ${successor}" | grep -qE '"login_user_id": *null' \
        || fail "$1: the successor row is still signed in"
}

# The test signs in as an account of its own, so it depends on no shipped credential.
seed_account() {
    (cd "$PROJECT_DIR" && php artisan tinker --execute='
        $site_id = 1;
        \App\RSpade\Core\Session\Session::set_temporary_site_id($site_id);

        $login = \App\RSpade\Core\Models\Login_User_Model::where("email", "'"$LOGIN_EMAIL"'")->first()
            ?? new \App\RSpade\Core\Models\Login_User_Model();
        $login->email = "'"$LOGIN_EMAIL"'";
        $login->password = \Illuminate\Support\Facades\Hash::make("'"$LOGIN_PASSWORD"'");
        $login->is_activated = true;
        $login->is_verified = true;
        $login->status_id = \App\RSpade\Core\Models\Login_User_Model::STATUS_ACTIVE;
        $login->save();

        $user = \App\RSpade\Core\Models\User_Model::without_site_scope(fn () =>
            \App\RSpade\Core\Models\User_Model::withTrashed()->where("login_user_id", $login->id)->where("site_id", $site_id)->first())
            ?? new \App\RSpade\Core\Models\User_Model();
        $user->login_user_id = $login->id;
        $user->site_id = $site_id;
        $user->email = "'"$LOGIN_EMAIL"'";
        $user->first_name = "Session";
        $user->last_name = "Http";
        $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $user->is_enabled = 1;
        $user->deleted_at = null;
        $user->save();

        echo "SEEDED";
    ' 2>&1 | grep -q SEEDED) || { echo "FAIL: $TEST_NAME - could not seed the test account"; exit 1; }
}

echo "[SETUP] Preparing test..." >&2
seed_account

# ---------------------------------------------------------------------------
# 1. One hour past the session length limit: inside the 2-hour grace.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Inside the grace window, Ajax and page sub-requests still answer..." >&2
sign_in
age login_at $((LIMIT + 60))
body="$(ajax Rsx_Timezone_Controller/get_settings)"
printf '%s' "$body" | grep -q '"_success":true' || fail "an Ajax call inside the grace window was refused: $body"
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Sec-Fetch-Dest: image' "${BASE}/dashboard" 2>/dev/null)"
[ "$status" = "200" ] || fail "a page sub-request inside the grace window returned $status (expected 200)"
signed_in_user | grep -qE '"login_user_id": *[0-9]+' || fail "the session was signed out inside the grace window"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# 2. A NAVIGATION at the same moment signs out, redirects, and says why.
# ---------------------------------------------------------------------------
echo "[TEST] 2. A page navigation past the limit signs out with a message..." >&2
headers="$(curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null)"
status="$(echo "$headers" | head -1 | awk '{print $2}')"
[ "$status" = "302" ] || fail "navigation past the limit returned $status (expected 302)"
echo "$headers" | grep -i '^Location:' | grep -q '/login' || fail "navigation past the limit did not redirect to /login: $(echo "$headers" | grep -i '^Location:')"
assert_retired "after the navigation"
login_page="$(curl -s -b "$JAR" -c "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/login" 2>/dev/null)"
printf '%s' "$login_page" | grep -q 'Your session has expired' || fail "the sign-in screen does not carry the expiry message"
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# 3. Three hours past the idle limit: past the grace. Ajax signs out.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Past the grace window an Ajax call answers session_expired..." >&2
sign_in
age last_page_view_at $((LIMIT + 180))
body="$(ajax Rsx_Timezone_Controller/get_settings)"
printf '%s' "$body" | grep -q '"error_code":"session_expired"' || fail "expected the session_expired envelope, got: $body"
printf '%s' "$body" | grep -q '"cause":"idle"' || fail "expected cause idle, got: $body"
assert_retired "after the Ajax refusal"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# 4. The page-view report gets no grace.
# ---------------------------------------------------------------------------
echo "[TEST] 4. The page-view report is judged like a navigation..." >&2
sign_in
age login_at $((LIMIT + 60))
body="$(ajax Rsx_Session_Controller/page_view)"
printf '%s' "$body" | grep -q 'session_expired' || fail "the page-view report inside the grace window was not refused: $body"
assert_retired "after the page-view refusal"
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# 5. A page-view report stamps the session and the account.
# ---------------------------------------------------------------------------
echo "[TEST] 5. The page-view report stamps the session and last_seen..." >&2
sign_in
age last_page_view_at 5
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE login_users l JOIN _sessions s ON s.login_user_id = l.id SET l.last_seen = NULL WHERE s.id = ${SESSION_ID}" >/dev/null 2>&1)
before="$(sql "SELECT last_page_view_at FROM _sessions WHERE id = ${SESSION_ID}")"
body="$(ajax Rsx_Session_Controller/page_view)"
printf '%s' "$body" | grep -q '"_success":true' || fail "the page-view report was refused: $body"
after="$(sql "SELECT last_page_view_at FROM _sessions WHERE id = ${SESSION_ID}")"
[ "$before" != "$after" ] || fail "the page-view report did not advance last_page_view_at"
sql "SELECT l.last_seen FROM login_users l JOIN _sessions s ON s.login_user_id = l.id WHERE s.id = ${SESSION_ID}" | grep -qE '"last_seen": *"' || fail "last_seen was not stamped on the account"
echo "[TEST] 5. OK" >&2

# ---------------------------------------------------------------------------
# 6. An Ajax call is not a page view.
# ---------------------------------------------------------------------------
echo "[TEST] 6. An Ajax call does not advance the page-view stamp..." >&2
age last_page_view_at 5
before="$(sql "SELECT last_page_view_at FROM _sessions WHERE id = ${SESSION_ID}")"
body="$(ajax Rsx_Timezone_Controller/get_settings)"
printf '%s' "$body" | grep -q '"_success":true' || fail "Ajax call refused: $body"
after="$(sql "SELECT last_page_view_at FROM _sessions WHERE id = ${SESSION_ID}")"
[ "$before" = "$after" ] || fail "an Ajax call advanced last_page_view_at"
echo "[TEST] 6. OK" >&2

# ---------------------------------------------------------------------------
# 7. Rendering a page is.
# ---------------------------------------------------------------------------
echo "[TEST] 7. Rendering a page advances the page-view stamp..." >&2
curl -s -o /dev/null -b "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null
after="$(sql "SELECT last_page_view_at FROM _sessions WHERE id = ${SESSION_ID}")"
[ "$before" != "$after" ] || fail "rendering a page did not advance last_page_view_at"
echo "[TEST] 7. OK" >&2

(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE id = ${SESSION_ID}" >/dev/null 2>&1)

echo "PASS: $TEST_NAME"
exit 0
