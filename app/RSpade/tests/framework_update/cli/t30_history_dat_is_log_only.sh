#!/bin/bash
# t30: rsx/resource/framework_update_history.dat is a LOG, not state.
#
# It once competed for the title of "what release is installed", with a per-release
# marker inside system/ as a fallback. Two authorities that merge independently - one
# inside system/, one in the app tree - is not redundancy, it is a coin toss with no
# referee, and a backwards merge duly made them disagree: the log said the current
# release (so the run reported "up to date") while the marker said the old one (so the
# baseline repair then untracked 7,503 paths). Field report, 2026-08-18.
#
# BOTH AUTHORITIES ARE NOW GONE. git decides: the recorded gitlink is what this project
# runs, and the submodule's HEAD is what it actually has. The log is written, never read.
#
# That makes this test simpler and stronger than the version it replaces: corrupt the
# log however you like and the update must be COMPLETELY unaffected - same revision, same
# exit, same commit. Nothing is reported, because there is no disagreement to report.

TEST_NAME="framework_update/cli t30 history.dat is log-only"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

HIST="$PROJECT/rsx/resource/framework_update_history.dat"

# ---- 1. A log that LIES about the installed revision changes nothing. ----------------
mkdir -p "$(dirname "$HIST")"
printf '## RSPADE-UPDATE 2099-01-01T00:00:00Z from=%s to=%s\nDate: 2099-01-01 00:00 UTC\n\nA log that claims the future.\n\n' \
    "$V1_SHA" "$V2_SHA" > "$HIST"

fx_run_pull --no-rebuild
[ "$FX_RC" -eq 0 ] || fx_fail "(lying log) expected exit 0, got $FX_RC. Output: $FX_OUT"

# The update happened on the evidence git holds, not on what the log claimed.
[ "$(fx_actual_revision)" = "$V2_SHA" ] \
    || fx_fail "(lying log) the submodule is not at the v2 revision"
[ "$(fx_recorded_revision)" = "$V2_SHA" ] \
    || fx_fail "(lying log) the recorded revision is not v2"

# And the log was APPENDED to, not consulted: the lie is still in there.
grep -q 'A log that claims the future' "$HIST" \
    || fx_fail "(lying log) the updater rewrote the log instead of appending to it"
grep -c '^## RSPADE-UPDATE' "$HIST" | grep -qx '2' \
    || fx_fail "(lying log) expected exactly one appended section beside the planted one"

# ---- 2. A log that is GARBAGE changes nothing either. --------------------------------
fx_build_downstream v1
mkdir -p "$(dirname "$HIST")"
head -c 4096 /dev/urandom > "$HIST"

fx_run_pull --no-rebuild
[ "$FX_RC" -eq 0 ] || fx_fail "(garbage log) expected exit 0, got $FX_RC. Output: $FX_OUT"
[ "$(fx_actual_revision)" = "$V2_SHA" ] \
    || fx_fail "(garbage log) the submodule is not at the v2 revision"

# ---- 3. NO log at all changes nothing. -----------------------------------------------
fx_build_downstream v1
rm -f "$HIST"

fx_run_pull --no-rebuild
[ "$FX_RC" -eq 0 ] || fx_fail "(absent log) expected exit 0, got $FX_RC. Output: $FX_OUT"
[ "$(fx_actual_revision)" = "$V2_SHA" ] \
    || fx_fail "(absent log) the submodule is not at the v2 revision"
[ -f "$HIST" ] || fx_fail "(absent log) the updater did not recreate the log"

fx_pass
