#!/usr/bin/env bash
#
# 120 - Retire the system/storage and system/tmp symlinks.
#
# An earlier layout kept a convenience symlink in system/ for each of the three volatile
# trees. build/ still has one and always will: it is FIXED at <project>/build, so the
# link is a constant string.
#
# storage/ and tmp/ are RELOCATABLE (RSX_STORAGE_PATH, RSX_TMP_PATH), and a link cannot
# promise where a relocated root is. On a sealed production box system/ is read-only, so
# an operator who edits either key afterwards would be left with a link pointing at the
# old tree and no way to repair it - the boot guard refusing to start, in a tree it is
# not allowed to write. Code reaches both roots through the path owner instead, which
# reads the live value on every call.
#
# A REAL directory or file at either path is reported and left alone: this script did not
# put it there, and it may hold the only copy of something.
#
# Contract (environment_updates/CLAUDE.md): self-detecting, idempotent, silent when
# already applied, non-fatal.

set -u

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container - these scripts modify the environment around the project, and that
# environment is the container's, not the host's.
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"

QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"
info() { [ "$QUIET" = true ] || echo "[env] $*"; }

for name in storage tmp; do
    link="$SYSTEM_DIR/$name"

    [ -L "$link" ] || [ -e "$link" ] || continue

    if [ -L "$link" ]; then
        rm -f "$link" && info "Removed the system/$name symlink (the path owner answers for that root now)."
        continue
    fi

    echo "[env] system/$name is a real $( [ -d "$link" ] && echo directory || echo file ), not the symlink this script retires." >&2
    echo "[env]   Nothing was removed. Move what matters out, then: rm -rf '$link'" >&2
done

exit 0
