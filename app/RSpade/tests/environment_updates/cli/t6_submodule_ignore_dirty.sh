#!/bin/bash
# t6: 080_submodule_ignore_dirty.sh - the framework's submodule POINTER must stay visible
# in ordinary git output. It sets submodule.system.ignore = dirty in the TRACKED
# .gitmodules (git submodule add writes path/url/branch only, so a converted project
# inherits the default) and unsets a repo-wide diff.ignoreSubmodules from .git/config
# (the value operators reach for is `all`, which hides the pointer as well as the churn).
#
# The sandbox is a real `git init` repo with a fabricated .gitmodules entry: both edits
# are pure configuration, so no actual submodule clone is needed to exercise them.

TEST_NAME="environment_updates/cli t6 submodule ignore=dirty"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

SCRIPT_NAME="080_submodule_ignore_dirty.sh"

# fx_sandbox_repo [--with-system-entry] - (re)initialise FX_APP as a git repository.
fx_sandbox_repo() {
    rm -rf "$FX_APP"
    mkdir -p "$FX_APP"
    ( cd "$FX_APP" && git init -q . && git config user.email t@t && git config user.name test )

    if [ "${1:-}" = "--with-system-entry" ]; then
        printf '[submodule "system"]\n\tpath = system\n\turl = https://example.com/rspade_system.git\n\tbranch = master\n' \
            > "$FX_APP/.gitmodules"
    fi
}

fx_gitmodules_ignore() {
    git config --file "$FX_APP/.gitmodules" --get submodule.system.ignore 2>/dev/null || true
}

fx_blanket() {
    git -C "$FX_APP" config --local --get diff.ignoreSubmodules 2>/dev/null || true
}

# ---- 1. Sets ignore = dirty when it is missing. -------------------------------------
fx_sandbox_repo --with-system-entry
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ "$(fx_gitmodules_ignore)" = "dirty" ] || fx_fail "expected submodule.system.ignore=dirty, got '$(fx_gitmodules_ignore)'"
fx_assert_out "ignore = dirty"
fx_assert_out "commit it"   # the .gitmodules edit is tracked; the developer must commit it

# The url and branch it did not come to change are untouched.
[ "$(git config --file "$FX_APP/.gitmodules" --get submodule.system.url)" = "https://example.com/rspade_system.git" ] \
    || fx_fail "the submodule url was altered"
[ "$(git config --file "$FX_APP/.gitmodules" --get submodule.system.branch)" = "master" ] \
    || fx_fail "the submodule branch was altered"

# ---- 2. Idempotent: the second run is silent. ---------------------------------------
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "an already-applied environment update must print nothing"
[ -z "$FX_ERR" ] || fx_fail "expected no stderr on a no-op run. stderr: $FX_ERR"
[ "$(fx_gitmodules_ignore)" = "dirty" ] || fx_fail "the second run lost the setting"

# ---- 3. A WRONG value is corrected (not only an absent one). ------------------------
git config --file "$FX_APP/.gitmodules" submodule.system.ignore all
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ "$(fx_gitmodules_ignore)" = "dirty" ] || fx_fail "ignore=all was not corrected to dirty"

# ---- 4. A repo-wide diff.ignoreSubmodules is unset, whatever its value. -------------
fx_sandbox_repo --with-system-entry
git -C "$FX_APP" config --local diff.ignoreSubmodules all
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ -z "$(fx_blanket)" ] || fx_fail "diff.ignoreSubmodules is still set to '$(fx_blanket)'"
fx_assert_out "diff.ignoreSubmodules"

# ... including a value that is not `all`: scoping belongs in .gitmodules either way.
git -C "$FX_APP" config --local diff.ignoreSubmodules dirty
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ -z "$(fx_blanket)" ] || fx_fail "a non-'all' diff.ignoreSubmodules was left in place"

# ---- 5. A .gitmodules with no `system` entry is left strictly alone. ----------------
fx_sandbox_repo
printf '[submodule "rsx/resource/model-builder"]\n\tpath = rsx/resource/model-builder\n\turl = https://example.com/mb.git\n' \
    > "$FX_APP/.gitmodules"
before="$(cat "$FX_APP/.gitmodules")"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "a project with no system submodule is not this update's business"
[ "$(cat "$FX_APP/.gitmodules")" = "$before" ] || fx_fail "another project's .gitmodules was modified"

# A project with no .gitmodules at all is equally none of its business.
fx_sandbox_repo
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "a project with no .gitmodules must be skipped silently"
[ ! -e "$FX_APP/.gitmodules" ] || fx_fail "a .gitmodules was created out of nothing"

# ---- 6. The framework monorepo is skipped: system/ there is authored source. --------
fx_sandbox_repo --with-system-entry
env PROJECT_ROOT="$FX_APP" SYSTEM_DIR="$FX_APP/system" IS_FRAMEWORK_DEVELOPER=true \
    bash "$FX_ENV_UPDATES/$SCRIPT_NAME" > "$FX_ROOT/stdout" 2> "$FX_ROOT/stderr"
[ $? -eq 0 ] || fx_fail "the framework-developer skip must exit 0"
[ -z "$(fx_gitmodules_ignore)" ] || fx_fail "the monorepo gate did not hold - .gitmodules was written"

# ---- 7. Quiet mode prints nothing on stdout, and still applies the change. ----------
fx_sandbox_repo --with-system-entry
git -C "$FX_APP" config --local diff.ignoreSubmodules all
fx_run "$SCRIPT_NAME" RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode must suppress the informational lines"
[ "$(fx_gitmodules_ignore)" = "dirty" ] || fx_fail "quiet mode skipped the .gitmodules change"
[ -z "$(fx_blanket)" ] || fx_fail "quiet mode skipped the .git/config unset"

fx_pass
