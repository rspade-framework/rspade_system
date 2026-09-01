#!/usr/bin/env python3
"""RSpade development SMTP catcher.

Listens on 127.0.0.1:1025 and files every accepted message into a Maildir. Nothing
leaves the box: this is the transport a fresh install is pointed at, so a developer
who has configured nothing can still send mail and read it back with `cat`.

THERE IS NO WEB UI, DELIBERATELY. Mailpit was the obvious alternative and was rejected
for exactly that reason: it cannot run without its HTTP listener, and a loopback HTTP
service inside the container is reachable by anything that can make the application
fetch a URL. This program opens ONE port, SMTP, on loopback.

WHY THIS FILE EXISTS INSTEAD OF `python3 -m aiosmtpd`. The framework's `aiosmtpd`
delivery mode VERIFIES the server's SMTP greeting before it trusts the connection: a
catcher that does not say `aiosmtpd` might be a real relay somebody pointed the box at
by accident, and mail meant for a Maildir would reach real people. aiosmtpd's own CLI
advertises `Python SMTP <version>` and exposes no flag to change it (checked against
1.4.4's `--help`), so the greeting is set here, where the Controller's SMTP keyword
arguments are reachable.

Supervisor owns the lifecycle: this runs in the foreground, does not daemonize, and
writes no pidfile.

Usage: python3 mail_catcher.py <maildir> [host] [port]
"""

import os
import sys
import threading

import aiosmtpd
from aiosmtpd.controller import Controller
from aiosmtpd.handlers import Mailbox

# The substring App\RSpade\Core\Mail\Rsx_Mail_Transport::probe_banner() looks for.
# Changing the word `aiosmtpd` here breaks that check; the two are one contract.
IDENT = "aiosmtpd {0} (RSpade dev mail catcher)".format(aiosmtpd.__version__)

DEFAULT_HOST = "127.0.0.1"
DEFAULT_PORT = 1025


def main(argv):
    if len(argv) < 2:
        sys.stderr.write("usage: mail_catcher.py <maildir> [host] [port]\n")
        return 2

    maildir = argv[1]
    host = argv[2] if len(argv) > 2 else DEFAULT_HOST
    port = int(argv[3]) if len(argv) > 3 else DEFAULT_PORT

    # The Mailbox handler opens the Maildir but does not create the parent path.
    os.makedirs(maildir, exist_ok=True)

    controller = Controller(
        Mailbox(maildir),
        hostname=host,
        port=port,
        ident=IDENT,
    )
    controller.start()

    sys.stderr.write(
        "mail-catcher listening on {0}:{1}, filing into {2} (ident: {3})\n".format(
            host, port, maildir, IDENT
        )
    )
    sys.stderr.flush()

    # The controller serves on its own thread. Block this one forever - supervisor
    # stops the program, and nothing here decides that the job is done.
    threading.Event().wait()

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
