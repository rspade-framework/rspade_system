#!/bin/bash
# t4: entrypoint.sh - RSPADE_BUILD_MIGRATE_ON_START and the start order of the serving programs.
#
# The decision is two functions of the entrypoint, switch_is_on() and start_steps(), EXTRACTED
# VERBATIM and driven with every switch spelling, mode and image target: on, a development box
# builds then migrates, debug and production build with --force then migrate; off, nothing is
# built or migrated and pending migrations are reported - except that the dev image migrates a
# database that has never been migrated (a failure warned about); any other value refuses,
# naming the switch. The rest is checked in the source: the serving
# programs are autostart=false and the infrastructure is not, the entrypoint starts exactly
# that set, and it does so after the build-and-migrate step and before the start is recorded
# finished, with every switched-on failure stopping the start, and the trees a build or
# migrate wrote as root handed back to the application user before anything serves. The live start (docker logs
# showing the build and the migrate, requests refused until they finish) needs a running
# container (see the catalog).

TEST_NAME="docker/cli t4 build and migrate on start"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(cd "$DIR/../../../resource/docker" && pwd)"
ENTRYPOINT="$DOCKER_DIR/entrypoint.sh"
CONF_DIR="$DOCKER_DIR/supervisor/conf.d"

fail() { echo "FAIL: $TEST_NAME - $*"; exit 1; }

# A shell function of the entrypoint, from its opening line to its closing brace.
function_source() {
    awk -v name="$1" '$0 ~ "^" name "\\(\\) \\{" { on = 1 } on { print } on && /^\}$/ { exit }' "$ENTRYPOINT"
}

FUNCTIONS="$(function_source switch_is_on)
$(function_source start_steps)"
[ -n "$(function_source switch_is_on)" ] || fail "the entrypoint defines no switch_is_on()"
[ -n "$(function_source start_steps)" ] || fail "the entrypoint defines no start_steps()"

# steps <target> <mode> <value> [never migrated] : "<steps joined by spaces>|<exit status>|<stderr>"
steps() {
    local out err rc
    err="$(mktemp)"
    out="$(bash -c "$FUNCTIONS"$'\nstart_steps "$@"' _ "$1" "$2" "$3" "${4:-0}" 2>"$err")"
    rc=$?
    printf '%s|%s|%s' "$(printf '%s' "$out" | tr '\n' ' ' | sed 's/ $//')" "$rc" "$(cat "$err")"
    rm -f "$err"
}

expect() {
    local actual
    actual="$(steps "$1" "$2" "$3" "${5:-0}")"
    [ "$actual" = "$4" ] || fail "start_steps $1 $2 '$3' ${5:-0}: expected '$4', got '$actual'"
}

for on in 1 true TRUE yes Yes on ON; do
    expect dev  development "$on" "build migrate|0|"
    expect prod development "$on" "build migrate|0|"
    expect dev  production  "$on" "build-force migrate|0|"
    expect prod production  "$on" "build-force migrate|0|"
    expect prod debug       "$on" "build-force migrate|0|"
done

for off in '' 0 false False no NO off Off; do
    # Off: nothing is built or migrated - except a dev-image database never migrated.
    expect dev  development "$off" "pending|0|"
    expect dev  production  "$off" "pending|0|"
    expect prod production  "$off" "pending|0|"
    expect prod development "$off" "pending|0|"
    expect dev  development "$off" "migrate-new|0|" 1
    expect dev  production  "$off" "migrate-new|0|" 1
    expect prod production  "$off" "pending|0|" 1
done

# On: the new-database signal changes nothing - the switch already builds and migrates.
expect dev development 1 "build migrate|0|" 1

for bad in 2 ture enabled ' 1'; do
    expect prod production "$bad" "|2|RSPADE_BUILD_MIGRATE_ON_START is '$bad': it must be 1/0, true/false, yes/no or on/off."
done

# Each step runs the command its name promises, and a switched-on failure stops the start.
grep -q 'php system/artisan rsx:build \\$' "$ENTRYPOINT" || fail "build does not run rsx:build"
grep -q 'php system/artisan rsx:build --force \\$' "$ENTRYPOINT" || fail "build-force does not run rsx:build --force"
[ "$(grep -c 'No web service was started."' "$ENTRYPOINT")" = 3 ] \
    || fail "build, build-force and migrate must each die naming that no web service was started"
grep -q 'die "\$start_steps_said"' "$ENTRYPOINT" || fail "an invalid switch value does not stop the start"
grep -q 'migrate --force --dump-rollback' "$ENTRYPOINT" && fail "the switched-on migrate must not take --dump-rollback"

# The serving set: autostart=false; the infrastructure: autostart=true.
serving="$(sed -n 's/^SERVING_PROGRAMS="\(.*\)"$/\1/p' "$ENTRYPOINT")"
[ "$serving" = "php-fpm nginx proxority fpc-proxy realtime tasks rsx-companiond" ] \
    || fail "SERVING_PROGRAMS is '$serving'"
for program in $serving; do
    grep -qx 'autostart=false' "$CONF_DIR/$program.conf" || fail "$program.conf is not autostart=false"
done
for program in mysql redis rsx-lockd mail-catcher security-updates log-to-docker; do
    grep -qx 'autostart=true' "$CONF_DIR/$program.conf" || fail "$program.conf is not autostart=true"
done
for conf in "$CONF_DIR"/*.conf; do
    program="$(basename "$conf" .conf)"
    case " $serving mysql redis rsx-lockd mail-catcher security-updates log-to-docker " in
        *" $program "*) ;;
        *) fail "$program.conf is in neither the serving set nor the infrastructure: decide which" ;;
    esac
done

# The order: supervisor, the build and migrations, configure.sh, the serving programs, then
# the start is recorded finished.
line_of() { grep -n -m1 -F -- "$1" "$ENTRYPOINT" | cut -d: -f1; }
supervisor_line="$(line_of 'supervisord -c /etc/supervisor/supervisord.conf &')"
steps_line="$(line_of 'for start_step in $start_steps_said; do')"
configure_line="$(line_of 'bash "$APP_DIR/rsx/resource/docker/configure.sh"')"
serve_line="$(line_of 'supervisorctl start "$program"')"
started_line="$(line_of 'startup_record started')"
for name in supervisor_line steps_line configure_line serve_line started_line; do
    [ -n "${!name}" ] || fail "the entrypoint no longer has its ${name%_line} statement"
done
[ "$supervisor_line" -lt "$steps_line" ] || fail "the build and migrate step runs before supervisor"
[ "$steps_line" -lt "$configure_line" ] || fail "configure.sh runs before the build and migrate step"

# Ownership: one implementation, applied by step 5b and again after a step that wrote
# (build or migrate run as root), before configure.sh and the serving programs.
handover_lines="$(grep -n '^[[:space:]]*hand_over_app_trees$' "$ENTRYPOINT" | cut -d: -f1)"
[ "$(printf '%s\n' "$handover_lines" | grep -c .)" = 3 ] \
    || fail "hand_over_app_trees is called from the PUID branch, the prod branch and after step 10 (found lines: $handover_lines)"
last_handover="$(printf '%s\n' "$handover_lines" | tail -n1)"
[ "$steps_line" -lt "$last_handover" ] && [ "$last_handover" -lt "$configure_line" ] \
    || fail "the hand-over after the build and migrate step is not between it and configure.sh"
[ "$(grep -c 'find storage -mindepth 1 -maxdepth 1 ! -name mysql_data' "$ENTRYPOINT")" = 1 ] \
    || fail "the storage hand-over is written more than once"
grep -q '\[ "\$start_step" = pending \] || trees_written=1' "$ENTRYPOINT" \
    || fail "a pending-only start must not re-walk the trees, and every other step must"
[ "$configure_line" -lt "$serve_line" ] || fail "the serving programs start before configure.sh"
[ "$serve_line" -lt "$started_line" ] || fail "the start is recorded finished before the serving programs start"

echo "PASS: $TEST_NAME"
exit 0
