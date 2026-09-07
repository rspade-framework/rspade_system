#!/bin/bash
# t23: after a pull/merge that succeeded and moved HEAD, the proxy re-applies the
# environment updates (post-update.sh --quiet).
#
# WHY. rsx/resource/ is manifest-ignored, so a pull whose only change is a teammate's new
# application skill triggers no rebuild - and the rebuild is what normally runs the
# environment updates. Without this the skill would arrive wired to nothing.
#
# It must NOT fire when the pull moved nothing (already up to date), and a failing
# post-update must never colour the pull's exit code.

TEST_NAME="git_proxy/cli t23 post-update after a pull"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream

# A SPY post-update.sh, untracked inside the fixture's system/ checkout. The framework
# revision never moves in this test, so the proxy never resets system/ and the spy
# survives every pull.
SPY_LOG="$PROJECT/post_update.log"
cat > "$PROJECT/system/bin/post-update.sh" <<'SPY'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$(cd "$(dirname "$0")/../.." && pwd)/post_update.log"
exit 0
SPY

# 1. Nothing to pull: HEAD does not move, so the hook must not fire.
fx_run pull
fx_assert_rc 0
[ ! -e "$SPY_LOG" ] || fx_fail "post-update must not run when the pull moved nothing: $(cat "$SPY_LOG")"

# 2. A real pull that brings a commit in.
fx_upstream_commit - "app v2" "a teammate's change"
fx_run pull
fx_assert_rc 0
[ -e "$SPY_LOG" ] || fx_fail "post-update must run after a pull that moved HEAD"
grep -q -- '--quiet' "$SPY_LOG" || fx_fail "the pull hook must pass --quiet, got: $(cat "$SPY_LOG")"
[ "$(grep -c . "$SPY_LOG")" -eq 1 ] || fx_fail "post-update must run exactly once, got: $(cat "$SPY_LOG")"

# 3. A FAILING post-update is a warning, never the pull's exit code.
cat > "$PROJECT/system/bin/post-update.sh" <<'SPY'
#!/usr/bin/env bash
echo "spy: deliberate failure" >&2
exit 1
SPY
fx_upstream_commit - "app v3" "another change"
fx_run pull
fx_assert_rc 0 "a failing environment update must not fail the pull"
fx_assert_out "post-update.sh reported a problem"

# 4. A subcommand that is not pull/merge never triggers it.
rm -f "$SPY_LOG"
cat > "$PROJECT/system/bin/post-update.sh" <<'SPY'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$(cd "$(dirname "$0")/../.." && pwd)/post_update.log"
exit 0
SPY
fx_run checkout master
[ ! -e "$SPY_LOG" ] || fx_fail "only pull/merge run the environment updates: $(cat "$SPY_LOG")"

fx_pass
