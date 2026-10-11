#!/bin/bash
set -e

TEST_NAME="Audit Session Over HTTP"

# HTTP integration test - runs against the live web server.
#
# Needs a FRAMEWORK-DEVELOPMENT box (IS_FRAMEWORK_DEVELOPER=true): the /ssr-test probe is
# gated #[Auth('is_framework_developer')].
#
# Proves what only a real request can about Session::get_audit_session_id() (the CLI half
# is Audit_Session_Test):
#
#   1. a caller with no cookie gets an audit session - address and user agent recorded,
#      no session row - and is given no session cookie for asking
#   2. the same context answers the same id
#   3. a different user agent is a different context, stored as its own _user_agents row
#   4. a signed-in browser's audit session names its identity, tenant, session row and
#      device

LOGIN_EMAIL="session-audit-http@rspade.test"
LOGIN_PASSWORD="session-http-test-password"
BASE="http://localhost"
PROBE="${BASE}/ssr-test/session-get-audit-session-id"

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

audit_id() {
    printf '%s' "$1" | grep -oE 'audit_session_id=[0-9]+' | grep -oE '[0-9]+$' || true
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
# 1. No cookie: attributable, and no session is created for asking.
# ---------------------------------------------------------------------------
echo "[TEST] 1. A cookie-less caller gets an audit session and no session cookie..." >&2
headers="$(mktemp)"
body="$(curl -s -D "$headers" -A 'audit-session-http/one' "$PROBE" 2>/dev/null)"
anon_id="$(audit_id "$body")"
[ -n "$anon_id" ] || fail "the probe returned no audit session id: $body"
if grep -qi '^Set-Cookie: rsx=' "$headers"; then
    rm -f "$headers"
    fail "asking for the audit session set a session cookie"
fi
rm -f "$headers"
row="$(sql "SELECT a.session_row_id, a.login_user_id, a.ip_address, u.user_agent, a.session_type_id FROM _audit_sessions a LEFT JOIN _user_agents u ON u.id = a.user_agent_id WHERE a.id = ${anon_id}")"
echo "$row" | grep -qE '"session_row_id": *null' || fail "a cookie-less context recorded a session row: $row"
echo "$row" | grep -qE '"login_user_id": *null' || fail "a cookie-less context recorded an identity: $row"
echo "$row" | grep -qE '"ip_address": *"[0-9a-f.:]+"' || fail "no client address recorded: $row"
echo "$row" | grep -q 'audit-session-http.\{1,2\}one' || fail "the user agent was not recorded: $row"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# 2. Same context, same id.  3. Another agent, another context.
# ---------------------------------------------------------------------------
echo "[TEST] 2. The same context answers the same id..." >&2
again="$(audit_id "$(curl -s -A 'audit-session-http/one' "$PROBE" 2>/dev/null)")"
[ "$again" = "$anon_id" ] || fail "the same context answered $again, then $anon_id"
echo "[TEST] 2. OK" >&2

echo "[TEST] 3. A different user agent is a different context..." >&2
other="$(audit_id "$(curl -s -A 'audit-session-http/two' "$PROBE" 2>/dev/null)")"
[ -n "$other" ] && [ "$other" != "$anon_id" ] || fail "a different user agent answered the same audit session ($other)"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# 4. Signed in: identity, tenant, session row and device.
# ---------------------------------------------------------------------------
echo "[TEST] 4. A signed-in browser's audit session names who and which session..." >&2
curl -s -o /dev/null -c "$JAR" "${BASE}/ssr-test/session-get-session-id" 2>/dev/null
page="$(curl -s -b "$JAR" -c "$JAR" "${BASE}/login" 2>/dev/null)"
csrf="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
    --data-urlencode "email=${LOGIN_EMAIL}" \
    --data-urlencode "password=${LOGIN_PASSWORD}" \
    --data-urlencode "__turnstile=inactive" \
    --data-urlencode "_csrf_token=${csrf}" \
    "${BASE}/login" 2>/dev/null)"
[ "$status" = "302" ] || fail "login returned $status (expected 302)"
token="$(grep -w rsx "$JAR" | awk '{print $7}')"
session="$(sql "SELECT id, login_user_id, device_id FROM _sessions WHERE session_token = '${token}'")"
session_id="$(echo "$session" | grep -oE '"id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
login_user_id="$(echo "$session" | grep -oE '"login_user_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
device_id="$(echo "$session" | grep -oE '"device_id": *[0-9]+' | grep -oE '[0-9]+$' || true)"
[ -n "$session_id" ] && [ -n "$login_user_id" ] && [ -n "$device_id" ] || fail "could not read the signed-in session row: $session"

signed_id="$(audit_id "$(curl -s -b "$JAR" -A 'audit-session-http/one' "$PROBE" 2>/dev/null)")"
[ -n "$signed_id" ] && [ "$signed_id" != "$anon_id" ] || fail "signing in did not change the audit session ($signed_id)"
row="$(sql "SELECT session_row_id, login_user_id, device_id, site_id FROM _audit_sessions WHERE id = ${signed_id}")"
echo "$row" | grep -qE "\"session_row_id\": *${session_id}\b" || fail "the audit session does not name session row ${session_id}: $row"
echo "$row" | grep -qE "\"login_user_id\": *${login_user_id}\b" || fail "the audit session does not name the identity: $row"
echo "$row" | grep -qE "\"device_id\": *${device_id}\b" || fail "the audit session does not name the device: $row"
echo "[TEST] 4. OK" >&2

(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE device_id = ${device_id}" >/dev/null 2>&1)

echo "PASS: $TEST_NAME"
exit 0
