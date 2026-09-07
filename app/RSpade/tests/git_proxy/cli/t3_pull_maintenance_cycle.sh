#!/bin/bash
# t3: a pull that moves the framework revision is followed by a submodule sync, inside a
# maintenance window.
#
# THIS IS THE PROXY'S ONLY JOB. A colleague updates the framework and pushes; your
# `git pull` updates the RECORDED revision and does not touch the submodule's working
# tree. Nothing fails, nothing is printed, and you are now running a framework version
# your project does not claim to use.
#
# The window matters: replacing the framework under a live php-fpm is exactly the
# situation maintenance mode exists for.

TEST_NAME="git_proxy/cli t3 pull syncs the submodule"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

# Precondition: this working copy is still on v1, both recorded and checked out.
[ "$(fx_recorded_revision)" = "$FW_V1" ] || fx_fail "precondition: not recorded at v1"
[ "$(fx_actual_revision)"   = "$FW_V1" ] || fx_fail "precondition: not checked out at v1"

rm -f "$(fx_maint_flag).disable_called"

fx_run pull origin master
fx_assert_rc 0

# ---- The recorded revision moved, and the submodule FOLLOWED it. --------------------
[ "$(fx_recorded_revision)" = "$FW_V2" ] \
    || fx_fail "the pull did not bring the v2 revision. Recorded: $(fx_recorded_revision)"
[ "$(fx_actual_revision)" = "$FW_V2" ] \
    || fx_fail "THE WHOLE POINT: the submodule was left behind at $(fx_actual_revision)"

# The v2 content is genuinely on disk - not just a moved pointer.
grep -q 'framework v2' "$PROJECT/system/app/RSpade/core.php" \
    || fx_fail "system/ still holds the v1 content"
[ -f "$PROJECT/system/app/RSpade/added.php" ] \
    || fx_fail "the file v2 added is not present"

# ---- It announced itself, and it used the maintenance window. -----------------------
fx_assert_out "framework revision changed"
[ -f "$(fx_maint_flag).disable_called" ] \
    || fx_fail "the maintenance window was never lowered (was it ever raised?)"
[ ! -f "$(fx_maint_flag)" ] \
    || fx_fail "the maintenance flag survived the sync"

# ---- A second pull has nothing to do and says nothing. ------------------------------
fx_run pull origin master
fx_assert_rc 0
fx_refute_out "framework revision changed"

fx_pass
