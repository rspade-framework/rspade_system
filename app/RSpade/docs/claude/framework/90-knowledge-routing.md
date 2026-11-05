<!-- single-source: never duplicate into another fragment. This fragment IS the
filing rule for all framework knowledge. Never loaded downstream. -->

## KNOWLEDGE ROUTING (how framework knowledge is recorded)

**Every instruction lives ONCE**, in `system/app/RSpade/docs/`. When asked to "record this to CLAUDE.md" (or you learn something worth keeping), file it by these two rules - never append to the root `CLAUDE.md` (an import shim), and a statement you would write into two files belongs in `shared/`.

**Rule 1 - BUCKET, by where the statement is TRUE** (not who reads it): `claude/shared/` (here AND downstream - most API surface, the large bucket) | `claude/framework/` (only here: publish, internals, box facts, framework tests, authoring obligations) | `claude/app/` (only downstream: `rsx:framework:pull`, `rsx:git`, `system/` read-only; never loaded here - read it when authoring downstream-facing contracts).

**Rule 2 - TIER, by the trigger-moment test.** **CLAUDE.md is RAM, skills are disk (owner ruling, 2026-08-17)**: a skill fires only once the agent knows it is inside a topic, so an always-on fragment (`claude/<bucket>/NN-*.md`) holds only what has NO reliable trigger moment - prohibitions, conduct rules, the subsystem map (summary + its ROADBLOCKS + API names and literal error strings as trigger vocabulary + the skill pointer) and cross-layer invariants. **Never trade trigger vocabulary for size, and a clause guarding a settled ruling stays** (the MANDATE always-on; its incident narrative may live beside it in the skill). Everything with task vocabulary - how-to depth, matrices, rosters, worked examples, gotcha catalogs - is a skill (`skills/{shared,framework,app}/<name>/SKILL.md`; description = gerund summary + "Use when ..." naming concrete symbols and literal error strings; body <500 lines; references one level deep). Contracts are the man page. **A demotion is a MOVE**: verify or append in the destination before removing. "Say only what needs to be said, but say all of it."

**The assembled always-on view targets 80 KB or less** (Claude Code warns past 150k chars across all instruction files); growth past it is demoted to the covering skill, never left to accumulate. Assembly, delivery and the audit procedure: skill `rspade:instruction-file-pruning`.
