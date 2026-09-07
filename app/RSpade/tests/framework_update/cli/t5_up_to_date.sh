#!/bin/bash
# t5: a second pull, once the tree is at the distribution tip, reports "up to
# date" and exits 0 without changes.

TEST_NAME="framework_update/cli t5 up to date"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

# First pull brings v1 -> v2 and records history (to=V2_SHA).
fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "first pull expected exit 0, got $FX_RC. Output: $FX_OUT"

# Second pull: installed == tip -> up to date.
fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "second pull expected exit 0, got $FX_RC. Output: $FX_OUT"
printf '%s' "$FX_OUT" | grep -qi "up to date" || fx_fail "second pull must report up to date. Output: $FX_OUT"

fx_pass
