#!/bin/bash
# proxority - the operator's command for the Proxority proxy this container runs.
#
#     proxority status            the ledgers, the queues, the whitelist
#     proxority log               the event ring, oldest first
#     proxority log --follow      the ring, then every new event as it is logged
#
# Installed as /usr/local/bin/proxority. It is `node /opt/rspade/proxority/proxority.js "$@"`
# and nothing else: the proxy's own CLI, which reads the running proxy's status socket.
exec node /opt/rspade/proxority/proxority.js "$@"
