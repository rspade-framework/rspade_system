#!/usr/bin/env bash
# =============================================================================
# rsx:git - the git proxy.
#
# It does exactly ONE thing: after any git operation that can move the recorded
# revision of the system/ submodule, it makes the submodule agree with it.
# Everything else is passthrough.
#
# (One rider, at the very bottom: a pull/merge that succeeded and moved HEAD also
# re-applies the environment updates, quietly. rsx/resource/ is manifest-ignored, so
# a pull carrying only a teammate's new application skill triggers no rebuild - and
# the rebuild is what normally runs them. See run_post_update.)
#
# THE PROBLEM. system/ is a git submodule. When a colleague updates the framework
# and you pull their commit, git updates the RECORDED revision and does not touch
# the submodule's working tree. Nothing fails, nothing is printed, and you are now
# running a framework version your project does not claim to use. `git status`
# says ` M system` and `git submodule status` prefixes a `+`, but nobody reads
# either before every command.
#
# The usual answer is to tell everyone to type `git pull --recurse-submodules`
# forever. A rule everybody has to remember is not a solution; this is.
#
# WHAT IT DOES, when the recorded revision moved and the submodule did not:
#     maintenance window up
#       -> rsx:clean          (resets the submodule hard, discarding local drift)
#       -> checkout the recorded revision
#       -> rebuild            (manifest + bundles)
#     maintenance window down
#
# The window matters: replacing the framework under a live php-fpm is exactly the
# situation maintenance mode exists for.
#
# WHAT IT NO LONGER DOES. This script used to be 1,553 lines, and every one of
# them followed from system/ being a VENDORED tree - ordinary tracked files
# sharing the application's index. It excluded system/ from status/diff/add with
# pathspecs, unstaged system/ paths out of commits, rewrote `commit -a`, and ran a
# release-reconciliation phase that read .rspade-release.json on both sides of a
# merge to decide which one was the framework. A submodule makes all of that
# structural: the framework contributes ONE gitlink to the application's index,
# git knows where the boundary is, and a system/ conflict is a one-line
# disagreement about which revision to use rather than a file-by-file merge.
#
# IT DOES NOT HAVE TO BE EXHAUSTIVE. bootstrap/rsx_submodule_sync.php refuses to
# boot when the recorded revision and the checkout disagree, whatever caused it -
# an IDE's git, /usr/bin/git directly, a script that never heard of this proxy. So
# this is the convenience and that is the correctness, which is why this file gets
# to be short enough to read.
#
# FAIL-OPEN. This wraps every git call for every downstream app, so a bug here
# must never brick git. Anything unexpected falls through to plain git with the
# original argv.
# =============================================================================

set -uo pipefail

ORIG_ARGV=("$@")

# Any `git` this script runs must be the REAL binary, never the shim that routed
# us here. The shim exports this itself; setting it again makes the script safe
# when invoked directly as `php artisan rsx:git`.
export RSX_GIT_SHIM_ACTIVE=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEM_DIR="$(dirname "$SCRIPT_DIR")"
PROJECT_ROOT="$(dirname "$SYSTEM_DIR")"
SUBMODULE_PATH="$(basename "$SYSTEM_DIR")"

# THE CALLER'S CWD IS ALREADY GONE BY THE TIME WE RUN, AND IT IS NOT system/.
#
# system/artisan does `chdir(__DIR__)` before anything else, so every
# `php artisan rsx:git ...` reaches this script with cwd = system/ no matter where
# the operator was standing. Git resolves a relative pathspec against cwd, so
# `rsx:git log -- rsx/foo.php` matches NOTHING and exits 0 - an audit command
# answering "no history" for a file that has history, which is the worst failure
# shape there is (found 2026-08-18; `rsx:git rev-parse --show-prefix` printed
# `system/`).
#
# The SHIM knows the answer and passes it in RSX_GIT_CWD - it runs before php and
# therefore before the chdir. Restore it and the proxy is genuinely transparent from
# any directory.
#
# Without the shim (a direct `php artisan rsx:git`, or an older shim), the operator's
# directory is unrecoverable and the honest anchor is the PROJECT ROOT - what every
# relative pathspec a human types is written against. That fallback undoes ONLY the
# artisan chdir: a cwd the caller genuinely chose (running this script directly, as
# the CLI fixtures do) is left exactly as it is.
if [ -n "${RSX_GIT_CWD:-}" ] && [ -d "${RSX_GIT_CWD}" ]; then
    cd "$RSX_GIT_CWD" || exit 1
elif [ "$(pwd -P 2>/dev/null)" = "$SYSTEM_DIR" ]; then
    cd "$PROJECT_ROOT" || exit 1
fi

# Reads never take git's optional index lock: this proxy must never contend with
# the very operation it is wrapping, nor with another session sharing the repo.
ROOT_GIT_RO=(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git --no-optional-locks -C "$PROJECT_ROOT")
ROOT_GIT=(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$PROJECT_ROOT")
SUB_GIT=(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$SYSTEM_DIR")

QUIET=false
MAINT_ACTIVE=false

note() { [ "$QUIET" = true ] || printf '\033[2m[rsx:git] %s\033[0m\n' "$*" >&2; }
warn() { printf '[WARNING] %s\n' "$*" >&2; }
err()  { printf '%s\n' "$*" >&2; }

# Fail-open: become plain git with the original argv.
bail_to_git() { exec git "${ORIG_ARGV[@]}"; }

# -----------------------------------------------------------------------------
# Argument handling. --rsx-* flags are ours (git would reject them); everything
# else is passed through untouched.
# -----------------------------------------------------------------------------
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --rsx-quiet)  QUIET=true ;;
        --rsx-*)      : ;;
        *)            ARGS+=("$arg") ;;
    esac
done

[ "${#ARGS[@]}" -eq 0 ] && exec git

for arg in "${ARGS[@]}"; do
    case "$arg" in
        --porcelain|--porcelain=*|-z|--quiet) QUIET=true ;;
    esac
done

# -----------------------------------------------------------------------------
# The four ways this is none of our business. Each one is exactly git.
# -----------------------------------------------------------------------------

# 1. The monorepo. There system/ IS the work being done, not a checkout of it.
if [ -f "$PROJECT_ROOT/.env" ] \
    && grep -qE '^IS_FRAMEWORK_DEVELOPER=true[[:space:]]*$' "$PROJECT_ROOT/.env" 2>/dev/null; then
    exec git "${ARGS[@]}"
fi

# 2. No git, or not a repository.
command -v git >/dev/null 2>&1 || bail_to_git
"${ROOT_GIT_RO[@]}" rev-parse --git-dir >/dev/null 2>&1 || exec git "${ARGS[@]}"

# 3. system/ is not a submodule - a project that has not been converted yet. There
#    is no gitlink to keep in step, and reaching into a tree whose shape we do not
#    recognise is not an improvement.
[ -e "$SYSTEM_DIR/.git" ] || exec git "${ARGS[@]}"

# 3b. A FRAMEWORK UPDATE IS IN FLIGHT. Stand down completely.
#
# rsx:framework:pull checks the new revision out BEFORE committing the gitlink, so
# for one moment the checkout is legitimately ahead of the record - which is exactly
# the shape this proxy exists to correct. Correcting it there means resetting the
# submodule back to the old revision and undoing the update.
#
# The updater already exports RSX_GIT_SHIM_ACTIVE so its own git calls never reach
# here. This is the second line, covering anything else that runs during the window -
# a build step, a hook, another shell in the same container.
if [ "${RSPADE_FRAMEWORK_UPDATE:-0}" = "1" ] || [ "${RSPADE_FRAMEWORK_COMMIT:-0}" = "1" ]; then
    exec git "${ARGS[@]}"
fi
if [ -f "$PROJECT_ROOT/storage/rsx-framework/.maintenance.mode.framework.update" ] \
    && [ "$(head -n1 "$PROJECT_ROOT/storage/rsx-framework/.maintenance.mode.framework.update" 2>/dev/null)" = "framework update in progress" ]; then
    exec git "${ARGS[@]}"
fi

# -----------------------------------------------------------------------------
# Find the real subcommand, stepping past git's global options. `git -C x -c k=v
# --no-pager pull` must classify as `pull`, not as `-C`.
# -----------------------------------------------------------------------------
SUBCMD=""
skip_next=false
for arg in "${ARGS[@]}"; do
    if [ "$skip_next" = true ]; then
        skip_next=false
        continue
    fi
    case "$arg" in
        -C|-c|--git-dir|--work-tree|--namespace|--exec-path|--super-prefix|--config-env)
            skip_next=true ;;
        -*) : ;;
        *)  SUBCMD="$arg"; break ;;
    esac
done

# 4. No subcommand (e.g. `git --version`), or one that cannot move the recorded
#    revision. THE LIST IS THE WHOLE POLICY: these are the operations that rewrite
#    what HEAD points at, and therefore the gitlink recorded for system/.
#
#    `restore` and `checkout <paths>` usually touch only files - they are here
#    because they CAN move it, and the before/after comparison below costs one
#    rev-parse and does nothing when it did not.
case "$SUBCMD" in
    pull|merge|rebase|cherry-pick|revert|checkout|switch|restore|reset|stash) : ;;
    *) exec git "${ARGS[@]}" ;;
esac

# =============================================================================
# Helpers
# =============================================================================

# The revision the PROJECT records for system/, read from the index - the same
# value `git status` compares against, and the same one the pre-boot guard reads.
recorded_revision() {
    "${ROOT_GIT_RO[@]}" ls-files -s -- "$SUBMODULE_PATH" 2>/dev/null \
        | awk '$1 == "160000" { print $2; exit }'
}

# The revision the submodule is ACTUALLY at.
actual_revision() {
    "${SUB_GIT[@]}" rev-parse HEAD 2>/dev/null || true
}

# An unmerged gitlink: both sides moved it and git could not choose. Not ours to
# settle - which framework release to run is a decision, not a merge.
gitlink_conflicted() {
    "${ROOT_GIT_RO[@]}" ls-files -u -- "$SUBMODULE_PATH" 2>/dev/null | grep -q .
}

maint_enable() {
    local script="$SYSTEM_DIR/bin/maintenance-mode.sh"
    [ -f "$script" ] || return 0
    bash "$script" enable --reason="framework update in progress" >/dev/null 2>&1 \
        && MAINT_ACTIVE=true
    return 0
}

maint_disable() {
    [ "$MAINT_ACTIVE" = true ] || return 0
    MAINT_ACTIVE=false
    local script="$SYSTEM_DIR/bin/maintenance-mode.sh"
    [ -f "$script" ] && bash "$script" disable >/dev/null 2>&1
    return 0
}

# Lift the window on EVERY exit path, Ctrl-C included. A proxy that leaves a box
# in 503 because a rebuild was interrupted is worse than no proxy.
trap 'maint_disable' EXIT INT TERM HUP

artisan() {
    local -a maint=()
    [ "$MAINT_ACTIVE" = true ] && maint=(--_framework-update-override)
    php "$SYSTEM_DIR/artisan" "$@" "${maint[@]}"
}

# Where the submodule fetches from. The submodule's own remote is the authority -
# a project tracking a fork keeps its fork.
upstream_url() {
    "${SUB_GIT[@]}" remote get-url origin 2>/dev/null || true
}

# =============================================================================
# sync_submodule - make the checkout agree with what the project records.
# =============================================================================
sync_submodule() {
    local recorded="$1"
    local actual="$2"

    note "framework revision changed: ${actual:0:12} -> ${recorded:0:12}"
    note "updating system/ to match (maintenance window up)"

    maint_enable

    # RESET THE SUBMODULE DIRECTLY, not through rsx:clean.
    #
    # rsx:clean does exactly this - and is still called below for the caches - but it
    # needs artisan to boot, and the checkout that follows must not depend on that. A
    # developer whose tree is too broken to run php is the developer most likely to be
    # pulling a fix, and `git checkout` refuses to overwrite local modifications, so
    # skipping the reset would leave the sync failing for the wrong reason.
    #
    # -x is deliberate: ignored files under system/ are framework build residue, not
    # developer content. Everything durable lives OUTSIDE the submodule, one level up
    # in storage/.
    git -C "$SYSTEM_DIR" reset --hard --quiet HEAD 2>/dev/null || true
    git -C "$SYSTEM_DIR" clean -qfdx 2>/dev/null || true

    # The recorded commit may not be in the local object store yet - a colleague
    # updated the framework, so their revision arrived as a gitlink with no
    # objects behind it.
    if ! "${SUB_GIT[@]}" cat-file -e "${recorded}^{commit}" 2>/dev/null; then
        local url
        url="$(upstream_url)"
        if [ -n "$url" ]; then
            note "fetching ${recorded:0:12} from $url"
            GIT_TERMINAL_PROMPT=0 "${SUB_GIT[@]}" fetch --quiet "$url" 2>/dev/null || true
        fi
    fi

    if ! "${SUB_GIT[@]}" cat-file -e "${recorded}^{commit}" 2>/dev/null; then
        maint_disable
        err ""
        err "[ERROR] This project records framework revision ${recorded:0:12}, which is not"
        err "        available from $(upstream_url || echo 'the configured remote')."
        err ""
        err "        system/ has been left at ${actual:0:12}. The application will refuse to"
        err "        start until they agree - fix it with:"
        err ""
        err "            git submodule update --init --recursive"
        err ""
        return 1
    fi

    if ! "${SUB_GIT[@]}" checkout --quiet --force "$recorded" 2>/dev/null; then
        maint_disable
        err "[ERROR] Failed to check out framework revision ${recorded:0:12} in system/."
        err "        Fix it with: git submodule update --init --recursive"
        return 1
    fi

    # And the caches, which describe the framework that was just replaced. This runs
    # AFTER the checkout on purpose: system/artisan refuses to boot while the recorded
    # revision and the checkout disagree (bootstrap/rsx_submodule_sync.php), and between
    # the reset above and this checkout they ALWAYS disagree - an rsx:clean placed there
    # never ran once, and its refusal was the "reported a problem" warning every revision
    # change printed. --_no-system-reset: the submodule was reset directly above.
    #
    # Best-effort - a stale cache is a rebuild away, an unsynced framework is not - but a
    # failure is REPORTED with its exit code and what the command said, never swallowed:
    # the moment the step fails is the one moment the explanation exists.
    local clean_output clean_rc=0
    clean_output="$(artisan rsx:clean --silent --_no-system-reset 2>&1)" || clean_rc=$?
    if [ "$clean_rc" -ne 0 ]; then
        warn "rsx:clean exited ${clean_rc}; the framework revision is still being updated. It said:"
        printf '%s\n' "$clean_output" | tail -n 8 | sed 's/^/    /' >&2
    fi

    # The framework just changed underneath a manifest that describes the old one.
    # --force because nothing about the previous build is incrementally valid.
    note "rebuilding"
    artisan rsx:manifest:build --force --_no-check-schema-updates-pending >/dev/null 2>&1 \
        || warn "rsx:manifest:build reported a problem - run it yourself: php artisan rsx:manifest:build --force"
    artisan rsx:bundle:compile >/dev/null 2>&1 \
        || warn "rsx:bundle:compile reported a problem - bundles JIT-compile on request, so continuing."

    maint_disable

    note "system/ is now at ${recorded:0:12}"

    # Migrations are a separate, deliberate step - a framework release can carry
    # schema changes and this proxy is not the thing that applies them.
    if artisan migrate:pending 2>/dev/null | grep -qv "No pending migrations"; then
        note ""
        warn "The new framework revision has pending migrations. Run: php artisan migrate"
    fi

    return 0
}

# =============================================================================
# post_update - re-apply the environment updates after a pull/merge landed code.
#
# WHY A PULL NEEDS THIS. The environment updates normally ride on a manifest build,
# and rsx/resource/ is manifest-IGNORED. So a pull whose only change is a teammate's
# new rsx/resource/skills/<name>/SKILL.md changes nothing the build watches, no
# rebuild is triggered, and the skill is never wired into .claude/skills/. Running
# the updates here makes "commit the skill" the whole distribution mechanism.
#
# --quiet: the developer asked for a pull, not for an environment report. Problems
# still print (post-update.sh never suppresses its WARNING lines).
#
# NON-FATAL, ALWAYS. The pull already succeeded; a failing environment update may
# not colour its outcome, and may not touch $GIT_RC.
#
# The monorepo never reaches this - check 1 above execs plain git there, which is
# the same IS_FRAMEWORK_DEVELOPER gate everything else in this file uses.
# =============================================================================
run_post_update() {
    local script="$SYSTEM_DIR/bin/post-update.sh"
    [ -f "$script" ] || return 0
    bash "$script" --quiet || warn "post-update.sh reported a problem after the pull; the pull itself succeeded."
    return 0
}

# =============================================================================
# Run the operation, then reconcile.
# =============================================================================
BEFORE="$(recorded_revision)"
HEAD_BEFORE="$("${ROOT_GIT_RO[@]}" rev-parse HEAD 2>/dev/null || true)"

git "${ARGS[@]}"
GIT_RC=$?

AFTER="$(recorded_revision)"
ACTUAL="$(actual_revision)"

# The operation's own exit code is what the caller sees, whatever we do next.
# A failed merge is still a failed merge.

if gitlink_conflicted; then
    err ""
    err "[NOTE] system/ is conflicted: both sides changed which framework revision"
    err "       this project uses. That is a choice, not a merge - pick one:"
    err ""
    err "           git checkout --theirs -- $SUBMODULE_PATH   # take the incoming revision"
    err "           git checkout --ours   -- $SUBMODULE_PATH   # keep yours"
    err "           git add $SUBMODULE_PATH"
    err ""
    err "       Then run 'php artisan rsx:framework:pull' to bring system/ into line."
    err ""
    exit "$GIT_RC"
fi

# Nothing recorded (system/ removed from the index) or nothing checked out: not a
# state to reconcile automatically.
if [ -z "$AFTER" ] || [ -z "$ACTUAL" ]; then
    exit "$GIT_RC"
fi

if [ "$AFTER" != "$ACTUAL" ]; then
    sync_submodule "$AFTER" "$ACTUAL" || true
fi

# A successful pull/merge that actually moved HEAD brought code in - re-apply the
# environment updates so anything it delivered outside the manifest's view (an
# application skill, a hook) is wired before the developer's next command.
if [ "$GIT_RC" -eq 0 ]; then
    case "$SUBCMD" in
        pull|merge)
            HEAD_AFTER="$("${ROOT_GIT_RO[@]}" rev-parse HEAD 2>/dev/null || true)"
            if [ -n "$HEAD_AFTER" ] && [ "$HEAD_AFTER" != "$HEAD_BEFORE" ]; then
                run_post_update
            fi
            ;;
    esac
fi

exit "$GIT_RC"
