<!-- BUCKET shared/: true in this monorepo AND downstream - the template app carries its own skills exactly as a downstream app does. Full treatment: rsx:man template_app. -->

## PROJECT SKILLS

An application's own Claude Code skills live at **`rsx/resource/skills/<name>/SKILL.md`** - never authored directly in `.claude/skills/`. The framework links each in as a RELATIVE symlink (`.claude/skills/<name>`) on every environment update, so **committing the skill is the whole distribution: never create the link** (re-run the wiring: `php artisan rsx:heal claude-skills`; a foreign link or directory on a skill's name is reported and left alone). Frontmatter `name` + a `description` that is a gerund summary + "Use when ..." naming concrete symbols and literal error strings (the description IS the trigger); `rspade` is a reserved name. Shape and the skill-vs-CLAUDE.md-vs-man-page test: `rsx/resource/skills/CLAUDE.md`; `rsx:man template_app`.
