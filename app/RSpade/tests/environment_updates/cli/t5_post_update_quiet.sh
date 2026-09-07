#!/bin/bash
# t5: post-update.sh --quiet. It exports RSPADE_ENV_UPDATE_QUIET=true, so a script's
# informational line disappears - while a FAILING script's WARNING and the failure count
# still reach stderr. A quiet run is a quiet SUCCESS, never a silent failure.

TEST_NAME="environment_updates/cli t5 post-update quiet"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

updates="$FX_APP/system/bin/environment_updates"
mkdir -p "$updates"

# A chatty script that honors the quiet contract, and one that fails.
cat > "$updates/900_chatty.sh" <<'CHATTY'
#!/usr/bin/env bash
set -uo pipefail
QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"
info() { [ "$QUIET" = true ] || echo "[env] $*"; }
info "Applied the fixture change."
exit 0
CHATTY

cat > "$updates/910_broken.sh" <<'BROKEN'
#!/usr/bin/env bash
echo "[env] fixture: something is wrong" >&2
exit 1
BROKEN

run_post_update() {
    env PROJECT_ROOT="$FX_APP" SYSTEM_DIR="$FX_APP/system" IS_FRAMEWORK_DEVELOPER=false \
        bash "$FX_POST_UPDATE" "$@" > "$FX_ROOT/stdout" 2> "$FX_ROOT/stderr"
    FX_RC=$?
    FX_OUT="$(cat "$FX_ROOT/stdout")"
    FX_ERR="$(cat "$FX_ROOT/stderr")"
}

# Loud: the informational line is printed.
run_post_update
fx_assert_rc 0 "a failing environment update is non-fatal"
fx_assert_out "Applied the fixture change."
fx_assert_err "environment update failed (non-fatal): 910_broken.sh"
fx_assert_err "1 environment update(s) reported a failure"

# Quiet: informational gone, problems intact.
run_post_update --quiet
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode must suppress the informational line"
fx_assert_err "environment update failed (non-fatal): 910_broken.sh"
fx_assert_err "1 environment update(s) reported a failure"

fx_pass
