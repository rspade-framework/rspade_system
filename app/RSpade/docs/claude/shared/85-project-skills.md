<!-- BUCKET shared/: true in this monorepo AND in a downstream app. The wiring is
identical in both - the template app carries its own skills exactly as a downstream
app does. Full treatment: rsx:man template_app. -->

## PROJECT SKILLS

An application authors its own Claude Code skills at **`rsx/resource/skills/<name>/SKILL.md`** — the canonical location, and the only one wired automatically. Frontmatter matches a framework skill's: `name`, plus a `description` that is a gerund summary + "Use when …" naming concrete symbols and literal error strings (the description IS the trigger).

The framework links each one in as **`.claude/skills/<name> -> ../../rsx/resource/skills/<name>`** — **RELATIVE, never absolute**, so the link is correct in every clone, container and deploy. **You never create the link**: `system/bin/environment_updates/070_app_skills.sh` regenerates it on every environment-update run — framework pull, `rsx:git pull`, every development rebuild, container start — so **a skill you COMMIT reaches every collaborator with nothing to wire**. Committing the `.claude/skills/<name>` link itself is optional; it regenerates either way. A directory with no `SKILL.md` is ignored (it is not a skill).

**A dangling link the framework made is pruned; a link it did not make is never touched** — a foreign symlink or a real directory sitting on a skill's name is reported and left in place, and that skill stays unlinked until you move it aside. **`rspade` is reserved**: framework skills arrive as the `rspade:*` PLUGIN link, so an app skill by that name is refused.

The directory's own **`rsx/resource/skills/CLAUDE.md`** carries the SKILL.md shape and the skill-vs-CLAUDE.md-vs-man-page routing test. `rsx:health` carries a **"Claude Skills"** row (WARN per unlinked skill, dangling link, or blocked name); `php artisan rsx:heal claude-skills` re-runs the wiring.
