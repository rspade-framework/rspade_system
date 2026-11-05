---
name: instruction-file-pruning
description: "Auditing the always-on CLAUDE.md fragments - what stays always-on, moves to a skill, is redundant or out of date - plus the currency pass over per-directory CLAUDE.md files. Use when asked to prune, trim or audit CLAUDE.md or the instruction files, when Claude Code warns 'over the 150.0k-char total limit', or when asked whether a directory CLAUDE.md is current."
---

# Pruning the instruction files

A defined ritual: a parallel research audit, a summary of proposed changes, the user's approval, then the edits. **Nothing is edited before the user approves the summary.**

---

## The policy being enforced

**Always-on instructions are RAM; skills are disk.** A skill loads only once the agent already knows it is inside the topic, so the always-on files exist to tell the agent what it does not know yet. They hold:

1. **Prohibitions with no trigger moment** - mistakes made incidentally while doing something else, before any skill would load (`never add a timeout`, `never eager load`, `$.ajax() throws`).
2. **Conduct rules** - how the agent works with the user.
3. **The subsystem map** - every subsystem summarized well enough that the agent knows it EXISTS, its basic SHAPE, the ROADBLOCKS it will hit there (prohibitions, throws, build FATALs), and WHICH SKILL to load before working in it.
4. **Cross-layer invariants** - rules that span PHP, JS, SQL and the wire (no field aliasing, ISO strings not Carbon).

Everything else is skill material: how-to depth, signatures, matrices, rosters, worked examples, gotcha catalogs, incident narratives. Contracts and specifications belong in the man pages.

Three rules protect what stays:

- **Trigger vocabulary is the firing mechanism. Never trade it for size.** API names and literal error strings in a fragment are what make the right skill load. A fragment cut to bare prose saves bytes and stops skills from firing.
- **A clause that looks unimportant is often guarding a settled ruling.** It is there so that a later session does not re-litigate a decision ("why don't we just do it the React way"). The MANDATE stays always-on. Its incident narrative may move into the skill, next to the rule it justifies.
- **No knowledge is destroyed; it is MOVED.** Every removed clause is accounted for in a destination: a fragment, a skill, a man page, or a directory CLAUDE.md. Before deleting the source, verify the destination says it (append it if not). The exceptions are content that is wrong (STALE) and content the codebase no longer has (REMOVE), and both need evidence.

## The four tiers

| Tier | Holds | Loads |
|---|---|---|
| Always-on fragment / root CLAUDE.md | The four categories above | Every session |
| Skill (`SKILL.md`) | How a feature works, how its integrations are used, procedures and best practices for writing a consistent, correct implementation | When its description matches the task |
| Man page (`rsx:man`) | The contract: exhaustive, terse, edge cases | On demand |
| **Directory CLAUDE.md**, beside the source | The OVERVIEW and RATIONALE for how that code is designed: what is here, its design philosophy, how it is meant to be used and extended, and references to the major subsystems it touches (skills, man pages, sibling directories) | When the agent works in that directory |

A directory CLAUDE.md explains WHY the code is shaped the way it is. The skill explains HOW to use it. When a directory file starts carrying procedure, move the procedure into the skill and leave a pointer.

## Scope by environment

- **Framework monorepo** (`IS_FRAMEWORK_DEVELOPER=true`): the fragments under `system/app/RSpade/docs/claude/{shared,framework,app}/`, the skills under `system/app/RSpade/docs/skills/`, the man pages, and every directory CLAUDE.md in `system/app/` AND `rsx/`. Filing rules for the buckets are in the always-on knowledge-routing fragment.
- **Downstream application**: the application's own files only. That means `rsx/resource/CLAUDE.md`, every `rsx/**/CLAUDE.md`, and `rsx/resource/skills/`. Everything under `system/` is framework property that the next pull replaces. A finding about a framework fragment, skill or man page is reported upstream (`rsx:man framework_debug_and_contrib`) and never edited locally. Downstream also loads the framework's always-on view, so its byte count is a fixed cost to budget around rather than something to prune.

## Assembly and delivery (monorepo)

`docs/claude/framework.md` (this tree's view) and `docs/claude/app.md` (the downstream view) are IMPORT INDEXES over `shared/` plus their own bucket; they carry no content. The monorepo root `CLAUDE.md` imports `framework.md`; downstream, `rsx/resource/CLAUDE.md` imports `app.md` (the import line is wired by an environment update). Skills load through the `rspade` plugin at `.claude/skills/rspade -> system/app/RSpade/docs/`, whose `.claude-plugin/plugin.json` lists `skills/shared/` + `skills/framework/` here; `bin/publish` rewrites that second entry to `skills/app/` in a release - a one-token manifest flip, because Claude Code does not follow a skills-DIRECTORY symlink, only skill-entry symlinks. Fragments load every session, skills on trigger. **The assembled always-on view targets 80 KB or less** (Claude Code warns past 150k chars across all instruction files); growth past it is demoted to the covering skill by the trigger-moment test, never left to accumulate. The record of the restructure that set this layout up: `docs.dev/knowledge_restructure/`.

## The procedure

### 1. Measure

Report the assembled always-on size (every file the CLAUDE.md `@` import chain reaches) against the target. The monorepo targets 80KB, and Claude Code warns past 150k chars across all instruction files. List each file's size, largest first.

```bash
wc -c system/app/RSpade/docs/claude/shared/*.md system/app/RSpade/docs/claude/framework/*.md | sort -rn
```

### 2. Fan out research - read-only background agents, in PARALLEL

Launch one agent per domain group, all in a single message so they run concurrently. Group the fragments into 4-8 batches of related subjects. Add one agent for the directory CLAUDE.md currency pass (step 3), and in the monorepo one for skill-description quality if skills are in scope. **Research agents edit nothing.** Every brief carries:

- The policy section above, verbatim.
- The files in its batch, and the skill directory to check destinations against.
- **Source verification**: every claim about a method, flag, config key, command or behaviour is checked against the code (grep, read), never against memory or another doc. If a claim cannot be verified, the agent says so.
- The classification below and the report shape below.

**Per-clause classification:**

| Verdict | Meaning | Required evidence |
|---|---|---|
| KEEP | Fits one of the four categories | which category |
| CONDENSE | Belongs here but can be said in fewer words with nothing lost | the proposed wording |
| MOVE -> skill `<name>` (existing or NEW) | Has a trigger moment; the skill would fire first | the destination, and whether it already says this |
| MOVE -> man / directory CLAUDE.md | Contract detail, or design rationale for one directory | the destination |
| REDUNDANT | Already stated always-on elsewhere, or inside another clause | where |
| SUPERFLUOUS | Restates the obvious, or something no agent would get wrong | why |
| STALE | The code says otherwise | file:line of the truth |
| REMOVE | The feature or symbol no longer exists | the search that proved it |

**Agent report shape:** one table per file (clause excerpt | verdict | evidence | destination), then the byte saving per file, then a list of proposed NEW skills (name + a draft description), then open questions. A trigger-vocabulary term that a MOVE would strip from always-on is flagged in its row.

### 3. The directory CLAUDE.md currency pass

A directory CLAUDE.md is a claim about its directory. It may be out of date when the code beside it changed AFTER the file last did. For each file, list the commits that touched its directory since the file's own last commit:

```bash
git ls-files '*CLAUDE.md' | grep -v -e node_modules -e vendor | while read -r f; do
  d=$(dirname "$f"); last=$(git log -1 --format=%H -- "$f")
  n=$(git rev-list --count "$last"..HEAD -- "$d" ":(exclude)$f")
  [ "$n" -gt 0 ] && printf '%4d  %s  (doc %s)\n' "$n" "$f" "$(git log -1 --format=%cs -- "$f")"
done | sort -rn
```

(Downstream, restrict the list to `rsx/`.) Also include uncommitted changes (`git status --short -- <dir>`). For each file with drift, read the intervening changes (`git log --stat "$last"..HEAD -- "$d"`, then the diffs that matter) and decide:

- **Cosmetic** (a bug fix, formatting, an internal refactor that changes nothing a reader would observe): CURRENT, no edit.
- **Substantive** (a new or renamed entry, a changed seam, a new integration with another subsystem, an abandoned design): re-audit how the subsystem works now. Then propose the updated overview, rationale and references.

Also report **missing** directory files: a key subsystem (a framework `Core/<Feature>/`, an application module, a component group, a library) with no CLAUDE.md, whose design an agent would otherwise have to rebuild from the code. A leaf directory of a documented feature is not a gap. Downstream, conformance audit `living_documentation_currency` is the per-file checklist for this pass. Run it as part of this step.

### 4. Synthesize and present - then STOP

Merge the agents' reports, re-check any surprising STALE or REMOVE verdict yourself, and present ONE summary to the user:

1. **Size**: now, projected, and the target.
2. **Moves**: grouped by destination skill. Existing skills updated, NEW skills proposed (name + description). Bytes freed per fragment.
3. **Stays always-on**: anything a reader might expect to move but that is kept, with the category that keeps it.
4. **Redundant / superfluous**: proposed deletions, each with where it is already said or why it adds nothing.
5. **Out of date**: STALE (revise, with the truth) and REMOVE (delete, with the proof). These are correctness fixes and outrank size work.
6. **Directory CLAUDE.md files**: drifted files and the proposed updates; missing files proposed for creation.
7. **Decisions for the user**: anything where keeping or moving is a judgment call. A ruling that looks dispensable but may be guarding a decision always lands here, and is never silently moved out of always-on.

**Wait for approval.** The user may approve all of it, approve parts, or overrule individual items. An overrule is final.

### 5. Apply the approved changes

Edits are made by ONE writer at a time (yourself, or sequential agents), never by parallel writers. For every MOVE: write or verify the destination first, then remove the source. Update a skill's `description` whenever its new content brings new trigger vocabulary. Re-measure afterwards and report the final sizes. No commit unless the user asks for one.
