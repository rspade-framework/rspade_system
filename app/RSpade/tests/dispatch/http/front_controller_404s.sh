#!/bin/bash
set -e

TEST_NAME="Front controller 404s"

# HTTP integration test - runs against the live web server, no database switching.
#
# Every request enters RSX through Rsx_Front_Controller, once. A build-artifact miss
# (/_vendor/, /_compiled/) is the ASSET channel's plain-text 404 RESPONSE - never a thrown
# 404 rendered as a page, and never a route scan. Laravel's router is not in the request
# path, so a URL a Laravel route (a vendor package's included) would claim is an ordinary
# RSX 404.

BASE="http://localhost"

if ! curl -s -o /dev/null --connect-timeout 2 "$BASE/" 2>/dev/null; then
    echo "SKIP: $TEST_NAME - web server not reachable on localhost"
    exit 0
fi

expect() {
    local label="$1" url="$2" want_status="$3" want_type="$4" method="${5:-GET}"
    local out status type
    out="$(curl -s -o /dev/null -X "$method" -w '%{http_code} %{content_type}' "$BASE$url")"
    status="${out%% *}"
    type="${out#* }"
    if [ "$status" != "$want_status" ]; then
        echo "FAIL: $TEST_NAME - $label: expected $want_status, got $status"
        exit 1
    fi
    if [ -n "$want_type" ] && [[ "$type" != "$want_type"* ]]; then
        echo "FAIL: $TEST_NAME - $label: expected $want_type, got $type"
        exit 1
    fi
    echo "[TEST] OK - $label: $status $type" >&2
}

expect "vendor miss" "/_vendor/00000000000000000000000000000000_nothing.js" 404 "text/plain"
expect "compiled miss" "/_compiled/No_Such_Probe__app.00000001.js" 404 "text/plain"
expect "compiled miss naming a real class" "/_compiled/Rsx_Settings__app.0123abcd.js" 404 "text/plain"
expect "unknown page" "/front-controller-probe-no-such-page" 404 "text/html"
expect "ignition health-check" "/_ignition/health-check" 404 ""
expect "ignition execute-solution" "/_ignition/execute-solution" 404 "" POST
expect "sanctum cookie" "/sanctum/csrf-cookie" 404 ""
expect "test-bundle-facade" "/test-bundle-facade" 404 ""

# An EXCLUDED public file (rsx.public.ignore_patterns: *.sh) answers exactly as a missing
# one does - a distinct status would tell a caller which excluded files exist. The probe
# file is created in the application's root public/ directory and removed on exit.
PUBLIC_DIR="$(cd "$(dirname "$0")/../../../../../.." && pwd)/rsx/public"
if [ -d "$PUBLIC_DIR" ]; then
    PROBE="$PUBLIC_DIR/front-controller-excluded-probe-$$.sh"
    trap 'rm -f "$PROBE"' EXIT
    echo "echo probe" > "$PROBE"
    missing="$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$BASE/front-controller-missing-probe-$$.sh")"
    excluded="$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$BASE/front-controller-excluded-probe-$$.sh")"
    if [ "$excluded" != "$missing" ] || [ "${excluded%% *}" != "404" ]; then
        echo "FAIL: $TEST_NAME - excluded public file answered '$excluded', a missing one '$missing'"
        exit 1
    fi
    echo "[TEST] OK - excluded public file answers as missing: $excluded" >&2
fi

echo "PASS: $TEST_NAME"
exit 0
