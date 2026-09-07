#!/bin/bash
# t24: the framework-update commit carries the FULL breaking changelog - subjects AND
# bodies - not just subject lines.
#
# WHY THIS IS A REQUIREMENT, NOT A NICETY: downstream, the app repo's git history is the
# audit record shown to the people funding the work. "What changed in the framework, and
# why" has to be answerable from `git log` alone. Subject-only failed that completely,
# because the distribution's own commits are release SQUASHES whose subjects read only
# "RSpade framework release X..Y (N commits)". A release that widened the owned zones, added
# index-lock retry and fixed statusline locking recorded exactly ONE content-free line. The
# substance existed - but only in rsx/resource/framework_update_history.dat, which is not
# what git log, a PR view, or a hosting UI shows.
#
# The substance is reachable because bin/publish embeds every underlying monorepo commit's
# full message into the release commit's BODY (measured: 48KB for an 18-commit release), so
# `%b` on a distribution commit IS the complete rationale. The fixture's v2 commit mirrors
# that shape deliberately.
#
# Asserted here:
#   1. The commit BODY contains the upstream body prose, not just the subject line.
#   2. ONE canonical changelog feeds both consumers - the same prose is in the history .dat.
#   3. Agent-attribution noise (Co-Authored-By) is filtered out of the customer-facing text.
#   4. The subject line keeps its existing scannable form.
#   5. The trailers remain the LAST lines, since tooling and humans key off them.

TEST_NAME="framework_update/cli t24 commit carries full changelog"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "pull expected exit 0, got $FX_RC. Output: $FX_OUT"

msg="$(git -C "$PROJECT" log -1 --pretty=%B)"
HIST="$PROJECT/rsx/resource/framework_update_history.dat"

# --- 4. Subject unchanged: scannable, names the range and the commit count. ----
subj="$(git -C "$PROJECT" log -1 --pretty=%s)"
printf '%s' "$subj" | grep -qE '^Framework update [0-9a-f]{12} -> [0-9a-f]{12} \([0-9]+ upstream commits\)$' \
    || fx_fail "subject line changed shape: '$subj'"

# --- 1. THE POINT: the body prose is in the commit message. --------------------
printf '%s' "$msg" | grep -q "SUBSTANTIVE rationale that must reach a downstream app" \
    || fx_fail "commit body is MISSING the upstream commit body prose. Message: $msg"
printf '%s' "$msg" | grep -q -- "- core_a bumped to VERSION 2" \
    || fx_fail "commit body lost the upstream body's indented detail lines. Message: $msg"
# The release subject survives too - it is the release-boundary marker when a range
# spans several releases, and the floor for a commit that has no body at all.
printf '%s' "$msg" | grep -q "v2 release: change core_a" \
    || fx_fail "commit body lost the release subject line. Message: $msg"

# --- 3. Agent attribution filtered out of the customer-facing record. ----------
printf '%s' "$msg" | grep -qi "Co-Authored-By: Somebody" \
    && fx_fail "agent attribution leaked into the commit message. Message: $msg"

# --- 2. ONE canonical changelog: the same prose reached the .dat. --------------
fx_assert_exists "$HIST"
fx_assert_contains "$HIST" "SUBSTANTIVE rationale that must reach a downstream app"
if grep -qi "Co-Authored-By: Somebody" "$HIST"; then
    fx_fail "agent attribution leaked into the history file"
fi

# --- 5. Trailers are still the LAST two lines. --------------------------------
tail2="$(printf '%s\n' "$msg" | grep -v '^$' | tail -2)"
printf '%s' "$tail2" | grep -q '^Framework-Update-Range: ' \
    || fx_fail "Framework-Update-Range trailer is not among the last lines. Tail: $tail2"
printf '%s' "$tail2" | grep -q '^Committed-By: rsx:framework:pull$' \
    || fx_fail "Committed-By trailer is not the last line. Tail: $tail2"

# --- 6. Baseline / --force repair keep their short bodies (no range to list). --
# A repair resync on an up-to-date install has OLD_SHA == NEW_SHA, so there is no upstream
# range and the changelog is empty by construction; its body must stay the fixed one-liner
# rather than becoming blank.
fx_build_downstream v2          # already at the tip
fx_run_pull --no-rebuild --yes --force
[ "$FX_RC" -eq 0 ] || fx_fail "--force repair expected exit 0, got $FX_RC. Output: $FX_OUT"
repair_msg="$(git -C "$PROJECT" log -1 --pretty=%B)"
if printf '%s' "$repair_msg" | grep -q '^Framework repair resync'; then
    printf '%s' "$repair_msg" | grep -q "Owned zones restored to pristine release content" \
        || fx_fail "repair commit lost its fixed body. Message: $repair_msg"
fi

fx_pass
