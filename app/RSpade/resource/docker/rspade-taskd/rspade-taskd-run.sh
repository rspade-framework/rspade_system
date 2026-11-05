#!/usr/bin/env bash
#
# Supervisor entry point for rspade-taskd, the container's task scheduler.
#
# Invoke with an explicit interpreter, never by relying on the exec bit:
#   command=bash /opt/rspade/rspade-taskd/rspade-taskd-run.sh
#
# Arguments are passed through to `rspade-taskd.js` (serve mode). The script ends in exec, so
# supervisor's pid IS the daemon: a stop signal reaches node directly, and node lets a tick in
# progress finish before it exits.
#
# The daemon is part of the IMAGE (/opt/rspade/rspade-taskd), so it starts whether or not an
# RSpade checkout is mounted at /var/www/html. With no checkout every tick fails, and the
# daemon keeps asking quietly until one succeeds - exactly as the container's services coming
# up after a start look to it.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENTRY="$SCRIPT_DIR/rspade-taskd.js"

NODE_BIN="${NODE_BIN:-}"
if [ -z "$NODE_BIN" ]; then
    NODE_BIN="$(command -v node 2>/dev/null || true)"
fi
if [ -z "$NODE_BIN" ] && [ -x /usr/bin/node ]; then
    NODE_BIN=/usr/bin/node
fi

if [ -z "$NODE_BIN" ]; then
    echo "[rspade-taskd-run] [ERROR] node not found on PATH and /usr/bin/node is not executable" >&2
    exit 1
fi

exec "$NODE_BIN" "$ENTRY" "$@"
