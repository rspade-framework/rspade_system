#!/usr/bin/env bash
#
# Supervisor entry point for Proxority.
#
# Invoke with an explicit interpreter, never by relying on the exec bit:
#   command=bash /var/www/html/system/bin/proxority/proxority-run.sh
#
# Arguments are passed through to `proxority.js` (serve mode). The script ends in exec, so
# supervisor's pid IS the proxy: a stop signal reaches node directly, and SIGHUP (re-read the
# pool sizes) does too.
#
# It waits for the entry file because system/ is delivered separately from the image: exiting
# would walk supervisor's FATAL path and leave the program down until somebody noticed.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENTRY="$SCRIPT_DIR/proxority.js"

NODE_BIN="${NODE_BIN:-}"
if [ -z "$NODE_BIN" ]; then
    NODE_BIN="$(command -v node 2>/dev/null || true)"
fi
if [ -z "$NODE_BIN" ] && [ -x /usr/bin/node ]; then
    NODE_BIN=/usr/bin/node
fi

if [ -z "$NODE_BIN" ]; then
    echo "[proxority-run] [ERROR] node not found on PATH and /usr/bin/node is not executable" >&2
    exit 1
fi

waited=0
while [ ! -f "$ENTRY" ]; do
    if [ "$((waited % 30))" -eq 0 ]; then
        echo "[proxority-run] Waiting for $ENTRY to appear (framework files not delivered yet)" >&2
    fi
    sleep 2
    waited=$((waited + 1))
done

exec "$NODE_BIN" "$ENTRY" "$@"
