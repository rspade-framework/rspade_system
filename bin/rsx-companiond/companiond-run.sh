#!/usr/bin/env bash
#
# Supervisor entry point for rsx-companiond.
#
# Invoke with an explicit interpreter, never by relying on the exec bit:
#   command=bash /var/www/html/system/bin/rsx-companiond/companiond-run.sh
#
# Never run this through the rspade-retry-run wrapper. That wrapper keeps a bash loop as
# supervisor's main pid, which exits at once on SIGTERM while the daemon is still stopping its
# children: supervisor would call the program stopped with the grace still running, stopwaitsecs
# would mean nothing, and a restart would start a second daemon beside the first. This script
# ends in exec, so supervisor's pid IS the daemon and a stop waits for the children.
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
