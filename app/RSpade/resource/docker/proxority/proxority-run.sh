#!/usr/bin/env bash
#
# Supervisor entry point for Proxority.
#
# Invoke with an explicit interpreter, never by relying on the exec bit:
#   command=bash /opt/rspade/proxority/proxority-run.sh
#
# Arguments are passed through to `proxority.js` (serve mode). The script ends in exec, so
# supervisor's pid IS the proxy: a stop signal reaches node directly, and SIGHUP (re-read the
# pool sizes) does too.
#
# The proxy is part of the IMAGE (/opt/rspade/proxority), so it starts whether or not an
# RSpade checkout is mounted at /var/www/html: it reads nothing from the checkout except the
# answer of its periodic whitelist refresh, and a refresh that fails (no checkout, no artisan)
# keeps the empty list - every request throttled - and is retried on the next interval.

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

exec "$NODE_BIN" "$ENTRY" "$@"
