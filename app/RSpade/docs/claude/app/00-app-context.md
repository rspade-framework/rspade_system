<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. -->

## FRAMEWORK STATUS AND REPORTING ISSUES

**RSpade is at RELEASE CANDIDATE.** It is ready for production use, but expect some
glitches and some changes going forward. Developers are encouraged to report anything
that misbehaves, confuses, or blocks legitimate work - unexpected command behavior,
confusing error messages, a workflow that fights you, an assumption that conflicts
with real-world usage. Three channels, all equivalent:

- email **brian@hanson.xyz**
- **https://rspade.org**
- the issue tracker at **https://github.com/rspade-framework/rspade**

## APPLICATION CONTEXT

- **Delegation research artifacts** (audits, investigation reports) are persisted verbatim into your own project's documentation tree - e.g. `rsx/resource/` - before implementation begins.
- **The starter `README.md`'s quick-start clone line is rewritten to your project's own `origin` on first container start** - only while the file is still byte-identical to the pristine copy shipped at `system/app/RSpade/resource/starter/README.md`, so any edit of your own ends the personalization for good (`rsx:man template_app`).

### The reference app — ground truth when your code has diverged

Your `/rsx/` was **seeded from** the RSpade reference application and is yours from your first commit; it is not the reference any more. The pristine, **release-current** copy ships inside every framework release at **`system/app/RSpade/resource/reference_app/`**.

**Read it whenever "follow an existing pattern" needs a pattern you cannot trust your own code for** — a feature the framework shipped after you diverged, or a wiring you suspect your app does the old way. RSpade wiring is largely implicit (no registration, attributes discovered by the manifest, forms binding by name), so a working example settles questions prose cannot, and this copy always matches the framework revision you are running. Your own code does not.

**It is READ-ONLY.** All of `system/` is overwritten by `rsx:framework:pull`; edits there vanish. Copy OUT of it into your `/rsx/`, never work inside it. It is invisible to the manifest and to `rsx:check` (any directory named `resource/` is ignored **by name** — which is what stops its classes colliding with yours). Details: `rsx:man template_app`.

### Project documentation

Write your own man pages as `rsx/resource/man/*.txt` - they are served by the same `php artisan rsx:man <topic>` as the framework's, and that directory's `CLAUDE.md` carries the format. Keep app-specific conventions there rather than growing this always-on file.

### Testing in your app

`rsx:test` runs YOUR application suite (the tests under `/rsx/`). Framework tests are **not shipped** with a release, so `rsx:test --framework` has nothing to run here - it is a monorepo-only command.

### Auth vocabulary

**MANDATE — keep the check list in YOUR CLAUDE.md.** Maintain a terse running list of every `#[Auth_Check]` your `Permission` and `Portal_Permission` define — name plus one line on what it checks — in the application's own `CLAUDE.md`, and UPDATE IT whenever a gate is added, renamed or removed. It is what lets an agent pick the right existing check instead of minting a near-duplicate, and it is the first thing to read before annotating a new surface.

Session, login-history and Turnstile settings are yours to tune: the `rsx.sessions.*` windows (web/anonymous timeouts, `max_web_sessions_per_user`, `login_history_retention_days`, `login_failure_window_minutes`), `rsx.portal.*` and `rsx.turnstile.*` are all overridable in `rsx/resource/config/rsx.php`.

To change the behavior of a core auth model (`User_Model`, `Portal_User_Model`, `Login_User_Model`), use the **class-override** pattern — a same-named class in `rsx/models/`. Keep app concepts (CRM links, memberships) in **separate** app models, never bolted onto the overridden core model.
