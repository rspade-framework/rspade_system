#!/bin/bash
set -e

TEST_NAME="Session Sign-In Rotation"

# HTTP integration test - runs against the live web server.
#
# Needs a FRAMEWORK-DEVELOPMENT box (IS_FRAMEWORK_DEVELOPER=true): the /ssr-test probe it
# mints a session with is gated #[Auth('is_framework_developer')]. It talks plain http to
# port 80, so the cookie is the development-only unprefixed `rsx` (Rsx_Session_Cookie).
#
# Proves ONE SESSION PER BROWSER with tokens rotated IN PLACE (rsx:man session):
#
#   1. a guest browser gets a row; a copy of its cookie is taken
#   2. signing in as A keeps the SAME row, gives it a new token (exactly one cookie in the
#      response), and points it at a signed-in user session for A
#   3. the copied (pre-sign-in) token is the one the rotation REPLACED, so an Ajax call or a
#      sub-resource on it was in flight during the sign-in: it is REFUSED - a sub-resource
#      401, an Ajax call the session_expired envelope with cause 'rotated' - with NO
#      Set-Cookie and no row minted, so it can never overwrite the cookie the sign-in just
#      gave the browser. A PAGE NAVIGATION on it is a guest (never the browser's row): a
#      browser that missed the new cookie recovers on its next page load
#   4. signing out stamps A's user session signed out, clears the pointer and the browser's
#      STAFF values (its guest value stays), and rotates the token again; the pre-sign-in
#      token, now two rotations old, is
#      an ordinary stranger (a signed-in page redirects it to sign-in, never the browser's row)
#   5. signing in as B on the same browser: a second user session, same row
#   6. signing in as A again REUSES A's user session (sign_in_count 2), and B's is signed out
#      as replaced; still one row, a new token every time

EMAIL_A="session-rotation-http-a@rspade.test"
EMAIL_B="session-rotation-http-b@rspade.test"
LOGIN_PASSWORD="session-http-test-password"
BASE="http://localhost"

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"

JAR="$(mktemp)"
STOLEN="$(mktemp)"
trap 'rm -f "$JAR" "$STOLEN"' EXIT

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

token_in() {
    grep -w rsx "$1" | awk '{print $7}'
}

csrf_of_page() {
    curl -s -b "$JAR" -c "$JAR" "${BASE}$1" 2>/dev/null | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true
}

login() {
    local csrf
    csrf="$(csrf_of_page /login)"
    curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" \
        --data-urlencode "email=$1" \
        --data-urlencode "password=${LOGIN_PASSWORD}" \
        --data-urlencode "__turnstile=inactive" \
        --data-urlencode "_csrf_token=${csrf}" \
        "${BASE}/login" 2>/dev/null
}

session_row_of() {
    sql "SELECT id, active, staff_user_session_id FROM _sessions WHERE session_token = '$1'"
}

# The test signs in as accounts of its own, so it depends on no shipped credential.
seed_account() {
    (cd "$PROJECT_DIR" && php artisan tinker --execute='
        $site_id = 1;
        \App\RSpade\Core\Session\Session::set_temporary_site_id($site_id);

        $login = \App\RSpade\Core\Models\Login_User_Model::where("email", "'"$1"'")->first()
            ?? new \App\RSpade\Core\Models\Login_User_Model();
        $login->email = "'"$1"'";
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
        $user->email = "'"$1"'";
        $user->first_name = "Session";
        $user->last_name = "Http";
        $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $user->is_enabled = 1;
        $user->deleted_at = null;
        $user->save();

        echo "SEEDED:" . $login->id;
    ' 2>&1 | grep -oE 'SEEDED:[0-9]+' | grep -oE '[0-9]+$') || { echo "FAIL: $TEST_NAME - could not seed $1"; exit 1; }
}

echo "[SETUP] Preparing test..." >&2
LOGIN_A="$(seed_account "$EMAIL_A")"
LOGIN_B="$(seed_account "$EMAIL_B")"
[ -n "$LOGIN_A" ] && [ -n "$LOGIN_B" ] || fail "could not seed the two accounts"

# ---------------------------------------------------------------------------
# 1. A guest browser row, and a COPY of its cookie - the token somebody else holds.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Guest session minted; its cookie copied..." >&2
curl -s -o /dev/null -c "$JAR" "${BASE}/ssr-test/session-get-session-id" 2>/dev/null
token_guest="$(token_in "$JAR")"
[ -n "$token_guest" ] || fail "no rsx cookie on session creation"
cp "$JAR" "$STOLEN"
BROWSER_ID="$(field "$(session_row_of "$token_guest")" id)"
[ -n "$BROWSER_ID" ] || fail "no row for the guest token"
# One value in the staff realm (1) and one in the guest realm (4): a staff sign-out clears
# the first and keeps the second.
(cd "$PROJECT_DIR" && php artisan db:query "INSERT INTO _session_values (session_id, realm_id, value_key, value) VALUES (${BROWSER_ID}, 1, 'rotation_test', '1'), (${BROWSER_ID}, 4, 'rotation_test', '1')" >/dev/null 2>&1)
echo "[TEST] 1. OK - browser row ${BROWSER_ID}" >&2

# ---------------------------------------------------------------------------
# 2. Signing in rotates the token on the SAME row and points it at A's user session.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Sign-in as A rotates in place..." >&2
headers="$(login "$EMAIL_A")"
[ "$(echo "$headers" | head -1 | awk '{print $2}')" = "302" ] || fail "login as A did not redirect: $(echo "$headers" | head -1)"
[ "$(echo "$headers" | grep -ci '^Set-Cookie: rsx=' || true)" = "1" ] || fail "the sign-in response did not carry exactly one rsx cookie"
token_a="$(token_in "$JAR")"
[ -n "$token_a" ] && [ "$token_a" != "$token_guest" ] || fail "the token did not change across sign-in"
row="$(session_row_of "$token_a")"
[ "$(field "$row" id)" = "$BROWSER_ID" ] || fail "sign-in moved the browser to another row: $row"
US_A="$(field "$row" staff_user_session_id)"
[ -n "$US_A" ] || fail "the browser does not point at a user session: $row"
us="$(sql "SELECT login_user_id, sign_in_count, signed_out_at FROM _user_sessions WHERE id = ${US_A}")"
[ "$(field "$us" login_user_id)" = "$LOGIN_A" ] || fail "the user session is not A's: $us"
[ "$(field "$us" sign_in_count)" = "1" ] || fail "A's first sign-in count is not 1: $us"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "${BASE}/dashboard" 2>/dev/null)" = "200" ] || fail "/dashboard on A's token is not 200"
echo "[TEST] 2. OK - user session ${US_A}" >&2

# ---------------------------------------------------------------------------
# 3. The copied token is the one the rotation replaced: refused, no cookie, nothing minted.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Ajax and sub-resources on the just-replaced token are refused..." >&2
rows_before="$(field "$(sql "SELECT COUNT(*) AS n FROM _sessions")" n)"
stolen_headers="$(curl -s -D - -o /dev/null -b "$STOLEN" -H 'Sec-Fetch-Dest: image' "${BASE}/dashboard" 2>/dev/null)"
stolen_status="$(echo "$stolen_headers" | head -1 | awk '{print $2}')"
[ "$stolen_status" = "401" ] || fail "a sub-resource on the just-replaced token returned $stolen_status (expected the 401 refusal)"
if echo "$stolen_headers" | grep -qi '^Set-Cookie: rsx='; then
    fail "the refusal carried a session cookie, which would overwrite the signed-in one"
fi
stolen_ajax_headers="$(curl -s -D - -o /tmp/session_rotation_ajax_body.txt -b "$STOLEN" -X POST -H 'Content-Type: application/json' -d '{}' "${BASE}/_ajax/Rsx_Timezone_Controller/get_settings" 2>/dev/null || true)"
stolen_ajax="$(cat /tmp/session_rotation_ajax_body.txt)"
rm -f /tmp/session_rotation_ajax_body.txt
printf '%s' "$stolen_ajax" | grep -q '"error_code":"session_expired"' || fail "an Ajax call on the just-replaced token was not answered session_expired: $stolen_ajax"
printf '%s' "$stolen_ajax" | grep -q '"cause":"rotated"' || fail "the Ajax refusal does not name cause rotated: $stolen_ajax"
if echo "$stolen_ajax_headers" | grep -qi '^Set-Cookie: rsx='; then
    fail "the Ajax refusal carried a session cookie"
fi
rows_after="$(field "$(sql "SELECT COUNT(*) AS n FROM _sessions")" n)"
[ "$rows_before" = "$rows_after" ] || fail "the refused requests minted a session row ($rows_before -> $rows_after)"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "${BASE}/dashboard" 2>/dev/null)" = "200" ] || fail "A's own cookie stopped working after the refused requests"
echo "[TEST] 3. A page navigation on the just-replaced token is a guest..." >&2
stolen_headers="$(curl -s -D - -o /dev/null -b "$STOLEN" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null)"
stolen_status="$(echo "$stolen_headers" | head -1 | awk '{print $2}')"
[ "$stolen_status" = "302" ] || fail "a navigation on the just-replaced token returned $stolen_status (expected the guest's 302 to sign-in)"
stolen_new="$(echo "$stolen_headers" | grep -i '^Set-Cookie: rsx=' | head -1 | sed -E 's/^[^=]*=([a-f0-9]+).*/\1/' || true)"
if [ -n "$stolen_new" ]; then
    [ "$(field "$(session_row_of "$stolen_new")" id)" != "$BROWSER_ID" ] || fail "the replaced token was handed the browser's row"
    (cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE session_token = '${stolen_new}'" >/dev/null 2>&1)
fi
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "${BASE}/dashboard" 2>/dev/null)" = "200" ] || fail "A's own cookie stopped working after the guest navigation"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# 4. Sign-out: A's user session stamped, pointer cleared, values cleared, token rotated.
# ---------------------------------------------------------------------------
echo "[TEST] 4. Sign-out stamps, clears and rotates..." >&2
curl -s -o /dev/null -b "$JAR" -c "$JAR" "${BASE}/logout" 2>/dev/null
token_out="$(token_in "$JAR")"
[ -n "$token_out" ] && [ "$token_out" != "$token_a" ] || fail "the token did not rotate at sign-out"
row="$(session_row_of "$token_out")"
[ "$(field "$row" id)" = "$BROWSER_ID" ] || fail "sign-out moved the browser to another row: $row"
echo "$row" | grep -qE '"staff_user_session_id": *null' || fail "the pointer survived sign-out: $row"
us="$(sql "SELECT signed_out_at, sign_out_reason_id FROM _user_sessions WHERE id = ${US_A}")"
echo "$us" | grep -qE '"signed_out_at": *null' && fail "A's user session was not stamped signed out: $us"
[ "$(field "$us" sign_out_reason_id)" = "1" ] || fail "A's sign-out reason is not SIGN_OUT_SIGNED_OUT: $us"
[ "$(field "$(sql "SELECT COUNT(*) AS n FROM _session_values WHERE session_id = ${BROWSER_ID} AND realm_id = 1")" n)" = "0" ] || fail "the browser's staff value survived the sign-out"
[ "$(field "$(sql "SELECT COUNT(*) AS n FROM _session_values WHERE session_id = ${BROWSER_ID} AND realm_id = 4")" n)" = "1" ] || fail "the browser's guest value did not survive the sign-out"
# Two rotations on, the pre-sign-in token is nobody's: an ordinary stranger.
stolen_headers="$(curl -s -D - -o /dev/null -b "$STOLEN" -H 'Sec-Fetch-Dest: document' "${BASE}/dashboard" 2>/dev/null)"
stolen_status="$(echo "$stolen_headers" | head -1 | awk '{print $2}')"
[ "$stolen_status" = "302" ] || fail "/dashboard on a two-rotations-old token returned $stolen_status (expected the 302 to sign-in)"
stolen_new="$(echo "$stolen_headers" | grep -i '^Set-Cookie: rsx=' | head -1 | sed -E 's/^[^=]*=([a-f0-9]+).*/\1/' || true)"
if [ -n "$stolen_new" ]; then
    [ "$(field "$(session_row_of "$stolen_new")" id)" != "$BROWSER_ID" ] || fail "the old token was handed the browser's row"
    (cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE session_token = '${stolen_new}'" >/dev/null 2>&1)
fi
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# 5. B signs in on the same browser: a second user session, the same row.
# ---------------------------------------------------------------------------
echo "[TEST] 5. Sign-in as B on the same browser..." >&2
login "$EMAIL_B" >/dev/null
token_b="$(token_in "$JAR")"
[ "$token_b" != "$token_out" ] || fail "the token did not rotate at B's sign-in"
row="$(session_row_of "$token_b")"
[ "$(field "$row" id)" = "$BROWSER_ID" ] || fail "B's sign-in moved the browser to another row: $row"
US_B="$(field "$row" staff_user_session_id)"
[ -n "$US_B" ] && [ "$US_B" != "$US_A" ] || fail "B did not get a user session of its own: $row"
echo "[TEST] 5. OK - user session ${US_B}" >&2

# ---------------------------------------------------------------------------
# 6. A again (without signing B out first): A's user session is REUSED, B's replaced.
# ---------------------------------------------------------------------------
echo "[TEST] 6. Sign-in as A again reuses A's user session..." >&2
login "$EMAIL_A" >/dev/null
token_a2="$(token_in "$JAR")"
[ "$token_a2" != "$token_b" ] || fail "the token did not rotate at A's second sign-in"
row="$(session_row_of "$token_a2")"
[ "$(field "$row" id)" = "$BROWSER_ID" ] || fail "A's second sign-in moved the browser to another row: $row"
[ "$(field "$row" staff_user_session_id)" = "$US_A" ] || fail "A's second sign-in did not reuse user session ${US_A}: $row"
us="$(sql "SELECT sign_in_count, signed_out_at FROM _user_sessions WHERE id = ${US_A}")"
[ "$(field "$us" sign_in_count)" = "2" ] || fail "A's sign-in count is not 2: $us"
echo "$us" | grep -qE '"signed_out_at": *null' || fail "A's reused user session is still stamped signed out: $us"
us_b="$(sql "SELECT sign_out_reason_id FROM _user_sessions WHERE id = ${US_B}")"
[ "$(field "$us_b" sign_out_reason_id)" = "8" ] || fail "B's user session was not signed out as replaced: $us_b"
[ "$(field "$(sql "SELECT COUNT(*) AS n FROM _user_sessions WHERE session_id = ${BROWSER_ID}")" n)" = "2" ] || fail "the browser does not hold exactly two user sessions"
echo "[TEST] 6. OK" >&2

(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE id = ${BROWSER_ID}" >/dev/null 2>&1)

echo "PASS: $TEST_NAME"
exit 0
