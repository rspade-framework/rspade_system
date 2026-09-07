#!/bin/bash
set -e

TEST_NAME="Portal Login Uses The One Session Cookie"

# HTTP integration test - runs against the live web server (dev DB). This is the
# full-stack counterpart to Flash_Alert_Realm_Test (php), which can only reach the
# write seam through the protected `_resolve_session_id()` (a PHP test IS a console
# process, where the public writers deliberately print instead of persisting).
#
# WHAT THIS LOCKS OUT
# A session identifies a BROWSER: ONE `rsx` cookie, ONE _sessions row, shared by the
# staff app and the client portal. A portal login must therefore set `rsx` and must
# NOT invent a second cookie - the retired `rsx_portal` is exactly the second cookie
# this model removed, and its return would mean a second session row, a second CSRF
# token, and the whole class of cross-experience breakage that came with it.
#
# The assertions, in order: the login response sets `rsx` and no `rsx_portal`; the
# flash READ on the next page delivers the alert (proving it was queued against the
# session the browser actually holds); the jar carries exactly one session cookie; a
# portal Ajax POST carrying the page's own csrf token is not rejected; and - the
# one-session proof - a STAFF page loaded on the SAME jar resolves the SAME session
# row that the portal login wrote its identity onto.

BASE="http://localhost"
PORTAL_PREFIX="${PORTAL_PREFIX:-/_portal}"
FIXTURE_EMAIL="flash-http-fixture@rspade.test"
FIXTURE_PASSWORD="FlashHttp!123"
FLASH_TEXT="Welcome to the Client Portal"
MISMATCH="CSRF token mismatch"

JAR="$(mktemp)"
H_LOGIN="$(mktemp)"
H_DASH="$(mktemp)"
BODY_DASH="$(mktemp)"
trap 'rm -f "$JAR" "$H_LOGIN" "$H_DASH" "$BODY_DASH"' EXIT

fail() {
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

# The retired portal cookie shares the `rsx` prefix, so the match must be anchored.
assert_no_portal_cookie() {
    local label="$1"; local header_file="$2"
    if grep -i '^set-cookie:' "$header_file" | grep -q 'rsx_portal='; then
        fail "$label set the retired rsx_portal cookie; there is one session cookie: $(grep -i '^set-cookie:' "$header_file")"
    fi
}

# ---------------------------------------------------------------------------
# Step 0: Seed the fixture portal account. Self-seeding on purpose - this test
# must not depend on a hand-made dev row surviving in someone's database.
# ---------------------------------------------------------------------------
echo "[TEST] 0. Seed the portal fixture account..." >&2
seed_out="$(cd /var/www/html && php artisan tinker --execute='
    $u = \App\RSpade\Core\Models\Portal_User_Model::withTrashed()
        ->where("site_id", 1)->where("email", "flash-http-fixture@rspade.test")->first();
    if (!$u) { $u = new \App\RSpade\Core\Models\Portal_User_Model(); $u->site_id = 1; $u->email = "flash-http-fixture@rspade.test"; }
    $u->deleted_at = null;
    $u->is_verified = 1;
    $u->status_id = \App\RSpade\Core\Models\Portal_User_Model::STATUS_ACTIVE;
    $u->set_password("FlashHttp!123");
    $u->save();
    echo "SEEDED:" . $u->id;
' 2>&1)" || fail "fixture seeding failed: $seed_out"
if ! printf '%s' "$seed_out" | grep -q "SEEDED:"; then
    fail "fixture seeding produced no id: $seed_out"
fi
echo "[TEST] 0. OK - $(printf '%s' "$seed_out" | grep -o 'SEEDED:[0-9]*')" >&2

# ---------------------------------------------------------------------------
# Step 1: A real portal form login succeeds and sets the ONE session cookie.
# ---------------------------------------------------------------------------
echo "[TEST] 1. Portal login POST sets rsx, and no second cookie..." >&2
login_status="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -D "$H_LOGIN" \
    --data-urlencode "email=${FIXTURE_EMAIL}" \
    --data-urlencode "password=${FIXTURE_PASSWORD}" \
    "${BASE}${PORTAL_PREFIX}/login" 2>/dev/null)"
if [ "$login_status" != "302" ]; then
    fail "portal login returned $login_status (expected 302); the fixture credentials or the portal prefix are wrong"
fi
if ! grep -i '^set-cookie:' "$H_LOGIN" | grep -qE '(^|[ ;])rsx='; then
    fail "portal login did not set the session cookie rsx: $(grep -i '^set-cookie:' "$H_LOGIN")"
fi
assert_no_portal_cookie "the login response" "$H_LOGIN"
echo "[TEST] 1. OK - rsx only" >&2

# ---------------------------------------------------------------------------
# Step 2: The next portal page (where the queued alert is READ and deleted)
# delivers the alert and still emits no second cookie.
# ---------------------------------------------------------------------------
echo "[TEST] 2. The flash read delivers the alert on the one session..." >&2
curl -s -b "$JAR" -c "$JAR" -D "$H_DASH" -o "$BODY_DASH" "${BASE}${PORTAL_PREFIX}" 2>/dev/null
assert_no_portal_cookie "the portal page that consumed the flash" "$H_DASH"
if ! grep -q "$FLASH_TEXT" "$BODY_DASH"; then
    fail "the login alert never reached the portal page; it was queued against the wrong session or dropped"
fi
echo "[TEST] 2. OK - alert delivered, rsx only" >&2

# ---------------------------------------------------------------------------
# Step 3: The cookie jar holds exactly one session cookie.
# ---------------------------------------------------------------------------
echo "[TEST] 3. The browser holds exactly one session cookie..." >&2
if grep -q 'rsx_portal' "$JAR"; then
    fail "the retired portal cookie ended up in the jar: $(cat "$JAR")"
fi
if ! grep -qE '[[:space:]]rsx[[:space:]]' "$JAR"; then
    fail "the session cookie is missing from the jar: $(cat "$JAR")"
fi
echo "[TEST] 3. OK" >&2

# ---------------------------------------------------------------------------
# Step 4: A portal Ajax POST that presents the page's own csrf token is not
# rejected. There is one token now, so this also proves the staff-channel seam
# and the portal-channel seam check the same value.
# ---------------------------------------------------------------------------
echo "[TEST] 4. Portal Ajax with the page's own csrf token passes the csrf seam..." >&2
token="$(grep -oE '"csrf": *"[a-f0-9]+"' "$BODY_DASH" | head -1 | grep -oE '[a-f0-9]{16,}' || true)"
if [ -z "$token" ]; then
    fail "could not read window.rsxapp.csrf from the portal page"
fi
ajax_body="$(curl -s -b "$JAR" -X POST -H 'Content-Type: application/json' \
    -H "X-CSRF-Token: ${token}" -d '{}' \
    "${BASE}${PORTAL_PREFIX}/_ajax/Flash_Portal_Probe/noop" 2>/dev/null || true)"
if printf '%s' "$ajax_body" | grep -q "$MISMATCH"; then
    fail "a portal Ajax POST with the portal csrf token was rejected; got: $ajax_body"
fi
echo "[TEST] 4. OK" >&2

# ---------------------------------------------------------------------------
# Step 5: THE ONE-SESSION PROOF. The session token the browser holds resolves to
# a single _sessions row, and that row is the one the portal login stamped its
# identity onto. A staff page load on the same jar re-uses it rather than minting
# a second session.
# ---------------------------------------------------------------------------
echo "[TEST] 5. A staff page on the same jar shares the portal login's session row..." >&2
curl -s -b "$JAR" -c "$JAR" -o /dev/null "${BASE}/" 2>/dev/null || true

session_token="$(grep -E '[[:space:]]rsx[[:space:]]' "$JAR" | awk '{print $NF}' | head -1)"
if [ -z "$session_token" ]; then
    fail "could not read the session token out of the jar"
fi

row_out="$(cd /var/www/html && RSX_TEST_SESSION_TOKEN="$session_token" php artisan tinker --execute='
    $t = getenv("RSX_TEST_SESSION_TOKEN");
    $rows = \App\RSpade\Core\Session\Session::where("session_token", $t)->get();
    echo "ROWS:" . $rows->count() . " PORTAL:" . (int) ($rows->first()->portal_user_id ?? 0);
' 2>&1)" || fail "session row lookup failed: $row_out"

if ! printf '%s' "$row_out" | grep -q 'ROWS:1'; then
    fail "the browser's token must resolve to exactly one session row; got: $row_out"
fi
if printf '%s' "$row_out" | grep -q 'PORTAL:0'; then
    fail "the shared session row lost the portal identity the login wrote; got: $row_out"
fi
echo "[TEST] 5. OK - one row, carrying the portal identity" >&2

echo "PASS: $TEST_NAME"
exit 0
