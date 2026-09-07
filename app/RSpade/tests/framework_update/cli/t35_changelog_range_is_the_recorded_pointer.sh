#!/bin/bash
# t35: the update range starts at the RECORDED GITLINK, never at the submodule's
# checked-out HEAD - so a pull interrupted between the checkout and the pointer
# commit is COMPLETED by the next pull, with the whole changelog it owed.
#
# THE FAILURE THIS EXISTS FOR (owner-reported). The run order is
#     fetch_upstream -> compute_changelog -> checkout_new -> write_history
#     -> commit_pointer -> do_rebuild
# so between checkout_new and a successful commit_pointer the working tree is at NEW
# while the parent repository still records OLD. That window is real and reachable:
# a git index lock that never clears (git_retry gives up LOUDLY there by design), a
# kill, a box going down. OLD_SHA used to be read from the CHECKOUT, so the next run
# computed OLD == NEW, took the "Framework is up to date" branch, rebuilt and exited
# 0 - never committing the pointer. The range's changelog was then lost from the
# application's history permanently, and the recorded gitlink stayed stale.
#
# The fix is a one-line rule with a big consequence: the previous revision is what
# the APPLICATION'S HISTORY records (recorded_pointer), because the changelog range
# and the "(N upstream commits)" count are statements about what that history moved.
# A checkout already sitting at NEW is the RECOVERY case, not an up-to-date one.
#
# Asserted here, over a THREE-release distribution (A -> B -> C):
#   1. The recovery pull makes exactly ONE new commit.
#   2. Its subject names the full range A -> C with the correct commit count.
#   3. Its body carries BOTH intervening releases - subject lines AND body prose.
#   4. The Framework-Update-Range trailer is A..C.
#   5. The history .dat records `from=A to=C` with the same full text.
#   6. The gitlink is now C.
#   7. A further pull with nothing new reports up to date and commits nothing.

TEST_NAME="framework_update/cli t35 changelog range is the recorded pointer"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream                       # A = V1_SHA, B = V2_SHA
A="$V1_SHA"
B="$V2_SHA"

fx_build_downstream v1                  # recorded A, checked out A

# C: a third release, published AFTER the downstream was built.
fx_add_upstream_release "v3 release: third release on top of v2" \
    "THIRD RELEASE RATIONALE that must also reach the application's history" \
    || fx_fail "could not publish the third release"
C="$FX_RELEASE_SHA"
[ -n "$C" ] && [ "$C" != "$B" ] || fx_fail "third release sha looks wrong: '$C'"

# =============================================================================
# Reproduce the post-failure state EXACTLY: the submodule fetched and checked out
# the new tip, and then the run died before commit_pointer succeeded. Doing it by
# hand rather than through a seam is deliberate - the state is what matters, and it
# is reachable from more than one failure (lock, kill, power).
# =============================================================================
git -C "$PROJECT/system" fetch -q "$BARE" master || fx_fail "fixture fetch failed"
git -C "$PROJECT/system" checkout -q --force "$C" || fx_fail "fixture checkout of C failed"

[ "$(fx_recorded_revision)" = "$A" ] || fx_fail "precondition: the project should still RECORD A ($A), records $(fx_recorded_revision)"
[ "$(fx_actual_revision)"   = "$C" ] || fx_fail "precondition: system/ should be CHECKED OUT at C ($C), is $(fx_actual_revision)"

commits_before="$(git -C "$PROJECT" rev-list --count HEAD)"

# =============================================================================
# The next pull. Under the old (checkout-sourced) OLD_SHA this printed
# "Framework is up to date" and made no commit at all.
# =============================================================================
fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "recovery pull expected exit 0, got $FX_RC. Output: $FX_OUT"

if printf '%s' "$FX_OUT" | grep -qi "Framework is up to date"; then
    fx_fail "an interrupted update was mistaken for an up-to-date install. Output: $FX_OUT"
fi

# --- 1. Exactly one new commit. -----------------------------------------------
commits_after="$(git -C "$PROJECT" rev-list --count HEAD)"
[ "$commits_after" -eq "$(( commits_before + 1 ))" ] \
    || fx_fail "expected exactly 1 new commit, went from $commits_before to $commits_after"

msg="$(git -C "$PROJECT" log -1 --pretty=%B)"
subj="$(git -C "$PROJECT" log -1 --pretty=%s)"

# --- 2. The subject names the FULL range, with the real commit count. ---------
expected_count="$( cd "$FX_ROOT/upstream_work" && git rev-list --count "$A..$C" )"
[ "$expected_count" -eq 2 ] || fx_fail "fixture sanity: A..C should span 2 releases, spans $expected_count"
expected_subj="Framework update ${A:0:12} -> ${C:0:12} (${expected_count} upstream commits)"
[ "$subj" = "$expected_subj" ] || fx_fail "subject should be '$expected_subj', got '$subj'"

# --- 3. The body carries BOTH releases, subjects and bodies alike. ------------
printf '%s' "$msg" | grep -q "v2 release: change core_a" \
    || fx_fail "commit body is missing release B's subject line. Message: $msg"
printf '%s' "$msg" | grep -q "SUBSTANTIVE rationale that must reach a downstream app" \
    || fx_fail "commit body is missing release B's body prose. Message: $msg"
printf '%s' "$msg" | grep -q "v3 release: third release on top of v2" \
    || fx_fail "commit body is missing release C's subject line. Message: $msg"
printf '%s' "$msg" | grep -q "THIRD RELEASE RATIONALE" \
    || fx_fail "commit body is missing release C's body prose. Message: $msg"
if printf '%s' "$msg" | grep -qi "Co-Authored-By: Somebody"; then
    fx_fail "agent attribution leaked into the recovery commit message. Message: $msg"
fi

# --- 4. The machine-readable range trailer is A..C. ---------------------------
printf '%s' "$msg" | grep -q "^Framework-Update-Range: $A..$C$" \
    || fx_fail "Framework-Update-Range should be $A..$C. Message: $msg"

# --- 5. The durable record says the same thing, in full. ----------------------
HIST="$PROJECT/rsx/resource/framework_update_history.dat"
fx_assert_exists "$HIST"
grep -q "from=$A to=$C" "$HIST" || fx_fail "history .dat is missing 'from=$A to=$C'"
fx_assert_contains "$HIST" "SUBSTANTIVE rationale that must reach a downstream app"
fx_assert_contains "$HIST" "THIRD RELEASE RATIONALE"

# --- 6. The gitlink now records C. --------------------------------------------
[ "$(fx_recorded_revision)" = "$C" ] || fx_fail "gitlink should now record C ($C), records $(fx_recorded_revision)"
[ "$(fx_actual_revision)"   = "$C" ] || fx_fail "system/ should be checked out at C ($C), is $(fx_actual_revision)"

# =============================================================================
# 7. With record and tip agreeing, THIS is what "up to date" means - and it still
#    commits nothing.
# =============================================================================
commits_before="$commits_after"
fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "follow-up pull expected exit 0, got $FX_RC. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -qi "up to date" \
    || fx_fail "a genuinely current install must report up to date. Output: $FX_OUT"
[ "$(git -C "$PROJECT" rev-list --count HEAD)" -eq "$commits_before" ] \
    || fx_fail "an up-to-date pull must not commit"

fx_pass
