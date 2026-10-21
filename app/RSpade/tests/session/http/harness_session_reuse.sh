#!/bin/bash
set -e

TEST_NAME="Harness Session Reuse"

# HTTP integration test - runs against the live web server, in development mode.
#
# Needs a FRAMEWORK-DEVELOPMENT box (IS_FRAMEWORK_DEVELOPER=true): the /ssr-test probe it
# reads the audit session through is gated #[Auth('is_framework_developer')], and the
# signed dev-auth headers verify only against this box's development grant.
#
# Proves ONE HARNESS ROW PER ACCOUNT (Session::_use_harness_session(), rsx:man session):
#
#   1. a dev-auth sign-in lands on a TYPE_PLAYWRIGHT row, and its traffic records no audit
#      session
#   2. a second "run" (a fresh cookie jar, as every rsx:debug run is) is pointed at the SAME
#      row - nothing new is minted
#   3. once that row's sign-in is past rsx.sessions.staff.max_length_minutes it is not
#      reused: it is signed out as timed out, and the next run gets a new row
#   4. the X-Playwright-Test marker alone, with no signed dev-auth, mints an ordinary
#      TYPE_WEB row - a header nobody signed never makes a harness session

LOGIN_EMAIL="session-harness-http@rspade.test"
LOGIN_PASSWORD="session-http-test-password"
BASE="http://localhost"
PAGE="/ssr-test/session-get-audit-session-id"

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"

JAR_ONE="$(mktemp)"
JAR_TWO="$(mktemp)"
JAR_THREE="$(mktemp)"
JAR_PLAIN="$(mktemp)"
CREATED_ROWS=""
trap 'rm -f "$JAR_ONE" "$JAR_TWO" "$JAR_THREE" "$JAR_PLAIN"; for id in $CREATED_ROWS; do (cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE id = $id" >/dev/null 2>&1) || true; done' EXIT

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

row_of() {
    field "$(sql "SELECT id FROM _sessions WHERE session_token = '$1'")" id
}

# The signed dev-auth headers rsx:debug sends, for $PAGE and the seeded account.
mint_headers() {
    (cd "$PROJECT_DIR" && php artisan tinker --execute='
        $minted = \App\RSpade\Core\Debug\Dev_Auth_Token::mint("'"$PAGE"'", '"$LOGIN_ID"', false);
        echo "MINTED:" . ($minted === null ? "" : $minted["token"] . "|" . $minted["exp"]);
    ' 2>&1 | grep -oE 'MINTED:.*' | sed 's/^MINTED://')
}

harness_get() {
    # harness_get <jar> - one dev-auth navigation, as a fresh rsx:debug run makes it
    local minted token exp
    minted="$(mint_headers)"
    token="${minted%%|*}"
    exp="${minted##*|}"
    [ -n "$token" ] && [ -n "$exp" ] || fail "could not mint a dev-auth token (no development grant?)"
    curl -s -c "$1" -b "$1" \
        -H "X-Dev-Auth-User-Id: ${LOGIN_ID}" \
        -H "X-Dev-Auth-Exp: ${exp}" \
        -H "X-Dev-Auth-Token: ${token}" \
        -H "X-Playwright-Test: 1" \
        "${BASE}${PAGE}" 2>/dev/null
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
        $user->last_name = "Harness";
        $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $user->is_enabled = 1;
        $user->deleted_at = null;
        $user->save();

        echo "SEEDED:" . $login->id;
    ' 2>&1 | grep -oE 'SEEDED:[0-9]+' | grep -oE '[0-9]+$') || { echo "FAIL: $TEST_NAME - could not seed the account"; exit 1; }
}

echo "[SETUP] Preparing test..." >&2
LOGIN_ID="$(seed_account)"
[ -n "$LOGIN_ID" ] || fail "could not seed the account"

# A previous run's harness rows for this account would be reused; start from none.
(cd "$PROJECT_DIR" && php artisan db:query "DELETE s FROM _sessions s JOIN _user_sessions u ON u.session_id = s.id WHERE s.type_id = 2 AND u.login_user_id = ${LOGIN_ID}" >/dev/null 2>&1) || true

# ---------------------------------------------------------------------------
# 1. A dev-auth sign-in lands on a harness row and records no audit session.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Dev-auth signs in on a TYPE_PLAYWRIGHT row, no audit session..." >&2
body="$(harness_get "$JAR_ONE")"
token_one="$(token_in "$JAR_ONE")"
[ -n "$token_one" ] || fail "the dev-auth request set no rsx cookie"
ROW_ONE="$(row_of "$token_one")"
[ -n "$ROW_ONE" ] || fail "no row for the harness cookie"
CREATED_ROWS="$CREATED_ROWS $ROW_ONE"
row="$(sql "SELECT s.type_id, u.login_user_id FROM _sessions s JOIN _user_sessions u ON u.id = s.staff_user_session_id WHERE s.id = ${ROW_ONE}")"
[ "$(field "$row" type_id)" = "2" ] || fail "the harness row is not TYPE_PLAYWRIGHT: $row"
[ "$(field "$row" login_user_id)" = "$LOGIN_ID" ] || fail "the harness row is not signed in as the account: $row"
printf '%s' "$body" | grep -qE 'audit_session_id=$' || fail "harness traffic answered an audit session: $body"
[ "$(field "$(sql "SELECT COUNT(*) AS n FROM _audit_sessions WHERE session_id = ${ROW_ONE}")" n)" = "0" ] || fail "an audit session was recorded for the harness row"
echo "[TEST] 1. OK - harness row ${ROW_ONE}" >&2

# ---------------------------------------------------------------------------
# 2. A second run (a fresh jar) reuses the row.
# ---------------------------------------------------------------------------
echo "[TEST] 2. A second run is pointed at the same row..." >&2
rows_before="$(field "$(sql "SELECT COUNT(*) AS n FROM _sessions")" n)"
harness_get "$JAR_TWO" >/dev/null
token_two="$(token_in "$JAR_TWO")"
[ "$(row_of "$token_two")" = "$ROW_ONE" ] || fail "the second run was not given the harness row ${ROW_ONE}"
rows_after="$(field "$(sql "SELECT COUNT(*) AS n FROM _sessions")" n)"
[ "$rows_before" = "$rows_after" ] || fail "the second run minted a row ($rows_before -> $rows_after)"
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# 3. Past the sign-in limit the row is not reused: it is signed out and a new one minted.
# ---------------------------------------------------------------------------
echo "[TEST] 3. An expired harness sign-in is replaced..." >&2
US_ONE="$(field "$(sql "SELECT staff_user_session_id FROM _sessions WHERE id = ${ROW_ONE}")" staff_user_session_id)"
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE _user_sessions SET signed_in_at = NOW(3) - INTERVAL 400 DAY WHERE id = ${US_ONE}" >/dev/null 2>&1)
harness_get "$JAR_THREE" >/dev/null
ROW_THREE="$(row_of "$(token_in "$JAR_THREE")")"
[ -n "$ROW_THREE" ] || fail "the run after the expiry has no row"
CREATED_ROWS="$CREATED_ROWS $ROW_THREE"
[ "$ROW_THREE" != "$ROW_ONE" ] || fail "an expired harness row was reused"
[ "$(field "$(sql "SELECT type_id FROM _sessions WHERE id = ${ROW_THREE}")" type_id)" = "2" ] || fail "the replacement is not TYPE_PLAYWRIGHT"
[ "$(field "$(sql "SELECT sign_out_reason_id FROM _user_sessions WHERE id = ${US_ONE}")" sign_out_reason_id)" = "2" ] || fail "the expired sign-in was not signed out as timed out (length)"
echo "[TEST] 3. OK - new harness row ${ROW_THREE}" >&2

# ---------------------------------------------------------------------------
# 4. The unsigned marker alone is an ordinary browser.
# ---------------------------------------------------------------------------
echo "[TEST] 4. X-Playwright-Test alone mints a TYPE_WEB row..." >&2
curl -s -o /dev/null -c "$JAR_PLAIN" -H "X-Playwright-Test: 1" "${BASE}/ssr-test/session-get-session-id" 2>/dev/null
ROW_PLAIN="$(row_of "$(token_in "$JAR_PLAIN")")"
[ -n "$ROW_PLAIN" ] || fail "the probe minted no row"
CREATED_ROWS="$CREATED_ROWS $ROW_PLAIN"
[ "$(field "$(sql "SELECT type_id FROM _sessions WHERE id = ${ROW_PLAIN}")" type_id)" = "1" ] || fail "an unsigned marker made a harness row"
echo "[TEST] 4. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
