#!/bin/bash
set -e

TEST_NAME="Sign-In Records And Their Timezones Over HTTP"

# HTTP integration test - runs against the live web server.
#
# Needs a FRAMEWORK-DEVELOPMENT box (IS_FRAMEWORK_DEVELOPER=true): the /ssr-test probe is
# gated #[Auth('is_framework_developer')].
#
# Proves, through the template's real sign-in page, what a command-line test cannot drive:
#
#   1. an automatic identity signing in from a browser that reports a zone records that zone,
#      source 'browser', on its login history row (the identity) AND on a site sign-in row
#      (the session entering its one site, entry 'sign_in')
#   2. the browser's audit session carries the same zone
#   3. the same sign-in with a changed rsx_tz cookie is a NEW audit session, in the new zone
#   4. an identity with an explicit zone records it, source 'explicit', whatever the browser
#      reports
#   5. with two memberships the sign-in has no site and records no site sign-in; choosing
#      a site records 'sign_in' there, switching to the other records 'site_switch'
#   6. an impersonation begun from the /_sys panel records 'impersonation' on the target's
#      membership, naming the impersonator

LOGIN_EMAIL="sign-in-timezones-http@rspade.test"
TARGET_EMAIL="sign-in-timezones-target@rspade.test"
SECOND_SITE_SLUG="sign-in-timezones-second-site"
LOGIN_PASSWORD="session-http-test-password"
BASE="http://localhost"
PROBE="${BASE}/ssr-test/session-get-audit-session-id"

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"

JAR="$(mktemp)"
SECOND_JAR="$(mktemp)"
THIRD_JAR="$(mktemp)"
trap 'rm -f "$JAR" "$SECOND_JAR" "$THIRD_JAR"' EXIT

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

# The browser's zone, as Rsx_Timezone_Cookie.js writes it, into a cookie jar.
set_browser_zone() {
    sed -i '/\trsx_tz\t/d' "$1"
    printf 'localhost\tFALSE\t/\tFALSE\t0\trsx_tz\t%s\n' "$2" >> "$1"
}

# The test signs in as an account of its own, on one site, so the sign-in binds that site.
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
        $login->timezone = null;
        $login->save();

        $user = \App\RSpade\Core\Models\User_Model::without_site_scope(fn () =>
            \App\RSpade\Core\Models\User_Model::withTrashed()->where("login_user_id", $login->id)->where("site_id", $site_id)->first())
            ?? new \App\RSpade\Core\Models\User_Model();
        $user->login_user_id = $login->id;
        $user->site_id = $site_id;
        $user->email = "'"$LOGIN_EMAIL"'";
        $user->first_name = "Sign In";
        $user->last_name = "Timezones";
        $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $user->is_enabled = 1;
        $user->deleted_at = null;
        $user->save();

        $login->is_developer = false;
        $login->save();

        // A second site and a membership on it, DISABLED until section 5 enables it, so the
        // first sign-ins have one usable site and bind it.
        $second = \App\RSpade\Core\Models\Site_Model::where("slug", "'"$SECOND_SITE_SLUG"'")->first()
            ?? new \App\RSpade\Core\Models\Site_Model();
        $second->slug = "'"$SECOND_SITE_SLUG"'";
        $second->name = "Sign-In Timezones Second Site";
        $second->is_enabled = 1;
        $second->save();

        $other = \App\RSpade\Core\Models\User_Model::without_site_scope(fn () =>
            \App\RSpade\Core\Models\User_Model::withTrashed()->where("login_user_id", $login->id)->where("site_id", $second->id)->first())
            ?? new \App\RSpade\Core\Models\User_Model();
        $other->login_user_id = $login->id;
        $other->site_id = $second->id;
        $other->email = "'"$LOGIN_EMAIL"'";
        $other->first_name = "Sign In";
        $other->last_name = "Timezones";
        $other->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $other->is_enabled = 0;
        $other->deleted_at = null;
        \App\RSpade\Core\Session\Session::set_temporary_site_id($second->id);
        $other->save();
        \App\RSpade\Core\Session\Session::set_temporary_site_id($site_id);

        // The identity section 6 impersonates: one membership, on site 1.
        $target = \App\RSpade\Core\Models\Login_User_Model::where("email", "'"$TARGET_EMAIL"'")->first()
            ?? new \App\RSpade\Core\Models\Login_User_Model();
        $target->email = "'"$TARGET_EMAIL"'";
        $target->password = \Illuminate\Support\Facades\Hash::make("'"$LOGIN_PASSWORD"'");
        $target->is_activated = true;
        $target->is_verified = true;
        $target->status_id = \App\RSpade\Core\Models\Login_User_Model::STATUS_ACTIVE;
        $target->timezone = null;
        $target->save();

        $target_user = \App\RSpade\Core\Models\User_Model::without_site_scope(fn () =>
            \App\RSpade\Core\Models\User_Model::withTrashed()->where("login_user_id", $target->id)->where("site_id", $site_id)->first())
            ?? new \App\RSpade\Core\Models\User_Model();
        $target_user->login_user_id = $target->id;
        $target_user->site_id = $site_id;
        $target_user->email = "'"$TARGET_EMAIL"'";
        $target_user->first_name = "Impersonation";
        $target_user->last_name = "Target";
        $target_user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $target_user->is_enabled = 1;
        $target_user->deleted_at = null;
        $target_user->save();

        echo "SEEDED " . $login->id . " " . $user->id . " " . $second->id . " " . $other->id . " " . $target->id . " " . $target_user->id;
    ' 2>&1 | grep -oE 'SEEDED [0-9 ]+') || { echo "FAIL: $TEST_NAME - could not seed the test account"; exit 1; }
}

# Sign in through the template's login page in the browser whose jar is $1.
sign_in() {
    local jar="$1"
    local page csrf status
    page="$(curl -s -b "$jar" -c "$jar" "${BASE}/login" 2>/dev/null)"
    csrf="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
    status="$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" \
        --data-urlencode "email=${LOGIN_EMAIL}" \
        --data-urlencode "password=${LOGIN_PASSWORD}" \
        --data-urlencode "__turnstile=inactive" \
        --data-urlencode "_csrf_token=${csrf}" \
        "${BASE}/login" 2>/dev/null)"
    [ "$status" = "302" ] || fail "login returned $status (expected 302)"
}

echo "[SETUP] Preparing test..." >&2
seeded="$(seed_account)"
login_user_id="$(echo "$seeded" | awk '{print $2}')"
user_id="$(echo "$seeded" | awk '{print $3}')"
second_site_id="$(echo "$seeded" | awk '{print $4}')"
second_user_id="$(echo "$seeded" | awk '{print $5}')"
target_login_user_id="$(echo "$seeded" | awk '{print $6}')"
target_user_id="$(echo "$seeded" | awk '{print $7}')"
(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _site_sign_ins WHERE login_user_id = ${target_login_user_id}" >/dev/null 2>&1)
(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _site_sign_ins WHERE login_user_id = ${login_user_id}" >/dev/null 2>&1)
(cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _login_history WHERE login_user_id = ${login_user_id}" >/dev/null 2>&1)

# ---------------------------------------------------------------------------
# 1. An automatic identity: the browser's zone on both sign-in records.
# ---------------------------------------------------------------------------
echo "[TEST] 1. The identity sign-in and the site sign-in record the browser's zone..." >&2
set_browser_zone "$JAR" "Asia/Kolkata"
sign_in "$JAR"

history="$(sql "SELECT timezone, timezone_source FROM _login_history WHERE login_user_id = ${login_user_id} AND status = 'success' ORDER BY id DESC LIMIT 1")"
echo "$history" | grep -qE '"timezone": *"Asia\\?/Kolkata"' || fail "the login history row does not carry the browser's zone: $history"
echo "$history" | grep -qE '"timezone_source": *"browser"' || fail "the login history row's zone is not from the browser: $history"

site="$(sql "SELECT site_id, user_id, entry_type, impersonator_login_user_id, timezone, timezone_source FROM _site_sign_ins WHERE login_user_id = ${login_user_id} ORDER BY id DESC")"
[ "$(echo "$site" | grep -cE '"entry_type"')" = "1" ] || fail "expected exactly one site sign-in row: $site"
echo "$site" | grep -qE '"site_id": *1\b' || fail "the site sign-in does not name site 1: $site"
echo "$site" | grep -qE "\"user_id\": *${user_id}\b" || fail "the site sign-in does not name membership ${user_id}: $site"
echo "$site" | grep -qE '"entry_type": *"sign_in"' || fail "the site sign-in is not a sign_in entry: $site"
echo "$site" | grep -qE '"impersonator_login_user_id": *null' || fail "a sign-in recorded an impersonator: $site"
echo "$site" | grep -qE '"timezone": *"Asia\\?/Kolkata"' || fail "the site sign-in does not carry the browser's zone: $site"
echo "$site" | grep -qE '"timezone_source": *"browser"' || fail "the site sign-in's zone is not from the browser: $site"
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# 2. The audit session carries it.  3. A changed zone is a new audit session.
# ---------------------------------------------------------------------------
echo "[TEST] 2. The audit session carries the browser's zone..." >&2
first="$(audit_id "$(curl -s -b "$JAR" -A 'sign-in-timezones/one' "$PROBE" 2>/dev/null)")"
[ -n "$first" ] || fail "the probe returned no audit session id"
row="$(sql "SELECT timezone, timezone_source, login_user_id FROM _audit_sessions WHERE id = ${first}")"
echo "$row" | grep -qE "\"login_user_id\": *${login_user_id}\b" || fail "the audit session is not the signed-in identity's: $row"
echo "$row" | grep -qE '"timezone": *"Asia\\?/Kolkata"' || fail "the audit session does not carry the browser's zone: $row"
echo "$row" | grep -qE '"timezone_source": *"browser"' || fail "the audit session's zone is not from the browser: $row"
echo "[TEST] 2. OK" >&2

echo "[TEST] 3. The same sign-in in another zone is another audit session..." >&2
set_browser_zone "$JAR" "Europe/Berlin"
moved="$(audit_id "$(curl -s -b "$JAR" -A 'sign-in-timezones/one' "$PROBE" 2>/dev/null)")"
[ -n "$moved" ] && [ "$moved" != "$first" ] || fail "a changed browser zone answered the same audit session ($moved)"
row="$(sql "SELECT timezone, timezone_source FROM _audit_sessions WHERE id = ${moved}")"
echo "$row" | grep -qE '"timezone": *"Europe\\?/Berlin"' || fail "the new audit session is not in the new zone: $row"
again="$(audit_id "$(curl -s -b "$JAR" -A 'sign-in-timezones/one' "$PROBE" 2>/dev/null)")"
[ "$again" = "$moved" ] || fail "the unchanged context answered $again, then $moved"
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# 4. An explicit zone outranks the browser's.
# ---------------------------------------------------------------------------
echo "[TEST] 4. An explicit zone is recorded as explicit..." >&2
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE login_users SET timezone = 'America/Denver' WHERE id = ${login_user_id}" >/dev/null 2>&1)
set_browser_zone "$SECOND_JAR" "Asia/Tokyo"
sign_in "$SECOND_JAR"
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE login_users SET timezone = NULL WHERE id = ${login_user_id}" >/dev/null 2>&1)

history="$(sql "SELECT timezone, timezone_source FROM _login_history WHERE login_user_id = ${login_user_id} AND status = 'success' ORDER BY id DESC LIMIT 1")"
echo "$history" | grep -qE '"timezone": *"America\\?/Denver"' || fail "the login history row does not carry the explicit zone: $history"
echo "$history" | grep -qE '"timezone_source": *"explicit"' || fail "the login history row's zone is not explicit: $history"
site="$(sql "SELECT timezone, timezone_source FROM _site_sign_ins WHERE login_user_id = ${login_user_id} ORDER BY id DESC LIMIT 1")"
echo "$site" | grep -qE '"timezone": *"America\\?/Denver"' || fail "the site sign-in does not carry the explicit zone: $site"
echo "$site" | grep -qE '"timezone_source": *"explicit"' || fail "the site sign-in's zone is not explicit: $site"
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# 5. Two memberships: no site at sign-in; choosing one, then switching.
# ---------------------------------------------------------------------------
echo "[TEST] 5. Choosing a site records sign_in, switching records site_switch..." >&2
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE users SET is_enabled = 1 WHERE id = ${second_user_id}" >/dev/null 2>&1)
before="$(sql "SELECT COUNT(*) AS n FROM _site_sign_ins WHERE login_user_id = ${login_user_id}" | grep -oE '"n": *[0-9]+' | grep -oE '[0-9]+$')"
set_browser_zone "$THIRD_JAR" "Australia/Sydney"
sign_in "$THIRD_JAR"
after="$(sql "SELECT COUNT(*) AS n FROM _site_sign_ins WHERE login_user_id = ${login_user_id}" | grep -oE '"n": *[0-9]+' | grep -oE '[0-9]+$')"
[ "$before" = "$after" ] || fail "a sign-in with two sites and none chosen recorded a site sign-in ($before -> $after)"

curl -s -o /dev/null -b "$THIRD_JAR" -c "$THIRD_JAR" "${BASE}/login/site/1" 2>/dev/null
row="$(sql "SELECT site_id, user_id, entry_type, timezone FROM _site_sign_ins WHERE login_user_id = ${login_user_id} ORDER BY id DESC LIMIT 1")"
echo "$row" | grep -qE '"site_id": *1\b' || fail "choosing site 1 recorded no site sign-in there: $row"
echo "$row" | grep -qE "\"user_id\": *${user_id}\b" || fail "the chosen site's row does not name membership ${user_id}: $row"
echo "$row" | grep -qE '"entry_type": *"sign_in"' || fail "choosing a site right after signing in is not a sign_in entry: $row"
echo "$row" | grep -qE '"timezone": *"Australia\\?/Sydney"' || fail "the chosen site's row does not carry the browser's zone: $row"

curl -s -o /dev/null -b "$THIRD_JAR" -c "$THIRD_JAR" "${BASE}/login/site/${second_site_id}" 2>/dev/null
row="$(sql "SELECT site_id, user_id, entry_type FROM _site_sign_ins WHERE login_user_id = ${login_user_id} ORDER BY id DESC LIMIT 1")"
echo "$row" | grep -qE "\"site_id\": *${second_site_id}\b" || fail "switching sites recorded no row on site ${second_site_id}: $row"
echo "$row" | grep -qE "\"user_id\": *${second_user_id}\b" || fail "the switched-to row does not name membership ${second_user_id}: $row"
echo "$row" | grep -qE '"entry_type": *"site_switch"' || fail "moving to another site is not a site_switch entry: $row"
echo "[TEST] 5. OK" >&2

# ---------------------------------------------------------------------------
# 6. An impersonation records the target's entry, naming the impersonator.
# ---------------------------------------------------------------------------
echo "[TEST] 6. Impersonating from /_sys records an impersonation entry..." >&2
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE login_users SET is_developer = 1 WHERE id = ${login_user_id}" >/dev/null 2>&1)
curl -s -o /dev/null -b "$THIRD_JAR" -c "$THIRD_JAR" "${BASE}/login/site/1" 2>/dev/null
page="$(curl -s -b "$THIRD_JAR" -c "$THIRD_JAR" "${BASE}/dashboard" 2>/dev/null)"
csrf="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
answer="$(curl -s -b "$THIRD_JAR" -c "$THIRD_JAR" -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: ${csrf}" \
    -d "{\"id\": ${target_login_user_id}, \"site_id\": 1}" "${BASE}/_ajax/_Sys_Users_Controller/sign_in_as" 2>/dev/null)"
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE login_users SET is_developer = 0 WHERE id = ${login_user_id}" >/dev/null 2>&1)
(cd "$PROJECT_DIR" && php artisan db:query "UPDATE users SET is_enabled = 0 WHERE id = ${second_user_id}" >/dev/null 2>&1)
printf '%s' "$answer" | grep -q '"destination"' || fail "the panel refused the impersonation: $answer"
row="$(sql "SELECT site_id, user_id, entry_type, impersonator_login_user_id FROM _site_sign_ins WHERE login_user_id = ${target_login_user_id} ORDER BY id DESC")"
[ "$(echo "$row" | grep -cE '"entry_type"')" = "1" ] || fail "expected exactly one site sign-in for the impersonated identity: $row"
echo "$row" | grep -qE "\"user_id\": *${target_user_id}\b" || fail "the impersonation row does not name the target's membership: $row"
echo "$row" | grep -qE '"entry_type": *"impersonation"' || fail "an impersonation is not an impersonation entry: $row"
echo "$row" | grep -qE "\"impersonator_login_user_id\": *${login_user_id}\b" || fail "the impersonation row does not name the impersonator: $row"
echo "[TEST] 6. OK" >&2

for jar in "$JAR" "$SECOND_JAR" "$THIRD_JAR"; do
    token="$(grep -w rsx "$jar" | awk '{print $7}')"
    [ -n "$token" ] && (cd "$PROJECT_DIR" && php artisan db:query "DELETE FROM _sessions WHERE session_token = '${token}'" >/dev/null 2>&1)
done

echo "PASS: $TEST_NAME"
exit 0
