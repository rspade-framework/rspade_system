#!/bin/bash
set -e

TEST_NAME="Session Sign-In Rotation"

# HTTP integration test - runs against the live web server.
#
# Needs a FRAMEWORK-DEVELOPMENT box (IS_FRAMEWORK_DEVELOPER=true): the /ssr-test probe it
# mints a session with is gated #[Auth('is_framework_developer')]. It talks plain http to
# port 80, so the cookie is the development-only unprefixed `rsx` (Rsx_Session_Cookie).
#
# Proves SIGN-IN ROTATION (rsx:man session): a sign-in moves the browser to a NEW session
# row with a new token, and the row it arrived with is superseded - stripped of every
# identity, never re-cookied, and never able to authenticate again. That is what makes a
# copied or planted cookie worthless across a sign-in. The browser's rows are tied
# together by one device record.

LOGIN_EMAIL="session-rotation-http@rspade.test"
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

token_in() {
    grep -w rsx "$1" | awk '{print $7}'
}

login() {
    local page csrf
    page="$(curl -s -b "$JAR" -c "$JAR" "${BASE}/login" 2>/dev/null)"
    csrf="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
    curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" \
        --data-urlencode "email=${LOGIN_EMAIL}" \
        --data-urlencode "password=${LOGIN_PASSWORD}" \
        --data-urlencode "__turnstile=inactive" \
        --data-urlencode "_csrf_token=${csrf}" \
        "${BASE}/login" 2>/dev/null
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
# 1. An anonymous browser session, and a COPY of its cookie - the token somebody
#    else holds.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Anonymous session minted; its cookie copied..." >&2
curl -s -o /dev/null -c "$JAR" "${BASE}/ssr-test/session-get-session-id" 2>/dev/null
token_before="$(token_in "$JAR")"
if [ -z "$token_before" ]; then
    echo "FAIL: $TEST_NAME - no rsx cookie on session creation"
    exit 1
fi
cp "$JAR" "$STOLEN"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# 2. Signing in gives the browser a DIFFERENT token.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Sign-in moves the browser to a new token..." >&2
login_headers="$(login)"
login_status="$(echo "$login_headers" | head -1 | awk '{print $2}')"
if [ "$login_status" != "302" ]; then
    echo "FAIL: $TEST_NAME - login returned $login_status (expected 302)"
    exit 1
fi
token_after="$(token_in "$JAR")"
if [ -z "$token_after" ] || [ "$token_before" = "$token_after" ]; then
    echo "FAIL: $TEST_NAME - the session token did not change across sign-in (before=$token_before after=$token_after)"
    exit 1
fi
cookie_count="$(echo "$login_headers" | grep -ci '^Set-Cookie: rsx=' || true)"
if [ "$cookie_count" != "1" ]; then
    echo "FAIL: $TEST_NAME - the sign-in response carried $cookie_count rsx cookies (expected exactly 1)"
    exit 1
fi
echo "[TEST] 2. OK - new token issued, exactly one session cookie in the response" >&2

# ---------------------------------------------------------------------------
# 3. The new token is signed in; the old one is not, and is never re-cookied.
# ---------------------------------------------------------------------------
echo "[TEST] 3. New token authenticates; the copied one does not..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "${BASE}/dashboard" 2>/dev/null)"
if [ "$status" != "200" ]; then
    echo "FAIL: $TEST_NAME - /dashboard on the new token returned $status (expected 200)"
    exit 1
fi
stolen_headers="$(curl -s -D - -o /dev/null -b "$STOLEN" "${BASE}/dashboard" 2>/dev/null)"
stolen_status="$(echo "$stolen_headers" | head -1 | awk '{print $2}')"
if [ "$stolen_status" != "302" ]; then
    echo "FAIL: $TEST_NAME - /dashboard on the superseded token returned $stolen_status (expected the 302 to login)"
    exit 1
fi
if echo "$stolen_headers" | grep -qi '^Set-Cookie: rsx='; then
    echo "FAIL: $TEST_NAME - a request on the superseded token was sent a session cookie"
    exit 1
fi
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# 4. The rows: the old one is superseded and identity-free, the new one carries the
#    identity, its sign-in time and a device; both name the same device.
# ---------------------------------------------------------------------------
echo "[TEST] 4. Row state..." >&2
old_row="$(sql "SELECT login_user_id, superseded_by_id, device_id, active FROM _sessions WHERE session_token = '${token_before}'")"
new_row="$(sql "SELECT id, login_user_id, login_at, last_page_view_at, device_id, superseded_by_id FROM _sessions WHERE session_token = '${token_after}'")"
old_device="$(echo "$old_row" | grep -oE '"device_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
new_device="$(echo "$new_row" | grep -oE '"device_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
new_id="$(echo "$new_row" | grep -oE '"id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
if ! echo "$old_row" | grep -qE '"login_user_id": *null'; then
    echo "FAIL: $TEST_NAME - the superseded row still carries an identity: $old_row"
    exit 1
fi
if ! echo "$old_row" | grep -qE "\"superseded_by_id\": *${new_id}\b"; then
    echo "FAIL: $TEST_NAME - the old row does not point at its successor ($new_id): $old_row"
    exit 1
fi
if echo "$new_row" | grep -qE '"login_at": *null' || echo "$new_row" | grep -qE '"last_page_view_at": *null'; then
    echo "FAIL: $TEST_NAME - the new row has no sign-in clocks: $new_row"
    exit 1
fi
if [ -z "$new_device" ] || [ "$old_device" != "$new_device" ]; then
    echo "FAIL: $TEST_NAME - the two rows do not share a device (old=$old_device new=$new_device)"
    exit 1
fi
echo "[TEST] 4. OK - device $new_device ties the rows together" >&2

# ---------------------------------------------------------------------------
# 5. Sign out and in again: another new token, the SAME device.
# ---------------------------------------------------------------------------
echo "[TEST] 5. A later sign-in on this browser keeps its device..." >&2
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE _sessions SET login_user_id = NULL, login_at = NULL, last_page_view_at = NULL WHERE id = ${new_id}" >/dev/null 2>&1)
login >/dev/null
token_third="$(token_in "$JAR")"
if [ "$token_third" = "$token_after" ]; then
    echo "FAIL: $TEST_NAME - signing in again did not issue a new token"
    exit 1
fi
third_device="$(sql "SELECT device_id FROM _sessions WHERE session_token = '${token_third}'" | grep -oE '"device_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
if [ "$third_device" != "$new_device" ]; then
    echo "FAIL: $TEST_NAME - the second sign-in landed on device $third_device (expected $new_device)"
    exit 1
fi
echo "[TEST] 5. OK" >&2

(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE device_id = ${new_device}" >/dev/null 2>&1)

echo "PASS: $TEST_NAME"
exit 0
