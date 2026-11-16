#!/bin/bash
#
# rspade-wait-started: block until this container has finished starting.
#
#     docker compose up --detach && docker compose exec <service> rspade-wait-started
#
# Exit 0 once the start finished, 1 with the entrypoint's error when it failed, 2 when the
# container was not started by the RSpade entrypoint. No deadline. startup-state.sh
# (rspade-startup) has the whole contract.
exec bash /usr/local/bin/rspade-startup wait "$@"
