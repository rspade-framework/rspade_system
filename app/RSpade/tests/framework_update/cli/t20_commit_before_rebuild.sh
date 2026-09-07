#!/bin/bash
# t20: the framework-update commit is made BEFORE the rebuild, so a rebuild that fails
# can never cost the synced release.
#
# THE FIELD INCIDENT this locks out: a release whose own manifest validator fails
# DETERMINISTICALLY on the not-yet-migrated app (new build-time enforcement) died at the
# rebuild step under the old commit-after-rebuild order, leaving system/ synced but
# UNCOMMITTED. The next app commit's pre-commit hook ran rsx:clean, which reset system/
# to its last commit - the PREVIOUS release - silently reverting the update. The repair
# needed app commits, and every app commit re-destroyed the release: a deadlock.
#
# With the commit ahead of the rebuild the release is in history before anything can
# fail, so a rebuild failure is repaired by a REBUILD, never by a re-pull.
#
# The fixture shim's `manifest_fatal` seam makes rsx:manifest:build fail with a NON-lock
# error (no retry loop), exactly as a validator rejection does.

TEST_NAME="framework_update/cli t20 commit precedes rebuild"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

before="$(git -C "$PROJECT" rev-parse HEAD)"

# Arm the deterministic rebuild failure. The seam sits at the PROJECT ROOT: system/ is a
# submodule the update resets with `git clean -fdx`, so an untracked file inside it would
# be gone before the rebuild it is meant to break.
touch "$PROJECT/manifest_fatal"

# NOTE: no --no-rebuild - the rebuild must actually run and fail.
fx_run_pull --yes

# The pull fails loudly: the update is incomplete until the app builds.
[ "$FX_RC" -ne 0 ] || fx_fail "expected a non-zero exit from the failed rebuild. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q 'rsx:manifest:build failed' \
    || fx_fail "expected the rebuild failure to be reported. Output: $FX_OUT"

# ...but the release is IN HISTORY.
after="$(git -C "$PROJECT" rev-parse HEAD)"
[ "$before" != "$after" ] || fx_fail "THE DEADLOCK: the rebuild failed and the release was left UNCOMMITTED"

subj="$(git -C "$PROJECT" log -1 --pretty=%s)"
echo "$subj" | grep -qE '^Framework update [0-9a-f]{12} -> [0-9a-f]{12} \([0-9]+ upstream commits\)$' \
    || fx_fail "the commit is not the framework-update commit: '$subj'"

# The committed GITLINK names the v2 revision, and the submodule is checked out at it.
# The parent commit carries a revision, not file content - the content lives in the
# submodule's own history, which is the whole point of the boundary.
recorded="$(git -C "$PROJECT" ls-tree HEAD -- system | awk '{print $3}')"
[ "$recorded" = "$V2_SHA" ] \
    || fx_fail "the commit records ${recorded:0:12}, not the v2 release ${V2_SHA:0:12}"
git -C "$PROJECT/system" show "HEAD:app/RSpade/core_a.php" 2>/dev/null | grep -q 'VERSION 2' \
    || fx_fail "the checked-out submodule does not carry the v2 release content"
# Adds and deletes are the submodule's business now - it is a checkout of the release,
# so they arrive and depart with the revision rather than being reconciled path by path.
git -C "$PROJECT/system" cat-file -e "HEAD:three_way/added.txt" 2>/dev/null \
    || fx_fail "the checked-out release is missing the file it added"
git -C "$PROJECT/system" cat-file -e "HEAD:app/RSpade/gone.php" 2>/dev/null \
    && fx_fail "the checked-out release still carries a file it deleted"
git -C "$PROJECT" cat-file -e "HEAD:rsx/resource/framework_update_history.dat" 2>/dev/null \
    || fx_fail "the update history was not committed with the release"

# The armed-window warning belongs to the synced-but-UNCOMMITTED state only. The commit
# happened, so it must NOT be printed - a false alarm here would send an operator into a
# recovery they do not need.
printf '%s' "$FX_OUT" | grep -q 'synced but UNCOMMITTED' \
    && fx_fail "the armed-window warning fired even though the release was committed. Output: $FX_OUT"

# A rebuild failure must leave NOTHING for a re-pull to redo: history is at NEW, so the
# repair is a rebuild. Proven by a second pull finding nothing to do.
rm -f "$PROJECT/manifest_fatal"
fx_run_pull --yes
[ "$FX_RC" -eq 0 ] || fx_fail "(re-run) expected exit 0 once the rebuild can pass, got $FX_RC. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q 'up to date' \
    || fx_fail "(re-run) expected the install to already be at the new release. Output: $FX_OUT"

fx_pass
