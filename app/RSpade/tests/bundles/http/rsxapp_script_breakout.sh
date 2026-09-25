#!/bin/bash
set -e

TEST_NAME="rsxapp Script Breakout"

# HTTP integration test. window.rsxapp is printed inside an inline <script>, and its
# params carry the query string. A `</script>` in a query value must reach the page as
# \u003C/script\u003E, never as a literal closing tag that ends the element and lets the
# rest of the value be parsed as markup.

BASE="http://localhost"

echo "[TEST] 1. A query value containing </script> stays inside the rsxapp string..." >&2
body="$(curl -s "${BASE}/login?rsxapp_probe=%3C%2Fscript%3E%3Cb%3Ebreakout")"

if printf '%s' "$body" | grep -q '</script><b>breakout'; then
    echo "FAIL: $TEST_NAME - the query value closed the rsxapp script element"
    exit 1
fi

if ! printf '%s' "$body" | grep -q '\\u003C/script\\u003E\\u003Cb\\u003Ebreakout'; then
    echo "FAIL: $TEST_NAME - the probe value is missing from rsxapp in its escaped form"
    exit 1
fi
echo "[TEST] 1. OK - the value is hex-escaped inside the JSON string" >&2

echo "PASS: $TEST_NAME"
exit 0
