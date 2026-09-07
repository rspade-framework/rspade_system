#!/bin/bash
# t16: after a pull, the ENTIRE framework tree (system/ + the update history file) is
# auto-committed into the downstream app repo as ONE whole-system commit carrying the
# aggregated breaking changelog: subject "Framework update <old12> -> <new12> (N upstream
# commits)", trailers Framework-Update-Range + Committed-By. The retired zone-era
# artifacts (skip-worktree bits, *.php.upstream gitignore hiding) must NOT be present
# afterward. --no-commit opts out, leaving the synced changes uncommitted for review.

TEST_NAME="framework_update/cli t16 auto-commit (whole-system model)"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream

# ---- Phase 1: default (auto-commit ON) ----
fx_build_downstream v1
before="$(git -C "$PROJECT" rev-parse HEAD)"

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "expected exit 0, got $FX_RC. Output: $FX_OUT"

after="$(git -C "$PROJECT" rev-parse HEAD)"
[ "$before" != "$after" ] || fx_fail "expected an auto-commit but HEAD did not advance"

subj="$(git -C "$PROJECT" log -1 --pretty=%s)"
echo "$subj" | grep -qE '^Framework update [0-9a-f]{12} -> [0-9a-f]{12} \([0-9]+ upstream commits\)$' \
    || fx_fail "auto-commit subject not recognized: '$subj'"

body="$(git -C "$PROJECT" log -1 --pretty=%B)"
printf '%s\n' "$body" | grep -q '^Framework-Update-Range: ' \
    || fx_fail "commit message missing Framework-Update-Range trailer. Body: $body"
printf '%s\n' "$body" | grep -q '^Committed-By: rsx:framework:pull$' \
    || fx_fail "commit message missing Committed-By trailer. Body: $body"

# The commit moves the system/ GITLINK - one entry, mode 160000 - plus optionally the
# history file, and NOTHING else. Under the vendored model this spanned the whole
# system/ tree; a submodule contributes a single path to the parent's index, which is
# the entire point of the change.
files="$(git -C "$PROJECT" show --name-only --pretty=format: HEAD | grep -v '^$' | sort -u)"
echo "$files" | grep -qx 'system' || fx_fail "commit did not move the system/ gitlink. Files: $files"
[ "$(git -C "$PROJECT" ls-tree HEAD -- system | awk '{print $1}')" = "160000" ] \
    || fx_fail "system is not recorded as a gitlink in the commit"
if echo "$files" | grep -vqE '^(system$|rsx/resource/framework_update_history\.dat$)'; then
    fx_fail "commit touched paths outside the system gitlink + the history file: $files"
fi

# Zone-era hiding is RETIRED: no skip-worktree bits, no *.php.upstream ignore line.
sw="$(git -C "$PROJECT" ls-files -v -- system | grep -c '^S' || true)"
[ "$sw" -eq 0 ] || fx_fail "found $sw skip-worktree bit(s) on system/ files - the retired zone-era hiding must not reappear"
if [ -f "$PROJECT/.gitignore" ] && grep -q '\*\.php\.upstream' "$PROJECT/.gitignore"; then
    fx_fail "root .gitignore still carries the retired *.php.upstream hiding pattern"
fi

# ---- Phase 2: --no-commit opts out ----
fx_build_downstream v1
before2="$(git -C "$PROJECT" rev-parse HEAD)"

fx_run_pull --no-rebuild --yes --no-commit
[ "$FX_RC" -eq 0 ] || fx_fail "(--no-commit) expected exit 0, got $FX_RC. Output: $FX_OUT"

after2="$(git -C "$PROJECT" rev-parse HEAD)"
[ "$before2" = "$after2" ] || fx_fail "--no-commit still created a commit (HEAD advanced)"
# The updated files are present but left uncommitted for the developer (working tree
# and/or index - the updater does not commit them).
if git -C "$PROJECT" diff --quiet -- system && git -C "$PROJECT" diff --cached --quiet -- system; then
    fx_fail "--no-commit: expected uncommitted system/ changes, tree is clean"
fi

fx_pass
