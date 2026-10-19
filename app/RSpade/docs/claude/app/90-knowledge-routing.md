<!-- single-source: never duplicate into another fragment. Downstream-only. -->

## YOUR KNOWLEDGE vs FRAMEWORK KNOWLEDGE

The framework's instructions (everything imported alongside this file) live under `system/app/RSpade/docs/` — **framework-owned, replaced by `rsx:framework:pull`, never edit them**. Your application's knowledge has its own homes:

- **Always-on app instructions** → `rsx/resource/CLAUDE.md` (the file importing this view). Keep it terse; it loads every session alongside the framework view.
- **App skills** → `rsx/resource/skills/<name>/SKILL.md`, committed with the app. The framework links each one into `.claude/skills/<name>` itself (a relative symlink, regenerated on every environment update) - never write into `.claude/skills/` by hand. Use them for app procedures with a triggering moment; the framework's `rspade:*` skills are namespaced and cannot collide with yours (an app skill named `rspade` is refused).
- **App reference docs** → `rsx/resource/man/*.txt` (`rsx:man` serves them; format in that directory's CLAUDE.md). Create one when an app feature has non-obvious details.
- **App conformance audits** → `rsx/resource/conformance/<id>.md` (contracts your code must honour that no rule checks; `rsx:man conformance_audits`).
- Maintain the `#[Auth_Check]` vocabulary list in your `CLAUDE.md` (see the app-context fragment's mandate).
- **A defect in `system/` itself** → `rsx:man framework_debug_and_contrib` (the contributor role, the patch + change-request workflow).

`php artisan rsx:man <topic>` is the framework's contract tier; `rsx:man` with no argument lists topics. When a framework instruction seems wrong, it may have been superseded — check `rsx:framework:status` and the update history before working around it, and report it upstream rather than editing `system/`.
