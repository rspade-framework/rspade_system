#!/bin/bash
# t8: PASSTHROUGH FIDELITY. Everything the proxy does not handle must be exactly git -
# same output, same exit code, same argv, nothing announced.
#
# The proxy handles ONE thing: after an operation that can move the recorded framework
# revision, it makes the submodule agree. Every other subcommand is git's, untouched. A
# wrapper that quietly reshapes ordinary commands is a wrapper nobody can trust.

TEST_NAME="git_proxy/cli t8 passthrough fidelity"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project

# ---- 1. Read commands are byte-identical to plain git. ------------------------------
# Each row is one command, word-split through an array: `${=var}` is zsh and expands to
# nothing here, which silently emptied every comparison in this loop.
for args in "log --oneline -1" "status --porcelain" "rev-parse HEAD" "branch --show-current"; do
    read -r -a argv <<< "$args"
    fx_run "${argv[@]}"
    plain="$(cd "$PROJECT" && git "${argv[@]}" 2>&1)"
    [ "$FX_OUT" = "$plain" ] \
        || fx_fail "'git $args' differed from plain git.
  proxy: $FX_OUT
  plain: $plain"
done

# ---- 2. Exit codes are git's own, success and failure alike. ------------------------
fx_run rev-parse HEAD
fx_assert_rc 0
fx_run rev-parse no_such_ref_at_all
[ "$FX_RC" -ne 0 ] || fx_fail "a failing rev-parse must not report success"

# ---- 2b. REVISION SUFFIXES REACH GIT INTACT. ----------------------------------------
# `^` is the character a developer repairing a merge types most (`show HEAD^2:path` to
# read the incoming side of it), and it is the one most likely to be eaten by a shell on
# the way through three layers - the artisan interception's escapeshellarg, the script's
# argv array, the exec. A field report (2026-09-21) read a `fatal: invalid object name
# 'HEAD^2'` as the proxy losing it; it does not, and this says so permanently.
fx_run rev-parse 'HEAD^'
plain="$(cd "$PROJECT" && git rev-parse 'HEAD^' 2>&1)"
[ "$FX_OUT" = "$plain" ] || fx_fail "a caret revision suffix did not survive the proxy.
  proxy: $FX_OUT
  plain: $plain"

# ---- 3. Nothing is announced on a passthrough. --------------------------------------
fx_run status --porcelain
fx_refute_out "rsx:git"

# ---- 4. A DIRTY SUBMODULE is hidden by .gitmodules ignore=dirty, not by the proxy. ---
# The manifest build renames .php <-> .php.upstream inside system/ constantly; without
# ignore=dirty every status in every downstream project would report ` M system` forever
# for churn that regenerates itself. This is git's configuration doing the work - the
# proxy has no pathspec tricks any more.
printf 'churn\n' > "$PROJECT/system/app/RSpade/scratch_churn.php"
fx_run status --porcelain
printf '%s' "$FX_OUT" | grep -q '^ M system$' \
    && fx_fail "a dirty submodule leaked into status - is ignore=dirty set in .gitmodules?"
rm -f "$PROJECT/system/app/RSpade/scratch_churn.php"

# ---- 5. A MOVED revision is NOT hidden - that is the thing you must see. -------------
git -C "$PROJECT/system" checkout -q "$FW_V2"
fx_run status --porcelain
printf '%s' "$FX_OUT" | grep -q 'system' \
    || fx_fail "a moved framework revision must be visible in status"

fx_pass
