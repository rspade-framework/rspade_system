#!/usr/bin/env bash
#
# 080 - Make the framework's submodule pointer VISIBLE in ordinary git output.
#
# system/ is a git submodule, so its gitlink IS the framework version: the single most
# consequential fact in an update commit. Two configurations hide it.
#
# 1. `.gitmodules` with no `ignore` entry. `git submodule add` writes path/url/branch and
#    nothing else, so a project CONVERTED from the old vendored layout inherits the
#    default `none` - which reports the submodule modified on any working-tree change
#    inside it. system/ is permanently dirty with build churn (the manifest build renames
#    .php <-> .php.upstream to apply class overrides, and it regenerates within a second
#    of any clean), so `none` means ` M system` on every status, forever.
#
# 2. A repo-wide `[diff] ignoreSubmodules = all` in .git/config - the natural answer to
#    that noise, and the reason this update exists. `all` suppresses submodule reporting
#    ENTIRELY, the recorded pointer included, so a framework update lands leaving no
#    trace in status, diff or the commit summary (field report, 2026-08-22:
#    the bump had to be confirmed by hand with `git ls-tree`). It also applies silently
#    to every OTHER gitlink the app keeps.
#
#       none/false -> pointer visible, churn visible (constant noise)
#       all        -> pointer HIDDEN,  churn hidden  (a silent framework update)
#       dirty      -> pointer visible, churn hidden                        <- correct
#
# So: set `ignore = dirty` per-submodule in the TRACKED .gitmodules, and remove the
# blanket repo-wide setting. Scoping it in .gitmodules is what makes it ship - .git/config
# is untracked and a fix there would silently miss every existing and future clone.
#
# WHO ELSE WRITES THIS. bin/publish writes `ignore = dirty` into the starter's .gitmodules,
# and framework-pull-upstream.sh sets it during the vendored -> submodule conversion. This
# script exists for the boxes converted BEFORE that landed (the generated updater lags one
# pull) and for anyone who added the blanket .git/config setting by hand.
#
# DOWNSTREAM ONLY. In the framework monorepo system/ is the authored source, not a
# submodule, and there is nothing here to configure.
#
# THE .gitmodules EDIT IS A TRACKED-FILE CHANGE. It cannot be committed for the developer
# (this runs unattended, inside somebody's working tree), so the one informational line
# says so and asks for the commit. The .git/config unset is untracked machine state and
# needs nothing.
#
# QUIET: RSPADE_ENV_UPDATE_QUIET=true suppresses the informational lines. Problems still
# print to stderr - a quiet run is a quiet SUCCESS, never a silent failure.
#
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

# CONTAINER GATE. First line of executable code, no exceptions (contract rule 0).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"
IS_FRAMEWORK_DEVELOPER="${IS_FRAMEWORK_DEVELOPER:-false}"
QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"

# The monorepo has no system/ submodule; anything below would be meaningless there.
[ "$IS_FRAMEWORK_DEVELOPER" = true ] && exit 0

GITMODULES="$PROJECT_ROOT/.gitmodules"
SUBMODULE_PATH="system"

errored=false

info() { [ "$QUIET" = true ] || echo "[env] $*"; }

# ---------------------------------------------------------------------------
# 1. .gitmodules - submodule.system.ignore = dirty
#
#    A .gitmodules with no `system` entry is not a submodule project (yet); the
#    conversion in framework-pull-upstream.sh owns that case. Skip, silently.
# ---------------------------------------------------------------------------
if [ -f "$GITMODULES" ] \
   && git config --file "$GITMODULES" --get "submodule.${SUBMODULE_PATH}.url" >/dev/null 2>&1; then

    current="$(git config --file "$GITMODULES" --get "submodule.${SUBMODULE_PATH}.ignore" 2>/dev/null || true)"

    if [ "$current" != "dirty" ]; then
        if git config --file "$GITMODULES" "submodule.${SUBMODULE_PATH}.ignore" dirty 2>/dev/null; then
            info "Set 'ignore = dirty' on the system/ submodule in .gitmodules - a framework"
            info "  update (a moved pointer) is now visible in status and diff, while the"
            info "  build's own churn inside system/ stays hidden."
            info "  .gitmodules is TRACKED: commit it so every clone inherits the setting."
        else
            echo "[env] submodule visibility: cannot write $GITMODULES" >&2
            errored=true
        fi
    fi
fi

# ---------------------------------------------------------------------------
# 2. .git/config - remove a blanket diff.ignoreSubmodules, whatever its value.
#
#    Scoping now lives per-submodule in .gitmodules. Any repo-wide value here is
#    either redundant (`dirty`) or actively hiding the pointer (`all`), and it
#    applies to every other gitlink the app keeps as well.
# ---------------------------------------------------------------------------
if [ -d "$PROJECT_ROOT/.git" ] || [ -f "$PROJECT_ROOT/.git" ]; then
    blanket="$(git -C "$PROJECT_ROOT" config --local --get diff.ignoreSubmodules 2>/dev/null || true)"

    if [ -n "$blanket" ]; then
        if git -C "$PROJECT_ROOT" config --local --unset-all diff.ignoreSubmodules 2>/dev/null; then
            info "Removed the repo-wide 'diff.ignoreSubmodules = $blanket' from .git/config - it"
            info "  hid the framework pointer (and every other gitlink) from status and diff."
            info "  Submodule scoping lives per-submodule in .gitmodules now."
        else
            echo "[env] submodule visibility: cannot unset diff.ignoreSubmodules in $PROJECT_ROOT/.git/config" >&2
            errored=true
        fi
    fi
fi

[ "$errored" = true ] && exit 1
exit 0
