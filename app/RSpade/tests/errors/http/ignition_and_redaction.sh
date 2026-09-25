#!/bin/bash
set -e

TEST_NAME="Ignition Read-Only and Caller-Keyed Redaction"

# HTTP integration test. Ignition's housekeeping routes must answer 404 (runnable
# solutions are hard-coded off), and an anonymous caller from off the box must get
# no exception detail on any channel - only an error id. The off-box caller is
# simulated with X-Forwarded-For: nginx appends the real hop, so the chain names a
# remote client and is_loopback_ip() is false.

BASE="http://localhost"
REMOTE=(-H 'X-Forwarded-For: 203.0.113.9')
CONFIG="$(dirname "$0")/../../../../../config/ignition.php"

echo "[TEST] 1. Both Ignition flags are literal false in config/ignition.php..." >&2
for key in enable_runnable_solutions enable_share_button; do
    if ! grep -Eq "^\s*'${key}' => false,\s*$" "$CONFIG"; then
        echo "FAIL: $TEST_NAME - ${key} is not the literal false"
        exit 1
    fi
done
echo "[TEST] 1. OK" >&2

echo "[TEST] 2. /_ignition/* answers 404..." >&2
status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/_ignition/health-check")"
if [ "$status" != "404" ]; then
    echo "FAIL: $TEST_NAME - GET /_ignition/health-check answered $status"
    exit 1
fi
status="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d '{"solution":"Spatie\\LaravelIgnition\\Solutions\\MakeViewVariableOptionalSolution","parameters":{}}' \
    "${BASE}/_ignition/execute-solution")"
if [ "$status" != "404" ]; then
    echo "FAIL: $TEST_NAME - POST /_ignition/execute-solution answered $status"
    exit 1
fi
echo "[TEST] 2. OK" >&2

echo "[TEST] 3. A remote caller's Ajax fatal carries an error id and no origin..." >&2
body="$(curl -s -X POST "${REMOTE[@]}" -H "Origin: ${BASE}" "${BASE}/_ajax/Nonexistent_Probe_Class/x")"
if ! printf '%s' "$body" | grep -q '"error_id":"[0-9a-f]\{16\}"'; then
    echo "FAIL: $TEST_NAME - no error_id in the redacted Ajax envelope: $body"
    exit 1
fi
if printf '%s' "$body" | grep -q '"file"\|backtrace\|Controller class'; then
    echo "FAIL: $TEST_NAME - the Ajax envelope leaked detail to a remote caller: $body"
    exit 1
fi
echo "[TEST] 3. OK" >&2

echo "[TEST] 4. A remote caller's batched fatal carries an error id and no message..." >&2
body="$(curl -s -X POST "${REMOTE[@]}" -H "Origin: ${BASE}" -H 'Content-Type: application/json' \
    -d '{"batch_calls":[{"call_id":1,"controller":"Nonexistent_Probe_Class","action":"x"}]}' \
    "${BASE}/_ajax/_batch")"
if ! printf '%s' "$body" | grep -q '"error_id":"[0-9a-f]\{16\}"'; then
    echo "FAIL: $TEST_NAME - no error_id in the redacted batch envelope: $body"
    exit 1
fi
if printf '%s' "$body" | grep -q 'Nonexistent_Probe_Class'; then
    echo "FAIL: $TEST_NAME - the batch envelope echoed the class probe to a remote caller: $body"
    exit 1
fi
echo "[TEST] 4. OK" >&2

echo "[TEST] 5. The same Ajax probe from the box itself keeps its detail..." >&2
body="$(curl -s -X POST -H "Origin: ${BASE}" "${BASE}/_ajax/Nonexistent_Probe_Class/x")"
if ! printf '%s' "$body" | grep -q 'Controller class not found'; then
    echo "FAIL: $TEST_NAME - a loopback caller lost the specific reason: $body"
    exit 1
fi
echo "[TEST] 5. OK" >&2

echo "PASS: $TEST_NAME"
exit 0
