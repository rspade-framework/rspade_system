#!/usr/bin/env bash
#
# 070 - Wire the APPLICATION's own Claude Code skills into this environment.
#
# An app authors skills at rsx/resource/skills/<name>/SKILL.md. Claude Code discovers
# skills under .claude/skills/, and it DOES follow a skill-ENTRY symlink (only a skills
# DIRECTORY symlink is unsupported), so one relative link per skill is the whole wiring:
#
#     .claude/skills/<name> -> ../../rsx/resource/skills/<name>
#
# RELATIVE, NEVER ABSOLUTE. The link is committable, and an absolute target names one
# developer's checkout path; the relative spelling is correct in every clone, container
# and deploy.
#
# WHY AN ENVIRONMENT UPDATE AND NOT A COMMITTED LINK. Committing the links is allowed and
# harmless, but it cannot be the mechanism: git distributes the SKILL, and rsx/resource/ is
# manifest-ignored, so a teammate's new skill arrives with nothing to wire it. Regenerating
# on every environment-update run means a skill reaches every collaborator by being
# committed, and nothing else.
#
# FRAMEWORK SKILLS ARE NOT THESE. They arrive as the `rspade` PLUGIN link that
# 060_claude_docs.sh owns (.claude/skills/rspade -> ../../system/app/RSpade/docs). The name
# `rspade` is therefore RESERVED here and an app skill by that name is reported and skipped.
#
# BOTH CONTEXTS. The monorepo runs this identically - the template app may carry its own
# skills, and the downstream wiring stays developable here.
#
# PRUNING, AND ITS LIMIT. A link we made that no longer resolves is removed (the skill was
# deleted or renamed). "We made it" is decided on the LITERAL target text - it must start
# with ../../rsx/resource/skills/ or ../../system/app/RSpade/ - and only a DANGLING one is
# touched. Anything else under .claude/skills/ (a foreign symlink, a real directory, a
# healthy link) is left strictly alone: a link we did not make is not ours to judge.
#
# AUGMENT-NEVER-CLOBBER (contract rule 7): a real file/dir or a foreign symlink sitting on
# a skill's name is reported on stderr and left in place - never moved, never overwritten.
# And the .claude/skills PARENT is never removed and recreated: recreating a watched
# top-level directory mid-session breaks Claude Code's file watcher.
#
# QUIET: RSPADE_ENV_UPDATE_QUIET=true suppresses the informational lines (created,
# retargeted, pruned). Problems still print to stderr - a quiet run is a quiet SUCCESS,
# never a silent failure. post-update.sh --quiet sets it (the rsx:git post-pull hook).
#
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container - these scripts modify the environment AROUND the project, and that
# environment is the container's, not the host's. Absent marker, absent consent:
# exit 0 and say nothing (a normal state, not a failure).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"
QUIET="${RSPADE_ENV_UPDATE_QUIET:-false}"

errored=false

skills_src="$PROJECT_ROOT/rsx/resource/skills"
skills_dir="$PROJECT_ROOT/.claude/skills"
target_prefix='../../rsx/resource/skills/'

# The plugin name 060 owns. An app skill may not take it.
RESERVED_NAME='rspade'

# Informational output. Gated on quiet; problems never are.
info() { [ "$QUIET" = true ] || echo "[env] $*"; }

# ---------------------------------------------------------------------------
# 1. REGISTER. One link per rsx/resource/skills/<name>/SKILL.md.
# ---------------------------------------------------------------------------
if [ -d "$skills_src" ]; then
    for skill_path in "$skills_src"/*; do
        [ -d "$skill_path" ] || continue
        [ -f "$skill_path/SKILL.md" ] || continue          # a directory without one is not a skill

        name="$(basename "$skill_path")"
        link="$skills_dir/$name"
        link_target="$target_prefix$name"

        if [ "$name" = "$RESERVED_NAME" ]; then
            echo "[env] app skills: 'rsx/resource/skills/$RESERVED_NAME' uses a reserved name (the framework plugin namespace); not linked." >&2
            echo "[env]   Rename it - framework skills are the ${RESERVED_NAME}:* plugin." >&2
            continue
        fi

        if [ -L "$link" ]; then
            current="$(readlink "$link" 2>/dev/null || true)"

            if [ "$current" = "$link_target" ] && [ -e "$link" ]; then
                continue                                   # correct already
            fi

            if [ ! -e "$link" ]; then
                # Dead link: nothing is at stake, retarget it.
                if ln -sfn "$link_target" "$link" 2>/dev/null; then
                    info "Retargeted the dead .claude/skills/$name symlink -> $link_target (application skill)."
                else
                    echo "[env] app skills: cannot repair $link" >&2
                    errored=true
                fi
            elif [ "$current" = "$link_target" ]; then
                continue                                   # correct, and resolves
            else
                echo "[env] .claude/skills/$name points elsewhere ($current); left untouched (application skill not linked)." >&2
                echo "[env]   Move it aside if you want the '$name' application skill, then re-run this update." >&2
            fi
        elif [ -e "$link" ]; then
            echo "[env] .claude/skills/$name exists and is not a symlink; left untouched (application skill not linked)." >&2
            echo "[env]   Move it aside if you want the '$name' application skill, then re-run this update." >&2
        else
            if mkdir -p "$skills_dir" 2>/dev/null && ln -s "$link_target" "$link" 2>/dev/null; then
                info "Linked .claude/skills/$name -> $link_target (application skill)."
            else
                echo "[env] app skills: cannot create $link" >&2
                errored=true
            fi
        fi
    done
fi

# ---------------------------------------------------------------------------
# 2. PRUNE. A link WE made that no longer resolves. Literal target, never a
#    resolved one - a resolved path says nothing about who wrote the link.
# ---------------------------------------------------------------------------
if [ -d "$skills_dir" ]; then
    for link in "$skills_dir"/*; do
        [ -L "$link" ] || continue
        [ -e "$link" ] && continue                         # healthy: not our business

        current="$(readlink "$link" 2>/dev/null || true)"
        ours=false
        case "$current" in
            "$target_prefix"*|'../../system/app/RSpade/'*) ours=true ;;
        esac
        [ "$ours" = true ] || continue                     # a foreign dead link is theirs to keep

        name="$(basename "$link")"
        if rm -f "$link" 2>/dev/null; then
            info "Pruned the dangling .claude/skills/$name symlink (its skill is gone)."
        else
            echo "[env] app skills: cannot prune $link" >&2
            errored=true
        fi
    done
fi

[ "$errored" = true ] && exit 1
exit 0
