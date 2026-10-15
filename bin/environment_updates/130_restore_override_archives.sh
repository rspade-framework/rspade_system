#!/usr/bin/env bash
#
# 130 - Restore framework files left archived as <file>.upstream by an earlier override pass.
#
# A class override no longer moves anything on disk: the framework file stays where it is,
# under its own name, and the manifest records which application file overrides it
# (rsx:man class_override). An install built by an earlier framework release may still
# carry the archives that release made - system/**/X.php.upstream beside a MISSING X.php -
# and nothing in the current framework reads them. Once the override that caused one is
# removed, the framework class it archived would simply be absent.
#
# Most installs never need this: the framework pull, the rsx:git submodule sync and
# rsx:clean all reset system/ (reset --hard + clean -fdx), which restores every tracked file
# and removes every archive. This script serves a box that reached the current framework
# some other way (a manual checkout or `git submodule update` inside system/).
#
# FOR EACH system/**/*.{php,js,jqhtml,scss}.upstream (vendor/ and node_modules/ excluded):
#   - the tracked sibling (the same path without .upstream) is MISSING: restore it from git
#     (`git -C system checkout -- <sibling>`), then delete the archive. A sibling git does
#     not know is reported and the archive is left in place - it may be the only copy.
#   - the sibling EXISTS: the tracked file wins and the archive is deleted. The archive is
#     stale by definition; it is a copy of an older release.
#
# DOWNSTREAM ONLY. In the framework monorepo system/ is the authored source and is never
# archived into.
#
# RETIRABLE once the fleet has turned over past the release that stopped archiving.
#
# QUIET: RSPADE_ENV_UPDATE_QUIET=true suppresses the informational line. Problems still
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

[ "$IS_FRAMEWORK_DEVELOPER" = true ] && exit 0
[ -d "$SYSTEM_DIR" ] || exit 0

info() { [ "$QUIET" = true ] || echo "[env] $*"; }

restored=0
removed=0
errored=false

while IFS= read -r -d '' archive; do
    sibling="${archive%.upstream}"
    relative="${sibling#"$SYSTEM_DIR"/}"

    if [ ! -e "$sibling" ]; then
        if ! git -C "$SYSTEM_DIR" ls-files --error-unmatch -- "$relative" >/dev/null 2>&1; then
            echo "[env] override archive: system/$relative.upstream has no tracked framework file to restore; left in place." >&2
            continue
        fi

        if ! git -C "$SYSTEM_DIR" checkout -- "$relative" 2>/dev/null; then
            echo "[env] override archive: could not restore system/$relative from git; system/$relative.upstream left in place." >&2
            errored=true
            continue
        fi

        restored=$((restored + 1))
    fi

    if rm -f -- "$archive"; then
        removed=$((removed + 1))
    else
        echo "[env] override archive: could not delete system/$relative.upstream" >&2
        errored=true
    fi
done < <(find "$SYSTEM_DIR" \
            \( -name vendor -o -name node_modules \) -prune -o \
            -type f \( -name '*.php.upstream' -o -name '*.js.upstream' -o -name '*.jqhtml.upstream' -o -name '*.scss.upstream' \) \
            -print0 2>/dev/null)

if [ "$removed" -gt 0 ]; then
    info "Removed $removed class-override archive(s) (*.upstream) from system/, restoring $restored framework file(s) from git - overrides now leave the framework tree untouched."
fi

[ "$errored" = true ] && exit 1
exit 0
