#!/bin/bash
# t19: CWD FIDELITY - a silent-wrong-answer defect, found while diagnosing the
# 2026-08-18 pull failure and easy to reintroduce.
#
# system/artisan does chdir(__DIR__) before anything else, so every
# `php artisan rsx:git ...` reaches the proxy with cwd = system/ no matter where the
# operator was standing. Git resolves a relative pathspec against cwd, so
# `rsx:git log -- rsx/foo.php` matched NOTHING and exited 0 - an audit command answering
# "no history" for a file that has history, which is the worst failure shape there is.
#
# The shim exports RSX_GIT_CWD before php can destroy it; the proxy restores it. Without
# the shim the honest anchor is the project root, which is what every relative pathspec a
# human types is written against.

TEST_NAME="git_proxy/cli t19 passthrough cwd"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project

# A file with history, in the APP tree - the shape of the original defect.
mkdir -p "$PROJECT/app/deep"
printf 'v1\n' > "$PROJECT/app/deep/tracked.txt"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "add a file with history"

# ---- 1. RSX_GIT_CWD is honoured: a pathspec relative to a SUBDIRECTORY resolves. ----
out="$( cd "$PROJECT/app" && RSX_GIT_CWD="$PROJECT/app" \
    bash "$PROJECT/system/bin/rsx-git.sh" log --oneline -- deep/tracked.txt 2>&1 )"
printf '%s' "$out" | grep -q 'add a file with history' \
    || fx_fail "a subdirectory-relative pathspec found no history. Output: $out"

# ---- 2. Without RSX_GIT_CWD, a cwd of system/ is undone to the project root. --------
# This is the artisan-chdir case: the pathspec is written against the project root.
out="$( cd "$PROJECT/system" && bash "$PROJECT/system/bin/rsx-git.sh" \
    log --oneline -- app/deep/tracked.txt 2>&1 )"
printf '%s' "$out" | grep -q 'add a file with history' \
    || fx_fail "a project-root pathspec found no history from cwd=system/. Output: $out"

# ---- 3. A cwd the caller GENUINELY chose is left alone. -----------------------------
# Only the artisan chdir is undone. Running from app/ directly must behave like git.
out="$( cd "$PROJECT/app" && bash "$PROJECT/system/bin/rsx-git.sh" \
    log --oneline -- deep/tracked.txt 2>&1 )"
printf '%s' "$out" | grep -q 'add a file with history' \
    || fx_fail "a genuinely-chosen cwd was not respected. Output: $out"

# ---- 4. THE DEFECT ITSELF: a pathspec that matches nothing must not silently pass. --
# (Proving the test above is meaningful: a wrong cwd produces empty output, not an error.)
out="$( cd "$PROJECT" && bash "$PROJECT/system/bin/rsx-git.sh" \
    log --oneline -- no/such/path.txt 2>&1 )"
[ -z "$out" ] || fx_fail "expected empty output for a genuinely absent path. Output: $out"

fx_pass
