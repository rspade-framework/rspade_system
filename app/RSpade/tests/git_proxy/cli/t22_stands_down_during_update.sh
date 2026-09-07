#!/bin/bash
# t22: the proxy STANDS DOWN while a framework update is in flight.
#
# THE BUG THIS LOCKS OUT, found by the end-to-end update test (2026-08-21):
#
# rsx:framework:pull checks the new revision out BEFORE committing the gitlink, so for
# one moment the checkout is legitimately ahead of the record. That is exactly the shape
# this proxy exists to correct - and correcting it there means resetting the submodule
# BACK to the old revision, undoing the update. The updater then had nothing to commit
# and reported success against variables it had set before the reset happened.
#
# Both components passed their own suites. Neither could have caught it alone.
#
# Two layers: the updater exports RSX_GIT_SHIM_ACTIVE so its git never reaches the proxy
# at all, and the proxy declines while the update's markers are present - covering
# anything ELSE that runs in that window (a build step, a hook, another shell).

TEST_NAME="git_proxy/cli t22 stands down during a framework update"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

# Reproduce the mid-update state: the checkout ahead of the record.
git -C "$PROJECT/system" checkout -q "$FW_V2"
[ "$(fx_actual_revision)" != "$(fx_recorded_revision)" ] \
    || fx_fail "precondition: the checkout should be ahead of the record"
ahead="$(fx_actual_revision)"

# ---- 1. RSPADE_FRAMEWORK_UPDATE=1 -> the proxy must not touch it. -------------------
FX_OUT="$(cd "$PROJECT" && RSPADE_FRAMEWORK_UPDATE=1 \
    bash "$PROJECT/system/bin/rsx-git.sh" checkout -q master 2>&1)"
[ "$(fx_actual_revision)" = "$ahead" ] \
    || fx_fail "the proxy reset the submodule during an update (RSPADE_FRAMEWORK_UPDATE=1)"
printf '%s' "$FX_OUT" | grep -q "framework revision changed" \
    && fx_fail "the proxy announced a sync during an update"

# ---- 2. RSPADE_FRAMEWORK_COMMIT=1 -> same. ------------------------------------------
FX_OUT="$(cd "$PROJECT" && RSPADE_FRAMEWORK_COMMIT=1 \
    bash "$PROJECT/system/bin/rsx-git.sh" checkout -q master 2>&1)"
[ "$(fx_actual_revision)" = "$ahead" ] \
    || fx_fail "the proxy reset the submodule during a framework commit"

# ---- 3. The update's maintenance window is up -> same. ------------------------------
mkdir -p "$PROJECT/storage/rsx-framework"
printf 'framework update in progress\nmode=development\n' > "$(fx_maint_flag)"
FX_OUT="$(cd "$PROJECT" && bash "$PROJECT/system/bin/rsx-git.sh" checkout -q master 2>&1)"
[ "$(fx_actual_revision)" = "$ahead" ] \
    || fx_fail "the proxy reset the submodule while the update's window was up"

# ---- 4. An OPERATOR's window is NOT an update - the proxy still works. --------------
printf 'database surgery\nmode=development\n' > "$(fx_maint_flag)"
FX_OUT="$(cd "$PROJECT" && bash "$PROJECT/system/bin/rsx-git.sh" checkout -q master 2>&1)"
[ "$(fx_actual_revision)" = "$(fx_recorded_revision)" ] \
    || fx_fail "an operator's window should not stop the proxy doing its job"
rm -f "$(fx_maint_flag)"

# ---- 5. THE UPDATER'S OWN BYPASS. -----------------------------------------------
# The shim execs /usr/bin/git when it sees RSX_GIT_SHIM_ACTIVE, so the proxy is never
# entered. Asserted against the AUTHORED updater: the fixture ships a fake framework
# carrying only the proxy and a maintenance stub.
REAL_UPDATER="/var/www/html/system/bin/framework-pull-upstream.sh.dist"
[ -f "$REAL_UPDATER" ] || REAL_UPDATER="/var/www/html/system/bin/framework-pull-upstream.sh"
grep -q '^export RSX_GIT_SHIM_ACTIVE=1' "$REAL_UPDATER" \
    || fx_fail "the updater no longer bypasses the shim - the proxy will undo its checkout"

fx_pass
