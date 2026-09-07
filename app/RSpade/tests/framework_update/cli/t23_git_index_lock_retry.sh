#!/bin/bash
# t23: a LOCKED git index is transient contention, retried - and a lock that never
# clears aborts the update LOUDLY, before the rebuild.
#
# Field failure (2026-08-11): the updater's git operations died outright the moment
# .git/index.lock existed. Git takes that lock for any index write - `add`, `commit`,
# and (until it was fixed in the same pass) a plain `status` refresh from the Claude
# Code status-line renderer, which paints constantly and, on a repo with an 18MB index
# (node_modules is tracked), is slow enough to collide with real work; a paint cut
# short mid-refresh ORPHANS the lock. So one unlucky moment aborted a whole framework
# update.
#
# Contract: the load-bearing git operations (staging system/, the framework-update
# commit) retry up to 3 times with a 1s pause. A holder that releases inside that
# window is absorbed. A lock that outlives all 3 attempts is FATAL - the run stops
# BEFORE the rebuild rather than building on a framework tree it could not commit -
# and the failure names the lock, the armed state, and the exact recovery.
#
# Note on coverage: the "released mid-retry, absorbed" path is NOT asserted by racing
# a background releaser against the pull. The pull spends seconds fetching and syncing
# before it ever touches the index, so any such race just releases the lock long before
# git runs and proves nothing. What IS proven here is every observable piece of the
# mechanism: the retry loop runs the full 3 attempts with the pause between them
# (case 1), and an unlocked run still succeeds and commits (case 2). A lock released
# between two of those attempts is the same code path as case 1 with an earlier exit.

TEST_NAME="framework_update/cli t23 git index lock retry"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream

# =============================================================================
# 1. A lock that never clears: 3 visible attempts, then a loud abort.
#
# The lock is written NON-EMPTY on purpose. A zero-byte lock held by nobody is an
# ORPHAN and is now cleared automatically (case 3 below); a non-empty one means git
# was part-way through writing a new index, which must never be removed underneath
# it. So non-empty is how you simulate genuine, unresolvable contention.
# =============================================================================
fx_build_downstream v1
LOCK="$PROJECT/.git/index.lock"
printf 'partial index write\n' > "$LOCK"      # non-empty: NOT an orphan, never cleared

start="$(date +%s)"
fx_run_pull --no-rebuild --yes
elapsed=$(( $(date +%s) - start ))
rm -f "$LOCK"

[ "$FX_RC" -ne 0 ] || fx_fail "a permanently-locked index must FAIL the update. Output: $FX_OUT"

# All three attempts, and the pause between them, are OBSERVABLE.
printf '%s' "$FX_OUT" | grep -q "attempt 1/3" || fx_fail "attempt 1 not surfaced. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q "attempt 2/3" || fx_fail "attempt 2 not surfaced. Output: $FX_OUT"
[ "$elapsed" -ge 2 ] || fx_fail "expected >=2s of retry pauses, took ${elapsed}s (is the 1s sleep there?)"

# The failure names the lock, the armed state, and the recovery.
printf '%s' "$FX_OUT" | grep -qi "stayed LOCKED" \
    || fx_fail "failure must say the index stayed locked. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q "index.lock" \
    || fx_fail "failure must NAME .git/index.lock. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -qi "NOT in history" \
    || fx_fail "failure must state the armed (on-disk, uncommitted) state. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q "rsx:framework:pull" \
    || fx_fail "failure must name the recovery command. Output: $FX_OUT"

# It must NOT claim a rebuild on the way out - stopping short is the point.
if printf '%s' "$FX_OUT" | grep -qi "Framework rebuilt"; then
    fx_fail "a lock-aborted run must not report a successful rebuild. Output: $FX_OUT"
fi

# =============================================================================
# 2. A ZERO-BYTE lock held by NOBODY is an orphan: cleared, and the update proceeds.
#
# This is the case that actually bit. A status-line render (or any git) killed
# mid-write leaves an O_CREAT|O_EXCL lockfile with nothing behind it, and nothing
# ever releases it - so retrying alone cannot help and a framework update dies for
# no reason at all. Removal is evidence-based: zero bytes AND no process holding it.
# =============================================================================
fx_build_downstream v1
LOCK="$PROJECT/.git/index.lock"
: > "$LOCK"                     # 0 bytes, no holder -> provably an orphan

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "an orphaned 0-byte lock must be cleared, not fatal (rc=$FX_RC). Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -qi "removing the orphan" \
    || fx_fail "clearing an orphan must be reported, not silent. Output: $FX_OUT"
[ ! -f "$LOCK" ] || fx_fail "the orphaned lock file should be gone"
head_subject="$(git -C "$PROJECT" log -1 --pretty=%s 2>/dev/null)"
printf '%s' "$head_subject" | grep -qi "Framework" \
    || fx_fail "update did not complete after clearing the orphan (HEAD: $head_subject)"

# =============================================================================
# 3. No lock: the wrapper is transparent - the update succeeds and commits.
# =============================================================================
fx_build_downstream v1

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "an unlocked update must succeed (rc=$FX_RC). Output: $FX_OUT"

# No retry noise when there is nothing to contend with.
if printf '%s' "$FX_OUT" | grep -q "index is locked"; then
    fx_fail "retry warnings must not appear when the index is free. Output: $FX_OUT"
fi

head_subject="$(git -C "$PROJECT" log -1 --pretty=%s 2>/dev/null)"
printf '%s' "$head_subject" | grep -qi "Framework" \
    || fx_fail "framework-update commit missing (HEAD: $head_subject)"

fx_pass
