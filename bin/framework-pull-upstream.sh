#!/usr/bin/env bash
# =============================================================================
# RSpade framework updater - submodule model.
#
# system/ IS A GIT SUBMODULE tracking:
#     https://github.com/rspade-framework/rspade_system.git
#
# ALL OF system/ IS FRAMEWORK PROPERTY AND IS OVERWRITTEN ON EVERY UPDATE.
# There are no owned zones, no protected sub-paths, and no merge. Nothing under
# system/ is yours to edit: an update discards local changes there without asking
# and without reporting them, because there is nothing to report - the tree is a
# checkout of somebody else's repository. Customize the framework the supported
# way, with a class override in rsx/ (`php artisan rsx:man class_override`).
#
# WHY THE SUBMODULE REPLACED THE VENDORED TREE. When system/ was ordinary tracked
# files in the application's own repository, the framework and the application
# shared one index, and everything that followed was an attempt to reconstruct a
# boundary git could not see: a per-file release inventory to detect tampering, a
# tamper gate, an owned-zone rsync, a three-way merge for the remainder, a
# pre-commit hook that unstaged system/ paths, a dedicated framework commit, and a
# foreign-path untracker. A submodule makes that boundary structural. What is left
# is: reset, fetch, checkout, record the pointer.
#
# THE UPDATE, IN FULL:
#   1. resolve context and paths
#   2. raise the maintenance window
#   3. establish that system/ is a submodule (converting inside the container,
#      refusing outside it)
#   4. git reset --hard the submodule - local changes there are not consulted
#   5. fetch and check out the upstream tip
#   6. commit the new submodule pointer in the parent repo, carrying the
#      concatenated upstream changelog as the commit body
#   7. rebuild
#   8. lower the maintenance window
#   9. report pending migrations and pending upstream_changes documents
#
# The live bin/framework-pull-upstream.sh is GENERATED from this .dist by the
# artisan interception in system/artisan. EDIT THE .dist ONLY.
# =============================================================================

set -uo pipefail

# EVERY `git` THIS SCRIPT RUNS MUST BE THE REAL BINARY, never the shim.
#
# Inside the container /usr/local/bin/git routes to `php artisan rsx:git`, and the
# proxy's whole job is to notice when the submodule and the recorded revision
# disagree and to put them back in step. That is precisely the state this script
# passes through: it checks the new revision out BEFORE committing the gitlink, so
# for one moment the checkout is ahead of the record.
#
# Without this, the proxy sees that moment, calls it drift, and resets the submodule
# BACK to the old recorded revision - undoing the update, while this script goes on
# to report success against variables it set before the reset happened. Both
# components pass their own tests and quietly destroy each other's work (found by
# the end-to-end update test, 2026-08-21).
#
# This script IS the thing that manages the submodule. It must never be watched by
# the thing that watches for unmanaged changes.
export RSX_GIT_SHIM_ACTIVE=1

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
# THE DEFAULT IS ONLY EVER USED TO CREATE THE SUBMODULE.
#
# Once system/ is a submodule, its URL belongs to the project, not to this script.
# A developer may point it at a fork, an internal mirror, a fork's fork - and an
# updater that re-asserted its own idea of the URL on every run would silently drag
# them back to the public distribution, discarding a deliberate decision with no
# message. So: hardcoded for the initial `git submodule add`, and read from the
# submodule itself every time after that.
#
# Anonymous https - no credentials required to pull the public distribution.
DEFAULT_UPSTREAM_URL="https://github.com/rspade-framework/rspade_system.git"
DEFAULT_UPSTREAM_BRANCH="master"
SUBMODULE_PATH="system"

# Resolved per run (see resolve_upstream). Empty until then.
UPSTREAM_URL=""
UPSTREAM_BRANCH=""

# --upstream-url= / --branch=: an explicit override FOR THIS RUN ONLY. It does not
# rewrite the submodule's configuration - a one-off pull from somewhere else is not
# a decision to make permanent on the developer's behalf.
UPSTREAM_URL_OVERRIDE=""
UPSTREAM_BRANCH_OVERRIDE=""

# -----------------------------------------------------------------------------
# Flags
# -----------------------------------------------------------------------------
SERVICE_CONTROL=true   # raise the maintenance window around the update;
                       # --no-service-control opts out. The service LIST lives in
                       # one place: bin/maintenance-mode.sh. Never enumerate it here.
NO_REBUILD=false
NO_COMMIT=false
SHOW_DIFF=false
FORCE=false            # retained so an existing invocation does not error; the
                       # tamper gate it used to override no longer exists, because
                       # local modification under system/ is no longer a question
                       # the updater asks.

# -----------------------------------------------------------------------------
# Derived state
# -----------------------------------------------------------------------------
SCRIPT_DIR=""
SYSTEM_DIR=""
PROJECT_ROOT=""
STORE_DIR=""
HISTORY_FILE=""
MAINT_ACTIVE=false
IN_CONTAINER=false
OLD_SHA=""
NEW_SHA=""
FRAMEWORK_CHANGELOG=""
CONVERTED=false
LOCK_EXHAUSTED_RC=97

# -----------------------------------------------------------------------------
# Output
# -----------------------------------------------------------------------------
say()  { echo "$*"; }
ok()   { echo "[OK] $*"; }
warn() { echo "[WARNING] $*"; }
err()  { echo "[ERROR] $*" >&2; }

die() {
    err "$*"
    exit 1
}

# =============================================================================
# cleanup - the EXIT/INT/TERM/HUP trap.
#
# ALWAYS lifts the maintenance window, on every exit path including Ctrl-C. ONE
# implementation: the same bin/maintenance-mode.sh the operator command runs (it
# restarts every supervised service it stopped, in dependency order, then clears
# the flag - that script owns the list and this one never repeats it). Guarded on
# MAINT_ACTIVE so a window the OPERATOR raised before running the pull is left
# exactly as they left it.
# =============================================================================
cleanup() {
    # The relocated copy of this script (see relocate_to_tmp). $0 is that copy once
    # the re-exec has happened; deleting it while it runs is safe on Linux and is
    # what keeps /tmp from filling with updaters.
    if [ "${RSPADE_UPDATER_RELOCATED:-0}" = "1" ]; then
        case "$0" in /tmp/rspade-updater-*) rm -f "$0" 2>/dev/null || true ;; esac
    fi
    if [ "$MAINT_ACTIVE" = true ]; then
        MAINT_ACTIVE=false
        if [ -f "$SYSTEM_DIR/bin/maintenance-mode.sh" ]; then
            bash "$SYSTEM_DIR/bin/maintenance-mode.sh" disable || true
        else
            # The tree lost the script mid-run: never leave a box stuck in 503.
            rm -f "$(storage_base)/rsx-framework/.maintenance.mode.framework.update" 2>/dev/null || true
            err "Maintenance script missing; the flag was cleared but services were NOT restarted."
        fi
    fi
}

# =============================================================================
# parse_flags
# =============================================================================
parse_flags() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --no-service-control)   SERVICE_CONTROL=false ;;
            --no-rebuild)           NO_REBUILD=true ;;
            --no-commit)            NO_COMMIT=true ;;
            --diff|--show-diff)     SHOW_DIFF=true ;;
            --force)                FORCE=true ;;
            --upstream-url=*)       UPSTREAM_URL_OVERRIDE="${arg#--upstream-url=}" ;;
            --branch=*)             UPSTREAM_BRANCH_OVERRIDE="${arg#--branch=}" ;;
            -h|--help)
                print_help
                exit 0
                ;;
            *)
                # Unknown flags are ignored rather than fatal: `php artisan
                # rsx:framework:pull` forwards every argument it was given, and a
                # future artisan-side option must not break the updater.
                :
                ;;
        esac
    done
}

print_help() {
    cat <<'HELP'
php artisan rsx:framework:pull [options]

Updates system/ - a git submodule tracking the RSpade framework distribution.
ALL of system/ is framework property and is overwritten on every update.

  --no-service-control   Do not raise the maintenance window (you manage it).
  --no-rebuild           Sync only; print the rebuild commands to run yourself.
  --no-commit            Update the submodule but do not commit the pointer.
  --diff                 Show what an update WOULD bring; change nothing.
  --upstream-url=<url>   Pull from somewhere other than the public distribution.
  --branch=<name>        Track a branch other than master.
HELP
}

# =============================================================================
# storage_base / derive_paths - everything from the script's own location, never
# the caller's cwd.
# =============================================================================
storage_base() {
    printf '%s' "$PROJECT_ROOT/storage"
}

derive_paths() {
    # After the relocation below this script no longer sits inside the tree it is
    # updating, so its own path cannot locate the project. The pre-relocation run
    # exports what it worked out; honour that when present.
    if [ -n "${RSPADE_UPDATER_PROJECT_ROOT:-}" ]; then
        PROJECT_ROOT="$RSPADE_UPDATER_PROJECT_ROOT"
        SYSTEM_DIR="$PROJECT_ROOT/$SUBMODULE_PATH"
        SCRIPT_DIR="$SYSTEM_DIR/bin"
    else
        SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
        SYSTEM_DIR="$(dirname "$SCRIPT_DIR")"
        PROJECT_ROOT="$(dirname "$SYSTEM_DIR")"
    fi

    STORE_DIR="$(storage_base)/rsx-framework"
    HISTORY_FILE="$PROJECT_ROOT/rsx/resource/framework_update_history.dat"

    # cwd immunity: anchor the process at PROJECT_ROOT and keep it there. artisan
    # forwards to this script inheriting whatever cwd `php artisan` ran from, which
    # may be INSIDE system/ - a directory the conversion path deletes. Every command
    # below uses absolute paths, so pinning the cwd here simply removes any
    # dependence on the caller's.
    cd "$PROJECT_ROOT" || die "Failed to enter project root: $PROJECT_ROOT"
}

# =============================================================================
# run_gates - the situations the framework must never auto-update in.
# =============================================================================
run_gates() {
    command -v git >/dev/null 2>&1 || die "git is required to update the framework."

    git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1 \
        || die "$PROJECT_ROOT is not a git repository. The framework is a git submodule of your application's repository; there is nowhere to record it."

    # BOTH .env PATHS. system/.env is normally a SYMLINK to the project-root file, so
    # these are one file - but not in a test fixture, and not on a checkout whose link
    # has not been healed yet. A gate that reads only one of them is a gate that can be
    # walked past by writing the other.
    local env_file="$PROJECT_ROOT/.env"
    local sys_env_file="$SYSTEM_DIR/.env"

    # Match $1 in either file.
    __env_says() {
        grep -qE "$1" "$env_file" 2>/dev/null || grep -qE "$1" "$sys_env_file" 2>/dev/null
    }

    # First value of $1 from either file (project root wins).
    __env_value() {
        local v=""
        v="$(grep -E "^$1=" "$env_file" 2>/dev/null | tail -n1 | sed -E "s/^$1=//; s/[\"[:space:]]//g")"
        [ -z "$v" ] && v="$(grep -E "^$1=" "$sys_env_file" 2>/dev/null | tail -n1 | sed -E "s/^$1=//; s/[\"[:space:]]//g")"
        printf '%s' "$v"
    }

    # -------------------------------------------------------------------------
    # THE FRAMEWORK MONOREPO. This is the most important refusal in the file.
    #
    # There system/ IS the authored framework source, not a checkout of it - and
    # therefore not a submodule. Without this gate the run reaches
    # convert_to_submodule(), which begins by deleting system/, and the framework
    # is replaced by a submodule pointing at its own published output. Every gate
    # below is a policy; this one is a safety interlock.
    # -------------------------------------------------------------------------
    if __env_says '^IS_FRAMEWORK_DEVELOPER=true[[:space:]]*$'; then
        err "This command is disabled for framework developers."
        say ""
        say "  system/ here is the framework's own source, not a checkout of a release."
        say "  This command exists for APPLICATION developers to pull framework updates;"
        say "  running it here would replace the source you are authoring with a submodule"
        say "  pointing at its published output."
        say ""
        say "  Framework developers manage this repository with ordinary git."
        exit 1
    fi

    # -------------------------------------------------------------------------
    # FORKED FRAMEWORK. The developer has taken ownership of the framework tree;
    # an update would overwrite work they deliberately claimed.
    # -------------------------------------------------------------------------
    if [ -f "$PROJECT_ROOT/.rspade-forked-framework" ] || [ -f "$SYSTEM_DIR/.rspade-forked-framework" ]; then
        err "Framework is in forked mode (.rspade-forked-framework present)."
        say ""
        say "  You have taken full ownership of the RSpade framework codebase, so automatic"
        say "  updates are disabled - all of system/ is overwritten by an update, and that"
        say "  is exactly what a fork exists to prevent."
        say ""
        say "  Manual update procedures: php artisan rsx:man framework_fork"
        exit 1
    fi

    # -------------------------------------------------------------------------
    # PRODUCTION. Framework updates are reviewed somewhere else and deployed, not
    # pulled into a live deployment.
    # -------------------------------------------------------------------------
    if __env_says '^APP_ENV=production[[:space:]]*$'; then
        err "Framework updates are disabled in production."
        say ""
        say "  Incorporate and test framework updates in a development environment, then"
        say "  deploy the reviewed result."
        exit 1
    fi

    # -------------------------------------------------------------------------
    # SEALED BUILDS. Stricter than the APP_ENV check above, which misses a sealed
    # DEBUG build. debug and production are compiled once and then immutable;
    # swapping the framework underneath one breaks the seal, and the application
    # then serves assets that no longer match what it was built from.
    #
    # RSX_MODE absent means development (the same default Rsx::get_mode() applies).
    # -------------------------------------------------------------------------
    local rsx_mode
    rsx_mode="$(__env_value RSX_MODE)"
    [ -z "$rsx_mode" ] && rsx_mode="development"
    [ "$rsx_mode" = "dev" ] && rsx_mode="development"

    if [ "$rsx_mode" != "development" ]; then
        err "Framework updates require development mode; this environment is '$rsx_mode'."
        say ""
        say "  debug and production are SEALED builds - compiled once and then immutable."
        say "  Replacing the framework under one leaves it serving assets that no longer"
        say "  match what it was built from."
        say ""
        say "  Pull in a development environment, then rebuild the sealed build from the"
        say "  reviewed result:  php artisan rsx:prod:refresh"
        exit 1
    fi
}

# =============================================================================
# detect_container - is this the RSpade container?
#
# The marker the framework's own Dockerfile writes. It decides ONE thing here:
# whether a project still carrying the old vendored system/ is converted
# automatically or told to convert itself. A conversion rewrites the application's
# git history, and doing that unasked on somebody's own machine is not the same act
# as doing it inside the container the framework built and controls.
# =============================================================================
detect_container() {
    if [ -f /.rspade_container ]; then
        IN_CONTAINER=true
    fi
}

# =============================================================================
# is_submodule - both halves must agree.
#
# A .gitmodules entry with no checked-out working tree is a half-finished clone,
# not a completed migration, and must fall through to be finished.
# =============================================================================
is_submodule() {
    git -C "$PROJECT_ROOT" config --file "$PROJECT_ROOT/.gitmodules" \
        --get "submodule.${SUBMODULE_PATH}.url" >/dev/null 2>&1 \
        && [ -e "$PROJECT_ROOT/$SUBMODULE_PATH/.git" ]
}

# =============================================================================
# refuse_conversion_outside_container - the fatal for a non-container box that has
# not been converted.
#
# It prints the whole remedy rather than the diagnosis alone: somebody reading this
# has a working application and no idea that the framework's storage model changed
# underneath them.
# =============================================================================
refuse_conversion_outside_container() {
    err "system/ is not a git submodule, and this is not the RSpade container."
    say ""
    say "  The framework is distributed as a git submodule. Your system/ directory is"
    say "  still the older form - ordinary files committed into this repository - and"
    say "  converting it rewrites this repository's history, which is not something to"
    say "  do to your machine without you asking for it."
    say ""
    say "  Convert it inside the container, which does this automatically:"
    say ""
    say "      docker compose exec app php artisan rsx:framework:pull"
    say ""
    say "  Or do it by hand, from the project root:"
    say ""
    say "      git rm -r --cached system && rm -rf system"
    say "      git commit -m 'Migrate system/ to a git submodule'"
    say "      git submodule add $DEFAULT_UPSTREAM_URL $SUBMODULE_PATH"
    say "      git commit -m 'Add system/ as a git submodule'"
    say ""
    say "  Either way, everyone else who clones this repository afterwards needs:"
    say ""
    say "      git submodule update --init --recursive"
    say ""
    exit 1
}

# =============================================================================
# convert_to_submodule - replace the vendored system/ with the submodule.
#
# Container-only (main() enforces that). Two commits, matching what a person would
# do by hand: remove the vendored tree, then add the submodule.
#
# REACHABILITY IS PROVEN FIRST. The failure this avoids is the expensive one:
# system/ deleted and committed, then the clone fails for want of a network,
# leaving an application with no framework and a commit saying that was intended.
# If the add fails anyway, the removal commit is rolled back.
# =============================================================================
convert_to_submodule() {
    # CREATING the submodule is the one moment the hardcoded default applies:
    # there is no existing configuration to respect yet. From the next run
    # onwards resolve_upstream reads whatever this establishes, so a developer
    # who later repoints it keeps their choice.
    UPSTREAM_URL="${UPSTREAM_URL_OVERRIDE:-$DEFAULT_UPSTREAM_URL}"
    UPSTREAM_BRANCH="${UPSTREAM_BRANCH_OVERRIDE:-$DEFAULT_UPSTREAM_BRANCH}"

    say "system/ is not a submodule yet - converting."
    say "  Tracking $UPSTREAM_URL ($UPSTREAM_BRANCH)"
    say ""

    GIT_TERMINAL_PROMPT=0 git ls-remote "$UPSTREAM_URL" HEAD >/dev/null 2>&1 \
        || die "Cannot reach $UPSTREAM_URL - leaving system/ exactly as it is. Check network access from the container, then re-run."

    # A previous half-finished attempt leaves the submodule's git dir behind, and
    # `git submodule add` refuses a path it already has one for.
    if [ -d "$PROJECT_ROOT/.git/modules/$SUBMODULE_PATH" ] && [ ! -e "$PROJECT_ROOT/$SUBMODULE_PATH/.git" ]; then
        rm -rf "$PROJECT_ROOT/.git/modules/$SUBMODULE_PATH" \
            || die "Could not clear a stale .git/modules/$SUBMODULE_PATH from an earlier attempt."
    fi

    # An identity is required to commit and a container often has none configured.
    # Supplied ONLY when the repository lacks one - a developer's own always wins.
    local -a git_id=()
    if ! git -C "$PROJECT_ROOT" config user.email >/dev/null 2>&1; then
        git_id=(-c "user.name=RSpade Framework Update" -c "user.email=rspade@localhost")
    fi

    # -- remove the vendored tree ------------------------------------------------
    # Scoped to system/ rather than `git add -A`: this runs unattended, and sweeping
    # a developer's unrelated work-in-progress into a framework commit is not a
    # decision to make for them.
    [ -e "$PROJECT_ROOT/$SUBMODULE_PATH" ] && rm -rf "$PROJECT_ROOT/$SUBMODULE_PATH"
    git -C "$PROJECT_ROOT" add -A -- "$SUBMODULE_PATH" 2>/dev/null || true

    local removal_commit=""
    if ! git -C "$PROJECT_ROOT" diff --cached --quiet -- "$SUBMODULE_PATH" 2>/dev/null; then
        # RSPADE_FRAMEWORK_COMMIT + --no-verify: a legacy pre-commit guard still
        # installed here exists to stop exactly this commit.
        RSPADE_FRAMEWORK_COMMIT=1 git -C "$PROJECT_ROOT" "${git_id[@]}" commit --quiet --no-verify \
            -m "Migrate system/ to a git submodule (remove the vendored framework tree)

system/ was ordinary tracked files in this repository. It becomes a submodule in
the following commit, so the boundary between the framework and this application
is one git enforces rather than one maintained by convention.

No application code is touched: everything under system/ is framework-owned." \
            || die "Failed to commit the removal of $SUBMODULE_PATH. Recover with: git checkout -- $SUBMODULE_PATH"
        removal_commit="$(git -C "$PROJECT_ROOT" rev-parse HEAD)"
    fi

    # -- add the submodule -------------------------------------------------------
    if ! GIT_TERMINAL_PROMPT=0 git -C "$PROJECT_ROOT" submodule add --force \
            -b "$UPSTREAM_BRANCH" "$UPSTREAM_URL" "$SUBMODULE_PATH" >/dev/null 2>&1; then
        err "Failed to add the submodule from $UPSTREAM_URL."
        if [ -n "$removal_commit" ] && [ "$(git -C "$PROJECT_ROOT" rev-parse HEAD)" = "$removal_commit" ]; then
            warn "Rolling back the removal commit so this checkout is left working."
            git -C "$PROJECT_ROOT" reset --hard --quiet HEAD~1 && ok "Rolled back; system/ is restored."
        fi
        exit 1
    fi

    git -C "$PROJECT_ROOT" add .gitmodules "$SUBMODULE_PATH" 2>/dev/null || true
    if ! git -C "$PROJECT_ROOT" diff --cached --quiet 2>/dev/null; then
        RSPADE_FRAMEWORK_COMMIT=1 git -C "$PROJECT_ROOT" "${git_id[@]}" commit --quiet --no-verify \
            -m "Add system/ as a git submodule (rspade_system)

Tracks $UPSTREAM_URL. Framework updates are now a submodule pointer change,
reviewable as a one-line diff, instead of a wholesale rewrite of vendored files." \
            || die "The submodule was added but committing it failed; it is staged - commit it by hand."
    fi

    CONVERTED=true
    ok "system/ is now a git submodule."
    say "     Anyone cloning this repository needs: git submodule update --init --recursive"
    say ""
}

# =============================================================================
# reset_submodule - discard everything local under system/.
#
# NO CHECKS, NO REPORT, NO PROMPT. All of system/ is framework property; a local
# modification there is not a change to preserve, weigh or describe - it is a
# checkout that has drifted from the commit it claims to be. This is the single
# line that replaces the entire tamper gate, release inventory, owned-zone rsync
# and three-way merge of the vendored era.
#
# -x on the clean is deliberate: ignored files under system/ are framework build
# residue, not developer content. Volatile state that must survive lives in
# <project>/storage, one level up (see environment_updates/030).
# =============================================================================
reset_submodule() {
    git -C "$PROJECT_ROOT/$SUBMODULE_PATH" reset --hard --quiet HEAD 2>/dev/null || true
    git -C "$PROJECT_ROOT/$SUBMODULE_PATH" clean -qfdx 2>/dev/null || true
}

# =============================================================================
# fetch_upstream - bring the submodule to the upstream tip.
#
# Sets OLD_SHA (where we were) and NEW_SHA (where we are going) - the range the
# changelog is computed over.
# =============================================================================
# =============================================================================
# resolve_upstream - work out WHERE this pull fetches from, and never change it.
#
# THE SUBMODULE'S OWN CONFIGURATION IS THE AUTHORITY. A developer is free to point
# system/ at a fork or an internal mirror; that is a supported thing to do, and the
# updater's job is to update from wherever the project actually tracks - not to
# have an opinion about it. Nothing here writes a remote, a URL or a branch.
#
# Precedence, most specific first:
#   1. --upstream-url= / --branch=   one-off override for this run
#   2. the submodule's `origin` remote (what git would use for a bare `git fetch`)
#   3. .gitmodules                   the recorded URL, when origin is somehow absent
#   4. the hardcoded default         last resort; normally unreachable here, since
#                                    a submodule that exists has a remote
#
# The branch is resolved the same way, with `submodule.<name>.branch` from
# .gitmodules as the recorded value - a fork tracking something other than master
# records it there, and `git submodule add -b` writes it.
# =============================================================================
resolve_upstream() {
    local sm="$PROJECT_ROOT/$SUBMODULE_PATH"

    if [ -n "$UPSTREAM_URL_OVERRIDE" ]; then
        UPSTREAM_URL="$UPSTREAM_URL_OVERRIDE"
    else
        UPSTREAM_URL="$(git -C "$sm" remote get-url origin 2>/dev/null || echo '')"

        if [ -z "$UPSTREAM_URL" ] && [ -f "$PROJECT_ROOT/.gitmodules" ]; then
            UPSTREAM_URL="$(git -C "$PROJECT_ROOT" config --file "$PROJECT_ROOT/.gitmodules" \
                --get "submodule.${SUBMODULE_PATH}.url" 2>/dev/null || echo '')"
        fi

        [ -n "$UPSTREAM_URL" ] || UPSTREAM_URL="$DEFAULT_UPSTREAM_URL"
    fi

    if [ -n "$UPSTREAM_BRANCH_OVERRIDE" ]; then
        UPSTREAM_BRANCH="$UPSTREAM_BRANCH_OVERRIDE"
    else
        UPSTREAM_BRANCH=""
        if [ -f "$PROJECT_ROOT/.gitmodules" ]; then
            UPSTREAM_BRANCH="$(git -C "$PROJECT_ROOT" config --file "$PROJECT_ROOT/.gitmodules" \
                --get "submodule.${SUBMODULE_PATH}.branch" 2>/dev/null || echo '')"
        fi
        # `.` is git's shorthand for "the superproject's branch"; it is not a ref
        # name and cannot be fetched by that spelling.
        [ "$UPSTREAM_BRANCH" = "." ] && UPSTREAM_BRANCH=""
        [ -n "$UPSTREAM_BRANCH" ] || UPSTREAM_BRANCH="$DEFAULT_UPSTREAM_BRANCH"
    fi
}

# =============================================================================
# fetch_upstream - bring the submodule to the tip of whatever it tracks.
#
# Sets OLD_SHA (where we were) and NEW_SHA (where we are going) - the range the
# changelog is computed over. Fetches BY URL rather than by remote name, so a
# --upstream-url= override needs no configuration change to take effect and a
# submodule with an oddly-named remote still works.
# =============================================================================
fetch_upstream() {
    local sm="$PROJECT_ROOT/$SUBMODULE_PATH"

    OLD_SHA="$(git -C "$sm" rev-parse HEAD 2>/dev/null || echo '')"

    say "Fetching $UPSTREAM_URL ($UPSTREAM_BRANCH)..."
    GIT_TERMINAL_PROMPT=0 git -C "$sm" fetch --quiet "$UPSTREAM_URL" "$UPSTREAM_BRANCH" \
        || die "Failed to fetch $UPSTREAM_BRANCH from $UPSTREAM_URL.
   This is the URL system/ tracks - change it with 'git -C system remote set-url origin <url>'
   if it is wrong, or pass --upstream-url=<url> for a one-off pull from elsewhere."

    NEW_SHA="$(git -C "$sm" rev-parse FETCH_HEAD 2>/dev/null || echo '')"
    [ -n "$NEW_SHA" ] || die "Could not resolve the upstream tip of $UPSTREAM_BRANCH."
}

# =============================================================================
# compute_changelog - the byte-faithful OLD..NEW changelog.
#
# ONE string, TWO consumers: the history .dat (in full) and the pointer commit
# message (size-capped below).
#
# Per upstream commit: a header line `<sha> <date> <subject>` then the commit BODY.
# Both halves are load-bearing. bin/publish embeds every underlying monorepo
# commit's full message into the release commit's body, so a single distribution
# commit's %b is the complete rationale for everything in that release - which is
# why the changelog does not need to reach through the release squash. The %s
# header is the release-boundary marker that separates blocks when a range spans
# several releases, and is all a body-less commit would contribute.
#
# Agent attribution noise is filtered: this is the customer-facing audit record in
# an application's history, not a record of who typed it.
# =============================================================================
compute_changelog() {
    FRAMEWORK_CHANGELOG=""
    [ -n "$OLD_SHA" ] || return 0
    [ "$OLD_SHA" = "$NEW_SHA" ] && return 0

    FRAMEWORK_CHANGELOG="$(git -C "$PROJECT_ROOT/$SUBMODULE_PATH" log --date=short \
        --pretty=format:'%h %ad %s%n%n%b' "$OLD_SHA..$NEW_SHA" 2>/dev/null \
        | grep -vE 'Claude Code|Co-Authored-By:' || true)"
}

# =============================================================================
# changelog_for_commit_message - FRAMEWORK_CHANGELOG, capped.
#
# The history .dat always gets the changelog IN FULL and is the complete record. A
# commit message is read in a terminal, a PR view and a hosting UI, so it takes a
# size cap - one that SAYS what it dropped and where the rest is. Silent truncation
# would defeat the point of putting the text here at all.
#
# Capped on BYTES rather than commit count: the meaningful cost is the size of the
# message, not how many commits produced it. Truncation lands on a line boundary -
# half a sentence reads as corruption.
# =============================================================================
CHANGELOG_COMMIT_MAX_BYTES=65536
changelog_for_commit_message() {
    local log="$FRAMEWORK_CHANGELOG"
    [ -n "$log" ] || { printf ''; return 0; }

    local size
    size="$(printf '%s' "$log" | wc -c)"
    if [ "$size" -le "$CHANGELOG_COMMIT_MAX_BYTES" ]; then
        printf '%s' "$log"
        return 0
    fi

    local kept dropped
    kept="$(printf '%s' "$log" | head -c "$CHANGELOG_COMMIT_MAX_BYTES" | sed '$d')"
    dropped=$(( size - $(printf '%s' "$kept" | wc -c) ))
    printf '%s\n\n[... %s more bytes of changelog truncated from this commit message.\n     The COMPLETE text for this range is in rsx/resource/framework_update_history.dat ...]' \
        "$kept" "$dropped"
}

# =============================================================================
# checkout_new - move the submodule working tree onto NEW_SHA.
#
# Detached HEAD is correct for a submodule: the parent records a commit, not a
# branch, and that is what makes a clone reproducible.
# =============================================================================
checkout_new() {
    git -C "$PROJECT_ROOT/$SUBMODULE_PATH" checkout --quiet --force "$NEW_SHA" \
        || die "Failed to check out $NEW_SHA in $SUBMODULE_PATH."
}

# =============================================================================
# write_history - append this update to rsx/resource/framework_update_history.dat.
#
# The DURABLE, COMPLETE record: the commit message carries a size-capped view of the
# same changelog, this carries all of it. The section header is a stable, greppable
# shape - `## RSPADE-UPDATE <iso> from=<sha> to=<sha>` - so the file can be read by
# something other than a human without parsing prose.
#
# A conversion has no previous revision and records `from=BASELINE`. An update that
# did not move (OLD == NEW) writes nothing: a from=X to=X section is pure noise.
# =============================================================================
write_history() {
    [ -n "$NEW_SHA" ] || return 0
    if [ -n "$OLD_SHA" ] && [ "$OLD_SHA" = "$NEW_SHA" ]; then
        return 0
    fi

    local now human section
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    human="$(date -u '+%Y-%m-%d %H:%M UTC')"

    mkdir -p "$(dirname "$HISTORY_FILE")" 2>/dev/null || return 0

    if [ -z "$OLD_SHA" ]; then
        section="$(cat <<EOF
## RSPADE-UPDATE $now from=BASELINE to=$NEW_SHA
Date: $human

History baseline established (system/ became a submodule in this update).
Framework revision: $NEW_SHA
EOF
)"
        say ""
        say "History baseline established at ${NEW_SHA:0:12}."
    else
        local stat
        stat="$(git -C "$PROJECT_ROOT/$SUBMODULE_PATH" diff --stat "$OLD_SHA" "$NEW_SHA" 2>/dev/null | sed 's/^/  /' || true)"

        # The changelog goes to the console too. An update that says only "done" gives
        # an operator nothing to review, and this is the moment they are looking.
        say ""
        say "=== Framework changelog (${OLD_SHA:0:12}..${NEW_SHA:0:12}) ==="
        say ""
        printf '%s\n' "$FRAMEWORK_CHANGELOG"
        say ""

        section="$(cat <<EOF
## RSPADE-UPDATE $now from=$OLD_SHA to=$NEW_SHA
Date: $human

Changelog (${OLD_SHA:0:12}..${NEW_SHA:0:12}):
$FRAMEWORK_CHANGELOG

Files changed:
$stat
EOF
)"
    fi

    printf '%s\n\n' "$section" >> "$HISTORY_FILE" 2>/dev/null || true
}

# =============================================================================
# git_retry - run a git command that WRITES THE INDEX, retrying a lock contention.
#
# A live box boots artisan constantly, and any of those boots can be holding
# .git/index.lock for a status refresh at the moment this tries to stage. That is
# contention, not failure, and it lasts milliseconds - so retry rather than abort an
# update over it.
#
# A lock that is NEVER released is a different thing: retrying cannot help, and the
# update must die loudly rather than hang. Three attempts, one second apart, then
# fail with git's own message.
# =============================================================================
# Clear a lock that is DEMONSTRABLY an orphan, and only then.
#
# A zero-byte .git/index.lock held by no process is what a git that died mid-write
# leaves behind: it can never clear itself, and every subsequent index write fails
# forever. Both conditions are required - a non-empty lock means a real write is in
# flight, and without fuser we cannot prove nobody holds it, so we do not touch it.
__clear_orphaned_index_lock() {
    local lock="$PROJECT_ROOT/.git/index.lock"
    [ -f "$lock" ] || return 0
    [ -s "$lock" ] && return 0                    # non-empty: a real write is in flight
    command -v fuser >/dev/null 2>&1 || return 0  # cannot prove it is free -> leave it

    if fuser "$lock" >/dev/null 2>&1; then
        return 0                                  # somebody holds it: not an orphan
    fi

    warn "  .git/index.lock is 0 bytes and held by no process - removing the orphan."
    rm -f "$lock" 2>/dev/null || true
}

# The loud stop when the lock never clears. It names the lock, the ARMED state (the
# revision is on disk and out of history), and the recovery - an operator who reads
# only this block has everything they need.
git_lock_fatal() {
    local what="$1"
    err ""
    err "The git index stayed LOCKED through all 3 attempts while $what."
    err ""
    err "  Another process is holding .git/index.lock, or a process that died mid-write"
    err "  left it behind. This run is stopping here rather than rebuilding on top of a"
    err "  framework revision it could not record."
    err ""
    err "  The new revision IS checked out and is NOT in history. The application will"
    err "  refuse to boot until they agree (bootstrap/rsx_submodule_sync.php), which is"
    err "  the correct behaviour and is how you will notice."
    err ""
    err "  1. Find the holder:   fuser -v $PROJECT_ROOT/.git/index.lock"
    err "                        ps -eo pid,etimes,cmd | grep '[g]it'"
    err "  2. If NOTHING holds it, the lock is stale - remove it:"
    err "                        rm -f $PROJECT_ROOT/.git/index.lock"
    err "  3. Re-run:            php artisan rsx:framework:pull"
    err ""
    die "Framework update aborted: git index locked ($what)."
}

git_retry() {
    local label="$1"; shift
    local attempt rc out
    for (( attempt = 1; attempt <= 3; attempt++ )); do
        __clear_orphaned_index_lock

        out="$("$@" 2>&1)"
        rc=$?
        [ "$rc" -eq 0 ] && return 0

        # A genuine git error does not improve by waiting - surface it immediately with
        # its real exit code. Only the lock signature is treated as transient.
        if ! printf '%s' "$out" | grep -qi 'index\.lock\|Another git process'; then
            printf '%s\n' "$out" >&2
            return "$rc"
        fi

        if [ "$attempt" -lt 3 ]; then
            warn "$label: git index is locked by another process (attempt $attempt/3), retrying in 1s..."
            sleep 1
        else
            printf '%s\n' "$out" >&2
            git_lock_fatal "$label"
        fi
    done
    return 1
}

# =============================================================================
# commit_pointer - record the new submodule revision in the parent repository.
#
# THIS IS THE WHOLE OF WHAT AN UPDATE WRITES to the application's repository: one
# commit changing one gitlink. The body carries the concatenated upstream changelog
# so `git log` in the application explains what arrived and why, without anybody
# having to go and read another repository's history.
# =============================================================================
commit_pointer() {
    if [ "$NO_COMMIT" = true ]; then
        warn "--no-commit: the submodule was updated but the pointer is not committed."
        say "  Commit it yourself:  git add $SUBMODULE_PATH && git commit"
        return 0
    fi

    # The gitlink AND the update log. write_history has just appended this release to
    # rsx/resource/framework_update_history.dat; leaving it unstaged would put a
    # permanent uncommitted change in the developer's tree after every update, for a
    # file the update itself wrote. It belongs in the same commit as the revision it
    # describes.
    local -a to_stage=("$SUBMODULE_PATH")
    [ -f "$HISTORY_FILE" ] && to_stage+=("$HISTORY_FILE")

    git_retry "staging the framework revision" \
        git -C "$PROJECT_ROOT" add -- "${to_stage[@]}" \
        || die "Could not stage the framework revision: the git index is locked and did not clear."

    # Nothing staged means the pointer did not move - an up-to-date run.
    if git -C "$PROJECT_ROOT" diff --cached --quiet -- "$SUBMODULE_PATH" 2>/dev/null; then
        return 0
    fi

    local -a git_id=()
    if ! git -C "$PROJECT_ROOT" config user.email >/dev/null 2>&1; then
        git_id=(-c "user.name=RSpade Framework Update" -c "user.email=rspade@localhost")
    fi

    # SUBJECT SHAPE IS A CONTRACT, asserted by the test suite and read by anyone
    # scanning `git log` for framework updates. The commit COUNT is the useful part:
    # it separates "one release" from "eight releases at once", which is the
    # difference between a routine update and a catch-up worth reading carefully.
    local subject body count
    count="$(git -C "$PROJECT_ROOT/$SUBMODULE_PATH" rev-list --count "${OLD_SHA}..${NEW_SHA}" 2>/dev/null || echo 0)"
    subject="Framework update ${OLD_SHA:0:12} -> ${NEW_SHA:0:12} (${count} upstream commits)"
    body="$(changelog_for_commit_message)"

    # MACHINE-READABLE TRAILERS. The body is prose meant for a person; these two lines
    # are what tooling greps for. The range is the authoritative record of what this
    # commit moved - `git log --grep=Framework-Update-Range` finds every framework
    # update in an application's history without pattern-matching a subject line.
    if ! RSPADE_FRAMEWORK_COMMIT=1 git_retry "committing the framework revision" \
            git -C "$PROJECT_ROOT" "${git_id[@]}" \
            commit --quiet --no-verify -m "$subject

$body

Framework-Update-Range: ${OLD_SHA:-BASELINE}..${NEW_SHA}
Committed-By: rsx:framework:pull"; then
        die "Failed to commit the submodule pointer. It is staged - commit it by hand."
    fi

    ok "Recorded the framework update in this repository's history."
}

# =============================================================================
# enter_maintenance - raise the window before anything is touched.
#
# ONE implementation, in bin/maintenance-mode.sh (the same script `php artisan
# rsx:maintenance:enable` runs): flag + reason, task kill-all, then stop every
# supervised service in dependency order. THAT SCRIPT OWNS THE SERVICE LIST - it is
# deliberately not repeated here, so adding a service is a one-file change and this
# comment cannot go stale. cleanup() lifts it on every exit path, Ctrl-C included.
# =============================================================================
enter_maintenance() {
    [ "$SERVICE_CONTROL" = true ] || return 0

    # AN OPERATOR'S WINDOW IS LEFT EXACTLY AS THEY RAISED IT.
    #
    # If a window is already up for some other reason, the services it stops are
    # already stopped - raising ours over it would overwrite their reason, and
    # lowering ours on the way out would RESTART services the operator deliberately
    # stopped, in the middle of whatever they raised it for. So: do not raise, do not
    # own it, do not lower it. MAINT_ACTIVE stays false and cleanup leaves it be.
    local flag="$STORE_DIR/.maintenance.mode.framework.update"
    if [ -f "$flag" ]; then
        local reason
        reason="$(head -n1 "$flag" 2>/dev/null)"
        if [ "$reason" != "framework update in progress" ]; then
            say "Maintenance mode is already up (\"$reason\") - leaving it exactly as it is."
            return 0
        fi
    fi

    local script="$SYSTEM_DIR/bin/maintenance-mode.sh"
    [ -f "$script" ] || die "Maintenance script missing: $script (the framework tree is broken)."

    bash "$script" enable --reason="framework update in progress" || die "Failed to enter maintenance mode."
    MAINT_ACTIVE=true
    say "Maintenance window raised (web 503; automated task runners refused) until the update completes."
}

# =============================================================================
# run_artisan_lock_retry - a live box boots artisan continuously (cron, supervisor,
# web), and each boot may take the build lock. Losing that race is contention, not
# failure, so the enumerated steps retry instead of aborting the update. A NON-lock
# failure surfaces immediately with its real exit code.
# =============================================================================
run_artisan_lock_retry() {
    local label="$1"; shift
    local -a maint=(); [ "$MAINT_ACTIVE" = true ] && maint=(--_framework-update-override)
    local attempt rc out
    for (( attempt = 1; attempt <= 10; attempt++ )); do
        out="$(php "$SYSTEM_DIR/artisan" "$@" "${maint[@]}" 2>&1)"
        rc=$?
        printf '%s\n' "$out"
        [ "$rc" -eq 0 ] && return 0
        if ! printf '%s' "$out" | grep -qi 'Failed to acquire.*lock'; then
            return "$rc"
        fi
        if [ "$attempt" -lt 10 ]; then
            warn "$label is waiting for a concurrent manifest rebuild to finish (attempt $attempt/10)..."
            sleep 5
        fi
    done
    return "$LOCK_EXHAUSTED_RC"
}

# =============================================================================
# do_rebuild
# =============================================================================
do_rebuild() {
    if [ "$NO_REBUILD" = true ]; then
        warn "--no-rebuild: skipping the framework rebuild."
        say ""
        say "Run these manually to finish the update:"
        say "  php artisan rsx:env:heal"
        say "  php artisan rsx:clean --silent --_no-system-reset"
        say "  php artisan rsx:manifest:build"
        say "  php artisan migrate --framework-only --force"
        say "  php artisan rsx:bundle:compile"
        say "  php artisan rsx:framework:post_update"
        say ""
        return 0
    fi

    say "Rebuilding framework..."
    local rc

    run_artisan_lock_retry "rsx:env:heal" rsx:env:heal
    [ $? -eq 0 ] || warn "rsx:env:heal reported a problem (continuing)."

    # NOT lock-wrapped: a real rsx:clean failure must abort loudly.
    # --_no-system-reset declares cache invalidation ONLY - system/ is a submodule
    # now, and build tooling never touches git state.
    local -a maint_clean=(); [ "$MAINT_ACTIVE" = true ] && maint_clean=(--_framework-update-override)
    RSPADE_FRAMEWORK_UPDATE=1 php "$SYSTEM_DIR/artisan" rsx:clean --silent --_no-system-reset "${maint_clean[@]}" \
        || die "rsx:clean failed"

    # --force: the tree was just replaced wholesale, so nothing about the previous
    # manifest can be trusted to be incrementally valid.
    # --_no-check-schema-updates-pending: main() emits the pending-migration notice
    # once as the pull's final line; without this it would fire twice.
    run_artisan_lock_retry "rsx:manifest:build --force" rsx:manifest:build --force --_no-check-schema-updates-pending
    rc=$?
    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        warn "rsx:manifest:build could not obtain the build lock after 10 attempts; a concurrent rebuild is holding it and thereby keeping the manifest current, so continuing."
    elif [ "$rc" -ne 0 ]; then
        die "rsx:manifest:build failed"
    fi

    run_artisan_lock_retry "framework migrations" migrate --framework-only --force
    rc=$?
    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        say ""
        warn "framework migrations could not obtain the build lock after 10 attempts (a concurrent rebuild held it). This is lock contention, NOT a migration failure. Re-run once the box is quiet to be certain the schema is current: php artisan migrate --framework-only --force"
        say ""
    elif [ "$rc" -ne 0 ]; then
        say ""
        die "Framework migrations FAILED. The update is incomplete - resolve the migration error above and re-run."
    fi

    run_artisan_lock_retry "rsx:bundle:compile" rsx:bundle:compile
    rc=$?
    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        warn "rsx:bundle:compile could not obtain the build lock after 10 attempts; bundles JIT-compile on web request, so continuing."
    elif [ "$rc" -ne 0 ]; then
        die "rsx:bundle:compile failed"
    fi

    # post_update carries the dependency, env and UPSTREAM_CHANGES checks - the
    # pending-documents report is emitted from in there.
    run_artisan_lock_retry "rsx:framework:post_update" rsx:framework:post_update
    [ $? -eq 0 ] || warn "Post-update check failed (non-fatal) - run manually: php artisan rsx:framework:post_update"

    ok "Framework rebuilt."
}

# =============================================================================
# preview - --diff: what an update WOULD bring. Changes nothing.
# =============================================================================
preview() {
    local sm="$PROJECT_ROOT/$SUBMODULE_PATH"
    resolve_upstream
    OLD_SHA="$(git -C "$sm" rev-parse HEAD 2>/dev/null || echo '')"
    say "Fetching $UPSTREAM_URL ($UPSTREAM_BRANCH)..."
    GIT_TERMINAL_PROMPT=0 git -C "$sm" fetch --quiet "$UPSTREAM_URL" "$UPSTREAM_BRANCH" \
        || die "Failed to fetch $UPSTREAM_BRANCH from $UPSTREAM_URL."
    NEW_SHA="$(git -C "$sm" rev-parse FETCH_HEAD)"

    if [ "$OLD_SHA" = "$NEW_SHA" ]; then
        ok "Framework is up to date (${NEW_SHA:0:12})."
        return 0
    fi

    compute_changelog
    say ""
    say "=== ${OLD_SHA:0:12}..${NEW_SHA:0:12} ==="
    say ""
    printf '%s\n' "$FRAMEWORK_CHANGELOG"
    say ""
}

# =============================================================================
# print_summary
# =============================================================================
print_summary() {
    say ""
    say "=== Update complete ==="
    say ""
    if [ -n "$OLD_SHA" ] && [ "$OLD_SHA" != "$NEW_SHA" ]; then
        say "  system/  ${OLD_SHA:0:12} -> ${NEW_SHA:0:12}"
    else
        say "  system/  ${NEW_SHA:0:12}"
    fi
    if [ -n "$FRAMEWORK_CHANGELOG" ]; then
        say "  Full changelog: $HISTORY_FILE"
    fi

    local status
    status="$(git -C "$PROJECT_ROOT" status --porcelain 2>/dev/null | head -n 40)"
    if [ -n "$status" ]; then
        say ""
        say "Working-tree changes (yours - the framework update is already committed):"
        printf '%s\n' "$status" | sed 's/^/  /'
        local total
        total="$(git -C "$PROJECT_ROOT" status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
        [ "$total" -gt 40 ] && say "  ... and $((total - 40)) more"
    fi
    say ""
}

# =============================================================================
# run_post_update - the environment updates, from the FRESHLY-SYNCED copy.
#
# Invoked as a subprocess on purpose: it reads the new bin/post-update.sh and the
# new bin/environment_updates/*.sh, so behaviour shipped in this release takes
# effect on THIS pull rather than the next one.
# =============================================================================
run_post_update() {
    local script="$SYSTEM_DIR/bin/post-update.sh"
    [ -f "$script" ] || return 0
    PROJECT_ROOT="$PROJECT_ROOT" SYSTEM_DIR="$SYSTEM_DIR" IS_FRAMEWORK_DEVELOPER=false \
        bash "$script" || warn "Environment updates reported a failure (non-fatal)."
}

# =============================================================================
# relocate_to_tmp - run from OUTSIDE the tree this script is about to replace.
#
# This file lives at system/bin/framework-pull-upstream.sh, INSIDE the submodule
# whose working tree the update rewrites. Bash reads a script incrementally rather
# than all at once, so `git checkout <new-sha>` landing a different version of this
# very file mid-run is the classic way to get a shell executing half of one program
# and half of another.
#
# The vendored-era updater lived with this by syncing bin/ through rsync, which
# writes a temp file and renames it, leaving the running process's open inode
# intact. git offers no such guarantee. So: copy to /tmp and re-exec before
# anything touches the tree, and carry the resolved project root across the exec
# because the copy can no longer find it from its own path.
# =============================================================================
relocate_to_tmp() {
    [ "${RSPADE_UPDATER_RELOCATED:-0}" = "1" ] && return 0

    local relocated
    relocated="$(mktemp /tmp/rspade-updater-XXXXXX.sh)" \
        || die "Could not create a temporary copy of the updater; refusing to run from inside the tree it replaces."
    if ! cat "$0" > "$relocated"; then
        rm -f "$relocated"
        die "Could not copy the updater to $relocated; refusing to run from inside the tree it replaces."
    fi

    export RSPADE_UPDATER_RELOCATED=1
    export RSPADE_UPDATER_PROJECT_ROOT="$PROJECT_ROOT"
    # exec, not a call: this process BECOMES the copy, so nothing is left reading a
    # file that is about to be rewritten.
    exec bash "$relocated" "$@"
}

# =============================================================================
# main
# =============================================================================
main() {
    # HUP is in the list deliberately. An untrapped SIGHUP terminates a
    # non-interactive bash WITHOUT running the EXIT trap, so a pull whose terminal
    # went away left the maintenance window up and the box 503ing until somebody
    # found the flag file.
    trap cleanup EXIT INT TERM HUP

    # 1 - context and paths
    parse_flags "$@"
    derive_paths

    # Before anything reads or writes the tree: get out of it. Everything above is
    # pure argument handling; everything below can rewrite this file.
    relocate_to_tmp "$@"

    run_gates
    detect_container

    say ""
    say "=== Pull RSpade Framework Updates ==="
    say ""

    # --diff is read-only and must not raise a window or convert anything.
    if [ "$SHOW_DIFF" = true ]; then
        is_submodule || die "system/ is not a submodule yet; there is nothing to diff. Run the update to convert it."
        preview
        exit 0
    fi

    # ADOPT A STALE WINDOW THIS UPDATER'S OWN LINEAGE LEFT BEHIND. cleanup() only
    # lifts a window THIS run raised, which is right for an operator's window - but a
    # flag stranded by a previous updater run (a kill, a crash) would otherwise
    # survive every subsequent run and leave the box 502 until somebody ran
    # rsx:maintenance:disable by hand. Ownership is the flag CONTENT, so only our own
    # reason string is adopted; an operator's window is left as they raised it.
    local stale_flag="$STORE_DIR/.maintenance.mode.framework.update"
    if [ "$SERVICE_CONTROL" = true ] && [ -f "$stale_flag" ] \
        && [ "$(head -n1 "$stale_flag" 2>/dev/null)" = "framework update in progress" ]; then
        MAINT_ACTIVE=true
        say "Adopting a stale maintenance window left by a previous framework update -"
        say "it will be lifted (services restarted) when this run exits."
    fi

    # 2 - raise the window
    enter_maintenance

    # 3 - establish the submodule
    if ! is_submodule; then
        if [ "$IN_CONTAINER" != true ]; then
            refuse_conversion_outside_container
        fi
        convert_to_submodule
    fi

    # 4 - discard local drift under system/, unconditionally
    reset_submodule

    # 5 - work out where this project actually tracks, then fetch it.
    #     resolve_upstream reads the submodule's own configuration and never
    #     writes one. A conversion has just established that configuration, so
    #     this reads back exactly what it set.
    resolve_upstream
    fetch_upstream

    if [ "$OLD_SHA" = "$NEW_SHA" ] && [ "$CONVERTED" != true ]; then
        # The pointer is current, but a rebuild is still worth doing: the marker
        # agrees, and that is a claim about the commit, not about the build state.
        ok "Framework is up to date (${NEW_SHA:0:12})."
        checkout_new
        do_rebuild
        print_summary
        run_post_update
        report_pending
        exit 0
    fi

    compute_changelog
    checkout_new

    # 6 - record it: history file in full, pointer commit capped
    write_history
    commit_pointer

    # 7 - rebuild
    do_rebuild

    print_summary
    run_post_update

    # 8 - the window comes down in cleanup(), on every exit path
    # 9 - what still needs a human
    report_pending

    exit 0
}

# =============================================================================
# report_pending - what the update could not finish for you.
#
# Migrations: advisory, silent when the schema is current, never fails the pull.
# upstream_changes: emitted by rsx:framework:post_update above; repeated here as a
# one-line pointer because the post_update output scrolls away and this is the last
# thing an operator reads.
# =============================================================================
report_pending() {
    local -a maint=(); [ "$MAINT_ACTIVE" = true ] && maint=(--_framework-update-override)

    php "$SYSTEM_DIR/artisan" migrate:status_notice "${maint[@]}" 2>/dev/null || true

    local pending
    pending="$(php "$SYSTEM_DIR/artisan" rsx:framework:upstream_changes "${maint[@]}" 2>/dev/null | grep -cE '^\s+-' || true)"
    if [ "${pending:-0}" -gt 0 ]; then
        say ""
        warn "$pending upstream change document(s) need your attention - these are"
        warn "         manual steps this update could not perform for you."
        say "  List them:   php artisan rsx:framework:upstream_changes"
        say "  Read one:    php artisan rsx:framework:upstream_changes:show <name>"
        say "  Mark it done: php artisan rsx:framework:upstream_changes:mark <name> --fulfilled"
        say ""
    fi
}

main "$@"
