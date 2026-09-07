#!/bin/bash
set -e

TEST_NAME="FPC Cache Behavior"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# HTTP integration test - runs against live web server and FPC proxy.
# No database switching needed; tests verify HTTP-level cache behavior.

echo "[SETUP] Preparing test..." >&2

FPC_PORT=3200
FPC_URL="http://localhost:$FPC_PORT"

# Verify FPC proxy is running
if ! curl -s -o /dev/null --connect-timeout 2 "$FPC_URL/ssr-test" 2>/dev/null; then
    echo "SKIP: $TEST_NAME - FPC proxy not running on port $FPC_PORT"
    exit 0
fi

# Clear all FPC cache entries before testing
redis-cli KEYS 'fpc:*' | xargs -r redis-cli DEL > /dev/null 2>&1

echo "[TEST] Running FPC cache assertions..." >&2

# Helper: get specific header value from response
get_header() {
    local url=$1
    local header=$2
    local extra_args=$3
    curl -s -D - $extra_args "$FPC_URL$url" 2>/dev/null | grep -i "^${header}:" | tr -d '\r' | head -1
}

# Helper: get just the header value after the colon
get_header_value() {
    local url=$1
    local header=$2
    local extra_args=$3
    get_header "$url" "$header" "$extra_args" | sed "s/^${header}: *//i"
}

# ---------------------------------------------------------------------------
# Test 1: First GET should be cache MISS
# ---------------------------------------------------------------------------
echo "[TEST] 1. Cache MISS on first request..." >&2
cache_status=$(get_header_value "/ssr-test" "X-FPC-Cache")
if [ "$cache_status" != "MISS" ]; then
    echo "FAIL: $TEST_NAME - Expected X-FPC-Cache: MISS, got: '$cache_status'"
    exit 1
fi
echo "[TEST] 1. OK - First request is MISS" >&2

# ---------------------------------------------------------------------------
# Test 2: Second GET should be cache HIT
# ---------------------------------------------------------------------------
echo "[TEST] 2. Cache HIT on second request..." >&2
cache_status=$(get_header_value "/ssr-test" "X-FPC-Cache")
if [ "$cache_status" != "HIT" ]; then
    echo "FAIL: $TEST_NAME - Expected X-FPC-Cache: HIT, got: '$cache_status'"
    exit 1
fi
echo "[TEST] 2. OK - Second request is HIT" >&2

# ---------------------------------------------------------------------------
# Test 3: ETag present on cached response
# ---------------------------------------------------------------------------
echo "[TEST] 3. ETag header present..." >&2
etag=$(get_header_value "/ssr-test" "ETag")
if [ -z "$etag" ]; then
    echo "FAIL: $TEST_NAME - No ETag header on cached response"
    exit 1
fi
echo "[TEST] 3. OK - ETag present: $etag" >&2

# ---------------------------------------------------------------------------
# Test 4: If-None-Match with correct ETag returns 304
# ---------------------------------------------------------------------------
echo "[TEST] 4. 304 Not Modified with matching ETag..." >&2
status_code=$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $etag" "$FPC_URL/ssr-test" 2>/dev/null)
if [ "$status_code" != "304" ]; then
    echo "FAIL: $TEST_NAME - Expected 304, got: $status_code"
    exit 1
fi
echo "[TEST] 4. OK - 304 with matching ETag" >&2

# ---------------------------------------------------------------------------
# Test 5: If-None-Match with wrong ETag returns 200
# ---------------------------------------------------------------------------
echo "[TEST] 5. 200 with non-matching ETag..." >&2
status_code=$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: wrong_etag_value" "$FPC_URL/ssr-test" 2>/dev/null)
if [ "$status_code" != "200" ]; then
    echo "FAIL: $TEST_NAME - Expected 200 with wrong ETag, got: $status_code"
    exit 1
fi
echo "[TEST] 5. OK - 200 with non-matching ETag" >&2

# ---------------------------------------------------------------------------
# Test 6: POST request bypasses cache (passthrough)
# ---------------------------------------------------------------------------
echo "[TEST] 6. POST request bypasses cache..." >&2
cache_header=$(get_header "/ssr-test" "X-FPC-Cache" "-X POST")
if [ -n "$cache_header" ]; then
    echo "FAIL: $TEST_NAME - POST request got FPC cache header: $cache_header"
    exit 1
fi
echo "[TEST] 6. OK - POST bypasses cache" >&2

# ---------------------------------------------------------------------------
# Test 7: Request with session cookie bypasses cache
# ---------------------------------------------------------------------------
echo "[TEST] 7. Session cookie bypasses cache..." >&2
cache_header=$(get_header "/ssr-test" "X-FPC-Cache" "-b rsx=fake_session_token")
if [ -n "$cache_header" ]; then
    echo "FAIL: $TEST_NAME - Request with session cookie got FPC header: $cache_header"
    exit 1
fi
echo "[TEST] 7. OK - Session cookie bypasses cache" >&2

# ---------------------------------------------------------------------------
# Test 8: HEAD request reads cache but doesn't populate it
# ---------------------------------------------------------------------------
echo "[TEST] 8. HEAD request reads from cache..." >&2
head_cache=$(curl -sI "$FPC_URL/ssr-test" 2>/dev/null | grep -i "^X-FPC-Cache:" | tr -d '\r' | sed 's/^X-FPC-Cache: *//i')
if [ "$head_cache" != "HIT" ]; then
    echo "FAIL: $TEST_NAME - HEAD on cached URL expected HIT, got: '$head_cache'"
    exit 1
fi
echo "[TEST] 8. OK - HEAD reads from cache" >&2

# ---------------------------------------------------------------------------
# Test 9: HEAD request does NOT populate cache for uncached URL
# ---------------------------------------------------------------------------
echo "[TEST] 9. HEAD does not populate cache..." >&2
curl -sI "$FPC_URL/ssr-test?_nocache_test=1" > /dev/null 2>&1
# Now GET should be MISS (HEAD didn't populate)
cache_status=$(get_header_value "/ssr-test?_nocache_test=1" "X-FPC-Cache")
if [ "$cache_status" != "MISS" ]; then
    echo "FAIL: $TEST_NAME - HEAD populated cache (GET was $cache_status instead of MISS)"
    exit 1
fi
echo "[TEST] 9. OK - HEAD does not populate cache" >&2

# ---------------------------------------------------------------------------
# Test 10: Non-FPC route does NOT get cached
# ---------------------------------------------------------------------------
echo "[TEST] 10. Non-FPC route not cached..." >&2
curl -s -o /dev/null "$FPC_URL/ssr-test-csr" 2>/dev/null
cache_header=$(get_header "/ssr-test-csr" "X-FPC-Cache")
if [ -n "$cache_header" ]; then
    echo "FAIL: $TEST_NAME - Non-FPC route got cache header: $cache_header"
    exit 1
fi
echo "[TEST] 10. OK - Non-FPC route not cached" >&2

# ---------------------------------------------------------------------------
# Test 11: X-RSpade-FPC internal header NOT leaked to client
# ---------------------------------------------------------------------------
echo "[TEST] 11. Internal FPC header not leaked..." >&2
internal_header=$(get_header "/ssr-test" "X-RSpade-FPC")
if [ -n "$internal_header" ]; then
    echo "FAIL: $TEST_NAME - Internal X-RSpade-FPC header leaked: $internal_header"
    exit 1
fi
echo "[TEST] 11. OK - Internal header stripped" >&2

# ---------------------------------------------------------------------------
# Test 12: Cached response has correct Content-Type
# ---------------------------------------------------------------------------
echo "[TEST] 12. Cached response Content-Type..." >&2
content_type=$(get_header_value "/ssr-test" "Content-Type")
if ! echo "$content_type" | grep -qi "text/html"; then
    echo "FAIL: $TEST_NAME - Cached response Content-Type not text/html: $content_type"
    exit 1
fi
echo "[TEST] 12. OK - Content-Type is text/html" >&2

# ---------------------------------------------------------------------------
# Test 13: Build key invalidation
# ---------------------------------------------------------------------------
echo "[TEST] 13. Build key invalidation..." >&2
old_etag=$(get_header_value "/ssr-test" "ETag")

# Force manifest rebuild (changes build key)
cd /var/www/html
php artisan rsx:manifest:build --force > /dev/null 2>&1

# Wait for proxy to detect build key change
sleep 1

# Next request should be MISS (new build key = new cache key)
cache_status=$(get_header_value "/ssr-test" "X-FPC-Cache")
if [ "$cache_status" != "MISS" ]; then
    echo "FAIL: $TEST_NAME - After build key change, expected MISS, got: '$cache_status'"
    exit 1
fi
echo "[TEST] 13. OK - Build key change invalidates cache" >&2

echo "PASS: $TEST_NAME"
exit 0
