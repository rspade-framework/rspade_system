#!/bin/bash
# t17: rsx:framework:pull refuses outside development mode (the RSX_MODE guard). Debug and
# production are sealed builds; syncing fresh framework files would break the seal.

TEST_NAME="framework_update/cli t17 dev-mode guard"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
fx_build_downstream v1

# Simulate a sealed (production) install.
printf 'RSX_MODE=production\n' > "$PROJECT/system/.env"

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -ne 0 ] || fx_fail "expected a non-zero exit when RSX_MODE=production, got 0. Output: $FX_OUT"
case "$FX_OUT" in
    *"require development mode"*|*"RSX_MODE=development"*) : ;;
    *) fx_fail "expected the dev-mode guard message. Output: $FX_OUT" ;;
esac

# And it must not have touched the tree (refused before the sync).
fx_assert_absent "$PROJECT/rsx/resource/framework_update_history.dat"

fx_pass
