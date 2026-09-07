#!/bin/bash
# t16: THE SUBCOMMAND CLASSIFIER. The proxy acts on operations that can move the recorded
# framework revision, and passes everything else straight through.
#
# The classifier has to step past git's global options: `git -C x -c k=v --no-pager pull`
# is a pull, not a `-C`. Getting that wrong in either direction is bad - a missed pull
# leaves the framework silently stale, and a misfire wraps a command that never needed it.

TEST_NAME="git_proxy/cli t16 subcommand classifier"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

# ---- 1. GLOBAL OPTIONS BEFORE THE SUBCOMMAND are stepped past. ----------------------
# The pull is buried behind three global options, two of which take a value.
fx_run -c pull.rebase=false --no-pager pull origin master
fx_assert_rc 0
[ "$(fx_actual_revision)" = "$FW_V2" ] \
    || fx_fail "a pull behind global options was not classified as a pull"

# ---- 2. A subcommand that CANNOT move the revision is never wrapped. ----------------
for sub in status log diff branch show; do
    fx_run "$sub" --oneline
    fx_refute_out "framework revision changed"
done

# ---- 3. NO subcommand at all (git --version) is passthrough. ------------------------
fx_run --version
fx_assert_rc 0
fx_refute_out "rsx:git"

# ---- 4. A HANDLED subcommand that moves nothing does nothing. -----------------------
# `checkout` is in the trigger list because it CAN move the revision. When it does not,
# the before/after comparison must find nothing to do and stay silent.
fx_run checkout master
fx_refute_out "framework revision changed"

# ---- 5. And it fires for the other ref-moving operations, not just pull. ------------
# Rewind the recorded revision with a reset and confirm the submodule is brought back.
git -C "$PROJECT/system" checkout -q "$FW_V1"
fx_run reset --hard -q HEAD
fx_assert_rc 0
[ "$(fx_actual_revision)" = "$(fx_recorded_revision)" ] \
    || fx_fail "reset did not trigger the sync: recorded=$(fx_recorded_revision) actual=$(fx_actual_revision)"

fx_pass
