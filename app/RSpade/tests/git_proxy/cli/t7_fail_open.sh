#!/bin/bash
# t7: FAIL-OPEN. This proxy wraps every git call for every downstream app, so a bug or a
# broken environment must never brick git.
#
# The rule is absolute: anything unexpected becomes plain git with the original argv. A
# developer whose tree is too broken to boot php is exactly the developer who needs git
# working, and a wrapper that traps them there is worse than no wrapper.

TEST_NAME="git_proxy/cli t7 fail-open"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"

# ---- 1. No maintenance script: the sync still happens, git still works. -------------
rm -f "$PROJECT/system/bin/maintenance-mode.sh"

fx_run pull origin master
fx_assert_rc 0
[ "$(fx_actual_revision)" = "$FW_V2" ] \
    || fx_fail "the submodule was not synced when the maintenance script was missing"

# ---- 2. A broken artisan: rsx:clean and the rebuild fail, the checkout still lands. --
fx_build_project
fx_build_upstream
fx_publish_framework_update || fx_fail "fixture: could not publish the framework update"
printf '#!/bin/sh\nexit 1\n' > "$PROJECT/system/artisan"
chmod +x "$PROJECT/system/artisan"

fx_run pull origin master
fx_assert_rc 0
[ "$(fx_actual_revision)" = "$FW_V2" ] \
    || fx_fail "a broken artisan stopped the submodule sync - it must not"

# ---- 3. A subcommand the proxy does not handle is untouched. ------------------------
fx_run log --oneline -1
fx_assert_rc 0
fx_refute_out "rsx:git"

# ---- 4. The operation's OWN exit code always reaches the caller. --------------------
# A failed git operation stays failed, whatever the proxy does afterwards.
fx_run pull origin no_such_branch
[ "$FX_RC" -ne 0 ] || fx_fail "a failed pull must not be reported as success"

fx_pass
