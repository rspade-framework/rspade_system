#!/bin/bash
#
# Echo the container's logs onto `docker logs`.
#
#     rspade-log-to-docker                  the log-to-docker supervisor program
#     rspade-log-to-docker filter-laravel   stdin -> stdout: the Laravel log filter alone
#
# RSPADE_LOG_TO_DOCKER=1 (or true/yes/on) in the container's environment turns it on; off by
# default, and then the program idles for the life of the container (supervisor would
# restart a program that exits). Docker's default json-file log driver never rotates, so
# a container echoing its access log should run with `--log-driver local` (or the compose
# `logging: driver: local`).
#
# WHAT IS ECHOED, as each line is written (nothing already in the files is replayed):
#
#   - [nginx] + every line of /var/log/nginx/access.log, on stdout - one line per request,
#     under the real client;
#   - [laravel] + every ERROR, CRITICAL, ALERT and EMERGENCY entry of the Laravel log
#     (<logs dir>/laravel.log), with the lines that continue it (its stack trace) up to the
#     next entry, on stderr. Lower levels are left in the file.
#
# The logs directory is <storage root>/logs, resolved through system/bin/lib/rsx_paths.sh
# exactly as PHP resolves it (RSX_STORAGE_PATH honoured). A log that does not exist yet is
# followed from the moment it appears, and a rotated one by name (tail -F).
#
set -u

APP_DIR=/var/www/html
NGINX_ACCESS_LOG=/var/log/nginx/access.log

# The Laravel filter. An entry starts `[<date> <time>] <channel>.<LEVEL>: ` (the channel is
# the environment name); every other line continues the entry before it. Entries at the
# four serious levels pass, with their continuation lines; the rest are dropped. A line
# before the first entry belongs to an entry that started before the filter did and is
# dropped too. A bash read loop, not awk: Ubuntu's awk (mawk) buffers what it reads from a
# pipe, so a live stream sat unprocessed; read handles each line as it arrives.
filter_laravel() {
    local line pass=0
    local entry='^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][^]]*\] [^ ]+\.([A-Z]+): '
    while IFS= read -r line; do
        if [[ $line =~ $entry ]]; then
            case "${BASH_REMATCH[1]}" in
                ERROR|CRITICAL|ALERT|EMERGENCY) pass=1 ;;
                *) pass=0 ;;
            esac
        fi
        if [ "$pass" = 1 ]; then
            printf '[laravel] %s\n' "$line"
        fi
    done
}

if [ "${1:-}" = filter-laravel ]; then
    filter_laravel
    exit 0
fi

case "$(printf '%s' "${RSPADE_LOG_TO_DOCKER:-0}" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|on) ;;
    *) exec sleep infinity ;;
esac

if [ -f "$APP_DIR/system/bin/lib/rsx_paths.sh" ]; then
    RSX_PATHS_PROJECT_ROOT_DIR="$APP_DIR"
    . "$APP_DIR/system/bin/lib/rsx_paths.sh"
    logs_dir="$(rsx_storage_root)/logs"
else
    logs_dir="$APP_DIR/storage/logs"
fi
laravel_log="$logs_dir/laravel.log"

echo "[log-to-docker] echoing $NGINX_ACCESS_LOG and the errors in $laravel_log."

# tail's own notices (a file not there yet, a file replaced) are not log lines.
tail -n 0 -F "$NGINX_ACCESS_LOG" 2>/dev/null | sed -u 's/^/[nginx] /' &
tail -n 0 -F "$laravel_log" 2>/dev/null | filter_laravel >&2 &

# Either follower ending is a fault: exit, and the runner starts both again.
wait -n
echo "[log-to-docker] a log follower stopped; restarting." >&2
exit 1
