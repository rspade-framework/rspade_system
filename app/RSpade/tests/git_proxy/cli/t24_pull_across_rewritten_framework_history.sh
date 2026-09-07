#!/bin/bash
# t24: A PULL ACROSS A REWRITTEN FRAMEWORK HISTORY.
#
# From a downstream field report (2026-09-06): a container several app commits behind
# could not pull at all, and every retry failed identically:
#
#     Fetching submodule system
#     fatal: remote error: upload-pack: not our ref 018244f086...
#     Errors during submodule fetch:
#             system
#
# Git's default is fetch.recurseSubmodules=on-demand: an incoming superproject commit
# whose gitlink has no objects locally makes git fetch the submodule for it - once per
# INTERMEDIATE gitlink across the pulled range. After the framework's history was
# rewritten upstream, those intermediate gitlinks name revisions the framework remote no
# longer has, upload-pack refuses them, and git aborts the whole operation. sync_submodule
# - which is the correct code, and wants exactly ONE object, the revision recorded at the
# new HEAD - never runs.
#
# The proxy therefore runs the developer's command with recursion scoped off. This test
# builds that exact shape and proves three things:
#
#   1. BEFORE: a proxy without the two -c options aborts, and HEAD does not move.
#   2. ESCAPE HATCH: an explicit --recurse-submodules still recurses (and still fails -
#      by request; the flag on the command line overrides -c).
#   3. AFTER: the shipped proxy merges, and system/ lands on the revision the new HEAD
#      records.

TEST_NAME="git_proxy/cli t24 pull across a rewritten framework history"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

# The whole fixture is local repositories, and a submodule fetch is not a "direct user
# action" as far as protocol.file.allow's default is concerned - so without this the
# recursion under test would be refused for the wrong reason. Env config reaches every
# child process, which is exactly the set that matters here.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=protocol.file.allow
export GIT_CONFIG_VALUE_0=always

trap fx_cleanup EXIT
fx_init
fx_build_project
fx_build_upstream

# -----------------------------------------------------------------------------
# 1. Two framework revisions that are about to be discarded.
# -----------------------------------------------------------------------------
FW_WORK="$FX_ROOT/fw_doomed"
git clone -q "$FRAMEWORK_BARE" "$FW_WORK" || fx_fail "fixture: could not clone the framework"
git -C "$FW_WORK" config user.email doomed@example.com
git -C "$FW_WORK" config user.name  Doomed
git -C "$FW_WORK" config commit.gpgsign false
git -C "$FW_WORK" checkout -q -b doomed master

printf 'framework doomed 1\n' > "$FW_WORK/app/RSpade/core.php"
git -C "$FW_WORK" commit -qam "doomed 1"
FW_X1="$(git -C "$FW_WORK" rev-parse HEAD)"

printf 'framework doomed 2\n' > "$FW_WORK/app/RSpade/core.php"
git -C "$FW_WORK" commit -qam "doomed 2"
FW_X2="$(git -C "$FW_WORK" rev-parse HEAD)"

# NOT pushed. The framework remote is modelled in its POST-rewrite state: the
# discarded revisions are gone from it, which is what makes upload-pack answer
# "not our ref" to anybody who still asks for them.

# -----------------------------------------------------------------------------
# 2. App commits that walk the gitlink over them and land on FW_V2.
#
# This is the shape that matters: the FINAL recorded revision is reachable from the
# framework's master, every INTERMEDIATE one is not.
# -----------------------------------------------------------------------------
PEER="$FX_ROOT/peer_history"
git clone -q "$BARE" "$PEER" || fx_fail "fixture: could not clone the app upstream"
git -C "$PEER" config user.email peer@example.com
git -C "$PEER" config user.name  Peer
git -C "$PEER" config commit.gpgsign false
git -C "$PEER" submodule update -q --init || fx_fail "fixture: could not init the peer submodule"
# The peer is the box that made those commits back when the objects still existed.
git -C "$PEER/system" fetch -q "$FW_WORK" doomed || fx_fail "fixture: peer could not obtain the doomed line"

n=0
for rev in "$FW_X1" "$FW_X2" "$FW_V2"; do
    n=$((n + 1))
    git -C "$PEER/system" checkout -q "$rev" || fx_fail "fixture: peer could not check out $rev"
    printf 'app c%s\n' "$n" > "$PEER/app/app_file.txt"
    git -C "$PEER" add -A >/dev/null 2>&1
    git -C "$PEER" commit -q -m "app commit $n"
done
git -C "$PEER" push -q origin master || fx_fail "fixture: could not push the app history"

# -----------------------------------------------------------------------------
# 3. The rewrite is already in force: the framework remote never carried the doomed
#    line, so the intermediate gitlinks in the app's history name revisions it cannot
#    serve. The FINAL one (FW_V2) it can.
# -----------------------------------------------------------------------------
# This working copy is behind by all three app commits and has never seen the doomed
# objects (its submodule was cloned before they existed).
git -C "$PROJECT/system" cat-file -e "${FW_X1}^{commit}" 2>/dev/null \
    && fx_fail "precondition: the project already has the discarded objects"
[ "$(fx_recorded_revision)" = "$FW_V1" ] || fx_fail "precondition: not recorded at v1"
HEAD_BEFORE="$(git -C "$PROJECT" rev-parse HEAD)"

# =============================================================================
# BEFORE THE FIX: the proxied invocation inherits git's default recursion.
# =============================================================================
PREFIX_PROXY="$PROJECT/system/bin/rsx-git-prefix.sh"
sed 's/^git -c fetch\.recurseSubmodules=no -c submodule\.recurse=false "\${ARGS\[@\]}"$/git "${ARGS[@]}"/' \
    "$PROJECT/system/bin/rsx-git.sh" > "$PREFIX_PROXY"
grep -q '^git "${ARGS\[@\]}"$' "$PREFIX_PROXY" \
    || fx_fail "could not build the pre-fix proxy - has the invocation line changed shape?"

out="$( cd "$PROJECT" && bash "$PREFIX_PROXY" pull origin master 2>&1 )"
rc=$?
[ "$rc" -ne 0 ] || fx_fail "PRE-FIX: the pull was expected to abort. Output: $out"
printf '%s' "$out" | grep -qE 'not our ref|Errors during submodule fetch' \
    || fx_fail "PRE-FIX: expected a submodule-fetch abort. Output: $out"
[ "$(git -C "$PROJECT" rev-parse HEAD)" = "$HEAD_BEFORE" ] \
    || fx_fail "PRE-FIX: HEAD moved despite the abort"
[ "$(fx_recorded_revision)" = "$FW_V1" ] \
    || fx_fail "PRE-FIX: the recorded revision moved despite the abort"
rm -f "$PREFIX_PROXY"

# =============================================================================
# AFTER THE FIX: the shipped proxy merges, never touching the submodule during the
# fetch, and reconciles system/ itself afterwards.
# =============================================================================
rm -f "$(fx_maint_flag).disable_called"
fx_run pull origin master
fx_assert_rc 0

[ "$(git -C "$PROJECT" rev-parse HEAD)" != "$HEAD_BEFORE" ] \
    || fx_fail "the pull did not move HEAD. Output: $FX_OUT"
fx_refute_out "Fetching submodule" "the proxied invocation must not recurse into system/"
fx_refute_out "not our ref" "the discarded revisions must never be asked for"

[ "$(fx_recorded_revision)" = "$FW_V2" ] \
    || fx_fail "the pull did not record v2. Recorded: $(fx_recorded_revision)"
[ "$(fx_actual_revision)" = "$FW_V2" ] \
    || fx_fail "THE WHOLE POINT: system/ was left at $(fx_actual_revision)"
grep -q 'framework v2' "$PROJECT/system/app/RSpade/core.php" \
    || fx_fail "system/ does not hold the v2 content"

# And it reconciled the ordinary way, through the maintenance window.
fx_assert_out "framework revision changed"
[ -f "$(fx_maint_flag).disable_called" ] || fx_fail "the maintenance window was never lowered"

# =============================================================================
# THE ESCAPE HATCH: a developer who types --recurse-submodules still gets recursion -
# a flag on the command line overrides -c.
#
# Note what it gets: an EXPLICIT --recurse-submodules fetches the submodule's remote
# the ordinary way, where the on-demand default asks for one named sha per intermediate
# gitlink. That is why the default is the one that dies on a rewritten history, and why
# the flag may or may not fail here. What is asserted is that the developer's flag
# decides, not the proxy.
# =============================================================================
git -C "$PROJECT" reset --hard --quiet "$HEAD_BEFORE" || fx_fail "could not rewind the project"
git -C "$PROJECT/system" checkout --quiet --force "$FW_V1" || fx_fail "could not rewind system/"
[ "$(fx_recorded_revision)" = "$FW_V1" ] || fx_fail "rewind: not recorded at v1 again"

fx_run pull --recurse-submodules origin master
fx_assert_out "Fetching submodule" "--recurse-submodules did not reach git"

fx_pass
