#!/bin/bash
# t10: 120_remove_storage_tmp_links.sh - retire the system/storage and system/tmp links.
#
# build/ keeps its link because it is FIXED at <project>/build. tmp/ and storage/ are
# relocatable, and a link in a read-only system/ cannot promise where a relocated root
# is - which is the state this script removes.
#
# The property worth proving is the refusal: only a SYMLINK is removed. A real directory
# at either path was put there by a person and may hold the only copy of something, so it
# is reported and left exactly as it is.

TEST_NAME="environment_updates/cli t10 remove the storage and tmp links"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

SCRIPT_NAME="120_remove_storage_tmp_links.sh"

# ---- 1. Both links are removed; system/build is left alone. --------------------------
ln -s ../storage "$FX_APP/system/storage"
ln -s ../tmp "$FX_APP/system/tmp"
ln -s ../build "$FX_APP/system/build"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0

[ -L "$FX_APP/system/storage" ] && fx_fail "system/storage was not removed"
[ -L "$FX_APP/system/tmp" ] && fx_fail "system/tmp was not removed"
[ -L "$FX_APP/system/build" ] || fx_fail "system/build was removed - build/ is fixed and keeps its link"
[ "$(readlink "$FX_APP/system/build")" = "../build" ] || fx_fail "system/build was retargeted"

fx_assert_out "system/storage"
fx_assert_out "system/tmp"

# ---- 2. Idempotent: the second run is silent. ----------------------------------------
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "an already-applied environment update must print nothing"
[ -z "$FX_ERR" ] || fx_fail "expected no stderr on a no-op run. stderr: $FX_ERR"

# ---- 3. A real directory is REPORTED and left alone. ---------------------------------
mkdir -p "$FX_APP/system/storage/uploads"
printf 'the only copy\n' > "$FX_APP/system/storage/uploads/important.bin"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0

[ -d "$FX_APP/system/storage" ] || fx_fail "a real directory was removed"
[ -f "$FX_APP/system/storage/uploads/important.bin" ] || fx_fail "a file the script did not put there was destroyed"
fx_assert_err "real directory"

# ---- 4. The quiet contract: informational lines gate, problems never do. -------------
rm -rf "$FX_APP/system/storage"
ln -s ../storage "$FX_APP/system/storage"

fx_run "$SCRIPT_NAME" RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "a quiet run says nothing about what it applied"
[ -L "$FX_APP/system/storage" ] && fx_fail "a quiet run must still do the work"

fx_pass
