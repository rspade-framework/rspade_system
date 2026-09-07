#!/bin/bash
set -e

TEST_NAME="Dispatcher Auth Rejection (full-page vs ajax channel split)"

# HTTP integration test - runs against the live web server, no database switching.
# Exercises B4.6 (closes B-31) over the wire: an unauthorized full-page GET now
# 302s to the login route with the intended URL threaded as ?redirect=, while the
# SAME rejection through an ajax endpoint keeps the JSON error_code contract (no
# 302). /dashboard is a protected frontend route whose controller pre_dispatch
# returns response_unauthorized() when logged out.

BASE="http://localhost"

echo "[TEST] 1. Logged-out full-page GET to a protected route -> 302 to login..." >&2
headers=$(curl -s -D - -o /dev/null "$BASE/dashboard" 2>/dev/null)
status_line=$(echo "$headers" | grep -iE '^HTTP/' | tail -n 1)
location=$(echo "$headers" | grep -i '^location:' | tail -n 1)

if ! echo "$status_line" | grep -q "302"; then
    echo "FAIL: $TEST_NAME - expected 302 for logged-out full-page GET, got: $status_line"
    exit 1
fi
if ! echo "$location" | grep -qi "/login"; then
    echo "FAIL: $TEST_NAME - 302 did not point at the login route: $location"
    exit 1
fi
# The originally requested path (/dashboard -> %2Fdashboard) must be captured.
if ! echo "$location" | grep -qi "redirect=%2Fdashboard"; then
    echo "FAIL: $TEST_NAME - login redirect did not thread the intended URL: $location"
    exit 1
fi
echo "[TEST] 1. OK - 302 to $location" >&2

echo "[TEST] 2. Same rejection via an ajax endpoint -> JSON error_code, no 302..." >&2
ajax_headers=$(curl -s -D - -o /dev/null -X POST \
    "$BASE/_ajax/Frontend_Dashboard_Controller/dashboard_data" \
    -H "Content-Type: application/json" -d '{}' 2>/dev/null)
ajax_status=$(echo "$ajax_headers" | grep -iE '^HTTP/' | tail -n 1)
ajax_location=$(echo "$ajax_headers" | grep -i '^location:' | tail -n 1)
ajax_body=$(curl -s -X POST \
    "$BASE/_ajax/Frontend_Dashboard_Controller/dashboard_data" \
    -H "Content-Type: application/json" -d '{}' 2>/dev/null)

if [ -n "$ajax_location" ]; then
    echo "FAIL: $TEST_NAME - ajax rejection issued a redirect (channel split broken): $ajax_location"
    exit 1
fi
if echo "$ajax_status" | grep -q "302"; then
    echo "FAIL: $TEST_NAME - ajax rejection returned 302: $ajax_status"
    exit 1
fi
if ! echo "$ajax_body" | grep -q '"error_code":"unauthorized"'; then
    echo "FAIL: $TEST_NAME - ajax rejection missing error_code:unauthorized. Body: $ajax_body"
    exit 1
fi
echo "[TEST] 2. OK - ajax path returned error_code:unauthorized with no redirect" >&2

echo "PASS: $TEST_NAME"
exit 0
