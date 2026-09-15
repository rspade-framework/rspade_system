#!/usr/bin/env bash
#
# 110 - Move an existing install onto the build/ tmp/ storage/ layout.
#
# RSpade keeps three volatile trees at the project root, each with one job:
#
#   build/     build OUTPUTS - the manifest index, compiled bundles, the Laravel
#              route/event/package caches, compiled Blade, the seal. Read-only to the
#              web user on a correctly configured production box.
#   tmp/       DERIVED caches and runtime temp - transform caches, generated JS stubs,
#              node sockets, scratch files, database dump caches, the mail catcher,
#              thumbnails and document renditions.
#   storage/   USER data - uploads, logs, storage/app - plus storage/state, holding the
#              maintenance flag, the flock files and the updater's ledger.
#
# Everything volatile used to share storage/, which is why nothing there could be made
# read-only at the OS level. This script performs the one-time move.
#
# WHAT IT DOES NOT DO: create build/. That tree holds build outputs, and a missing one
# must fail loud naming the build command rather than quietly appearing empty.
#
# Contract (environment_updates/CLAUDE.md): self-detecting, idempotent, silent when
# already applied, non-fatal.

set -u

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container. These scripts modify the environment around the project - git hooks,
# editor and agent settings, the on-disk storage layout - and that environment is
# the container's, not the host's. Run on a host they would install container
# assumptions into somebody's own machine, silently and with no way to know it
# happened. Absent marker, absent consent: exit 0 and say nothing (the contract
# is silent-when-not-applicable, and this is a normal state, not a failure).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"

# The path library comes from THIS script's own tree - it is code, and the copy beside
# this file is the one that ships with it. PROJECT_ROOT is DATA and may point at a
# synthetic tree, which is how a test drives the real script against a sandbox.
RSX_PATHS_PROJECT_ROOT_DIR="$PROJECT_ROOT"
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/../lib" && pwd)/rsx_paths.sh"

QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"
info() { [ "$QUIET" = true ] || echo "[env] $*"; }

BUILD_ROOT="$(rsx_build_root)"
TMP_ROOT="$(rsx_tmp_root)"
STORAGE_ROOT="$(rsx_storage_root)"
STATE_ROOT="$(rsx_state_root)"

OLD_DIRS=(rsx-build rsx-tmp rsx-framework rsx-locks flock db_backups mail-catcher rsx-thumbnails rsx-renditions)

# -----------------------------------------------------------------------------
# Applied detection
# -----------------------------------------------------------------------------
# Applied means: no old directory survives under the storage root, storage/state is a
# real directory, and system/build points at the build root.
link_is_correct() {
    local link="$1" root="$2" name="$3" want
    [ -L "$link" ] || return 1
    if [ "$root" = "$PROJECT_ROOT/$name" ]; then want="../$name"; else want="$root"; fi
    [ "$(readlink "$link")" = "$want" ]
}

already_applied() {
    local d
    for d in "${OLD_DIRS[@]}"; do
        [ -e "$STORAGE_ROOT/$d" ] && return 1
    done
    [ -e "$STORAGE_ROOT/.rsx-formatter-cache.json" ] && return 1
    [ -d "$STATE_ROOT" ] || return 1
    link_is_correct "$SYSTEM_DIR/build" "$BUILD_ROOT" build || return 1
    return 0
}

already_applied && exit 0

# -----------------------------------------------------------------------------
# 1. The destinations that are always safe to create.
# -----------------------------------------------------------------------------
# build/ is deliberately absent from this list.
mkdir -p "$TMP_ROOT" "$STATE_ROOT" "$STATE_ROOT/flock" 2>/dev/null || true

# -----------------------------------------------------------------------------
# 2. Reap node daemons bound to the OLD socket directory.
# -----------------------------------------------------------------------------
# Every node daemon - the RPC node service, the SSR server, anything left over from an
# older release - took its socket path as an argv argument at spawn time and never
# reconsiders it. Moving the directory under a running daemon strands it permanently: it
# keeps serving an inode nobody can reach again. TERM, one settle pass, then whatever is
# still there goes. NO TIMEOUT: the settle pass is a single check, not a deadline.
#
# The container-start trigger runs this before supervisor, which is the safe moment.
if [ -d "$STORAGE_ROOT/rsx-tmp" ] && command -v pgrep >/dev/null 2>&1; then
    orphans="$(pgrep -f -- "--socket=$STORAGE_ROOT/rsx-tmp/" 2>/dev/null || true)"
    if [ -n "$orphans" ]; then
        count="$(printf '%s\n' "$orphans" | grep -c .)"
        printf '%s\n' "$orphans" | xargs -r kill 2>/dev/null || true
        printf '%s\n' "$orphans" | xargs -r -I{} bash -c 'kill -0 {} 2>/dev/null && kill -9 {} 2>/dev/null' || true
        info "Quiesced $count node daemon(s) bound to the old socket directory (respawned on demand)."
    fi
fi

# -----------------------------------------------------------------------------
# 3. State files: move, never copy.
# -----------------------------------------------------------------------------
# mv within one filesystem PRESERVES THE INODE, so a flock() a live process is holding
# on storage/flock/x still excludes a process that acquires storage/state/flock/x. A
# copy would hand out two independent locks over what callers believe is one.
move_children() {
    local src="$1" dest="$2" moved=0 entry
    [ -d "$src" ] || return 0
    mkdir -p "$dest" 2>/dev/null || true
    for entry in "$src"/* "$src"/.[!.]*; do
        [ -e "$entry" ] || continue
        mv -f "$entry" "$dest/" 2>/dev/null && moved=$((moved + 1))
    done
    rmdir "$src" 2>/dev/null || true
    printf '%s' "$moved"
}

if [ -d "$STORAGE_ROOT/rsx-framework" ]; then
    n="$(move_children "$STORAGE_ROOT/rsx-framework" "$STATE_ROOT")"
    info "Moved $n framework state file(s) into storage/state/."
fi

if [ -d "$STORAGE_ROOT/flock" ]; then
    n="$(move_children "$STORAGE_ROOT/flock" "$STATE_ROOT/flock")"
    info "Moved $n lock file(s) into storage/state/flock/ (inodes preserved)."
fi

# -----------------------------------------------------------------------------
# 4. An interrupted database snapshot is MOVED, never deleted.
# -----------------------------------------------------------------------------
# rsx:db:rebuild_provision_cache_snapshot holds a full dump of the live database while it
# works, marked .in_progress. Re-running the command completes the restore from exactly
# these files, so they are the opposite of disposable.
OLD_DB_CACHE="$STORAGE_ROOT/rsx-tmp/db_cache"
if [ -e "$OLD_DB_CACHE/.in_progress" ] || [ -e "$OLD_DB_CACHE/live_db.sql.gz" ]; then
    n="$(move_children "$OLD_DB_CACHE" "$TMP_ROOT/db_cache")"
    info "An interrupted database snapshot was preserved: $n file(s) moved to tmp/db_cache/."
    info "  Re-run 'php artisan rsx:db:rebuild_provision_cache_snapshot' to complete the restore."
fi

# -----------------------------------------------------------------------------
# 5. Caches that are worth carrying rather than regenerating.
# -----------------------------------------------------------------------------
for name in db_backups mail-catcher; do
    if [ -d "$STORAGE_ROOT/$name" ]; then
        n="$(move_children "$STORAGE_ROOT/$name" "$TMP_ROOT/$name")"
        info "Moved $name/ into tmp/ ($n entries)."
    fi
done

if [ -f "$STORAGE_ROOT/.rsx-formatter-cache.json" ]; then
    mv -f "$STORAGE_ROOT/.rsx-formatter-cache.json" "$TMP_ROOT/rsx-formatter-cache.json" 2>/dev/null || true
    info "Moved the formatter cache into tmp/."
fi

# -----------------------------------------------------------------------------
# 6. The trees that regenerate wholesale.
# -----------------------------------------------------------------------------
# The thumbnail and rendition caches are in this list because every entry in them is
# derived from a blob that is still in the store: they now live in tmp/ and are
# re-rendered on demand.
for name in rsx-build rsx-tmp rsx-locks rsx-thumbnails rsx-renditions; do
    if [ -e "$STORAGE_ROOT/$name" ]; then
        rm -rf "$STORAGE_ROOT/$name"
        info "Removed storage/$name (regenerated on the next build)."
    fi
done

# -----------------------------------------------------------------------------
# 7. The system/build link.
# -----------------------------------------------------------------------------
# build/ only. storage/ and tmp/ are relocatable, so a convenience link in system/
# cannot promise where either one is; 120_remove_storage_tmp_links.sh retires the two
# links an earlier layout installed.
# A directory carrying ONLY cache/ and temp/ children is the artifact cache an older
# release kept at system/build. It holds nothing anybody wants, and it sits exactly where
# the link has to go. Anything else real is reported and left alone.
is_stale_artifact_cache() {
    local dir="$1" entry
    for entry in "$dir"/* "$dir"/.[!.]*; do
        [ -e "$entry" ] || continue
        case "$(basename "$entry")" in
            cache|temp) ;;
            *) return 1 ;;
        esac
    done
    return 0
}

ensure_link() {
    # One name per `local` line: bash expands the whole command BEFORE assigning, so a
    # `local name="$1" link="$SYSTEM_DIR/$name"` would compose the link from whatever
    # `name` the CALLER last had - which is the step-6 loop variable, and which silently
    # creates a link under the wrong name.
    local name="$1"
    local root="$2"
    local link="$SYSTEM_DIR/$name"
    local want
    if [ "$root" = "$PROJECT_ROOT/$name" ]; then want="../$name"; else want="$root"; fi

    if [ -L "$link" ]; then
        [ "$(readlink "$link")" = "$want" ] && return 0
        rm -f "$link"
        ln -s "$want" "$link" && info "Retargeted system/$name -> $want."
        return 0
    fi

    if [ -d "$link" ]; then
        if is_stale_artifact_cache "$link"; then
            rm -rf "$link"
            ln -s "$want" "$link" && info "Replaced the old system/$name artifact cache with a link to $want."
            return 0
        fi
        echo "[env] system/$name is a real directory holding files this script did not put there." >&2
        echo "[env]   Move what matters out, then: rm -rf '$link' && ln -s '$want' '$link'" >&2
        return 0
    fi

    if [ -e "$link" ]; then
        echo "[env] system/$name exists and is not a symlink. Remove it, then: ln -s '$want' '$link'" >&2
        return 0
    fi

    ln -s "$want" "$link" && info "Created system/$name -> $want."
}

ensure_link build "$BUILD_ROOT"

# -----------------------------------------------------------------------------
# 8. Artifacts that moved house rather than changing shape.
# -----------------------------------------------------------------------------
# Laravel's five cached files now live in build/laravel and compiled Blade in build/views.
# The copies at their old addresses are read by nothing and would confuse anybody reading
# the tree.
rm -f "$SYSTEM_DIR/bootstrap/cache"/*.php 2>/dev/null || true
rm -rf "$STORAGE_ROOT/framework" 2>/dev/null || true

exit 0
