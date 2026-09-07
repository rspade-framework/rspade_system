#!/bin/bash
# t1: clean update v1 -> v2. Owned changes + deletions applied, three-way updates
# applied, history .dat written with the correct old/new SHAs, exit 0.

TEST_NAME="framework_update/cli t1 clean update"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

fx_run_pull --no-rebuild --yes

[ "$FX_RC" -eq 0 ] || fx_fail "expected exit 0, got $FX_RC. Output: $FX_OUT"

SYS="$PROJECT/system"
fx_assert_contains "$SYS/app/RSpade/core_a.php" "VERSION 2"     # owned change applied
fx_assert_absent   "$SYS/app/RSpade/gone.php"                   # owned deletion applied
fx_assert_contains "$SYS/three_way/upstream_change.txt" "u2"    # three-way update applied
fx_assert_absent   "$SYS/three_way/removed.txt"                 # three-way deletion applied
fx_assert_exists   "$SYS/three_way/added.txt"                   # three-way addition applied

HIST="$PROJECT/rsx/resource/framework_update_history.dat"
fx_assert_exists "$HIST"
fx_assert_contains "$HIST" "from=$V1_SHA"
fx_assert_contains "$HIST" "to=$V2_SHA"

fx_pass
