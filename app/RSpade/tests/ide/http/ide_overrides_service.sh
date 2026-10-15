#!/bin/bash
set -e

TEST_NAME="IDE bridge overrides service"

# HTTP integration test - runs against the live web server (development mode only: the
# bridge exists nowhere else). Pinned:
#
#   1. /_ide/service/overrides requires the X-Ide-Token grant like every bridge service: a
#      tokenless request is 401.
#   2. With the grant it answers 200 and {"success": true, "overrides": {...}} - an OBJECT
#      even when empty (the extension reads it as a path-keyed map), every key a
#      project-relative framework path under system/ and every value {kind, name, by} with
#      `by` under rsx/.
#   3. It is a read service: GET is served.
#
# The map this box carries is whatever its rsx/ declares; the template declares no override,
# so on this tree the per-entry checks see an empty map. Nothing here writes an override into
# the real tree.

BASE="http://localhost"
SYSTEM_DIR="$(cd "$(dirname "$0")/../../../../.." && pwd)"

if ! curl -s -o /dev/null --connect-timeout 2 "$BASE/" 2>/dev/null; then
    echo "SKIP: $TEST_NAME - web server not reachable on localhost"
    exit 0
fi

MODE="$(cd "$SYSTEM_DIR" && php -r '$app = require "script.php"; echo \App\RSpade\Core\Rsx::is_development() ? "dev" : "sealed";' 2>/dev/null | tail -1)"
if [ "$MODE" != "dev" ]; then
    echo "SKIP: $TEST_NAME - the IDE bridge exists in development mode only"
    exit 0
fi

TOKEN="$(cd "$SYSTEM_DIR" && php -r '$app = require "script.php"; \App\RSpade\Core\Ide\Ide_Bridge_Token::ensure_grant_store(); echo \App\RSpade\Core\Ide\Ide_Bridge_Token::current_token();' 2>/dev/null | tail -1)"
if [ -z "$TOKEN" ]; then
    echo "FAIL: $TEST_NAME - no IDE grant token could be established"
    exit 1
fi

# 1. The grant is required.
got="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost' "$BASE/_ide/service/overrides")"
if [ "$got" != "401" ]; then
    echo "FAIL: $TEST_NAME - a tokenless request answered $got (expected 401)"
    exit 1
fi
echo "[TEST] 1. OK - tokenless request refused" >&2

# 2 + 3. GET with the grant: status and shape.
BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

got="$(curl -s -o "$BODY_FILE" -w '%{http_code}' -H "X-Ide-Token: $TOKEN" "$BASE/_ide/service/overrides")"
if [ "$got" != "200" ]; then
    echo "FAIL: $TEST_NAME - GET with the grant answered $got (expected 200): $(head -c 300 "$BODY_FILE")"
    exit 1
fi

verdict="$(php -r '
    $raw = file_get_contents($argv[1]);
    $doc = json_decode($raw);
    if (!is_object($doc) || ($doc->success ?? null) !== true) { echo "not {success: true}: " . substr($raw, 0, 300); exit; }
    if (!isset($doc->overrides) || !is_object($doc->overrides)) { echo "overrides is not a JSON object: " . substr($raw, 0, 300); exit; }
    foreach ((array) $doc->overrides as $path => $record) {
        if (!str_starts_with($path, "system/app/RSpade/")) { echo "key is not a project-relative framework path: $path"; exit; }
        if (!isset($record->kind, $record->name, $record->by)) { echo "entry lacks kind/name/by: $path"; exit; }
        if (!str_starts_with($record->by, "rsx/")) { echo "by is not under rsx/: $record->by"; exit; }
    }
    echo "ok";
' "$BODY_FILE")"

if [ "$verdict" != "ok" ]; then
    echo "FAIL: $TEST_NAME - $verdict"
    exit 1
fi
echo "[TEST] 2. OK - {success: true, overrides: {...}} with project-relative paths" >&2

echo "PASS: $TEST_NAME"
exit 0
