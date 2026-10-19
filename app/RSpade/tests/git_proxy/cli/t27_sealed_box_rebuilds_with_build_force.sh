#!/bin/bash
# t27: on a SEALED box (debug or production) a pull that changes the checkout ends with
# `rsx:build --force`, inside the maintenance window.
#
# WHY. A sealed box refuses the development rebuild chain (rsx:clean, rsx:manifest:build
# --force, rsx:bundle:compile) - correctly, those are the guards that keep a sealed tree
# immutable - and a sealed build serves only what it was built from. A pull that moved the
# framework used to run that refused chain and leave new framework PHP running against the
# old build; a pull that moved only application code rebuilt nothing at all.
#
# Proves: a framework move rebuilds with rsx:build --force and never the development chain;
# an application-only move rebuilds too; the window is lowered; a failed build names
# rsx:build --force and says the box is down; a pull that moved nothing builds nothing.

TEST_NAME="git_proxy/cli t27 sealed box rebuilds with rsx:build --force"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

# The mode is the project .env's; a live variable would win over it.
unset RSX_MODE

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

printf 'RSX_MODE=production\n' > "$PROJECT/.env"

# ---- The framework moved: rsx:build --force, never the development chain. -----------
: > "$PROJECT/artisan.log"
rm -f "$(fx_maint_flag).disable_called"
fx_run pull origin master
fx_assert_rc 0

[ "$(fx_actual_revision)" = "$FW_V2" ] || fx_fail "the submodule did not follow the recorded revision"
grep -q '^rsx:build --force' "$PROJECT/artisan.log" \
    || fx_fail "a sealed box was not rebuilt with rsx:build --force. artisan.log: $(cat "$PROJECT/artisan.log")"
grep -q -e '^rsx:clean' -e '^rsx:manifest:build' -e '^rsx:bundle:compile' "$PROJECT/artisan.log" \
    && fx_fail "the development chain ran on a sealed box. artisan.log: $(cat "$PROJECT/artisan.log")"
[ "$(grep -c '^rsx:build' "$PROJECT/artisan.log")" = "1" ] \
    || fx_fail "the framework sync and the application change were each rebuilt - one build was expected"
[ -f "$(fx_maint_flag).disable_called" ] || fx_fail "the maintenance window was never lowered"
[ ! -f "$(fx_maint_flag)" ] || fx_fail "the maintenance flag survived the rebuild"
fx_refute_out "rsx:manifest:build --force"

# ---- Only application code moved: it rebuilds too. ----------------------------------
fx_upstream_commit - "app v2" "application change"
: > "$PROJECT/artisan.log"
rm -f "$(fx_maint_flag).disable_called"
fx_run pull origin master
fx_assert_rc 0

grep -q 'app v2' "$PROJECT/app/app_file.txt" || fx_fail "fixture: the application change did not arrive"
grep -q '^rsx:build --force' "$PROJECT/artisan.log" \
    || fx_fail "an application-only pull did not rebuild the sealed box. artisan.log: $(cat "$PROJECT/artisan.log")"
[ -f "$(fx_maint_flag).disable_called" ] || fx_fail "the application rebuild ran outside a maintenance window"
[ ! -f "$(fx_maint_flag)" ] || fx_fail "the maintenance flag survived the application rebuild"

# ---- A failed build says the box is down and names the command that rebuilds it. ----
fx_upstream_commit - "app v3" "another application change"
: > "$PROJECT/rebuild_fail"
fx_run pull origin master
fx_assert_rc 0
rm -f "$PROJECT/rebuild_fail"

fx_assert_out "rsx:build --force FAILED"
fx_assert_out "UNSEALED"
fx_assert_out "php artisan rsx:build --force"
[ ! -f "$(fx_maint_flag)" ] || fx_fail "a failed build left the maintenance window up"

# ---- Nothing moved: nothing is built. ------------------------------------------------
: > "$PROJECT/artisan.log"
fx_run pull origin master
fx_assert_rc 0
grep -q '^rsx:build' "$PROJECT/artisan.log" && fx_fail "a pull that moved nothing rebuilt the box"

# ---- Development is unchanged: an application-only pull builds nothing. --------------
printf 'RSX_MODE=development\n' > "$PROJECT/.env"
fx_upstream_commit - "app v4" "a development-box change"
: > "$PROJECT/artisan.log"
fx_run pull origin master
fx_assert_rc 0
grep -q '^rsx:build' "$PROJECT/artisan.log" && fx_fail "a development box ran rsx:build after an application-only pull"

fx_pass
