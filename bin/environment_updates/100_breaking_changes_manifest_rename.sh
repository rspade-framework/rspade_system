#!/usr/bin/env bash
#
# 100 - Carry the app's breaking-changes fulfillment record across the 2026-09-04 rename.
#
# The mandate-tracking system was called `upstream_changes` until 2026-09-04, and every
# application records which mandate documents it has already dealt with in a project-local
# dotfile named after it. The rename moved the directory and the commands; this script moves
# the STATE FILE:
#
#     rsx/resource/.upstream_changes_manifest.json  ->  rsx/resource/.breaking_changes_manifest.json
#
# WHY IT MATTERS. If the new name simply did not exist, the first breaking-changes read
# would auto-baseline a FRESH manifest - which marks every current document fulfilled - so
# the app's real record (including documents it deliberately left unfulfilled) would be
# silently discarded and replaced with a lie. One rename preserves years of marks.
#
# THIS SCRIPT IS THE EAGER TIER, NOT THE ONLY ONE. Breaking_Changes::manifest_path() (the
# choke point every read goes through) performs the same adoption lazily, because on the
# very pull that delivers the rename, rsx:framework:post_update READS the manifest before
# post-update.sh runs these scripts - an env-script-only migration would arrive after the
# damage. Both sides are the same idempotent rename; whichever runs first wins and the
# other finds nothing to do. Delete both together, once no live application predates the
# rename.
#
# BOTH CONTEXTS. The monorepo's template app migrated the day of the rename, so here this
# is a silent no-op - which is also the steady state everywhere else after one run.
#
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container - absent marker, absent consent: exit 0 and say nothing.
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"

info() { [ "$QUIET" = true ] || echo "[env] $*"; }

old_manifest="$PROJECT_ROOT/rsx/resource/.upstream_changes_manifest.json"
new_manifest="$PROJECT_ROOT/rsx/resource/.breaking_changes_manifest.json"

# Steady state: nothing under the old name. The common case forever after one run.
[ -f "$old_manifest" ] || exit 0

if [ -f "$new_manifest" ]; then
    # Both names exist. The new file is live (something has already read or baselined it),
    # so it is not ours to clobber - and the old one is not ours to judge: it can only get
    # here by hand (the lazy adoption RENAMES, it never copies). Report and touch nothing
    # (augment-never-clobber, contract rule 7).
    echo "[env] WARNING: both $old_manifest and $new_manifest exist;" >&2
    echo "[env]          leaving both - remove the old file after confirming the new one is correct." >&2
    exit 0
fi

if mv "$old_manifest" "$new_manifest"; then
    info "breaking-changes manifest adopted from its former upstream_changes name"
else
    # Non-fatal by contract; the lazy adoption in Breaking_Changes::manifest_path() remains.
    echo "[env] WARNING: could not rename $old_manifest to $new_manifest" >&2
fi

exit 0
