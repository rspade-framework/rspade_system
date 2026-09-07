#!/bin/bash
# t19: the updater must never commit in-flight state.
#
# commit_system_update() runs INSIDE the maintenance window, so the live flag is on disk
# at commit time. On a downstream where system/storage/ is tracked, `add -f -A -- system`
# swept it into the framework-update commit, which was then pushed - every box that pulled
# materialized a maintenance flag and answered 503 with no update running anywhere. Two
# 13-hour outages before it was traced. A .gitignore entry cannot stop it: -f exists to
# override ignore rules.
#
# The exclusion is CONDITIONAL on the flag being on disk, and this test pins both halves:
#   - flag present (pre-relocation shape)  -> excluded, never committed, left on disk
#   - flag absent but tracked in HEAD (post-relocation shape) -> NOT excluded, so the
#     commit records its DELETION and the historical damage purges itself
# An unconditional exclusion would pass the first case and freeze the bad file in HEAD
# forever, which is the outage that has to be undone.

TEST_NAME="framework_update/cli t19 maintenance flag never committed"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream

FLAG_REL="storage/rsx-framework/.maintenance.mode.framework.update"

# =============================================================================
# Phase 1: a LIVE flag is never swept into the commit
# =============================================================================
fx_build_downstream v1

mkdir -p "$PROJECT/storage/rsx-framework"
printf 'framework update in progress\n' > "$PROJECT/$FLAG_REL"

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "expected exit 0, got $FX_RC. Output: $FX_OUT"

files="$(git -C "$PROJECT" show --name-only --pretty=format: HEAD | grep -v '^$')"
if printf '%s\n' "$files" | grep -q 'maintenance\.mode'; then
    fx_fail "THE P0: the live maintenance flag was committed. Files: $files"
fi

# The real framework change still lands - the exclusion must be surgical, not a
# blanket refusal to commit. Under the submodule model "the framework change" IS the
# gitlink move; the files it brings live in the submodule's own history.
printf '%s\n' "$files" | grep -qx 'system' \
    || fx_fail "the framework revision was not committed. Files: $files"

# It is still tracked nowhere.
if git -C "$PROJECT" cat-file -e "HEAD:$FLAG_REL" 2>/dev/null; then
    fx_fail "the flag reached HEAD despite not appearing in the commit's file list"
fi

# And it is STILL ON DISK: the window is the caller's to lift (the EXIT trap owns it).
# Excluding it from a commit must never be confused with clearing it.
fx_assert_exists "$PROJECT/$FLAG_REL"

# =============================================================================
# Phase 2: a run whose ONLY dirt is the flag takes the clean no-op path
# =============================================================================
# Second pull, already up to date, flag still on disk from phase 1.
before="$(git -C "$PROJECT" rev-parse HEAD)"
fx_run_pull --no-rebuild --yes
after="$(git -C "$PROJECT" rev-parse HEAD)"

[ "$before" = "$after" ] || fx_fail "an empty commit was created for a flag-only dirty tree"
printf '%s' "$FX_OUT" | grep -q 'pathspec' \
    && fx_fail "the exclusion produced a 'pathspec matched nothing' error. Output: $FX_OUT"

# =============================================================================
# Phase 3 (RETIRED): a maintenance flag committed INSIDE system/.
#
# It used to be possible to commit one - the vendored updater committed the whole
# system/ tree, so a flag raised at the wrong moment rode along into history, and the
# updater had to purge it on the next run.
#
# The updater now commits ONE GITLINK. It cannot commit a file under system/ because
# it never stages one, and the flag lives at <project>/storage/ - outside the
# submodule entirely. The failure this phase guarded is unreachable by construction.
# =============================================================================

fx_pass
