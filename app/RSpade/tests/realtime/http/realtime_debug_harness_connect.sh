#!/bin/bash
set -e

TEST_NAME="Realtime Debug Harness Connect"

# Proves the realtime client can complete a full WebSocket handshake INSIDE the
# rsx:debug harness: the harness browses http://localhost, so the client's
# scheme-follows-page derivation (Rsx_Realtime._connect_url) must produce
# ws://localhost/ws and connect through nginx's port-80 /ws proxy to the relay.
# Regression guard for the 2026-07-24 ERR_CONNECTION_REFUSED harness gap
# (client hardcoded wss:// which nothing serves on the container's localhost).
#
# The page renders without a session cookie under the harness, so nothing
# subscribes on its own - the eval forces a connection attempt and asserts the
# full token-mint -> ws:// handshake -> auth round trip reaches 'connected'.
#
# Requires the relay to be running (supervisor [program:realtime]); skips with a
# clear message when realtime is disabled.

cd /var/www/html/system

if ! grep -q '^REALTIME_ENABLED=true' .env 2>/dev/null; then
    echo "SKIP: $TEST_NAME - REALTIME_ENABLED is not true in .env"
    exit 0
fi

result="$(timeout 180 php artisan rsx:debug /_sys --user=1 \
    --eval="Rsx_Realtime._connect(); await sleep(2000); return JSON.stringify({state: Rsx_Realtime._state, url: Rsx_Realtime._connect_url()})" 2>&1 \
    | grep -o '{"state":[^}]*}')"

if ! echo "$result" | grep -q '"url":"ws://localhost/ws"'; then
    echo "FAIL: $TEST_NAME - connect URL did not derive to ws://localhost/ws: $result"
    exit 1
fi

if ! echo "$result" | grep -q '"state":"connected"'; then
    echo "FAIL: $TEST_NAME - client did not reach connected state under the harness: $result"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
