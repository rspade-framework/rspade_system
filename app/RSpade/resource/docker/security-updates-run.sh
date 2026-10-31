#!/bin/bash
#
# Supervisor runner for the scheduled security update check.
#
# Every RSPADE_SECURITY_UPDATE_HOURS hours (4), at a random point within 10 minutes either
# side of the slot, runs `rspade-security-update scheduled` - which installs the security
# updates this container may take and reloads php-fpm when anything was installed. The
# container start runs the same check before PHP serves anything (see the entrypoint);
# this keeps a long-running container current between starts.
#
# A SUPERVISOR PROGRAM, NOT CRON, for the reason task-run.sh gives: the image has no cron
# daemon, and a program here is visible in `supervisorctl status` and logs to the
# container log like every other service.
#
# THE SLOTS are the multiples of the interval on the UTC clock (00:00, 04:00, ...), so a
# fleet spreads its checks only by the jitter - enough to keep a fleet from arriving at the
# PHP PPA in the same second. The sleep is the cadence, not a timeout: the check itself is
# never interrupted.
#
# OFF: RSX_AUTO_SECURITY_UPDATES=0 (false/no/off) idles this program for the life of the
# container, saying so once.
#
#     rspade-security-updates-run plan <now> <random>
#
# prints the epoch second the next check would run at, for a given clock and a given
# random draw - the scheduling rule alone, for a test.
#
set -u

HOURS="${RSPADE_SECURITY_UPDATE_HOURS:-4}"
JITTER_SECONDS=600
interval=$((HOURS * 3600))

# The next check after <now>: the next slot, moved by <random> within the jitter either
# side. A negative move can land before <now> just after a slot passes; the slot after
# that is taken instead.
next_target() {
    local now="$1" random="$2" slot target
    slot=$(( (now / interval + 1) * interval ))
    target=$(( slot + (random % (2 * JITTER_SECONDS + 1)) - JITTER_SECONDS ))

    if [ "$target" -le "$now" ]; then
        target=$(( target + interval ))
    fi

    echo "$target"
}

if [ "${1:-}" = plan ]; then
    next_target "$2" "$3"
    exit 0
fi

case "$(printf '%s' "${RSX_AUTO_SECURITY_UPDATES:-1}" | tr '[:upper:]' '[:lower:]')" in
    0|false|no|off)
        echo "[security-updates] automatic security updates are off (RSX_AUTO_SECURITY_UPDATES=${RSX_AUTO_SECURITY_UPDATES}); idling."
        exec sleep infinity
        ;;
esac

echo "[security-updates] checking every ${HOURS}h, within ${JITTER_SECONDS}s of the slot."

while true; do
    now=$(date +%s)
    target=$(next_target "$now" "$RANDOM")
    sleep $(( target - now ))
    bash /usr/local/bin/rspade-security-update scheduled
done
