#!/bin/bash
# t9: 110_relocate_build_tmp.sh - move an existing install onto the three-tree layout
# (build/ outputs, tmp/ derived caches, storage/ user data plus storage/state).
#
# The two properties worth proving are the ones a re-run could not recover from:
#
#   1. State files are MOVED, not copied. mv within one filesystem preserves the inode,
#      so a flock() a live process holds on the old path still excludes an acquirer of
#      the new one. A copy would hand out two independent locks over what every caller
#      believes is a single file.
#   2. An interrupted database snapshot is PRESERVED. Those files are a full dump of a
#      live database, and re-running the snapshot command completes the restore from
#      exactly them.
#
# Plus the contract every script here obeys: idempotent, silent on the second run, and
# it NEVER creates build/ - build outputs are produced by the build, and a missing tree
# must fail loud naming it.

TEST_NAME="environment_updates/cli t9 relocate build/tmp/state"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

SCRIPT_NAME="110_relocate_build_tmp.sh"

# fx_legacy_layout - build the pre-split shape under FX_APP.
fx_legacy_layout() {
    rm -rf "$FX_APP"
    mkdir -p "$FX_APP/system/bin" \
             "$FX_APP/storage/rsx-build/bundles" \
             "$FX_APP/storage/rsx-tmp/db_cache" \
             "$FX_APP/storage/rsx-framework" \
             "$FX_APP/storage/rsx-locks" \
             "$FX_APP/storage/flock" \
             "$FX_APP/storage/db_backups" \
             "$FX_APP/storage/mail-catcher" \
             "$FX_APP/storage/rsx-thumbnails/preset" \
             "$FX_APP/storage/uploads/ab"

    printf 'framework update in progress\nmode=development\n' \
        > "$FX_APP/storage/rsx-framework/.maintenance.mode.framework.update"
    printf 'abc123\n' > "$FX_APP/storage/rsx-framework/environment_updates_fingerprint"
    : > "$FX_APP/storage/flock/cluster__SITE_1"
    printf 'stale index\n' > "$FX_APP/storage/rsx-build/manifest_index.php"
    printf 'dump\n' > "$FX_APP/storage/db_backups/test-db.sql"
    printf 'msg\n' > "$FX_APP/storage/mail-catcher/1.eml"
    printf '{}' > "$FX_APP/storage/.rsx-formatter-cache.json"
    printf 'thumb\n' > "$FX_APP/storage/rsx-thumbnails/preset/x.webp"
    printf 'blob\n' > "$FX_APP/storage/uploads/ab/deadbeef"
}

fx_inode() { stat -c %i "$1" 2>/dev/null || echo "MISSING"; }

# ---- 1. The move, end to end. --------------------------------------------------------
fx_legacy_layout

FLOCK_INODE_BEFORE="$(fx_inode "$FX_APP/storage/flock/cluster__SITE_1")"
FINGERPRINT_INODE_BEFORE="$(fx_inode "$FX_APP/storage/rsx-framework/environment_updates_fingerprint")"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0

# State moved into storage/state, INODES INTACT.
[ -f "$FX_APP/storage/state/.maintenance.mode.framework.update" ] \
    || fx_fail "the maintenance flag was not moved into storage/state"
[ -f "$FX_APP/storage/state/environment_updates_fingerprint" ] \
    || fx_fail "the environment-update fingerprint was not moved into storage/state"
[ -f "$FX_APP/storage/state/flock/cluster__SITE_1" ] \
    || fx_fail "the lock file was not moved into storage/state/flock"

[ "$(fx_inode "$FX_APP/storage/state/flock/cluster__SITE_1")" = "$FLOCK_INODE_BEFORE" ] \
    || fx_fail "the lock file was COPIED, not moved: a held flock() would stop excluding"
[ "$(fx_inode "$FX_APP/storage/state/environment_updates_fingerprint")" = "$FINGERPRINT_INODE_BEFORE" ] \
    || fx_fail "the fingerprint was copied, not moved"

# Caches carried into tmp/.
[ -f "$FX_APP/tmp/db_backups/test-db.sql" ] || fx_fail "db_backups/ was not carried into tmp/"
[ -f "$FX_APP/tmp/mail-catcher/1.eml" ] || fx_fail "mail-catcher/ was not carried into tmp/"
[ -f "$FX_APP/tmp/rsx-formatter-cache.json" ] || fx_fail "the formatter cache was not carried into tmp/"

# The regenerable trees are gone.
for gone in rsx-build rsx-tmp rsx-locks rsx-framework flock db_backups mail-catcher; do
    [ -e "$FX_APP/storage/$gone" ] && fx_fail "storage/$gone survived the relocation"
done

# USER DATA IS UNTOUCHED. Everything above is machine-made; uploads are not.
[ -f "$FX_APP/storage/uploads/ab/deadbeef" ] || fx_fail "the blob store was disturbed"

# The thumbnail cache is regenerated from blobs that are still there, so it is removed
# rather than carried: it lives in tmp/ now and re-renders on demand.
[ -e "$FX_APP/storage/rsx-thumbnails" ] && fx_fail "the old thumbnail cache survived under storage/"

# The link. build/ is the only tree with one: it is fixed at <project>/build, while a
# relocatable root cannot have a link that says where it moved to.
[ -e "$FX_APP/system/tmp" ] && fx_fail "system/tmp was created - a relocatable root has no link"
[ -L "$FX_APP/system/build" ] || fx_fail "system/build was not created as a symlink"
[ "$(readlink "$FX_APP/system/build")" = "../build" ] || fx_fail "system/build points at '$(readlink "$FX_APP/system/build")'"

# BUILD/ IS NOT CREATED. A dangling system/build is the correct state until a build runs;
# an empty one would let a production box serve nothing instead of naming the build command.
[ -d "$FX_APP/build" ] && fx_fail "the script created build/ - build outputs are the build's to make"

fx_assert_out "storage/state"

# ---- 2. Idempotent: the second run is silent. ----------------------------------------
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "an already-applied environment update must print nothing"
[ -z "$FX_ERR" ] || fx_fail "expected no stderr on a no-op run. stderr: $FX_ERR"

# ---- 3. An interrupted database snapshot is preserved, never deleted. ----------------
fx_legacy_layout
printf 'blob-root\n' > "$FX_APP/storage/rsx-tmp/db_cache/.in_progress"
printf 'a whole live database\n' > "$FX_APP/storage/rsx-tmp/db_cache/live_db.sql.gz"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0

[ -f "$FX_APP/tmp/db_cache/.in_progress" ] || fx_fail "the in-progress marker was lost"
[ -f "$FX_APP/tmp/db_cache/live_db.sql.gz" ] || fx_fail "the live database dump was LOST"
[ "$(cat "$FX_APP/tmp/db_cache/live_db.sql.gz")" = "a whole live database" ] \
    || fx_fail "the preserved dump does not carry its original bytes"
fx_assert_out "rebuild_provision_cache_snapshot"

# ---- 4. The old two-child artifact cache at system/build is replaced. ----------------
fx_legacy_layout
mkdir -p "$FX_APP/system/build/cache" "$FX_APP/system/build/temp"
printf 'derived\n' > "$FX_APP/system/build/cache/x.json"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ -L "$FX_APP/system/build" ] || fx_fail "the old artifact cache was not replaced by a link"
[ "$(readlink "$FX_APP/system/build")" = "../build" ] || fx_fail "the replacement link points elsewhere"

# ---- 5. A foreign directory at system/build is REPORTED and left alone. --------------
fx_legacy_layout
mkdir -p "$FX_APP/system/build"
printf 'somebody else\n' > "$FX_APP/system/build/important.txt"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ -d "$FX_APP/system/build" ] || fx_fail "a foreign system/build was removed"
[ -f "$FX_APP/system/build/important.txt" ] || fx_fail "a file the script did not put there was destroyed"
fx_assert_err "real directory"

# ---- 6. A wrong-target link is retargeted. -------------------------------------------
fx_legacy_layout
rm -f "$FX_APP/system/build"
ln -s /somewhere/else "$FX_APP/system/build"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ "$(readlink "$FX_APP/system/build")" = "../build" ] || fx_fail "a wrong-target link was not retargeted"

fx_pass
