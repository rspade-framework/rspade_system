#!/bin/bash
# t7: 090_readme_clone_url.sh - the starter README's quick-start clone line must name THIS
# project's own origin, and the script's ONLY authorization to rewrite is that the project's
# README.md is still byte-identical to the pristine copy shipped in the release at
# system/app/RSpade/resource/starter/README.md.
#
# The sandbox is a real `git init` project plus a FABRICATED pristine copy under the sandbox
# SYSTEM_DIR: the script compares bytes and replaces two exact lines, so a short stand-in
# README carrying those two lines exercises every path the shipped one does.

TEST_NAME="environment_updates/cli t7 readme clone url"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

SCRIPT_NAME="090_readme_clone_url.sh"

OLD_CLONE='git clone --depth 1 --recurse-submodules https://github.com/rspade-framework/rspade my-app'
OLD_NOTE='start. `--depth 1` skips the history and is the recommended way in.'

STARTER_DIR_REL="app/RSpade/resource/starter"

# fx_sandbox_project [--no-clone-line] - a git repo whose README.md is byte-identical to a
# fabricated pristine copy in the sandbox SYSTEM_DIR.
fx_sandbox_project() {
    rm -rf "$FX_APP"
    # SYSTEM_DIR is $FX_APP/system, so the pristine copy belongs under it.
    mkdir -p "$FX_APP/system/$STARTER_DIR_REL"
    ( cd "$FX_APP" && git init -q . && git config user.email t@t && git config user.name test )

    local pristine="$FX_APP/system/$STARTER_DIR_REL/README.md"
    {
        echo '# RSpade'
        echo ''
        echo '## Quick start'
        echo ''
        echo '```bash'
        if [ "${1:-}" != "--no-clone-line" ]; then
            echo "$OLD_CLONE"
        else
            echo 'cd my-app'
        fi
        echo '```'
        echo ''
        echo 'The clone is a few hundred megabytes because dependencies are committed. That is'
        echo 'deliberate: it is why there is no install step and no network round-trip when you'
        echo "$OLD_NOTE"
        echo ''
        echo '## License'
    } > "$pristine"

    cp "$pristine" "$FX_APP/README.md"
}

fx_commit_readme() {
    ( cd "$FX_APP" && git add -A && git commit -qm "initial" )
}

fx_readme_has() {
    grep -qxF "$1" "$FX_APP/README.md"
}

# ---- 1. Rewrites when pristine and origin is this project's own. --------------------
fx_sandbox_project
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_readme_has 'git clone --recurse-submodules https://github.com/acme/my-crm.git my-app' \
    || fx_fail "the clone line was not rewritten to the project's own origin"
grep -q -- 'git clone --depth 1' "$FX_APP/README.md" && fx_fail "--depth 1 survived in the clone command"
fx_readme_has "$OLD_NOTE" && fx_fail "the --depth 1 recommendation survived the rewrite"
grep -q 'usually wants to keep' "$FX_APP/README.md" || fx_fail "the requirements-note sentence was not adjusted"
fx_assert_out "https://github.com/acme/my-crm.git"
fx_assert_out "TRACKED"

# Nothing else moved: the file differs from the pristine copy in exactly the two lines.
diff_lines="$(diff "$FX_APP/system/$STARTER_DIR_REL/README.md" "$FX_APP/README.md" | grep -c '^[<>]')"
[ "$diff_lines" -eq 4 ] || fx_fail "expected exactly two changed lines (4 diff rows), got $diff_lines rows"

# ---- 2. Idempotent: the second run is silent (the README is no longer pristine). ----
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "an already-personalized README must produce no output"
[ -z "$FX_ERR" ] || fx_fail "expected no stderr on a no-op run. stderr: $FX_ERR"

# ---- 3. One byte of difference ends it forever. -------------------------------------
fx_sandbox_project
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
printf '.' >> "$FX_APP/README.md"
before="$(cat "$FX_APP/README.md")"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "a developer-edited README is not this update's business"
[ "$(cat "$FX_APP/README.md")" = "$before" ] || fx_fail "an edited README was rewritten"

# ---- 4. No pristine copy in the release (an older framework) -> silent no-op. -------
fx_sandbox_project
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
rm -f "$FX_APP/system/$STARTER_DIR_REL/README.md"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "with no pristine copy there is nothing to authorize a rewrite"
fx_readme_has "$OLD_CLONE" || fx_fail "the README was altered with no pristine copy present"

# ---- 5. The block is absent (a future README that drops it) -> silent no-op. --------
fx_sandbox_project --no-clone-line
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
before="$(cat "$FX_APP/README.md")"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "no clone line means nothing to personalize"
[ "$(cat "$FX_APP/README.md")" = "$before" ] || fx_fail "a README with no clone line was rewritten"

# ---- 6. No origin -> no-op: the pristine window stays open until a remote exists. ---
fx_sandbox_project
before="$(cat "$FX_APP/README.md")"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "no origin means nothing to personalize yet"
[ "$(cat "$FX_APP/README.md")" = "$before" ] || fx_fail "a README was rewritten with no origin configured"

# ---- 7. An origin that IS the starter is a no-op, in both URL shapes. ---------------
for starter_url in \
    https://github.com/rspade-framework/rspade.git \
    git@github.com:rspade-framework/rspade \
    ssh://git@git.internal.hanson.xyz/brianhansonxyz/rspade_project.git ; do
    fx_sandbox_project
    git -C "$FX_APP" remote add origin "$starter_url"
    fx_run "$SCRIPT_NAME"
    fx_assert_rc 0
    fx_assert_silent_stdout "a checkout of the starter ($starter_url) is not a project yet"
    fx_readme_has "$OLD_CLONE" || fx_fail "the starter's own README was rewritten ($starter_url)"
done

# ---- 8. The framework monorepo is skipped: that README is the authored source. ------
fx_sandbox_project
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
env PROJECT_ROOT="$FX_APP" SYSTEM_DIR="$FX_APP/system" IS_FRAMEWORK_DEVELOPER=true \
    bash "$FX_ENV_UPDATES/$SCRIPT_NAME" > "$FX_ROOT/stdout" 2> "$FX_ROOT/stderr"
[ $? -eq 0 ] || fx_fail "the framework-developer skip must exit 0"
fx_readme_has "$OLD_CLONE" || fx_fail "the monorepo gate did not hold - README.md was rewritten"

# ---- 9. Quiet mode prints nothing on stdout, and still applies the change. ----------
fx_sandbox_project
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
fx_commit_readme
fx_run "$SCRIPT_NAME" RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode must suppress the informational lines"
fx_readme_has 'git clone --recurse-submodules https://github.com/acme/my-crm.git my-app' \
    || fx_fail "quiet mode skipped the rewrite"

# ---- 10. The single-commit note is informational, and only in a loud run. -----------
fx_sandbox_project
git -C "$FX_APP" remote add origin https://github.com/acme/my-crm.git
fx_commit_readme
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_out "fresh template repository"

fx_pass
