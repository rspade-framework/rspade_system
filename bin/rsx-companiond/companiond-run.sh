#!/usr/bin/env bash
#
# Supervisor entry point for rsx-companiond.
#
# Invoke with an explicit interpreter, never by relying on the exec bit:
#   command=bash /usr/local/bin/rspade-retry-run rsx-companiond bash /var/www/html/system/bin/rsx-companiond/companiond-run.sh
#
# Supervisor runs it under rspade-retry-run, which restarts a daemon that died and passes a
# stop signal on, waiting for the daemon to exit. This script ends in exec, so the pid the
# runner signals and waits for IS the daemon, and a stop waits for the children.
#
# It waits for the entry file because system/ is delivered separately from the image: exiting
# would walk supervisor's FATAL path and leave the program down until somebody noticed.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENTRY="$SCRIPT_DIR/companiond.js"

NODE_BIN="${NODE_BIN:-}"
if [ -z "$NODE_BIN" ]; then
    NODE_BIN="$(command -v node 2>/dev/null || true)"
fi
if [ -z "$NODE_BIN" ] && [ -x /usr/bin/node ]; then
    NODE_BIN=/usr/bin/node
fi

if [ -z "$NODE_BIN" ]; then
    echo "[companiond-run] [ERROR] node not found on PATH and /usr/bin/node is not executable" >&2
    exit 1
fi

waited=0
while [ ! -f "$ENTRY" ]; do
    if [ "$((waited % 30))" -eq 0 ]; then
        echo "[companiond-run] Waiting for $ENTRY to appear (framework files not delivered yet)" >&2
    fi
    sleep 2
    waited=$((waited + 1))
done

exec "$NODE_BIN" "$ENTRY" run "$@"
