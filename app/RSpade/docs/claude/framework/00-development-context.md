<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade monorepo. -->

## DEVELOPMENT CONTEXT (MONOREPO)

Working with **Brian**, creator and sole maintainer of RSpade. This is **framework and starter template development**. The user has 20 years of professional experience and is extremely opinionated about implementation.

**Current focus**: framework core stable; building the reference SaaS application (`/rsx/`) as the starter template.

**Your role**:
- **Framework** (`/system/app/RSpade/`): core systems, manifest, bundles, auth, developer tooling.
- **Reference app** (`/rsx/`): working SaaS with auth, user management, multi-tenant, professional UI.

### CRITICAL DIRECTIVE: NO BACKWARDS COMPATIBILITY

Zero external users. No releases. We control 100% of the codebase. Breaking changes are required for progress.

**BACKWARDS COMPATIBILITY = TECHNICAL DEBT WITH LIPSTICK**

**DUAL IMPLEMENTATIONS CAUSE DEVELOPERS PTSD** - two systems doing the same thing creates debugging nightmares.

**Rules:**
- Delete old implementations immediately after updating call sites
- ONE pattern per feature when complete
- Never create fallback/compatibility layers
- Remove deprecated code before marking a feature complete
- Never document "legacy", "compatibility", or how things "used to work"

### CRITICAL DIRECTIVE: EVERYTHING YOU WRITE HERE BECOMES PUBLIC

**This monorepo's commit messages and source comments end up in the open-source release.** Every commit here is replayed verbatim into the published release commit body (`bin/publish` assembles the changelog from `git log`), and `system/` + `rsx/` ship as public repositories. `docs/claude/framework/` ships too - it is not loaded downstream, but it IS readable on GitHub. Only `docs.dev/` is stripped.

**NEVER name a client, employer, partner, or any external project** - not in a commit message, not in a code comment, not in a test docstring, not in a man page, not in a config default. Work done with them is confidential and is not ours to disclose. Attribute a field-sourced fix to **"a downstream field report"** or "a live deployment", never to whose deployment it was. The date and the symptom are the useful parts; the name is the part that cannot be taken back once pushed.

**RSpade is presented as independently developed, because that is what the public record must show.** Never document the meta-story of a change: what other codebase an idea was studied in, what was ported from where, what was dropped into this tree to be read alongside, what is gitignored locally and why. A commit says what the framework now does - never what was consulted to get there. Local-only material belongs in `.git/info/exclude`, never in the tracked `.gitignore`, where the entry itself is the disclosure.

**A leak is permanent.** A pushed commit message cannot be unpublished, so the check happens BEFORE the commit, every time.

### Safety checks here

The safety-check override prohibition (shared conduct fragment) applies to this tree's own gates - `IS_FRAMEWORK_DEVELOPER=true` is the canonical example. Never modify it, never pass a flag around it: STOP, INFORM, ASK, WAIT.

### Monorepo naming rows

| Context | Convention | Examples |
|---------|------------|----------|
| Framework classes | `PascalCase` or `Like_This_*` | `ManifestKernel`, `Rsx_Controller_Abstract` |
| Framework internal | `_underscore_prefix()` | `_internal_method()` |
| Temp files | `name-temp.extension` | `test-temp.php` |

### Box facts

**NEVER restart PHP/Nginx** - OPcache is disabled on this box.

### Framework-only references

- `rsx:man code_quality` (rule-catalog architecture), `rsx:man manifest_api`, `rsx:man manifest_build`, `rsx:man ast_sourcecode_parsers`, `rsx:man vs_code_extension` exist HERE only - publish strips them from releases; never point a shared fragment or skill at them.

### Domain notes

- **Realtime is ENABLED in this dev env**: the relay runs under supervisor (`[program:realtime]`) and nginx proxies `/ws`. The template app ships **no `#[Emitter]` consumer** (verified 2026-08-17) - emitters are exercised only by framework tests.
- **Monorepo cron line carries the path**: `* * * * * cd /var/www/html && php artisan rsx:task:process` (the shipped/downstream form is pathless).
- **Backlog pointers**: **B-85** (a task should DECLARE whether it wants the site lock) and **B-87** (automatic per-tenant write locks on `save()` DISABLED since 2026-08-11, re-enable pending; the `Rsx_Artisan` mandate stands regardless).
- A framework test whose SUBJECT is the artisan entrypoint keeps its raw spawn under a rationale'd `@ARTISAN-SPAWN-01-EXCEPTION`.
- Colocated deep docs: `Core/Task/CLAUDE.md`, `Core/Realtime/CLAUDE.md`, `Commands/CLAUDE.md` (why `rsx:check` refuses that directory, pre-boot interception and its stubs - `rsx:man artisan_commands`).

### Documentation obligations

- **Document divergences** from Laravel in `man/framework_divergences.txt` (`rsx:man framework_divergences`) — its ADDING AN ENTRY section is the contract.
- **Delegation research artifacts** are persisted into `/docs.dev/` before implementation begins.
