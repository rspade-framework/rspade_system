#!/bin/bash
set -e

TEST_NAME="rsx:debug Captures A Loaded Page With The Realtime Relay Down"

# A component that subscribes to realtime in on_create() holds its first load until the
# subscription is live or rsx.realtime.load_gate_timeout_ms passes. With the relay down the
# page goes network-idle BEFORE that load runs, and rsx:debug used to capture it empty.
# It now waits for the SPA action to reach ready. --no-realtime makes the headless browser
# meet a down relay (every /ws connection closed at once) without touching the real one.
#
# Subject: /_sys/workers, a framework page whose action subscribes in on_create() and
# loads its worker list in on_load(). The eval reports whether the list loaded and whether
# the realtime client ever authenticated (it must not have: the relay was refused).

cd /var/www/html

out="$(php artisan rsx:debug /_sys/workers --user=1 --no-realtime \
    --eval="return JSON.stringify({developer: !!window.rsxapp.is_developer, loaded: Spa.action().data.workers !== null, authenticated: !!Rsx_Realtime._authenticated})" 2>&1)"

result="$(echo "$out" | grep -A1 '^JavaScript Eval Result:' | tail -1)"

if echo "$result" | grep -q '"developer":false'; then
    echo "SKIP: $TEST_NAME - user 1 is not a developer on this box, so /_sys is closed to it"
    exit 0
fi

if ! echo "$out" | grep -q '^200 '; then
    echo "FAIL: $TEST_NAME - the page did not render with a 200"
    echo "$out"
    exit 1
fi

if ! echo "$result" | grep -q '"authenticated":false'; then
    echo "FAIL: $TEST_NAME - the realtime client authenticated, so --no-realtime did not refuse the relay: $result"
    exit 1
fi

if ! echo "$result" | grep -q '"loaded":true'; then
    echo "FAIL: $TEST_NAME - the action's subscription-gated load had not run when the page was captured: $result"
    echo "$out"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
