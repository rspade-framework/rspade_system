#!/bin/bash
set -e

TEST_NAME="Two-Factor Login Flow"

# HTTP integration test - the whole two-stage login, driven over real HTTP against the live
# web server (dev DB), because the two things it pins cannot be observed anywhere else.
#
# WHY IT EXISTS. Two rows of the php tier are deferred with the same two reasons:
#   tfa-chal-18  last_login is stamped by a completed challenge. Session::set_login_user_id()
#                returns from its CLI branch BEFORE the stamp, so no CLI login can ever
#                observe it - the flag is only reachable over HTTP.
#   tfa-chal-19  verify_challenge()'s own Login_Throttle::require_not_throttled() refuses a
#                locked-out client. Session::get_client_ip() is null in CLI by design, so the
#                ambient call enforces nothing there - it needs a real remote address.
# Both were additionally blocked on there being no HTTP path to verify_challenge() at all,
# because the application owns the verification endpoint. The template app now ships one
# (Rsx\App\Login\Login_Controller::verify_2fa), so both are reachable and this is them.
#
# ALSO PINNED, because they are the point of suppressing the recording at the password stage:
# ONE success row for the whole two-step login, written when the second factor lands and not
# before, and a 302 to the challenge screen rather than into the application.
#
# THE THROTTLE IS PER CLIENT IP AND SHARED WITH THIS BOX. Spending the budget locks the
# loopback address out of every login for the configured lockout window, so the trap below
# resets it however this script exits - including a failure part way through the loop.
#
# BOTH LOOPBACK SPELLINGS ARE RESET. curl against http://localhost resolves to ::1 here and to
# 127.0.0.1 elsewhere, and the throttle keys on the literal address string, so clearing only
# one of them leaves the box locked out for the lockout window - which is exactly how this was
# found: the sibling gates script started failing its login step with "You're doing that too
# fast".
#
# THIS TEST WRITES. It enrolls a TOTP factor on the dev default identity and removes it
# again, and it leaves login-history rows behind (a success and a run of 2FA failures), which
# is what a login flow does.
#
# Uses the dev default credentials. If a site overrides RSPADE_DEFAULT_EMAIL /
# RSPADE_DEFAULT_PASSWORD, adjust below.
#
# The endpoint-driven half of this flow is also covered in process by the application
# suite (rsx/tests/Two_Factor_Login_Verify_Test.php). What only exists here is what only
# HTTP can show: the last_login stamp and the ambient per-IP throttle.

LOGIN_EMAIL="admin@test.com"
LOGIN_PASSWORD="admintest99"
BASE="http://localhost"
ARTISAN="php artisan"

# Every artisan call below is relative to the framework root, so the script fixes its
# own working directory rather than depending on the caller's (the shell runner does not
# change it).
cd /var/www/html/system

JAR="$(mktemp)"
JAR2="$(mktemp)"

cleanup() {
    # The factor and the throttle are the two pieces of state this script leaves outside its
    # own scope. Both are cleared unconditionally.
    $ARTISAN rsx:users:2fa:remove --user="$LOGIN_EMAIL" --force --json >/dev/null 2>&1 || true
    $ARTISAN tinker --execute='\App\RSpade\Core\Auth\Login_Throttle::reset("127.0.0.1"); \App\RSpade\Core\Auth\Login_Throttle::reset("::1");' >/dev/null 2>&1 || true
    rm -f "$JAR" "$JAR2"
}
trap cleanup EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

# ---------------------------------------------------------------------------
# APPLICABILITY. Everything below the framework's own verify_challenge() belongs to the
# APPLICATION: the verification endpoint, the login form, the challenge screen and the
# destination a completed challenge lands on. The framework ships none of them, so this
# script only has a flow to drive where an application declares one. Probe the endpoint
# and skip when it is absent - an unknown ajax controller answers the fatal envelope
# naming the class it could not find, which is the whole signal needed here.
# ---------------------------------------------------------------------------
probe="$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' \
    "${BASE}/_ajax/Login_Controller/verify_2fa" 2>/dev/null)"

if [ -z "$probe" ]; then
    echo "SKIP: $TEST_NAME - web server not reachable on $BASE"
    exit 0
fi

if printf '%s' "$probe" | grep -qi 'class not found\|method not found\|not callable'; then
    echo "SKIP: $TEST_NAME - this application ships no Login_Controller::verify_2fa endpoint"
    exit 0
fi

# A live authenticator code for the enrolled seed, computed by the framework's own Totp so
# the test never carries a second implementation of RFC 6238.
live_code() {
    $ARTISAN tinker --execute="echo \\App\\RSpade\\Core\\TwoFactor\\Totp::code_for('$1', intdiv(time(), 30));" 2>/dev/null | tail -1 | tr -d '[:space:]'
}

sql_value() {
    $ARTISAN tinker --execute="echo $1;" 2>/dev/null | tail -1 | tr -d '[:space:]'
}

# ---------------------------------------------------------------------------
# Setup: a confirmed TOTP factor on the dev identity, and a clean throttle.
# ---------------------------------------------------------------------------
echo "[TEST] 0. Enrolling a TOTP factor for ${LOGIN_EMAIL}..." >&2
$ARTISAN rsx:users:2fa:remove --user="$LOGIN_EMAIL" --force --json >/dev/null 2>&1 || true
$ARTISAN tinker --execute='\App\RSpade\Core\Auth\Login_Throttle::reset("127.0.0.1"); \App\RSpade\Core\Auth\Login_Throttle::reset("::1");' >/dev/null 2>&1

setup_json="$($ARTISAN rsx:users:2fa:setup --user="$LOGIN_EMAIL" --json 2>/dev/null)"
SECRET="$(printf '%s' "$setup_json" | grep -oE '"secret": *"[A-Z2-7]+"' | head -1 | grep -oE '[A-Z2-7]{16,}')"
[ -n "$SECRET" ] || fail "could not read the enrolled secret from rsx:users:2fa:setup"

LOGIN_USER_ID="$(sql_value "\\App\\RSpade\\Core\\Models\\Login_User_Model::where('email', '${LOGIN_EMAIL}')->first()->id")"
[ -n "$LOGIN_USER_ID" ] || fail "could not resolve the login identity id"

last_login_before="$(sql_value "json_encode(\\App\\RSpade\\Core\\Models\\Login_User_Model::where('id', ${LOGIN_USER_ID})->first()->last_login)")"
successes_before="$(sql_value "\\Illuminate\\Support\\Facades\\DB::table('_login_history')->where('login_user_id', ${LOGIN_USER_ID})->where('status', 'success')->count()")"

# ---------------------------------------------------------------------------
# Test 1: a correct password stops at the challenge - it does not enter the app.
# ---------------------------------------------------------------------------
echo "[TEST] 1. A correct password redirects to the challenge, not into the app..." >&2
redirect="$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" \
    --data-urlencode "email=${LOGIN_EMAIL}" \
    --data-urlencode "password=${LOGIN_PASSWORD}" \
    --data-urlencode "__turnstile=inactive" \
    "${BASE}/login" 2>/dev/null)"

case "$redirect" in
    */login/verify) ;;
    *) fail "password stage redirected to '$redirect' (expected /login/verify)" ;;
esac

status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "${BASE}/dashboard" 2>/dev/null)"
[ "$status" = "302" ] || fail "the half-authenticated session reached /dashboard with status $status (expected a redirect)"

echo "[TEST] 1. OK - stopped at the challenge" >&2

# ---------------------------------------------------------------------------
# Test 2: the challenge page renders and carries a CSRF token.
# ---------------------------------------------------------------------------
echo "[TEST] 2. The challenge page renders..." >&2
page="$(curl -s -b "$JAR" -c "$JAR" "${BASE}/login/verify" 2>/dev/null)"

printf '%s' "$page" | grep -q 'Two_Factor_Challenge' || fail "the challenge page does not host <Two_Factor_Challenge>"

TOKEN="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
[ -n "$TOKEN" ] || fail "could not read window.rsxapp.csrf from the challenge page"
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# Test 3 (tfa-chal-18): a live code completes the login, stamps last_login, and writes
# EXACTLY ONE success row for the whole two-step flow.
# ---------------------------------------------------------------------------
echo "[TEST] 3. A live code completes the login..." >&2
CODE="$(live_code "$SECRET")"
[ -n "$CODE" ] || fail "could not compute a live authenticator code"

response="$(curl -s -b "$JAR" -c "$JAR" -X POST \
    -H 'Content-Type: application/json' \
    -H "X-CSRF-TOKEN: ${TOKEN}" \
    -d "{\"code\":\"${CODE}\"}" "${BASE}/_ajax/Login_Controller/verify_2fa" 2>/dev/null)"

printf '%s' "$response" | grep -q '"_success":true' || fail "verify_2fa refused a live code: $response"
printf '%s' "$response" | grep -q '"redirect"' || fail "verify_2fa answered no redirect (the component throws on this): $response"

status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "${BASE}/dashboard" 2>/dev/null)"
[ "$status" = "200" ] || fail "the completed session could not reach /dashboard (status $status)"

last_login_after="$(sql_value "json_encode(\\App\\RSpade\\Core\\Models\\Login_User_Model::where('id', ${LOGIN_USER_ID})->first()->last_login)")"
[ "$last_login_after" != "$last_login_before" ] || fail "last_login was not stamped by the completed challenge (still ${last_login_before})"

successes_after="$(sql_value "\\Illuminate\\Support\\Facades\\DB::table('_login_history')->where('login_user_id', ${LOGIN_USER_ID})->where('status', 'success')->count()")"
expected=$((successes_before + 1))
[ "$successes_after" = "$expected" ] || fail "the two-step login wrote $((successes_after - successes_before)) success rows (expected exactly 1)"

echo "[TEST] 3. OK - signed in, last_login stamped, one success row" >&2

# ---------------------------------------------------------------------------
# Test 4 (tfa-chal-19): the ambient throttle refuses a locked-out client, and the refusal
# reaches the screen as ITSELF - never as a wrong code.
# ---------------------------------------------------------------------------
echo "[TEST] 4. Wrong codes spend the budget and the throttle refuses..." >&2

curl -s -o /dev/null -c "$JAR2" -b "$JAR2" \
    --data-urlencode "email=${LOGIN_EMAIL}" \
    --data-urlencode "password=${LOGIN_PASSWORD}" \
    --data-urlencode "__turnstile=inactive" \
    "${BASE}/login" 2>/dev/null

page="$(curl -s -b "$JAR2" -c "$JAR2" "${BASE}/login/verify" 2>/dev/null)"
TOKEN2="$(printf '%s' "$page" | grep -oE '"csrf": *"[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
[ -n "$TOKEN2" ] || fail "could not read the CSRF token for the throttle run"

throttled=0
attempt=0

# The budget is rsx.sessions.login_throttle.attempts (10 by default). 25 wrong answers is
# comfortably past any sane setting; the loop stops the moment the refusal appears.
while [ $attempt -lt 25 ]; do
    attempt=$((attempt + 1))

    response="$(curl -s -b "$JAR2" -c "$JAR2" -X POST \
        -H 'Content-Type: application/json' \
        -H "X-CSRF-TOKEN: ${TOKEN2}" \
        -d '{"code":"000000"}' "${BASE}/_ajax/Login_Controller/verify_2fa" 2>/dev/null)"

    printf '%s' "$response" | grep -q '"_success":true' && fail "a wrong code SUCCEEDED on attempt ${attempt}: $response"

    if printf '%s' "$response" | grep -qi 'too fast'; then
        throttled=1
        break
    fi
done

[ "$throttled" = "1" ] || fail "25 wrong codes never reached the throttle - verify_challenge()'s require_not_throttled() is not enforcing over HTTP"

echo "[TEST] 4. OK - refused by the throttle after ${attempt} wrong answers" >&2

echo "PASS: $TEST_NAME"
exit 0
