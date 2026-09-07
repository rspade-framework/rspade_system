#!/bin/bash
set -e

TEST_NAME="Man Command"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

source "$TEST_DIR/../../_lib/test_env.sh"

trap test_trap_exit EXIT

echo "[SETUP] Preparing test..." >&2
test_mode_enter

echo "[TEST] Running assertions..." >&2

# Test 1: List all docs (no term)
output=$(cd /var/www/html && php artisan rsx:man 2>&1) || true
if ! echo "$output" | grep -q "spa"; then
    echo "FAIL: $TEST_NAME - Expected 'spa' in doc list"
    exit 1
fi
if ! echo "$output" | grep -q "jqhtml"; then
    echo "FAIL: $TEST_NAME - Expected 'jqhtml' in doc list"
    exit 1
fi
echo "[TEST] List all docs: OK" >&2

# Test 2: Exact term match
output=$(cd /var/www/html && php artisan rsx:man spa 2>&1) || true
if ! echo "$output" | grep -qi "SPA\|single page"; then
    echo "FAIL: $TEST_NAME - Expected SPA documentation content"
    exit 1
fi
echo "[TEST] Exact term match: OK" >&2

# Test 3: Partial term match
output=$(cd /var/www/html && php artisan rsx:man jq 2>&1) || true
if ! echo "$output" | grep -qi "jqhtml\|template"; then
    echo "FAIL: $TEST_NAME - Expected jqhtml documentation for partial match 'jq'"
    exit 1
fi
echo "[TEST] Partial term match: OK" >&2

# Test 4: --agent-helper-message flag
output=$(cd /var/www/html && php artisan rsx:man --agent-helper-message 2>&1) || true
if ! echo "$output" | grep -q "spa"; then
    echo "FAIL: $TEST_NAME - Expected 'spa' in agent helper message"
    exit 1
fi
# Should be space-separated list
if echo "$output" | grep -q "|"; then
    echo "FAIL: $TEST_NAME - Agent helper should be space-separated, not pipe-separated"
    exit 1
fi
echo "[TEST] --agent-helper-message flag: OK" >&2

# Test 5: Not found error handling
output=$(cd /var/www/html && php artisan rsx:man nonexistent_topic_xyz123 2>&1) || true
if ! echo "$output" | grep -qi "No documentation found"; then
    echo "FAIL: $TEST_NAME - Expected 'No documentation found' message for nonexistent topic"
    exit 1
fi
# Should list available docs when not found
if ! echo "$output" | grep -q "Available"; then
    echo "FAIL: $TEST_NAME - Expected 'Available' documentation list on not found"
    exit 1
fi
echo "[TEST] Not found error handling: OK" >&2

echo "PASS: $TEST_NAME"
exit 0
