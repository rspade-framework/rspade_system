<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade monorepo. -->

## DEVELOPMENT CONTEXT (MONOREPO)

Working with **Brian**, creator and sole maintainer of RSpade, on **framework and starter-template development** - the framework in `/system/app/RSpade/` (core systems, manifest, bundles, auth, tooling), the reference SaaS app (the starter template) in `/rsx/`. 20 years' professional experience; extremely opinionated about implementation.

**Template sign-in posture (owner ruling, 2026-11-03): NO self-signup, staff or portal.** An account exists only by accepting an invitation (staff `/accept-invite`, portal `/register` from a portal invitation; both mark the address verified) or as the initial RSpade account on an empty database (`Rsx_Initial_User::create()`); SSO signs in only an EXISTING linked or verified-email-matched account and declines every unknown identity. `rsx.auth.require_two_factor` and `rsx.portal.emailed_sign_in_codes` stay coded in and switched OFF by a hardcoded boolean in `rsx/resource/config/rsx.php`. Every staff sign-in finishes in `Login_Controller::complete_sign_in()`, every portal one in `Portal_Login_Controller::complete_sign_in()` - **keep it that way** (caller inventory: `rsx/app/login/CLAUDE.md`).

### CRITICAL DIRECTIVE: NO BACKWARDS COMPATIBILITY

Zero external users, no releases to protect, 100% of the code is ours: breaking changes are required for progress. **Backwards compatibility = technical debt with lipstick; dual implementations cause developers PTSD.** Delete the old implementation as soon as its call sites move; ONE pattern per feature; never a fallback or compatibility layer; remove deprecated code before calling a feature complete; never document "legacy", "compatibility" or how things "used to work".

### CRITICAL DIRECTIVE: EVERYTHING YOU WRITE HERE BECOMES PUBLIC

**This monorepo's commit messages and source comments end up in the open-source release.** Every commit is replayed verbatim into the published release commit body (`bin/publish` assembles the changelog from `git log`), and `system/` + `rsx/` ship as public repositories; `docs/claude/framework/` ships too (readable on GitHub). Only `docs.dev/` is stripped.

**NEVER name a client, employer, partner, or any external project** - not in a commit message, code comment, test docstring, man page or config default. Work done with them is confidential and is not ours to disclose. Attribute a field-sourced fix to **"a downstream field report"** or "a live deployment", never to whose deployment it was: the date and the symptom are the useful parts; the name is the part that cannot be taken back once pushed.

**RSpade is presented as independently developed, because that is what the public record must show.** Never document the meta-story of a change: what other codebase an idea was studied in, what was ported from where, what was dropped into this tree to be read alongside, what is gitignored locally and why. A commit says what the framework now does - never what was consulted to get there. Local-only material belongs in `.git/info/exclude`, never in the tracked `.gitignore`, where the entry itself is the disclosure.

**A leak is permanent.** A pushed commit message cannot be unpublished, so the check happens BEFORE the commit, every time. `bin/publish`'s mechanical CONFIDENTIALITY GATE (a blocked-term scan over every staged tree and the release changelog, aborting on one hit) is the LAST line of defense, never a reason to defer the check: it knows one needle, and a leak that reaches the monorepo's history is scrubbed by hand.

### Box facts

- **Safety checks here**: the override prohibition (shared conduct) covers this tree's own gates - `IS_FRAMEWORK_DEVELOPER=true` is the canonical one. Never modify it, never pass a flag around it: STOP, INFORM, ASK, WAIT.
- **NEVER restart PHP/Nginx** - OPcache revalidates every file on every request. **Redis may be restarted whenever useful, without asking** (owner authorization, 2026-10-01: `supervisorctl restart redis`); **rsx-lockd is NOT a cache service** - restarting it drops every held lock and needs the owner's approval.
- **`rsx:clean` here**: beyond an explicit request, the one exception to "never run it" is after modifying `Manifest.php` or the bundle compiler.
- Realtime is ENABLED (`[program:realtime]`, nginx proxies `/ws`); the task scheduler is `rspade-taskd` (supervisor `tasks`; box wiring in `docs.dev/task_scheduler_daemon/THIS_BOX.md`).
- Framework-internal methods are `_underscore_prefix()`; temp files are `name-temp.extension`.
- **Publish-stripped man pages** exist HERE only - `code_quality`, `manifest_api`, `manifest_build`, `ast_sourcecode_parsers`, `vs_code_extension`; never point a shared fragment, skill or shipped page at one.
- Divergences from Laravel are documented in `rsx:man framework_divergences` (its ADDING AN ENTRY section is the contract); delegation research artifacts are persisted into `/docs.dev/`.
