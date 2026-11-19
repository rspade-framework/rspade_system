#!/bin/bash
# t3: log-to-docker.sh - the echo of the container's logs onto `docker logs`.
#
# The Laravel filter is driven through `filter-laravel` (stdin -> stdout) with sample log
# text: the four serious levels pass with their continuation lines (the stack trace), lower
# levels and lines before the first entry are dropped, and every passed line is prefixed.
# The off switch is checked in the source: idle, never exit (supervisor would restart it).
# Following the live files needs a running container (see the catalog).

TEST_NAME="docker/cli t3 log to docker"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(cd "$DIR/../../../resource/docker" && pwd)"
SCRIPT="$DOCKER_DIR/log-to-docker.sh"

fail() { echo "FAIL: $TEST_NAME - $*"; exit 1; }

input='#0 a trace line left from an entry before the filter started
[2026-11-19 12:00:00] production.INFO: a routine line
[2026-11-19 12:00:01] production.ERROR: Something broke {"exception":"[object] (RuntimeException(code: 0): Something broke at /var/www/html/rsx/x.php:3)
[stacktrace]
#0 /var/www/html/rsx/y.php(10): x()
#1 {main}
"}
[2026-11-19 12:00:02] local.WARNING: a warning
continued warning text
[2026-11-19 12:00:03] local.CRITICAL: critical one
[2026-11-19T12:00:04.123456+00:00] testing.DEBUG: debug [with] brackets
[2026-11-19 12:00:05] production.NOTICE: message mentioning ] fake.ERROR: inside
[2026-11-19 12:00:06] production.ALERT: alert
[2026-11-19T12:00:07.000000+00:00] production.EMERGENCY: emergency
  its continuation'

expected='[laravel] [2026-11-19 12:00:01] production.ERROR: Something broke {"exception":"[object] (RuntimeException(code: 0): Something broke at /var/www/html/rsx/x.php:3)
[laravel] [stacktrace]
[laravel] #0 /var/www/html/rsx/y.php(10): x()
[laravel] #1 {main}
[laravel] "}
[laravel] [2026-11-19 12:00:03] local.CRITICAL: critical one
[laravel] [2026-11-19 12:00:06] production.ALERT: alert
[laravel] [2026-11-19T12:00:07.000000+00:00] production.EMERGENCY: emergency
[laravel]   its continuation'

actual="$(printf '%s\n' "$input" | bash "$SCRIPT" filter-laravel)" || fail "filter-laravel exited non-zero"
[ "$actual" = "$expected" ] || fail "filter output differs:
--- expected
$expected
--- actual
$actual"

# The continuation of a dropped entry stays dropped.
printf '%s\n' '[2026-11-19 12:00:00] production.INFO: x' 'tail of x' | bash "$SCRIPT" filter-laravel | grep -q . \
    && fail "a continuation of a dropped entry was echoed"

grep -q 'exec sleep infinity' "$SCRIPT" || fail "the program no longer idles when switched off"

echo "PASS: $TEST_NAME"
exit 0
