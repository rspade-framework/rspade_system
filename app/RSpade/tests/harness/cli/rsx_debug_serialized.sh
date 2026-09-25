#!/bin/bash
set -e

TEST_NAME="rsx:debug Runs One At A Time"

# Two rsx:debug runs that overlap on one box share the harness's browser and dev-auth
# state and fail each other. Every run holds the rsx_debug system lock, so a second run
# waits for the first and says so once on stderr. Both must succeed.

cd /var/www/html

out_a="$(mktemp)"
out_b="$(mktemp)"
trap 'rm -f "$out_a" "$out_b"' EXIT

php artisan rsx:debug / >"$out_a" 2>&1 &
pid_a=$!
php artisan rsx:debug /login >"$out_b" 2>&1 &
pid_b=$!

wait "$pid_a" || { echo "FAIL: $TEST_NAME - the first run failed"; cat "$out_a"; exit 1; }
wait "$pid_b" || { echo "FAIL: $TEST_NAME - the second run failed"; cat "$out_b"; exit 1; }

if ! head -1 "$out_a" | grep -q '^200 ' || ! grep -q '^200 ' "$out_b"; then
    echo "FAIL: $TEST_NAME - a run did not render its page with a 200"
    cat "$out_a" "$out_b"
    exit 1
fi

if ! cat "$out_a" "$out_b" | grep -q 'Waiting for another rsx:debug to finish'; then
    echo "FAIL: $TEST_NAME - neither run reported waiting for the other"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
