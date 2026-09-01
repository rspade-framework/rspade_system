<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade
monorepo. CANONICAL HOME of the documentation-authoring obligations. -->

## DOCUMENTATION OBLIGATIONS

**Man pages are the CONTRACT tier** — the full treatment of a feature lives there, not in always-on knowledge. Framework man pages: `system/app/RSpade/man/*.txt`; project man pages: `rsx/resource/man/*.txt`. Terse, complete, expert-level.

### THE DOCUMENTATION IS PART OF THE CHANGE

**The man pages describe what the framework ACTUALLY DOES.** Documentation that describes a previous version of the behavior is worse than no documentation, because it is trusted.

**Documentation moves when the CONTRACT or OBSERVABLE BEHAVIOR moves — not when code moves.** A refactor, an internal implementation swap, or a bug fix that finally makes the code match what the page always said needs NO documentation edit: the documentation was already right. The test is *"did what a developer can observe, invoke or trip over change?"* — if no, you are done.

**When it did move, update the affected tiers as part of the same work, WITHOUT ASKING** — the man page(s), the skill(s), and the always-on fragment when the trigger-moment test (`90-knowledge-routing.md`) puts it there. A change that leaves its documentation describing the old behavior is not finished, and removing a feature includes removing every passage that documents it.

**The one thing that still comes to the owner: CHANGING a promise, as opposed to RECORDING one.** Writing down what the framework now does is yours to do. Rewriting what a man page COMMITS the framework to — narrowing a guarantee, inventing a contract nothing implements, ruling that a documented behavior was wrong — is a design decision wearing documentation clothing. Propose it; never land it.

**WHO the tier serves decides what belongs in it.**
- **Internal mechanics** — how the framework achieves something, where nothing surfaces at a developer-facing seam: a monorepo-scoped skill (`skills/framework/`), a publish-stripped man page, or a colocated `Core/*/CLAUDE.md`.
- **Everything a developer can observe, invoke or trip over** — conventions, features, signatures, and framework behaviors as far as knowing them is necessary to be effective: a shipped man page plus `skills/shared/`.

**A downstream developer treats the framework as a black box, and is entitled to.** Document internals for them ONLY where those internals leak into how they write code. Nothing earns a place in a tier by being interesting.

**CLAUDE fragments stay expensive.** They load on EVERY task in EVERY session. A fragment earns its place by the trigger-moment test alone, never because a topic feels central — if a skill would fire at the right moment, it belongs in the skill. Terse, useful, discriminating.

**The prelaunch checklist** (`rsx:man prelaunch_checklist`) is the designated home for launch-gating audits that are impractical to enforce with a lint rule. It is a **sanctioned enforcement channel**, and **the RSpade LLM may proactively suggest "add this to the prelaunch checklist"** to the owner.

### MANDATE: shipping a downstream-facing contract change

The rule above governs EVERY change. This mandate is the heavier checklist that applies ADDITIONALLY when downstream code must now be written or invoked differently.

**Any framework change that alters the CONTRACT or SYNTAX of how downstream code is written or invoked is not done until all of these are done** — not one of them, all that apply. A change that is only implemented is a change nobody downstream can use correctly.

1. **This monorepo's always-on knowledge** (`docs/claude/framework/`, or `docs/claude/shared/` when true in both environments) — how to USE the feature. **Terse.**
2. **The shared fragment(s)** — **write it ONCE**; the downstream view assembles the same file.
3. **The reference app (`/rsx/`)** — make it demonstrate the new way. **This item ships**: `bin/publish` vendors `/rsx/` into every release at `system/app/RSpade/resource/reference_app/`, where a downstream developer reads it as pristine, release-current ground truth. A feature the reference app does not demonstrate is a silent lie in a file that looks canonical. Never mark a contract change done with this item outstanding.
4. **The relevant man page(s)** — the full treatment.
5. **`upstream_changes/*.txt`** — the migration, **ONLY when the gate is met**: no mandatory `IF YOU DO NOTHING: <one concrete breakage>` line, no document.
6. **`man/prelaunch_checklist.txt`** — ONLY when the change does not self-correct through a thrown exception or failed build (a silent behavior change), and so needs a human eye.

**Cite the reference app by path.** `system/app/RSpade/resource/reference_app/...` resolves in BOTH environments (here a symlink to `/rsx/`, downstream real files), so a man page, skill or `upstream_changes` document may point at a concrete file and be correct everywhere. RSpade's wiring is implicit, and a worked example settles what prose cannot.

**Keep the audiences separate.** Always-on knowledge describes how to write code with the feature TODAY — audit sweeps, "find your existing call sites" and conversion steps belong in `upstream_changes/`; recurring pre-launch verification belongs in the prelaunch checklist. Mixing migration prose into always-on knowledge bloats what every reader loads on every task.

**An `upstream_changes` document exists ONLY when the end user must MANUALLY act or something concrete BREAKS** — proved by its mandatory `IF YOU DO NOTHING:` line, which `bin/publish` enforces. Not a changelog: self-applying behavior, a lint rule, a loud-failing removal and an optional adoption each need no document. A recurring FRAMEWORK-INTERNAL audit goes instead to `docs.dev/audits/framework_internal_audit_checklist.md`.

### External requests

`docs.dev/external_requests/` holds pre-authorized requests from external environments that Brian controls. **MANDATE**: the directory is tracked by a self-maintained `INDEX.md`, one row per request, updated whenever status changes, and **a FULLY-processed request is ALSO MOVED into `archive/`** — so a file still at the top level is OPEN. Row shape and dispositions: skill `rspade:shipping-a-contract-change`.

**Hierarchy**: development (`docs.dev/`) -> always-on knowledge (`docs/claude/{shared,framework,app}/`) + contract tier (`man/*.txt`, `Core/*/CLAUDE.md`) -> upstream changes. **Editing always-on knowledge**: *"Say only what needs to be said, but say all of it."*

Skill `rspade:shipping-a-contract-change` (working the mandate, audit routing, authoring an upstream_changes doc or checklist entry). Details: `rsx:man upstream_changes`.
