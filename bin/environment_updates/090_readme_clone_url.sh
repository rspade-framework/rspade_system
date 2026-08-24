#!/usr/bin/env bash
#
# 090 - Point the starter README's quick-start clone line at THIS project's own remote.
#
# The starter project ships with a public README whose quick start says:
#
#     git clone --depth 1 --recurse-submodules https://github.com/rspade-framework/rspade my-app
#
# That line is correct on github.com/rspade-framework/rspade, where somebody is reading it
# to GET the framework. It is wrong the moment a developer has made their own project from
# it - "Use this template" creates a NEW repository with one squashed initial commit and the
# developer's own origin, and a fork or a clone-and-push behaves the same way. The README
# then tells that project's own teammates to clone somebody else's repository. GitHub
# Markdown has no repo-URL variable, so the fix cannot be in the file: it has to happen
# after the clone, which is what this is.
#
# THE ONLY AUTHORIZATION TO REWRITE IS BYTE IDENTITY. A pristine copy of the shipped README
# rides inside the release at:
#
#     system/app/RSpade/resource/starter/README.md
#
# If the project's own README.md is not byte-identical to it, this script does nothing, ever
# again - the developer has edited it, or a previous run already personalized it. No git
# history heuristics, no marker comments, no "looks unmodified" fuzz: one cmp, and hands off.
# A release that predates the pristine copy has none, which is also a silent no-op.
#
# THE ORIGIN. `git remote get-url origin`, verbatim. No origin (or no git repo) is a
# no-op: a placeholder would carry no more information than the shipped line and, by
# changing the file, would close the pristine window for good - so a remote added a minute
# later would never be picked up. Leaving the file untouched keeps that window open until
# an origin exists. And if origin IS the starter repository itself (or the internal
# rspade_project), this is a checkout of the starter and not a project yet: no-op, the
# shipped line is already correct there.
#
# --depth 1 IS DROPPED. It is the right advice for someone taking a look at the framework
# and the wrong advice for a teammate joining a project: they want the history.
#
# TRACKED FILE. README.md is committed, so the rewrite is a working-tree change the
# developer must commit like any other. The one informational line says so.
#
# QUIET: RSPADE_ENV_UPDATE_QUIET=true suppresses the informational lines. Problems still
# print to stderr - a quiet run is a quiet SUCCESS, never a silent failure.
#
# DOWNSTREAM ONLY. The monorepo has no starter README at its root, and the publish asset it
# does carry is the AUTHORED source - the last file that should be rewritten in place.
#
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

# CONTAINER GATE. First line of executable code, no exceptions (contract rule 0).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"
IS_FRAMEWORK_DEVELOPER="${IS_FRAMEWORK_DEVELOPER:-false}"
QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"

[ "$IS_FRAMEWORK_DEVELOPER" = true ] && exit 0

PRISTINE="$SYSTEM_DIR/app/RSpade/resource/starter/README.md"
README="$PROJECT_ROOT/README.md"

info() { [ "$QUIET" = true ] || echo "[env] $*"; }

# The two lines this update owns, matched EXACTLY and replaced whole.
OLD_CLONE='git clone --depth 1 --recurse-submodules https://github.com/rspade-framework/rspade my-app'
OLD_NOTE='start. `--depth 1` skips the history and is the recommended way in.'
NEW_NOTE='start. Adding `--depth 1` skips the history, which a teammate cloning this project usually wants to keep.'

# ---------------------------------------------------------------------------
# 1. PRISTINE GATE. No shipped copy (an older release), no project README, or a
#    README that differs by so much as one byte -> not ours to touch.
# ---------------------------------------------------------------------------
[ -f "$PRISTINE" ] || exit 0
[ -f "$README" ] || exit 0
cmp -s "$PRISTINE" "$README" || exit 0

# The block itself must be present. (It is, in a byte-identical file - this is a
# guard against a future README that drops the line while the pristine copy tracks
# it, which would otherwise be a silent rewrite of nothing.)
grep -qxF "$OLD_CLONE" "$README" || exit 0

# ---------------------------------------------------------------------------
# 2. THE ORIGIN URL. None yet -> wait (keep the pristine window open).
# ---------------------------------------------------------------------------
origin="$(git -C "$PROJECT_ROOT" remote get-url origin 2>/dev/null || true)"

case "$origin" in
    # A checkout of the starter itself is not a project yet; the shipped line is right.
    *rspade-framework/rspade | *rspade-framework/rspade.git | */rspade_project | */rspade_project.git)
        exit 0
        ;;
esac

[ -n "$origin" ] || exit 0
url="$origin"

NEW_CLONE="git clone --recurse-submodules $url my-app"

# ---------------------------------------------------------------------------
# 3. REWRITE. Exact whole-line replacement, atomic (temp in the same directory,
#    then mv), and every step checked.
# ---------------------------------------------------------------------------
tmp="$README.rspade-env-update.$$"

if ! : > "$tmp" 2>/dev/null; then
    echo "[env] readme clone url: cannot write $tmp" >&2
    exit 1
fi

rewrite_ok=true
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        "$OLD_CLONE") line="$NEW_CLONE" ;;
        "$OLD_NOTE")  line="$NEW_NOTE" ;;
    esac
    printf '%s\n' "$line" >> "$tmp" || { rewrite_ok=false; break; }
done < "$README"

if [ "$rewrite_ok" != true ] || ! grep -qxF "$NEW_CLONE" "$tmp"; then
    rm -f "$tmp"
    echo "[env] readme clone url: the rewrite did not produce the expected line; README.md left untouched" >&2
    exit 1
fi

if ! mv -f "$tmp" "$README" 2>/dev/null; then
    rm -f "$tmp"
    echo "[env] readme clone url: cannot replace $README" >&2
    exit 1
fi

info "README.md quick start now clones $url (this project's own origin) instead of the"
info "  RSpade starter repository. README.md is TRACKED: commit it with your next change."

# Informational only, never a gate: one commit is what "Use this template" produces.
if [ "$(git -C "$PROJECT_ROOT" rev-list --count HEAD 2>/dev/null || echo '')" = "1" ]; then
    info "  (a fresh template repository - single initial commit)"
fi

exit 0
