#!/bin/bash
# t8: 100_breaking_changes_manifest_rename.sh - the app's breaking-changes fulfillment
# record survives the 2026-09-04 upstream_changes -> breaking_changes rename. The state
# lives in a project-local dotfile keyed by document basename; without the rename, the
# first read under the new name would auto-baseline a fresh manifest (everything marked
# fulfilled) and silently discard the app's real record.
#
# The sandbox needs only rsx/resource/ and a JSON file - the script is a guarded mv.

TEST_NAME="environment_updates/cli t8 breaking-changes manifest rename"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

SCRIPT_NAME="100_breaking_changes_manifest_rename.sh"

OLD="$FX_APP/rsx/resource/.upstream_changes_manifest.json"
NEW="$FX_APP/rsx/resource/.breaking_changes_manifest.json"
MARKS='{"login_throttle_08_30.txt":{"status":"unfulfilled"}}'

# ---- 1. Steady state: neither file. Silent success, nothing created. -----------------
rm -f "$OLD" "$NEW"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ -z "$FX_OUT" ] || fx_fail "steady state must be silent, got: $FX_OUT"
[ ! -e "$OLD" ] && [ ! -e "$NEW" ] || fx_fail "steady state must create nothing"

# ---- 2. Old present, new absent: renamed, content byte-identical, one info line. -----
printf '%s' "$MARKS" > "$OLD"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ ! -e "$OLD" ] || fx_fail "the old-name file must be gone after adoption"
[ -f "$NEW" ] || fx_fail "the new-name file must exist after adoption"
[ "$(cat "$NEW")" = "$MARKS" ] || fx_fail "the fulfillment record was not carried byte-for-byte"
fx_assert_out "adopted"

# ---- 3. Idempotent: the second run is silent. -----------------------------------------
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
[ -z "$FX_OUT" ] || fx_fail "the second run must be silent, got: $FX_OUT"
[ "$(cat "$NEW")" = "$MARKS" ] || fx_fail "an idempotent rerun altered the record"

# ---- 4. Both present: warn on stderr, touch NEITHER (augment-never-clobber). ----------
printf '%s' '{"hand":"made"}' > "$OLD"
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
case "$FX_ERR" in *WARNING*) : ;; *) fx_fail "both-present must warn on stderr, got: $FX_ERR" ;; esac
[ "$(cat "$OLD")" = '{"hand":"made"}' ] || fx_fail "the old file was altered in the both-present case"
[ "$(cat "$NEW")" = "$MARKS" ] || fx_fail "the new file was altered in the both-present case"
rm -f "$OLD"

# ---- 5. Quiet mode: the adoption still happens, the info line does not. ---------------
rm -f "$NEW"
printf '%s' "$MARKS" > "$OLD"
fx_run "$SCRIPT_NAME" RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
[ -f "$NEW" ] || fx_fail "quiet mode must still adopt"
[ -z "$FX_OUT" ] || fx_fail "quiet mode must print nothing, got: $FX_OUT"

fx_pass
