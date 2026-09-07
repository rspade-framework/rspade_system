#!/bin/bash
# t9: in the framework monorepo the proxy is EXACTLY git. system/ there is the authored
# framework source, not a vendored tree - excluding it from add/commit, or resetting it
# to HEAD before a pull, would destroy the work being done.

TEST_NAME="git_proxy/cli t9 monorepo inertness"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project framework_dev
fx_build_upstream
fx_upstream_commit "framework v2" "app v2" "upstream work"

printf 'authored framework work\n' > "$PROJECT/system/app/RSpade/core.php"
printf 'app edit\n'                > "$PROJECT/app/app_file.txt"

# status shows the truth, with no footer.
FX_OUT="$(cd "$PROJECT" && bash "$PROJECT/system/bin/rsx-git.sh" status --porcelain 2>&1)"
FX_RC=$?
fx_assert_rc 0
fx_assert_out "system/app/RSpade/core.php" "the monorepo must SEE its own framework source"
fx_refute_out "[rsx:git]" "the monorepo path announces nothing"

# add -A stages system/ like any other source.
fx_run add -A
fx_assert_rc 0
staged="$(fx_git diff --cached --name-only)"
case "$staged" in
    *system/app/RSpade/core.php*) : ;;
    *) fx_fail "the monorepo must stage system/. Staged: $staged" ;;
esac

fx_git commit -q -m "framework work"

# A pull does not cycle maintenance and does not reset system/.
printf 'more authored work\n' > "$PROJECT/system/app/RSpade/core.php"
fx_run pull
fx_assert_file_contains "$PROJECT/system/app/RSpade/core.php" "more authored work"

if [ -f "$PROJECT/artisan.log" ]; then
    fx_fail "the monorepo path must not call artisan at all. Log: $(cat "$PROJECT/artisan.log")"
fi
fx_assert_absent "$(fx_maint_flag)"

fx_pass
