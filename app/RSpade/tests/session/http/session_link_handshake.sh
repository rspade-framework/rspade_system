#!/bin/bash
set -e

TEST_NAME="Linked-Session Handshake Over HTTP"

# HTTP integration test - runs against the live web server (dev DB), self-seeding.
#
# The end-to-end proof of the linked-session handshake (Session_Link): the three GET legs,
# driven as two separate cookie jars - the STAFF browser's and the PORTAL host's - end with
# the portal side's response setting `rsx` to the STAFF row's token. This is the part the
# PHP test (Portal_Session_Link_Test) cannot reach: Session::_clone_session_to_this_host()
# emits a real Set-Cookie and refuses in CLI, where there is no browser.
#
# The live server runs whatever layout the box is configured with, and this test does not
# change it (that would mean editing the box's .env). In the default same-host layout both
# "hosts" are APP_URL's host, so each leg is addressed with that Host header and the two
# jars are kept apart by hand: the staff leg sends only the staff token, the portal legs only
# the portal side's cookies. The portal side starts with a session of its own, so the test
# also proves the replaced cookie is withdrawn (exactly one rsx Set-Cookie on the last leg)
# and the replaced row deactivated. The host binding of each leg is the PHP test's job. On a
# box whose PORTAL_URL names a host of its own the test SKIPs.
#
# The staff identity is a fixture user at the application's most privileged role; when the
# application's can_impersonate denies even that role, the test SKIPs (the check is the
# application's rule, not the framework's).

SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"
PROJECT_DIR="$(dirname "$SYSTEM_DIR")"
STAFF_EMAIL="session-link-http-staff@rspade.test"
PORTAL_EMAIL="session-link-http-portal@rspade.test"

H1="$(mktemp)"; H2="$(mktemp)"; H3="$(mktemp)"; H4="$(mktemp)"
STAFF_ROW=""; PORTAL_ROW=""

cleanup() {
    rm -f "$H1" "$H2" "$H3" "$H4"
    if [ -n "$STAFF_ROW$PORTAL_ROW" ]; then
        (cd "$PROJECT_DIR" && php artisan tinker --execute="
            \App\RSpade\Core\Session\Session::whereIn('id', [${STAFF_ROW:-0}, ${PORTAL_ROW:-0}])->raw_bulk()->delete();
        " >/dev/null 2>&1) || true
    fi
}
trap cleanup EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

tinker() {
    (cd "$PROJECT_DIR" && php artisan tinker --execute="$1" 2>&1)
}

# Value of a response header (first match), CR stripped.
header_value() {
    grep -i "^$1:" "$2" | head -1 | sed -E "s/^[^:]+:[[:space:]]*//" | tr -d '\r'
}

# Path + query of a URL (absolute or not).
path_of() {
    printf '%s' "$1" | sed -E 's#^https?://[^/]+##'
}

# ---------------------------------------------------------------------------
# Step 0: the layout, and the fixtures.
# ---------------------------------------------------------------------------
LAYOUT="$(cd "$SYSTEM_DIR" && php -r '$app = require "script.php"; echo (\App\RSpade\Core\Portal\Rsx_Portal_Url::is_separate_host() ? "separate" : "same") . " " . parse_url((string) config("app.url"), PHP_URL_HOST);' 2>/dev/null | tail -1)"
case "${LAYOUT%% *}" in
    same) APP_HOST="${LAYOUT#* }" ;;
    separate) echo "SKIP: $TEST_NAME - PORTAL_URL names a host of its own"; exit 0 ;;
    *) fail "the portal layout could not be read from the configuration" ;;
esac
BASE="http://localhost"

echo "[TEST] 0. Seed a staff browser session, a portal user and leg 1..." >&2
seed_out="$(tinker '
    $site_id = 1;
    \App\RSpade\Core\Session\Session::set_temporary_site_id($site_id);

    $login = \App\RSpade\Core\Models\Login_User_Model::where("email", "'"$STAFF_EMAIL"'")->first()
        ?? new \App\RSpade\Core\Models\Login_User_Model();
    $login->email = "'"$STAFF_EMAIL"'";
    $login->password = \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(16)));
    $login->is_activated = true;
    $login->is_verified = true;
    $login->status_id = \App\RSpade\Core\Models\Login_User_Model::STATUS_ACTIVE;
    $login->save();

    $user = \App\RSpade\Core\Models\User_Model::without_site_scope(fn () =>
        \App\RSpade\Core\Models\User_Model::withTrashed()->where("login_user_id", $login->id)->where("site_id", $site_id)->first())
        ?? new \App\RSpade\Core\Models\User_Model();
    $user->login_user_id = $login->id;
    $user->site_id = $site_id;
    $user->email = "'"$STAFF_EMAIL"'";
    $user->first_name = "Session";
    $user->last_name = "Link";
    $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
    $user->is_enabled = 1;
    $user->deleted_at = null;
    $user->save();

    $portal = \App\RSpade\Core\Models\Portal_User_Model::withTrashed()->where("site_id", $site_id)->where("email", "'"$PORTAL_EMAIL"'")->first()
        ?? new \App\RSpade\Core\Models\Portal_User_Model();
    $portal->site_id = $site_id;
    $portal->email = "'"$PORTAL_EMAIL"'";
    $portal->deleted_at = null;
    $portal->is_verified = 1;
    $portal->status_id = \App\RSpade\Core\Models\Portal_User_Model::STATUS_ACTIVE;
    $portal->set_password(bin2hex(random_bytes(16)));
    $portal->save();

    \App\RSpade\Core\Session\Session::impersonate($site_id, (int) $login->id, (int) $user->id);
    if (!\App\RSpade\Core\Auth\Auth_Gates::evaluate("can_impersonate", "staff")) { echo "DENIED"; return; }

    $rows = [];
    foreach (["staff" => $login->id, "portal" => null] as $kind => $login_user_id) {
        $s = new \App\RSpade\Core\Session\Session();
        $s->session_token = bin2hex(random_bytes(32));
        $s->csrf_token = bin2hex(random_bytes(32));
        $s->ip_address = "127.0.0.1";
        $s->user_agent = "session-link-http";
        $s->last_active = now();
        $s->active = true;
        $s->site_id = $login_user_id ? $site_id : 0;
        $s->login_user_id = $login_user_id;
        $s->type_id = \App\RSpade\Core\Session\Session::TYPE_WEB;
        $s->version = 1;
        $s->save();
        $rows[$kind] = $s;
    }

    $leg1 = \App\RSpade\Core\Session\Session_Link::begin_impersonation($rows["staff"]->id, $portal->id, $site_id, $user->id);

    echo "SEEDED " . $rows["staff"]->id . " " . $rows["staff"]->session_token . " " . $rows["portal"]->id . " "
        . $rows["portal"]->session_token . " " . $portal->id . " " . $user->id . " " . $leg1;
')" || fail "seeding failed: $seed_out"

if printf '%s' "$seed_out" | grep -q "DENIED"; then
    echo "SKIP: $TEST_NAME - this application's can_impersonate denies its most privileged role"
    exit 0
fi
seed_line="$(printf '%s' "$seed_out" | grep -o 'SEEDED .*' | tail -1)"
[ -n "$seed_line" ] || fail "seeding produced nothing usable: $seed_out"
read -r _ STAFF_ROW STAFF_TOKEN PORTAL_ROW PORTAL_TOKEN PORTAL_USER_ID STAFF_USER_ID LEG1 <<< "$seed_line"
echo "[TEST] 0. OK - staff row $STAFF_ROW, portal-side row $PORTAL_ROW" >&2

# ---------------------------------------------------------------------------
# Step 1: leg 1 on the portal side - the portal jar holds only its own session.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Leg 1 sets the nonce cookie and sends the browser to the staff host..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -D "$H1" -H "Host: $APP_HOST" \
    -b "rsx=$PORTAL_TOKEN" "${BASE}$(path_of "$LEG1")")"
[ "$status" = "302" ] || fail "leg 1 answered $status, expected 302"
NONCE="$(grep -i '^set-cookie: rsx_link=' "$H1" | head -1 | sed -E 's/^[^=]+=([^;]*).*/\1/' | tr -d '\r')"
[ -n "$NONCE" ] || fail "leg 1 set no rsx_link nonce cookie: $(grep -i '^set-cookie:' "$H1")"
LEG2="$(header_value Location "$H1")"
case "$LEG2" in
    *"/_session_link/confirm?"*) ;;
    *) fail "leg 1 redirected to '$LEG2', expected the staff host's /_session_link/confirm" ;;
esac
echo "[TEST] 1. OK" >&2

# ---------------------------------------------------------------------------
# Step 2: leg 2 on the staff side - the staff jar's own cookie.
# ---------------------------------------------------------------------------
echo "[TEST] 2. Leg 2 proves the staff session and sends the browser back..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -D "$H2" -H "Host: $APP_HOST" \
    -b "rsx=$STAFF_TOKEN" "${BASE}$(path_of "$LEG2")")"
[ "$status" = "302" ] || fail "leg 2 answered $status, expected 302"
LEG3="$(header_value Location "$H2")"
case "$LEG3" in
    *"/_session_link/complete?"*) ;;
    *) fail "leg 2 redirected to '$LEG3', expected the portal's /_session_link/complete" ;;
esac
echo "[TEST] 2. OK" >&2

# ---------------------------------------------------------------------------
# Step 3: leg 3 on the portal side - its own (soon replaced) session plus the nonce.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Leg 3 points the portal side's rsx cookie at the staff row..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' -D "$H3" -H "Host: $APP_HOST" \
    -b "rsx=$PORTAL_TOKEN; rsx_link=$NONCE" "${BASE}$(path_of "$LEG3")")"
[ "$status" = "302" ] || fail "leg 3 answered $status, expected 302"

rsx_cookies="$(grep -ic '^set-cookie: rsx=' "$H3" || true)"
[ "$rsx_cookies" = "1" ] || fail "leg 3 must send exactly one rsx cookie, sent $rsx_cookies: $(grep -i '^set-cookie: rsx=' "$H3")"
grep -i '^set-cookie: rsx=' "$H3" | grep -q "rsx=${STAFF_TOKEN};" \
    || fail "leg 3's rsx cookie is not the staff row's token: $(grep -i '^set-cookie: rsx=' "$H3")"
grep -i '^set-cookie: rsx_link=' "$H3" | grep -qiE 'max-age=0|expires=.*19[0-9]{2}|rsx_link=deleted' \
    || fail "leg 3 did not clear the nonce cookie: $(grep -i '^set-cookie: rsx_link=' "$H3")"
echo "[TEST] 3. OK - one rsx cookie, the staff token" >&2

# ---------------------------------------------------------------------------
# Step 4: the database - one row carrying the impersonation, the replaced row retired.
# ---------------------------------------------------------------------------
echo "[TEST] 4. The staff row carries the impersonation; the replaced row is inactive..." >&2
rows_out="$(tinker '
    $s = \App\RSpade\Core\Session\Session::find('"$STAFF_ROW"');
    $p = \App\RSpade\Core\Session\Session::find('"$PORTAL_ROW"');
    echo "ROWS portal_user=" . (int) $s->portal_user_id . " impersonator=" . (int) $s->impersonator_user_id
        . " staff_active=" . (int) $s->active . " replaced_active=" . (int) $p->active
        . " links=" . \Illuminate\Support\Facades\DB::table("_session_links")->where("session_id", '"$STAFF_ROW"')->count();
')"
printf '%s' "$rows_out" | grep -q "ROWS portal_user=${PORTAL_USER_ID} impersonator=${STAFF_USER_ID} staff_active=1 replaced_active=0 links=0" \
    || fail "unexpected session state after the handshake: $rows_out"
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# Step 5: a replayed last leg is refused with the generic page.
# ---------------------------------------------------------------------------
echo "[TEST] 5. Replaying leg 3 is refused..." >&2
status="$(curl -s -o "$H4" -w '%{http_code}' -H "Host: $APP_HOST" \
    -b "rsx=$STAFF_TOKEN; rsx_link=$NONCE" "${BASE}$(path_of "$LEG3")")"
[ "$status" = "400" ] || fail "a replayed leg 3 answered $status, expected 400"
grep -q "This link has expired or was already used." "$H4" || fail "the replay did not get the generic refusal page"
echo "[TEST] 5. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
