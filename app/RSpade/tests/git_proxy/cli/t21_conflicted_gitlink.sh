#!/bin/bash
# t21: a CONFLICTED framework revision is reported, never resolved automatically.
#
# When both sides of a merge moved the recorded revision, git cannot choose - and neither
# should this. Which framework release to run is a decision with consequences (a release
# can carry migrations); the vendored-era proxy tried to settle it by comparing release
# dates, and that cleverness is exactly what a submodule makes unnecessary.
#
# The contract: say what happened, name the commands that resolve it, change nothing, and
# return git's own exit code.

TEST_NAME="git_proxy/cli t21 conflicted gitlink"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream

# THEIRS: a colleague moves the framework to v2 and pushes.
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

# OURS: a local commit that moves the same gitlink somewhere else, so both sides changed
# it to different values - the only way a submodule conflicts.
git -C "$PROJECT/system" checkout -q "$FW_V2"
git -C "$PROJECT" add system >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "local framework move"
git -C "$PROJECT" revert -q --no-edit HEAD >/dev/null 2>&1 || true

fx_run pull origin master

if git -C "$PROJECT" ls-files -u -- system 2>/dev/null | grep -q .; then
    # ---- It conflicted: guidance, and nothing automated. ---------------------------
    fx_assert_out "both sides changed which framework revision"
    fx_assert_out "checkout --theirs"
    fx_assert_out "checkout --ours"
    fx_assert_out "rsx:framework:pull"
    fx_refute_out "framework revision changed"

    git -C "$PROJECT" ls-files -u -- system | grep -q . \
        || fx_fail "the proxy resolved the conflict itself - that is a decision, not a merge"
    fx_pass
fi

# ---- No conflict arose: the sync ran normally and left things consistent. -----------
[ "$(fx_actual_revision)" = "$(fx_recorded_revision)" ] \
    || fx_fail "no conflict, but the submodule and the record still disagree"

fx_pass
