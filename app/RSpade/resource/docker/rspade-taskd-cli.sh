#!/bin/bash
# rspade-taskd - the operator's command for the task scheduler this container runs.
#
#     rspade-taskd status    the last tick, its outcome, and when the next one runs
#     rspade-taskd poke      ask for a tick now (the application pokes by itself)
#
# Installed as /usr/local/bin/rspade-taskd. It is `node /opt/rspade/rspade-taskd/rspade-taskd.js
# "$@"` and nothing else: the daemon's own CLI, which talks to the running daemon's socket.
exec node /opt/rspade/rspade-taskd/rspade-taskd.js "$@"
