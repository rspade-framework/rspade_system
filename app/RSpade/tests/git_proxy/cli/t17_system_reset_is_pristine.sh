#!/bin/bash
# t17: the sync leaves system/ PRISTINE at the recorded revision - local drift is
# discarded, not merged and not reported.
#
# All of system/ is framework property. A modification there is not work to protect, it
# is a checkout that has drifted from the commit it claims to be, and the supported way
# to customize a framework class is a class override in rsx/.
#
# This is what makes the checkout in the sync unable to fail: `git checkout` would refuse
# to overwrite local modifications, so rsx:clean resets the submodule hard first.

TEST_NAME="git_proxy/cli t17 system reset is pristine"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

# Drift of every shape the framework tree can carry.
printf 'I EDITED THE FRAMEWORK\n' > "$PROJECT/system/app/RSpade/core.php"
printf 'stray\n'                  > "$PROJECT/system/app/RSpade/stray_untracked.php"
rm -f "$PROJECT/system/bin/rsx-git.sh.bak" 2>/dev/null
printf 'ignored build residue\n'  > "$PROJECT/system/build_residue.log"

fx_run pull origin master
fx_assert_rc 0

# ---- The tracked edit is gone, replaced by the release content. ---------------------
grep -q 'framework v2' "$PROJECT/system/app/RSpade/core.php" \
    || fx_fail "the edited framework file was not restored to the release content"
grep -q 'I EDITED' "$PROJECT/system/app/RSpade/core.php" \
    && fx_fail "the local edit survived the sync"

# ---- The untracked files are gone too. ----------------------------------------------
[ ! -f "$PROJECT/system/app/RSpade/stray_untracked.php" ] \
    || fx_fail "an untracked file survived the sync"
[ ! -f "$PROJECT/system/build_residue.log" ] \
    || fx_fail "ignored build residue survived the sync (clean must use -x)"

# ---- The submodule is exactly at the recorded revision, and clean. ------------------
[ "$(fx_actual_revision)" = "$(fx_recorded_revision)" ] \
    || fx_fail "the submodule is not at the recorded revision"
[ -z "$(git -C "$PROJECT/system" status --porcelain 2>/dev/null)" ] \
    || fx_fail "the submodule is not pristine after the sync"

# ---- rsx:clean RAN, AFTER THE CHECKOUT, WITHOUT ITS OWN RESET. ---------------------
# Between the direct reset and the checkout the recorded gitlink and system/ HEAD
# disagree, and the real artisan refuses to boot in that state - an rsx:clean placed
# there never ran once (a downstream field report, 2026-09-01: "rsx:clean reported a
# problem" on every revision change). The fixture stub logs the HEAD it saw.
grep -q '^rsx:clean --silent --_no-system-reset' "$PROJECT/artisan.log" \
    || fx_fail "rsx:clean was not invoked with --silent --_no-system-reset (the reset is done directly)"
[ "$(awk '$1 == "rsx:clean" {print $2}' "$PROJECT/artisan_head.log" | tail -n 1)" = "$FW_V2" ] \
    || fx_fail "rsx:clean ran while system/ was not yet at the recorded revision (artisan refuses to boot there)"

# ---- IT SAID NOTHING ABOUT THE DISCARDED WORK, deliberately. -----------------------
# There is nothing to report: the tree is a checkout of somebody else's repository.
fx_refute_out "I EDITED"
fx_refute_out "stray_untracked"

# ---- Storage is OUTSIDE the submodule and survives. --------------------------------
# This is why volatile state was moved one level up in the first place.
[ -f "$PROJECT/storage/.rspade_storage_relocated" ] \
    || fx_fail "project storage was destroyed by the submodule reset"

fx_pass
