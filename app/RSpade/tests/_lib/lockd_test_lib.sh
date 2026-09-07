#!/bin/bash
#
# Shared shell harness for the rsx-lockd tests (tests/locks/http and tests/locks/cli).
#
# ISOLATION IS THE WHOLE POINT. Every test that needs a live daemon starts ITS OWN on a
# scratch port with a temp config and kills it from an EXIT trap. The supervised daemon
# (the one PHP talks to on the configured port) is never connected to, never signalled,
# and never restarted - a lock test that disturbed the box's real lock server would be
# worse than no lock test at all.
#
# Usage:
#     source "$(dirname "${BASH_SOURCE[0]}")/../../_lib/lockd_test_lib.sh"
#     lockd_skip_unless_available "$TEST_NAME"
#     trap lockd_stop EXIT
#     lockd_start 6291 || exit 1
#     node "$harness"                       # LOCKD_PORT / LOCKD_DIR are exported for it
#
# Every function is deliberately quiet on success (unix philosophy); failures print an
# [ERROR] line naming what went wrong.

LOCKD_DIR="/var/www/html/system/bin/rsx-lockd"
LOCKD_PROJECT_ROOT="/var/www/html"
# The node-side helper lives under a `resource/` dir on purpose: that basename is excluded
# from manifest scanning, and a module.exports file anywhere the JS indexer looks is a build
# error (JS sources are concatenated, not required). Same reason js_transform keeps its own
# harness there.
LOCKD_HARNESS_LIB="/var/www/html/system/app/RSpade/tests/locks/resource/lockd_test_harness.js"
export LOCKD_DIR LOCKD_PROJECT_ROOT LOCKD_HARNESS_LIB

# The port the SUPERVISED daemon owns. Never bound by a test, at any cost.
LOCKD_LIVE_PORT=6210

LOCKD_TMP=""
LOCKD_PORT=""
LOCKD_CONF=""
LOCKD_BG_PID=""

# node, the daemon itself, and the shared HMAC key are all preconditions rather than
# assertions: a box without them cannot run this test, and saying so is not a failure.
lockd_skip_unless_available() {
    local test_name="$1"

    if ! command -v node >/dev/null 2>&1; then
        echo "SKIP: $test_name - node not available"
        exit 0
    fi
    if [ ! -f "$LOCKD_DIR/lockd.js" ]; then
        echo "SKIP: $test_name - rsx-lockd is not installed at $LOCKD_DIR"
        exit 0
    fi
    if ! grep -q '^APP_KEY=.' "$LOCKD_PROJECT_ROOT/.env" 2>/dev/null; then
        echo "SKIP: $test_name - APP_KEY not set in .env"
        exit 0
    fi
}

# True when NOTHING is listening on the port. /dev/tcp keeps this dependency-free.
lockd_port_free() {
    ! (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null
}

# First free port at or above the preferred one, so two of these tests running back to
# back (or a leftover socket in TIME_WAIT) cannot collide.
lockd_pick_port() {
    local port="$1"
    local limit=$((port + 40))

    while [ "$port" -lt "$limit" ]; do
        if [ "$port" -ne "$LOCKD_LIVE_PORT" ] && lockd_port_free "$port"; then
            echo "$port"
            return 0
        fi
        port=$((port + 1))
    done

    return 1
}

# Start a scratch daemon. Sets LOCKD_TMP / LOCKD_PORT / LOCKD_CONF / LOCKD_BG_PID and
# exports LOCKD_PORT, LOCKD_DIR, LOCKD_PROJECT_ROOT for the node harness.
lockd_start() {
    local preferred="${1:-6291}"

    LOCKD_TMP="$(mktemp -d /tmp/lockd-test-XXXXXX)"
    LOCKD_PORT="$(lockd_pick_port "$preferred")"

    if [ -z "$LOCKD_PORT" ]; then
        echo "[ERROR] no free scratch port at or above $preferred"
        return 1
    fi
    if [ "$LOCKD_PORT" = "$LOCKD_LIVE_PORT" ]; then
        echo "[ERROR] refusing to bind the supervised daemon's port $LOCKD_LIVE_PORT"
        return 1
    fi

    LOCKD_CONF="$LOCKD_TMP/lockd.conf"
    cat > "$LOCKD_CONF" <<EOF
{
  "unix":   { "enabled": false, "path": "$LOCKD_TMP/lockd.sock", "mode": "0660" },
  "tcp":    { "enabled": true,  "port": $LOCKD_PORT, "bind": "127.0.0.1" },
  "auth":   { "hmac_key_env": "APP_KEY" },
  "pidfile": "$LOCKD_TMP/lockd.pid",
  "logfile": "$LOCKD_TMP/lockd.log"
}
EOF

    node "$LOCKD_DIR/lockd.js" run --config="$LOCKD_CONF" >"$LOCKD_TMP/stdout.log" 2>&1 &
    LOCKD_BG_PID=$!

    # The pidfile is written AFTER a successful bind, so a listening port plus a pidfile
    # is proof the daemon is actually serving - not merely that node started.
    local attempt=0
    while [ "$attempt" -lt 100 ]; do
        if ! lockd_port_free "$LOCKD_PORT" && [ -s "$LOCKD_TMP/lockd.pid" ]; then
            export LOCKD_PORT LOCKD_DIR LOCKD_PROJECT_ROOT
            return 0
        fi
        if ! kill -0 "$LOCKD_BG_PID" 2>/dev/null; then
            break
        fi
        sleep 0.1
        attempt=$((attempt + 1))
    done

    echo "[ERROR] the scratch daemon did not come up on port $LOCKD_PORT"
    cat "$LOCKD_TMP/stdout.log" 2>/dev/null
    cat "$LOCKD_TMP/lockd.log" 2>/dev/null
    return 1
}

# Idempotent teardown, safe as an EXIT trap even when lockd_start never ran.
lockd_stop() {
    local status=$?

    if [ -n "$LOCKD_BG_PID" ]; then
        kill "$LOCKD_BG_PID" 2>/dev/null
        local attempt=0
        while [ "$attempt" -lt 30 ] && kill -0 "$LOCKD_BG_PID" 2>/dev/null; do
            sleep 0.1
            attempt=$((attempt + 1))
        done
        kill -9 "$LOCKD_BG_PID" 2>/dev/null
        wait "$LOCKD_BG_PID" 2>/dev/null
        LOCKD_BG_PID=""
    fi

    if [ -n "$LOCKD_TMP" ] && [ -d "$LOCKD_TMP" ]; then
        rm -rf "$LOCKD_TMP"
        LOCKD_TMP=""
    fi

    return $status
}
