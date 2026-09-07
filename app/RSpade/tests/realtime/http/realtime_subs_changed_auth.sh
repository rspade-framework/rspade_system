#!/bin/bash
set -e

TEST_NAME="Realtime subs_changed Endpoint Auth"

# /_realtime/subs_changed is the relay->PHP notify channel: the ONLY realtime route reachable
# from the network that is not a browser Ajax endpoint. It is authenticated by an HMAC of the
# RAW body under APP_KEY (the same shared secret the websocket tokens use) plus a 60s replay
# window, and it is an untrusted-input boundary: a rejection is logged and answered 403,
# never thrown.
#
# Asserts, over real HTTP against localhost:
#   - a correctly signed, fresh request is accepted (200, ok:true),
#   - a body tampered with after signing is rejected (403),
#   - a correctly signed body carrying a stale timestamp is rejected (403) - the signature
#     alone would otherwise make a captured body valid forever,
#   - a request with no signature header at all is rejected (403).
#
# The accepted case deliberately carries a member on a topic NO emitter serves, so the
# endpoint returns entries:0 and dispatches no task - this test never writes to the database.

if ! command -v curl >/dev/null 2>&1; then
    echo "SKIP: $TEST_NAME - curl not available"
    exit 0
fi
if ! grep -q '^REALTIME_ENABLED=true' /var/www/html/.env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi
if ! grep -q '^APP_KEY=' /var/www/html/.env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - APP_KEY not set in .env"
    exit 0
fi

APP_KEY="$(grep '^APP_KEY=' /var/www/html/.env | head -1 | cut -d= -f2- | tr -d '"'"'"'')"
URL="http://localhost/_realtime/subs_changed"

fails=0
check() {
    if [ "$2" = "1" ]; then echo "  ok   $1"; else echo "  FAIL $1"; fails=$((fails + 1)); fi
}

sign() {
    printf '%s' "$1" | openssl dgst -sha256 -hmac "$APP_KEY" -r | cut -d' ' -f1
}

post() {
    # $1 = body sent, $2 = signature header value
    curl -s -o /tmp/subs_changed_body.$$ -w '%{http_code}' -X POST "$URL" \
        -H 'Content-Type: application/json' \
        -H "X-Realtime-Signature: $2" \
        --data-binary "$1"
}

NOW="$(date +%s)"
UNSERVED_MEMBER='{\"site_id\":1,\"topic\":\"Realtime_Test_Private_Topic\",\"filter\":{\"portal_user_id\":42}}'

# 1) Valid signature + fresh timestamp -> 200.
body="{\"ts\":$NOW,\"members\":[\"$UNSERVED_MEMBER\"]}"
code="$(post "$body" "$(sign "$body")")"
resp="$(cat /tmp/subs_changed_body.$$ 2>/dev/null)"
rm -f /tmp/subs_changed_body.$$
check "valid signature + fresh ts -> 200 (got $code)" "$([ "$code" = "200" ] && echo 1 || echo 0)"
check "valid request answers ok:true (got: $resp)" "$(echo "$resp" | grep -q '"ok":true' && echo 1 || echo 0)"
check "unserved topic dispatches nothing (entries:0)" "$(echo "$resp" | grep -q '"entries":0' && echo 1 || echo 0)"

# 2) Tampered body: signature computed over the ORIGINAL, a different body sent.
sig="$(sign "$body")"
tampered="{\"ts\":$NOW,\"members\":[\"$UNSERVED_MEMBER\",\"injected\"]}"
code="$(post "$tampered" "$sig")"
rm -f /tmp/subs_changed_body.$$
check "tampered body -> 403 (got $code)" "$([ "$code" = "403" ] && echo 1 || echo 0)"

# 3) Stale timestamp, correctly signed (a captured replay).
stale_body="{\"ts\":$((NOW - 3600)),\"members\":[\"$UNSERVED_MEMBER\"]}"
code="$(post "$stale_body" "$(sign "$stale_body")")"
rm -f /tmp/subs_changed_body.$$
check "correctly signed but stale ts -> 403 (got $code)" "$([ "$code" = "403" ] && echo 1 || echo 0)"

# 4) No signature at all.
code="$(post "$body" "")"
rm -f /tmp/subs_changed_body.$$
check "missing signature -> 403 (got $code)" "$([ "$code" = "403" ] && echo 1 || echo 0)"

if [ "$fails" -gt 0 ]; then
    echo "FAIL: $TEST_NAME ($fails assertion(s))"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
