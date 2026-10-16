#!/bin/bash
#
# prod_mode/cli - the production lifecycle, end to end, on this box.
#
# Everything else in this concern is a unit: a predicate asked in isolation, with the
# mode forced through a test seam and the build root pointed at a scratch directory.
# This script is the other half, and it is the only place the real contract can be
# observed at all:
#
#   1. rsx:prod:enable produces a SEALED build and the site serves from it - the page
#      and the compiled bundle it names both answer 200.
#   2. A sealed box WRITES NOTHING under build/, system/ or rsx/ while it serves, runs
#      rsx:health (which reports the seal intact), and runs migrate. `find -newer` over the three trees is the proof,
#      and it is the assertion the downstream field report that started this epic was
#      about.
#   3. A REAL MIGRATION runs on the sealed box and NOTHING IS REBUILT. A throwaway,
#      purely additive migration (one new table - tests/_lib/prod_probe_migration.sh) is
#      genuinely pending; `php artisan migrate` applies it, and afterwards: no file under
#      build/, system/ or rsx/ is newer than the marker, every file under build/ has the
#      checksum it had, rsx:prod:verify passes, the CODE key is unchanged and the STATE
#      key has moved, a fresh PHP process sees the new table's columns, a signed-in page
#      renders from the sealed, minified bundle with no error and its models answer
#      field_length(), the page's field-length script carries the new state key and is
#      served immutable under it (and uncacheable under the old one), and rsx:health
#      gains no FAIL row.
#   4. The reverse costs nothing either: with the table and its migration row gone the
#      state key is the one the box started with, and the page still renders.
#   5. The refusals hold: rsx:build and rsx:clean both demand --force.
#   6. Remove the seal and the box STOPS - a web request answers 500 and rsx:health
#      exits non-zero naming the remedy - rather than quietly rebuilding itself.
#   7. rsx:build --force reseals and the site serves again.
#   8. rsx:prod:disable returns the box to development, and a real page renders.
#
# A CLASS OVERRIDE RIDES THROUGH ALL OF IT. Before anything is built the script writes a
# real override into rsx/ (tests/_lib/prod_probe_overrides.sh): a PHP override of the
# framework's Region_Model carrying a marker as a public constant. (Only PHP classes are
# overridable; that an application JS class or template of a framework name is refused is
# tests/class_override's.) The sealed build must serve it and leave system/ alone: no change
# to system/app, system/config or system/bin, no *.upstream file, the shadowed framework file
# byte for byte as it was; under system/vendor/composer only the three generated autoloader
# files the strict build rewrites (autoload_classmap.php, autoload_real.php,
# autoload_static.php) may differ. On the sealed box the classmap-authoritative process
# resolves the framework FQCN to the override, rsx:overrides:list names it, /login - whose
# bundle names no application model - answers Region_Model.PROBE with the marker and no
# error, and its compiled bundles carry the override's model stub. The probe goes before the
# repair rebuild, after which system/ is still untouched, nothing is listed and the bundles
# no longer carry the marker; and once the box is back in development
# system/vendor/composer, system/ and rsx/ are exactly as the run found them.
#
# THE BOX ENDS IN DEVELOPMENT MODE WITH A WORKING BUILD. The EXIT trap removes the
# override probe first, then reads RSX_MODE through the pre-boot resolver and runs
# rsx:prod:disable if anything left the box in a production mode - on a failed assertion,
# on an interrupt, on anything. The same trap removes the probe migration's file, puts the migration whitelist back byte for byte,
# drops the probe table and its _migrations row, and retires the account the page probe
# signed in as.
#
# It runs against the DEVELOPMENT database and the live web server, like the http tests,
# and a sealed box takes no migration snapshot: the one table the probe migration creates
# (dropped again on every exit) and the page probe's own account (retired on every exit)
# are the whole of what it changes there.
#
# Not fast: four full builds, roughly six minutes of wall clock. It is a lifecycle
# test, and the lifecycle is what takes the time.

TEST_NAME="prod_mode/cli prod lifecycle"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$DIR/../../../../../.." && pwd)"
SYSTEM_DIR="$PROJECT_ROOT/system"

RSX_PATHS_PROJECT_ROOT_DIR="$PROJECT_ROOT"
. "$SYSTEM_DIR/bin/lib/rsx_paths.sh"

BUILD_ROOT="$(rsx_build_root)"
SEAL_FILE="$BUILD_ROOT/prod_seal.json"
LOG="/tmp/rsx_prod_lifecycle_$$.log"
SUMS_BEFORE="/tmp/rsx_prod_sums_before_$$"
SUMS_AFTER="/tmp/rsx_prod_sums_after_$$"

. "$SYSTEM_DIR/app/RSpade/tests/_lib/prod_probe_migration.sh"
. "$SYSTEM_DIR/app/RSpade/tests/_lib/prod_probe_overrides.sh"

SYSTEM_BEFORE="/tmp/rsx_prod_system_before_$$"
SYSTEM_AFTER="/tmp/rsx_prod_system_after_$$"
COMPOSER_BEFORE="/tmp/rsx_prod_composer_before_$$"
COMPOSER_AFTER="/tmp/rsx_prod_composer_after_$$"
BUNDLES_TEXT="/tmp/rsx_prod_bundles_$$.js"

# The files under system/vendor/composer a strict production build regenerates
# (composer dump-autoload --classmap-authoritative). Nothing else there may change.
COMPOSER_BUILD_FILES="system/vendor/composer/autoload_classmap.php
system/vendor/composer/autoload_real.php
system/vendor/composer/autoload_static.php"

# The declared host. APP_URL is the single hostname source; its $HOSTNAME token
# resolves to the OS hostname exactly as Rsx_App_Url resolves it at boot. Requests go
# to the loopback web server carrying that Host header, so the hostname guard sees the
# host it declared whatever mode the box is in.
APP_HOST="$(rsx_env_value APP_URL https://localhost)"
APP_HOST="${APP_HOST#*://}"
APP_HOST="${APP_HOST%%/*}"
if [ "$APP_HOST" = '$HOSTNAME' ]; then
    APP_HOST="$(hostname)"
fi

cd "$PROJECT_ROOT" || { echo "FAIL: $TEST_NAME - cannot enter $PROJECT_ROOT"; exit 1; }

# ---------------------------------------------------------------------------
# Harness
# ---------------------------------------------------------------------------

current_mode() { rsx_env_value RSX_MODE development; }

note() { echo "[TEST] $*" >&2; }

fail() {
    echo "" >&2
    echo "---- last command output ----" >&2
    tail -n 40 "$LOG" >&2
    echo "-----------------------------" >&2
    echo "FAIL: $TEST_NAME - $1"
    exit 1
}

# Run an artisan command, tee its FULL output into the log, and echo it.
# Never truncated: on migrate in particular the snapshot narrative IS the diagnostic.
artisan() {
    echo "### php artisan $*" >> "$LOG"
    php artisan "$@" > /tmp/rsx_prod_step_$$.out 2>&1
    local rc=$?
    cat /tmp/rsx_prod_step_$$.out >> "$LOG"
    cat /tmp/rsx_prod_step_$$.out
    rm -f /tmp/rsx_prod_step_$$.out
    return $rc
}

# HTTP status of one path, through the loopback server with the declared Host.
http_status() {
    curl -s -o /tmp/rsx_prod_body_$$.html -w '%{http_code}' -H "Host: $APP_HOST" "http://localhost$1"
}

# One response's status line and headers, through the loopback server.
http_headers() {
    curl -s -D - -o /dev/null -H "Host: $APP_HOST" "http://localhost$1"
}

# sha256 of every file under build/, in path order.
build_checksums() {
    find build -type f -print0 | sort -z | xargs -0 sha256sum
}

# The labels of the rsx:health rows that FAIL, one per line, sorted.
health_fail_rows() {
    echo "$1" | awk -F'|' '$3 ~ /^[[:space:]]*FAIL[[:space:]]*$/ { gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2 }' | sort
}

# Render the signed-in pages against the given state key; fail on the probe's first FAIL.
#   $1 the expected state key   $2 what this render is proving (for the message)
render_pages() {
    PAGES_OUTPUT="$(probe_pages "$APP_HOST" "$1" "$CODE_KEY")"
    local rc=$?
    echo "### page probe ($2)" >> "$LOG"
    echo "$PAGES_OUTPUT" >> "$LOG"
    echo "$PAGES_OUTPUT" | sed 's/^/[TEST]    /' >&2
    [ $rc -eq 0 ] || fail "$2: $(echo "$PAGES_OUTPUT" | grep -m1 '^FAIL:')"
}

# system/ against the fingerprint taken before the override probe was written; fail naming the step.
#   $1 what this comparison is proving (for the message)
assert_system_unchanged() {
    system_fingerprint > "$SYSTEM_AFTER"
    if ! diff "$SYSTEM_BEFORE" "$SYSTEM_AFTER" >> "$LOG" 2>&1; then
        fail "$1: system/ changed: $(diff "$SYSTEM_BEFORE" "$SYSTEM_AFTER" | grep '^[<>]' | head -5 | tr '\n' ' ')"
    fi
}

# The files under system/vendor/composer that differ from the fingerprint taken before the
# override probe was written, one path per line.
composer_changed_files() {
    composer_fingerprint > "$COMPOSER_AFTER"
    diff "$COMPOSER_BEFORE" "$COMPOSER_AFTER" | sed -n 's/^[<>] [0-9a-f]*  //p' | sort -u
}

# Only the generated autoloader files may differ under system/vendor/composer.
#   $1 what this comparison is proving (for the message)
assert_composer_only_build_files() {
    local unexpected
    unexpected="$(comm -23 <(composer_changed_files) <(echo "$COMPOSER_BUILD_FILES" | sort))"
    [ -z "$unexpected" ] || fail "$1: system/vendor/composer changed beyond the generated autoloader: $(echo "$unexpected" | head -5 | tr '\n' ' ')"
}

# Every compiled script /login names, concatenated into $BUNDLES_TEXT.
fetch_login_bundles() {
    : > "$BUNDLES_TEXT"
    local status path
    status="$(http_status /login)"
    [ "$status" = "200" ] || fail "/login answered $status"
    for path in $(grep -oE '/_compiled/[A-Za-z0-9_.-]+\.js' /tmp/rsx_prod_body_$$.html | sort -u); do
        curl -s -H "Host: $APP_HOST" "http://localhost$path" >> "$BUNDLES_TEXT"
    done
    [ -s "$BUNDLES_TEXT" ] || fail "/login named no compiled script"
}

# Restore the box no matter how this script ends.
restore_development() {
    local rc=$?

    # The source tree first: the development rebuild below must see no probe.
    probe_overrides_remove
    probe_migration_remove_files

    if [ "$(current_mode)" != "development" ]; then
        echo "[TEST] restoring development mode..." >&2
        php artisan rsx:prod:disable >> "$LOG" 2>&1
        if [ "$(current_mode)" != "development" ]; then
            echo "[TEST] rsx:prod:disable did NOT restore development mode. Log: $LOG" >&2
            echo "[TEST] Run it yourself: php artisan rsx:prod:disable" >&2
        fi
    fi

    if ! probe_migration_remove_rows; then
        echo "[TEST] the probe table was NOT removed. Log: $LOG" >&2
        echo "[TEST] Drop it yourself: DROP TABLE $PROBE_TABLE; and delete the _migrations row '$PROBE_MIGRATION'" >&2
    fi

    if ! probe_account_retire; then
        echo "[TEST] the probe account $PROBE_EMAIL was NOT retired. Log: $LOG" >&2
    fi

    rm -f /tmp/rsx_prod_body_$$.html /tmp/rsx_prod_step_$$.out "$SUMS_BEFORE" "$SUMS_AFTER"
    rm -f "$SYSTEM_BEFORE" "$SYSTEM_AFTER" "$COMPOSER_BEFORE" "$COMPOSER_AFTER" "$BUNDLES_TEXT"
    exit $rc
}

trap restore_development EXIT

: > "$LOG"

# ---------------------------------------------------------------------------
# 0. Precondition: a development box.
# ---------------------------------------------------------------------------

if [ "$(current_mode)" != "development" ]; then
    echo "SKIP: $TEST_NAME - this box is in $(current_mode) mode; the test starts from development"
    exit 0
fi

# The account the page probe signs in as (a sealed box has no development grant store).
probe_account_seed || fail "could not create the page probe's account"

# What the build must leave alone, measured before anything is built, then the override
# probe. The page probe renders /login anonymously and asks it for Region_Model.PROBE.
system_fingerprint > "$SYSTEM_BEFORE"
composer_fingerprint > "$COMPOSER_BEFORE"
RSX_STATUS_BEFORE="$(git status --porcelain -- rsx)"

probe_overrides_create || fail "could not write the override probe"
PROBE_ANON_PATH="/login"
PROBE_ANON_EXPRESSION="Region_Model.PROBE"
PROBE_ANON_MARKER="$PROBE_OVERRIDE_MARKER"

# ---------------------------------------------------------------------------
# 1. rsx:prod:enable produces a sealed build.
# ---------------------------------------------------------------------------

note "1. php artisan rsx:prod:enable (this builds everything - minutes, not seconds)"
enable_output="$(artisan rsx:prod:enable)" || fail "rsx:prod:enable exited non-zero"

case "$enable_output" in
    *"Sealed production build"*) ;;
    *) fail "rsx:prod:enable printed no seal summary" ;;
esac

case "$enable_output" in
    *"Build key:"*) ;;
    *) fail "the seal summary names no build key" ;;
esac

[ "$(current_mode)" = "production" ] || fail "RSX_MODE is '$(current_mode)' after rsx:prod:enable"
[ -f "$SEAL_FILE" ] || fail "no seal file at $SEAL_FILE"

# ---------------------------------------------------------------------------
# 2. The sealed box serves: the page, and the compiled bundle the page names.
# ---------------------------------------------------------------------------

note "2. the sealed build serves /login and its bundle"
status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status on a sealed production box"

bundle_path="$(grep -oE '/_compiled/[A-Za-z0-9_.-]+\.js' /tmp/rsx_prod_body_$$.html | head -1)"
[ -n "$bundle_path" ] || fail "/login named no compiled bundle - the page rendered without its assets"

status="$(http_status "$bundle_path")"
[ "$status" = "200" ] || fail "the compiled bundle $bundle_path answered $status"

# ---------------------------------------------------------------------------
# 2b. The sealed build serves the override and left system/ alone.
#
# Nothing under system/ is renamed, archived or edited to make an override work: the build
# records the framework file as shadowed, and the override loader and the bundle compiler
# answer with the application's file. The /login render itself is the page probe's (step 4).
# ---------------------------------------------------------------------------

note "2b. the override is served, and system/ is untouched"
assert_system_unchanged "after the sealed build"
assert_composer_only_build_files "after the sealed build"

resolution="$(probe_override_resolution)"
case "$resolution" in
    "$PROBE_PHP_OVERRIDE_FQCN|$PROBE_OVERRIDE_MARKER|"*) ;;
    *) fail "the sealed box does not resolve $PROBE_PHP_FRAMEWORK_FQCN to the override (it answered '$resolution')" ;;
esac
case "${resolution##*|}" in
    *A*) ;;
    *) fail "the probe process's composer loader is not classmap-authoritative ('${resolution##*|}') - the strict build was not what answered" ;;
esac

listed="$(probe_overrides_listed "$(artisan rsx:overrides:list --json)")" || fail "rsx:overrides:list --json printed no override list"
[ "$listed" = "Region_Model" ] \
    || fail "rsx:overrides:list on the sealed box lists '$(echo "$listed" | tr '\n' ' ')', not the probe"

fetch_login_bundles
grep -qF "$PROBE_OVERRIDE_MARKER" "$BUNDLES_TEXT" || fail "the sealed /login bundles do not carry the overriding Region_Model's stub"

# Strict production obfuscates the app bundle's strings (rsx.production.obfuscate_strings,
# on by default): the minified app script carries the _$$SC dictionary.
grep -qF '_$$SC=[' "$BUNDLES_TEXT" || fail "the strict-production /login bundles carry no _\$\$SC string dictionary"

# ---------------------------------------------------------------------------
# 3. A sealed box writes NOTHING under build/, system/ or rsx/.
#
# The marker is the reference mtime. Everything that follows is what a production box
# does all day - serve pages, report health, run migrations - and none of it may leave
# a file behind in a tree the seal covers or a deployed source tree.
# ---------------------------------------------------------------------------

note "3. serving + health + migrate leave the three trees untouched"
MARKER="/tmp/rsx_prod_marker_$$"
: > "$MARKER"

http_status /login > /dev/null
http_status "$bundle_path" > /dev/null
http_status / > /dev/null

# rsx:health runs on a sealed box and reports the seal intact. Its EXIT CODE is not
# asserted: the exit code sums every row, and rows unrelated to the seal - the hostname,
# the scheduler, login auto-fill - are facts about the box the script runs on, not about
# the lifecycle. Step 8 asserts the exit code, where the seal itself is the failing row.
health_output="$(artisan rsx:health)" || true
echo "$health_output" | grep -Eq 'Production Seal[[:space:]|]+OK' \
    || fail "rsx:health does not report the Production Seal row OK on a sealed box"
artisan migrate > /dev/null || fail "migrate exited non-zero on a sealed box"

written="$(find build system rsx -newer "$MARKER" -type f 2>/dev/null)"
rm -f "$MARKER"

if [ -n "$written" ]; then
    echo "$written" >> "$LOG"
    fail "a sealed box wrote files under build/, system/ or rsx/: $(echo "$written" | head -5 | tr '\n' ' ')"
fi

# ---------------------------------------------------------------------------
# 4. A REAL MIGRATION on the sealed box, and nothing is rebuilt.
#
# The build holds code facts only: which columns a table has is read from the database at
# run time, cached under the STATE key (the code key combined with the applied migrations).
# So a migration moves the state key and nothing else - no file under build/ changes, the
# seal still describes what is on disk, and every process answers from the new schema.
# ---------------------------------------------------------------------------

note "4. a real pending migration on the sealed box"

HEALTH_FAILS_BEFORE="$(health_fail_rows "$health_output")"
CODE_KEY="$(artisan rsx:manifest:get_build_key | tail -n 1)"
STATE_KEY_BEFORE="$(artisan rsx:manifest:get_state_key | tail -n 1)"

[ -n "$CODE_KEY" ] || fail "rsx:manifest:get_build_key printed no key on the sealed box"
[ "$CODE_KEY" = "$(cat "$BUILD_ROOT/build_key")" ] || fail "the code key ($CODE_KEY) is not the one in build/build_key"
[ -n "$STATE_KEY_BEFORE" ] || fail "rsx:manifest:get_state_key printed no key on the sealed box"
[ "$STATE_KEY_BEFORE" != "$CODE_KEY" ] || fail "the state key equals the code key - it carries no migration state"

# The sealed, minified bundle before anything changes, so a failure after the migration is
# the migration's and not the build's.
note "   the signed-in pages render from the sealed build"
render_pages "$STATE_KEY_BEFORE" "before the migration"

probe_migration_create || fail "could not write the probe migration and its whitelist entry"

build_checksums > "$SUMS_BEFORE"
[ -s "$SUMS_BEFORE" ] || fail "recorded no checksums under build/"

MARKER="/tmp/rsx_prod_marker_$$"
: > "$MARKER"

note "   php artisan migrate ($PROBE_MIGRATION)"
migrate_output="$(artisan migrate)" || fail "migrate exited non-zero applying a real migration on a sealed box"

case "$migrate_output" in
    *"$PROBE_MIGRATION"*) ;;
    *) fail "the migrate output does not name the probe migration - it was not applied" ;;
esac

rebuild_wording="$(echo "$migrate_output" | grep -Ei 'rebuil|reseal|rsx:build' | head -3)"
[ -z "$rebuild_wording" ] || fail "the sealed migrate output speaks of a rebuild: $rebuild_wording"

written="$(find build system rsx -newer "$MARKER" -type f 2>/dev/null)"
rm -f "$MARKER"

if [ -n "$written" ]; then
    echo "$written" >> "$LOG"
    fail "a real migration on a sealed box wrote files under build/, system/ or rsx/: $(echo "$written" | head -5 | tr '\n' ' ')"
fi

build_checksums > "$SUMS_AFTER"
if ! diff "$SUMS_BEFORE" "$SUMS_AFTER" >> "$LOG" 2>&1; then
    fail "files under build/ changed across the migration: $(diff "$SUMS_BEFORE" "$SUMS_AFTER" | grep '^[<>]' | head -3 | tr '\n' ' ')"
fi

artisan rsx:prod:verify > /dev/null || fail "rsx:prod:verify reports drift after the migration"

code_key_after="$(artisan rsx:manifest:get_build_key | tail -n 1)"
[ "$code_key_after" = "$CODE_KEY" ] || fail "the code key moved across the migration ($CODE_KEY -> $code_key_after)"
[ "$(cat "$BUILD_ROOT/build_key")" = "$CODE_KEY" ] || fail "build/build_key changed across the migration"

STATE_KEY_AFTER="$(artisan rsx:manifest:get_state_key | tail -n 1)"
[ -n "$STATE_KEY_AFTER" ] || fail "rsx:manifest:get_state_key printed no key after the migration"
[ "$STATE_KEY_AFTER" != "$STATE_KEY_BEFORE" ] || fail "the state key did not move across the migration ($STATE_KEY_BEFORE)"

note "   a fresh PHP process sees the new table"
columns="$(probe_schema_columns)"
case ",$columns," in
    *",$PROBE_COLUMN:$PROBE_COLUMN_LENGTH,"*) ;;
    *) fail "a fresh process does not see $PROBE_TABLE.$PROBE_COLUMN at length $PROBE_COLUMN_LENGTH through Rsx_Schema (it saw: '$columns')" ;;
esac

note "   the signed-in pages render under the new state key"
render_pages "$STATE_KEY_AFTER" "after the migration"

field_length_url="$(echo "$PAGES_OUTPUT" | sed -n 's/^FIELD_LENGTH_URL=//p' | head -1)"
field_length_sample="$(echo "$PAGES_OUTPUT" | sed -n 's/^FIELD_LENGTH_SAMPLE=//p' | head -1)"
[ -n "$field_length_url" ] || fail "the page probe reported no field-length script URL"

php_sample="$(probe_php_sample_length)"
[ -n "$php_sample" ] && [ "$field_length_sample" = "$php_sample" ] \
    || fail "User_Model.field_length('first_name') answered '$field_length_sample' in the sealed bundle and '$php_sample' in PHP"

case "$field_length_url" in
    *"?v=$STATE_KEY_AFTER") ;;
    *) fail "the page's field-length script URL does not carry the new state key: $field_length_url" ;;
esac

script_headers="$(http_headers "$field_length_url")"
echo "$script_headers" | head -1 | grep -q ' 200' \
    || fail "the field-length script $field_length_url answered $(echo "$script_headers" | head -1 | tr -d '\r')"
echo "$script_headers" | grep -i '^cache-control:' | grep -qi 'immutable' \
    || fail "the field-length script is not served immutable under the current state key"

stale_url="${field_length_url%%\?v=*}?v=$STATE_KEY_BEFORE"
stale_headers="$(http_headers "$stale_url")"
echo "$stale_headers" | grep -i '^cache-control:' | grep -qi 'no-store' \
    || fail "the field-length script under the pre-migration key is not served no-store: $(echo "$stale_headers" | grep -i '^cache-control:' | tr -d '\r')"

# The anonymous page too: its copy in the full page cache was filed under the code key,
# which did not move, so only the migrate purge discards it.
status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status after the migration"
grep -Eq "\"build_key\": *\"$STATE_KEY_AFTER\"" /tmp/rsx_prod_body_$$.html \
    || fail "/login does not carry the new state key after the migration - a page cached before it is still being served"

health_output="$(artisan rsx:health)" || true
new_fails="$(comm -13 <(echo "$HEALTH_FAILS_BEFORE") <(health_fail_rows "$health_output"))"
[ -z "$new_fails" ] || fail "rsx:health gained FAIL rows after the migration: $(echo "$new_fails" | tr '\n' ';')"

# ---------------------------------------------------------------------------
# 5. The reverse direction costs nothing either.
#
# Still sealed: the table and its migration row go, and the state key is the one the box
# had before - no build step in either direction.
# ---------------------------------------------------------------------------

note "5. with the migration gone the state key returns, and the page still renders"
probe_migration_remove_files
probe_migration_remove_rows || fail "could not remove the probe table and its migration row"

state_key_restored="$(artisan rsx:manifest:get_state_key | tail -n 1)"
[ "$state_key_restored" = "$STATE_KEY_BEFORE" ] \
    || fail "the state key is $state_key_restored after removing the migration, not the original $STATE_KEY_BEFORE"

[ -z "$(probe_schema_columns)" ] || fail "a fresh process still sees $PROBE_TABLE after it was dropped"

render_pages "$STATE_KEY_BEFORE" "after removing the migration"

build_checksums > "$SUMS_AFTER"
diff "$SUMS_BEFORE" "$SUMS_AFTER" >> "$LOG" 2>&1 || fail "files under build/ changed while the migration was being removed"

# ---------------------------------------------------------------------------
# 6. The refusals. Discarding or rebuilding a live build is deliberate, never incidental.
# ---------------------------------------------------------------------------

note "6. rsx:build and rsx:clean both demand --force"
build_output="$(artisan rsx:build)" && fail "rsx:build rebuilt a sealed box without --force"
case "$build_output" in
    *"--force"*) ;;
    *) fail "the rsx:build refusal does not name --force" ;;
esac

clean_output="$(artisan rsx:clean)" && fail "rsx:clean discarded a sealed build tree without --force"
case "$clean_output" in
    *"--force"*) ;;
    *) fail "the rsx:clean refusal does not name --force" ;;
esac

# ---------------------------------------------------------------------------
# 7. The seal describes what is on disk.
# ---------------------------------------------------------------------------

note "7. rsx:prod:verify"
artisan rsx:prod:verify > /dev/null || fail "rsx:prod:verify reported drift on a build it had just made"

# ---------------------------------------------------------------------------
# 8. Without its seal the box STOPS - loudly, naming the remedy.
#
# A raw rm, deliberately: the guard is a guardrail and never claimed to stop one. What
# is being proven is what the box does about it afterwards.
# ---------------------------------------------------------------------------

note "8. an unsealed production box refuses to serve and says why"
rm -f "$SEAL_FILE"

status="$(http_status /login)"
[ "$status" = "500" ] || fail "an unsealed production box answered $status to /login, not 500"

health_output="$(artisan rsx:health)" && fail "rsx:health exited 0 on an unsealed production box"
case "$health_output" in
    *unsealed*) ;;
    *) fail "the health output does not say the build is unsealed" ;;
esac
case "$health_output" in
    *"rsx:build --force"*) ;;
    *) fail "the health output does not name the command that repairs it" ;;
esac

# ---------------------------------------------------------------------------
# 9. The build is the repair.
# ---------------------------------------------------------------------------

note "9. the override probe goes, and php artisan rsx:build --force reseals"
probe_overrides_remove
PROBE_ANON_PATH=""
PROBE_ANON_EXPRESSION=""
PROBE_ANON_MARKER=""

artisan rsx:build --force > /dev/null || fail "rsx:build --force did not rebuild the unsealed box"
[ -f "$SEAL_FILE" ] || fail "rsx:build --force wrote no seal"

status="$(http_status /login)"
[ "$status" = "200" ] || fail "/login answered $status after the rebuild"

assert_system_unchanged "after removing the override and rebuilding"
assert_composer_only_build_files "after removing the override and rebuilding"

listed="$(probe_overrides_listed "$(artisan rsx:overrides:list --json)")" || fail "rsx:overrides:list --json printed no override list"
[ -z "$listed" ] || fail "rsx:overrides:list still lists '$(echo "$listed" | tr '\n' ' ')' after the probe was removed and the box rebuilt"

resolution="$(probe_override_resolution)"
case "$resolution" in
    "$PROBE_PHP_FRAMEWORK_FQCN|"*) ;;
    *) fail "with the override gone the box does not resolve $PROBE_PHP_FRAMEWORK_FQCN to the framework class (it answered '$resolution')" ;;
esac

fetch_login_bundles
grep -qF "$PROBE_OVERRIDE_MARKER" "$BUNDLES_TEXT" && fail "the rebuilt /login bundles still carry the removed override's model stub"

# ---------------------------------------------------------------------------
# 10. Back to development, with assets that work.
# ---------------------------------------------------------------------------

note "10. php artisan rsx:prod:disable"
artisan rsx:prod:disable > /dev/null || fail "rsx:prod:disable exited non-zero"
[ "$(current_mode)" = "development" ] || fail "RSX_MODE is '$(current_mode)' after rsx:prod:disable"
[ -f "$SEAL_FILE" ] && fail "the seal survived rsx:prod:disable - a development box is never sealed"

debug_output="$(artisan rsx:debug /)" || fail "rsx:debug / exited non-zero after returning to development"
case "$debug_output" in
    200*) ;;
    *) fail "rsx:debug / did not render 200: $(echo "$debug_output" | head -1)" ;;
esac
case "$debug_output" in
    *"JavaScript Console Errors: None"*) ;;
    *) fail "the development page rendered with console errors" ;;
esac

# The whole round trip, override included, leaves the source trees as it found them; and
# rsx:prod:disable regenerates composer's autoloader byte for byte as it was.
assert_system_unchanged "back in development"
[ -z "$(composer_changed_files)" ] \
    || fail "system/vendor/composer is not as the run found it: $(composer_changed_files | tr '\n' ' ')"
[ "$(git status --porcelain -- rsx)" = "$RSX_STATUS_BEFORE" ] \
    || fail "rsx/ is not as the run found it: $(git status --porcelain -- rsx | head -5 | tr '\n' ' ')"

rm -f "$LOG"
echo "PASS: $TEST_NAME"
