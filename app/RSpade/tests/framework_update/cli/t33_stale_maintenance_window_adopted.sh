#!/bin/bash
set -u

# t33: a maintenance window stranded by a PREVIOUS updater run is ADOPTED and lifted
# on every exit path - the early up-to-date exit included - while an OPERATOR's
# window is left exactly as they raised it.
#
# Field report 2026-08-18: cleanup() only lifted a window THIS run raised, so a flag
# stranded by a crashed/killed updater survived every subsequent early-exit run and
# the box stayed 502 until a manual rsx:maintenance:disable. Ownership is the flag
# CONTENT (first line = the raiser's reason): only "framework update in progress"
# is adopted.

TEST_NAME="framework_update/cli t33 stale maintenance window adopted"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

# Converge to the tip first so subsequent runs take the up-to-date exit.
fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "precondition pull failed: $FX_OUT"

# Stub maintenance-mode.sh: flag-only, no supervisorctl - records disable calls.
# storage/ is at the PROJECT ROOT - outside the submodule, which is what lets a raised
# window survive the reset every update performs. The flag-only maintenance-mode.sh is
# shipped by the fixture INSIDE the fake upstream (see fx_make_tree_v1): writing it into
# the project's system/ here would put it in a checkout that `git clean -fdx` empties.
FLAG_DIR="$PROJECT/storage/rsx-framework"
mkdir -p "$FLAG_DIR"

# fx_run_pull minus --no-service-control: the adoption is gated on service control,
# and the stub maintenance-mode.sh above keeps it flag-only (no supervisorctl).
run_with_service_control() {
    FX_OUT="$( cd "$PROJECT" && git config protocol.file.allow always >/dev/null 2>&1;
        bash "$PROJECT/system/bin/framework-pull-upstream.sh.dist" \
        --upstream-url="$BARE" --no-rebuild 2>&1 )"
    FX_RC=$?
}

# ---- 1. A stale UPDATER-authored window: adopted and lifted by the up-to-date exit. ----
printf 'framework update in progress\nmode=development\n' > "$FLAG_DIR/.maintenance.mode.framework.update"
rm -f "$FLAG_DIR/.maintenance.mode.framework.update.disable_called"

run_with_service_control
[ $FX_RC -eq 0 ] || fx_fail "up-to-date run failed: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q "Framework is up to date" \
    || fx_fail "expected the up-to-date exit. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q "Adopting a stale maintenance window" \
    || fx_fail "the stale updater window was not adopted. Output: $FX_OUT"
[ ! -f "$FLAG_DIR/.maintenance.mode.framework.update" ] \
    || fx_fail "the stale flag survived the up-to-date exit"
[ -f "$FLAG_DIR/.maintenance.mode.framework.update.disable_called" ] \
    || fx_fail "maintenance-mode.sh disable was never invoked (services would stay stopped)"

# ---- 2. An OPERATOR-authored window: never touched. ----
printf 'database surgery\nmode=development\n' > "$FLAG_DIR/.maintenance.mode.framework.update"
rm -f "$FLAG_DIR/.maintenance.mode.framework.update.disable_called"

run_with_service_control
[ $FX_RC -eq 0 ] || fx_fail "up-to-date run under an operator window failed: $FX_OUT"
printf '%s' "$FX_OUT" | grep -q "Adopting a stale maintenance window" \
    && fx_fail "an OPERATOR's window was adopted - it must be left as they raised it"
[ -f "$FLAG_DIR/.maintenance.mode.framework.update" ] \
    || fx_fail "the operator's flag was removed"
grep -q "^database surgery" "$FLAG_DIR/.maintenance.mode.framework.update" \
    || fx_fail "the operator's reason was altered"
[ ! -f "$FLAG_DIR/.maintenance.mode.framework.update.disable_called" ] \
    && true || fx_fail "disable was invoked on an operator window"

fx_pass
