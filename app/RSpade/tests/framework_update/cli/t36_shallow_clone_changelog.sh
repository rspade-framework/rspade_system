#!/bin/bash
# t36: the changelog range starts at the RECORDED gitlink - which is not automatically
# an object system/ HOLDS. A shallow submodule is deepened to recover it, and a
# revision that cannot be recovered at all is reported LOUDLY, never as silence.
#
# WHY THIS IS A REAL SHAPE. The starter's own README recommends
#     git clone --depth 1 --recurse-submodules ...
# so a downstream system/ can be a shallow clone with a graft boundary a few commits
# back. `git log OLD..NEW` against an OLD that is missing from the object store
# prints NOTHING and exits 0 - an empty changelog indistinguishable from "no changes",
# written into the application's permanent audit record.
#
# Two cases:
#   1. Recorded pointer BEHIND the shallow boundary -> deepen, then the FULL range.
#   2. Recorded pointer not obtainable from upstream at all -> the changelog says so,
#      in the commit and in the history .dat, and the update still completes.

TEST_NAME="framework_update/cli t36 shallow clone changelog"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_upstream
A="$V1_SHA"
B="$V2_SHA"

# =============================================================================
# 1. A SHALLOW submodule whose recorded pointer predates its graft boundary.
#
# The clone carries only B; the application's history records A. The updater must
# deepen rather than emit an empty changelog for A..C.
# =============================================================================
PROJECT="$FX_ROOT/project"
rm -rf "$PROJECT"
mkdir -p "$PROJECT/rsx/resource" "$PROJECT/storage/rsx-framework"
(
    cd "$PROJECT" || exit 1
    git init -q && git config user.email t@t && git config user.name test
    printf 'IS_FRAMEWORK_DEVELOPER=false\n' > .env
    # file:// is REQUIRED for a shallow clone: git ignores --depth on a local-path
    # clone ("--depth is ignored in local clones") and hardlinks the whole object
    # store instead, which would silently make this a full clone and prove nothing.
    git -c protocol.file.allow=always submodule add -q --depth 1 "file://$BARE" system >/dev/null 2>&1
    git add -A && git commit -q -m "downstream (shallow submodule at the tip)"
    # Record A while the checkout stays shallow at B: exactly the state a shallow
    # recursive clone of an application whose pointer has since moved leaves behind.
    git update-index --add --cacheinfo "160000,$A,system"
    git commit -q -m "records A"
) || fx_fail "could not build the shallow-submodule fixture"
chmod +x "$PROJECT/system/bin/framework-pull-upstream.sh.dist" "$PROJECT/system/artisan" 2>/dev/null || true

[ "$(git -C "$PROJECT/system" rev-parse --is-shallow-repository)" = "true" ] \
    || fx_fail "fixture sanity: system/ should be a shallow clone"
[ "$(fx_recorded_revision)" = "$A" ] || fx_fail "fixture sanity: the project should record A"
git -C "$PROJECT/system" cat-file -e "${A}^{commit}" 2>/dev/null \
    && fx_fail "fixture sanity: A must be ABSENT from the shallow object store"

fx_add_upstream_release "v3 release: third release on top of v2" \
    "THIRD RELEASE RATIONALE that must also reach the application's history" \
    || fx_fail "could not publish the third release"
C="$FX_RELEASE_SHA"

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "shallow pull expected exit 0, got $FX_RC. Output: $FX_OUT"

msg="$(git -C "$PROJECT" log -1 --pretty=%B)"
printf '%s' "$msg" | grep -q "^Framework-Update-Range: $A..$C$" \
    || fx_fail "range trailer should be $A..$C. Message: $msg"
printf '%s' "$msg" | grep -q "CHANGELOG UNAVAILABLE" \
    && fx_fail "a deepenable shallow clone must recover the changelog, not give up. Message: $msg"
printf '%s' "$msg" | grep -q "SUBSTANTIVE rationale that must reach a downstream app" \
    || fx_fail "the deepened changelog is missing release B's body. Message: $msg"
printf '%s' "$msg" | grep -q "THIRD RELEASE RATIONALE" \
    || fx_fail "the deepened changelog is missing release C's body. Message: $msg"
[ "$(fx_recorded_revision)" = "$C" ] || fx_fail "gitlink should record C after the shallow pull"

# =============================================================================
# 2. A recorded pointer upstream cannot supply. Deepening cannot help; the record
#    must SAY the text is missing rather than record an empty range.
# =============================================================================
fx_build_downstream v1
MISSING="deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
( cd "$PROJECT" && git update-index --add --cacheinfo "160000,$MISSING,system" \
    && git commit -q -m "records a revision upstream does not have" ) \
    || fx_fail "could not plant an unobtainable recorded pointer"

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "an unreadable changelog must not fail the update, got $FX_RC. Output: $FX_OUT"

msg="$(git -C "$PROJECT" log -1 --pretty=%B)"
printf '%s' "$msg" | grep -q "CHANGELOG UNAVAILABLE" \
    || fx_fail "an unreadable range must say so in the commit, never be silently empty. Message: $msg"
printf '%s' "$msg" | grep -q "$MISSING" \
    || fx_fail "the unavailable-changelog notice must name the revision. Message: $msg"
fx_assert_contains "$PROJECT/rsx/resource/framework_update_history.dat" "CHANGELOG UNAVAILABLE"
[ "$(fx_recorded_revision)" = "$C" ] \
    || fx_fail "the pointer must still be corrected to the tip, records $(fx_recorded_revision)"

fx_pass
