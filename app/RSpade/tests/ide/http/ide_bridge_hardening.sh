#!/bin/bash
set -e

TEST_NAME="IDE bridge hardening"

# HTTP integration test - runs against the live web server (development mode only: the
# bridge exists nowhere else). Pinned:
#
#   1. The X-Ide-Token grant is ALWAYS required: a tokenless request from loopback with
#      Host: localhost over http - the shape the retired loopback exemption admitted - is 401.
#   2. The services that write or run git (format, refactor, git, git/diff) and
#      manifest_build refuse GET with 405, so a page in the developer's browser cannot
#      reach them with a navigation.
#   3. git/diff refuses a path that would read as a git option ('--output=...') and a path
#      that climbs out of the project, and the option never writes its file.

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

status_of() {
    curl -s -o /dev/null -w '%{http_code}' "$@"
}

# 1. No exemption for loopback.
got="$(status_of -H 'Host: localhost' "$BASE/_ide/service/health")"
if [ "$got" != "401" ]; then
    echo "FAIL: $TEST_NAME - a tokenless loopback request answered $got (expected 401)"
    exit 1
fi
echo "[TEST] 1. OK - tokenless loopback request refused" >&2

got="$(status_of -H "X-Ide-Token: $TOKEN" "$BASE/_ide/service/health")"
if [ "$got" != "200" ]; then
    echo "FAIL: $TEST_NAME - a request carrying the grant answered $got (expected 200)"
    exit 1
fi

# 2. The mutating services are POST-only.
for service in format refactor git git/diff manifest_build; do
    got="$(status_of -H "X-Ide-Token: $TOKEN" "$BASE/_ide/service/$service")"
    if [ "$got" != "405" ]; then
        echo "FAIL: $TEST_NAME - GET $service answered $got (expected 405)"
        exit 1
    fi
done
echo "[TEST] 2. OK - GET refused on every mutating service" >&2

# 3. git/diff paths.
OUT_FILE="/tmp/ide_bridge_diff_probe_$$"
rm -f "$OUT_FILE"
post_diff() {
    curl -s -X POST -H "X-Ide-Token: $TOKEN" -H 'Content-Type: application/json' \
        --data "{\"file\":\"$1\"}" "$BASE/_ide/service/git/diff"
}

body="$(post_diff "--output=$OUT_FILE")"
case "$body" in
    *'Invalid file path'*) ;;
    *)
        echo "FAIL: $TEST_NAME - an option-shaped path was not refused: $body"
        exit 1
        ;;
esac
if [ -e "$OUT_FILE" ]; then
    rm -f "$OUT_FILE"
    echo "FAIL: $TEST_NAME - the --output option wrote its file"
    exit 1
fi

for climb in "../html2/x.php" "../../etc/passwd" "rsx/../../outside.php"; do
    body="$(post_diff "$climb")"
    case "$body" in
        *'Invalid file path'*) ;;
        *)
            echo "FAIL: $TEST_NAME - a path outside the project was not refused ($climb): $body"
            exit 1
            ;;
    esac
done

body="$(post_diff "system/artisan")"
case "$body" in
    *'"success":true'*) ;;
    *)
        echo "FAIL: $TEST_NAME - a project path was refused: $body"
        exit 1
        ;;
esac
echo "[TEST] 3. OK - option and climbing paths refused, a project path served" >&2

echo "PASS: $TEST_NAME"
exit 0
