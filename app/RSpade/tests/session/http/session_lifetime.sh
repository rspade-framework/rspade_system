#!/bin/bash
set -e

TEST_NAME="Session Lifetime"

# HTTP integration test - runs against the live web server.
#
# Proves the session lifetime rules (rsx:man session, SESSION LIFETIME) with the shipped
# defaults - 7 days from sign-in, 8 hours from the last page view, 2 hours of grace for
# requests that are not page navigations - by moving a real session's clocks back:
#
#   1. inside the grace window an Ajax call and a page sub-request still answer
#   2. a page NAVIGATION at the same moment signs the browser out, redirects to the
#      sign-in screen and says why
#   3. past the grace window an Ajax call signs out and answers session_expired
#   4. the page-view report is judged like a navigation - no grace
#   5. the page-view report stamps the user session and last_seen on the account
#   6. an Ajax call does not advance the page-view stamp
#   7. rendering a page does
#   8. last_active (the browser) is written at most once a minute
#   9. a browser unused for rsx.sessions.unused_session_days (30) is made inactive at
#      lookup, and the request carrying its cookie is a stranger
#
# The clocks live on the USER SESSION the browser's staff pointer names; an ended sign-in
# is that user session stamped signed out, the pointer cleared, the browser row kept.

LOGIN_EMAIL="session-lifetime-http@rspade.test"
LOGIN_PASSWORD="session-http-test-password"
BASE="http://localhost"

# The shipped defaults, in minutes: rsx.sessions.staff.max_length_minutes (7 days) and
# rsx.sessions.staff.max_idle_minutes (8 hours).
LENGTH_LIMIT=10080
IDLE_LIMIT=480

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
USER_SESSION_ID=""
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
    USER_SESSION_ID="$(sql "SELECT staff_user_session_id FROM _sessions WHERE id = ${SESSION_ID}" | grep -oE '"staff_user_session_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
    [ -n "$USER_SESSION_ID" ] || fail "the session row points at no staff user session"
}

ajax() {
    curl -s -b "$JAR" -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: ${CSRF}" -d '{}' "${BASE}/_ajax/$1" 2>/dev/null || true
}

# Move one of the user session's clocks back by N minutes.
age() {
    (cd "$PROJECT_DIR" && php artisan db:query "UPDATE _user_sessions SET $1 = $1 - INTERVAL $2 MINUTE WHERE id = ${USER_SESSION_ID}" >/dev/null 2>&1)
}

signed_in_user() {
    sql "SELECT staff_user_session_id FROM _sessions WHERE id = ${SESSION_ID}"
}

# An expired sign-in is its USER SESSION stamped signed out with the reason (2 length,
# 3 idle), the browser's pointer cleared, and the browser row kept, still active.
assert_signed_out() {
    local row user_session
    row="$(sql "SELECT active, staff_user_session_id FROM _sessions WHERE id = ${SESSION_ID}")"
    echo "$row" | grep -qE '"active": *(1|true)' || fail "$1: the browser row was deactivated: $row"
    echo "$row" | grep -qE '"staff_user_session_id": *null' || fail "$1: the browser still points at the expired sign-in: $row"
    user_session="$(sql "SELECT signed_out_at, sign_out_reason_id FROM _user_sessions WHERE id = ${USER_SESSION_ID}")"
    echo "$user_session" | grep -qE '"signed_out_at": *null' && fail "$1: the user session was not stamped signed out: $user_session"
    echo "$user_session" | grep -qE "\"sign_out_reason_id\": *$2\b" || fail "$1: expected sign-out reason $2: $user_session"
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
age signed_in_at $((LENGTH_LIMIT + 60))
body="$(ajax Rsx_Timezone_Controller/get_settings)"
printf '%s' "$body" | grep -q '"_success":true' || fail "an Ajax call inside the grace window was refused: $body"
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Sec-Fetch-Dest: image' "${BASE}/dashboard" 2>/dev/null)"
[ "$status" = "200" ] || fail "a page sub-request inside the grace window returned $status (expected 200)"
signed_in_user | grep -qE '"staff_user_session_id": *[0-9]+' || fail "the session was signed out inside the grace window"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# 2. A NAVIGATION at the same moment signs out, redirects, and says why.
# ---------------------------------------------------------------------------
echo "[TEST] 2. A page navigation past the limit signs out with a message..." >&2
headers="$(curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null)"
status="$(echo "$headers" | head -1 | awk '{print $2}')"
[ "$status" = "302" ] || fail "navigation past the limit returned $status (expected 302)"
echo "$headers" | grep -i '^Location:' | grep -q '/login' || fail "navigation past the limit did not redirect to /login: $(echo "$headers" | grep -i '^Location:')"
assert_signed_out "after the navigation" 2
login_page="$(curl -s -b "$JAR" -c "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/login" 2>/dev/null)"
printf '%s' "$login_page" | grep -q 'Your session has expired' || fail "the sign-in screen does not carry the expiry message"
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# 3. Three hours past the idle limit: past the grace. Ajax signs out.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Past the grace window an Ajax call answers session_expired..." >&2
sign_in
age last_page_view_at $((IDLE_LIMIT + 180))
body="$(ajax Rsx_Timezone_Controller/get_settings)"
printf '%s' "$body" | grep -q '"error_code":"session_expired"' || fail "expected the session_expired envelope, got: $body"
printf '%s' "$body" | grep -q '"cause":"idle"' || fail "expected cause idle, got: $body"
assert_signed_out "after the Ajax refusal" 3
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# 4. The page-view report gets no grace.
# ---------------------------------------------------------------------------
echo "[TEST] 4. The page-view report is judged like a navigation..." >&2
sign_in
age signed_in_at $((LENGTH_LIMIT + 60))
body="$(ajax Rsx_Session_Controller/page_view)"
printf '%s' "$body" | grep -q 'session_expired' || fail "the page-view report inside the grace window was not refused: $body"
assert_signed_out "after the page-view refusal" 2
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# 5. A page-view report stamps the session and the account.
# ---------------------------------------------------------------------------
echo "[TEST] 5. The page-view report stamps the session and last_seen..." >&2
sign_in
age last_page_view_at 5
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE login_users l JOIN _user_sessions u ON u.login_user_id = l.id SET l.last_seen = NULL WHERE u.id = ${USER_SESSION_ID}" >/dev/null 2>&1)
before="$(sql "SELECT last_page_view_at FROM _user_sessions WHERE id = ${USER_SESSION_ID}")"
body="$(ajax Rsx_Session_Controller/page_view)"
printf '%s' "$body" | grep -q '"_success":true' || fail "the page-view report was refused: $body"
after="$(sql "SELECT last_page_view_at FROM _user_sessions WHERE id = ${USER_SESSION_ID}")"
[ "$before" != "$after" ] || fail "the page-view report did not advance last_page_view_at"
sql "SELECT l.last_seen FROM login_users l JOIN _user_sessions u ON u.login_user_id = l.id WHERE u.id = ${USER_SESSION_ID}" | grep -qE '"last_seen": *"' || fail "last_seen was not stamped on the account"
echo "[TEST] 5. OK" >&2

# ---------------------------------------------------------------------------
# 6. An Ajax call is not a page view.
# ---------------------------------------------------------------------------
echo "[TEST] 6. An Ajax call does not advance the page-view stamp..." >&2
age last_page_view_at 5
before="$(sql "SELECT last_page_view_at FROM _user_sessions WHERE id = ${USER_SESSION_ID}")"
body="$(ajax Rsx_Timezone_Controller/get_settings)"
printf '%s' "$body" | grep -q '"_success":true' || fail "Ajax call refused: $body"
after="$(sql "SELECT last_page_view_at FROM _user_sessions WHERE id = ${USER_SESSION_ID}")"
[ "$before" = "$after" ] || fail "an Ajax call advanced last_page_view_at"
echo "[TEST] 6. OK" >&2

# ---------------------------------------------------------------------------
# 7. Rendering a page is.
# ---------------------------------------------------------------------------
echo "[TEST] 7. Rendering a page advances the page-view stamp..." >&2
curl -s -o /dev/null -b "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null
after="$(sql "SELECT last_page_view_at FROM _user_sessions WHERE id = ${USER_SESSION_ID}")"
[ "$before" != "$after" ] || fail "rendering a page did not advance last_page_view_at"
echo "[TEST] 7. OK" >&2

# ---------------------------------------------------------------------------
# 8. last_active is written at most once a minute.
# ---------------------------------------------------------------------------
echo "[TEST] 8. last_active is written at most once a minute..." >&2
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE _sessions SET last_active = NOW(3) - INTERVAL 20 SECOND WHERE id = ${SESSION_ID}" >/dev/null 2>&1)
before="$(sql "SELECT last_active FROM _sessions WHERE id = ${SESSION_ID}")"
ajax Rsx_Timezone_Controller/get_settings >/dev/null
after="$(sql "SELECT last_active FROM _sessions WHERE id = ${SESSION_ID}")"
[ "$before" = "$after" ] || fail "last_active was rewritten within the minute"
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE _sessions SET last_active = NOW(3) - INTERVAL 90 SECOND WHERE id = ${SESSION_ID}" >/dev/null 2>&1)
before="$(sql "SELECT last_active FROM _sessions WHERE id = ${SESSION_ID}")"
ajax Rsx_Timezone_Controller/get_settings >/dev/null
after="$(sql "SELECT last_active FROM _sessions WHERE id = ${SESSION_ID}")"
[ "$before" != "$after" ] || fail "last_active was not advanced after a minute"
echo "[TEST] 8. OK" >&2

# ---------------------------------------------------------------------------
# 9. A browser unused for 30 days is retired at lookup.
# ---------------------------------------------------------------------------
echo "[TEST] 9. A browser unused for 30 days is made inactive and is a stranger..." >&2
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE _sessions SET last_active = NOW(3) - INTERVAL 31 DAY WHERE id = ${SESSION_ID}" >/dev/null 2>&1)
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null)"
[ "$status" = "302" ] || fail "a 31-day-old browser was still signed in ($status)"
sql "SELECT active FROM _sessions WHERE id = ${SESSION_ID}" | grep -qE '"active": *(0|false)' || fail "the unused browser row was not made inactive"
echo "[TEST] 9. OK" >&2

(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE id = ${SESSION_ID}" >/dev/null 2>&1)

echo "PASS: $TEST_NAME"
exit 0
