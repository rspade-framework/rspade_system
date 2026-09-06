---
name: shipping-a-contract-change
description: "Finishing a framework change that alters how downstream code is written or invoked - working the six-artifact mandate (always-on knowledge, shared fragment, template app, man page, breaking_changes, prelaunch checklist), deciding which audit artifact a new obligation belongs in, authoring a breaking_changes document against the manual-action gate, writing a prelaunch checklist entry, and the external-requests INDEX/archive workflow. Use whenever a framework change touches a downstream-facing contract, syntax, default, or behavior."
---

# Shipping a downstream-facing contract change

**A change that is only implemented is a change nobody downstream can use correctly.** The mandate: any framework change that alters the CONTRACT or SYNTAX of how downstream code is written or invoked **is not done until all of these are done** - not one of them, all that apply.

Work the list in order; each step tells you where the knowledge belongs and how terse it must be.

---

## 1. Always-on knowledge (monorepo view)

`system/app/RSpade/docs/claude/framework/*.md` if the fact is true ONLY here (publishing, box facts, monorepo git rules). **Terse.** This is how to USE/WRITE the feature today - never a migration guide.

## 2. The shared fragment — write it ONCE

If the fact is true in both environments, it goes in `system/app/RSpade/docs/claude/shared/*.md` and **both** views assemble it. **There is no second copy to keep in sync** - the old "also update `docs.dist/CLAUDE.dist.md`" step is gone, and re-introducing a parallel downstream copy of the same paragraph is the failure mode this structure exists to end.

Rules of thumb:
- Downstream-only truth (`system/` is read-only, `rsx:git`, the pull) → `docs/claude/app/`.
- One canonical home per topic. If your paragraph belongs next to an existing one, EDIT that fragment; do not add a second.
- The fragment header comment names its bucket and its single-source rule - keep it accurate.
- Deep how-to does not belong in a fragment. It belongs in a skill (`docs/skills/{shared,app,framework}/<name>/SKILL.md`) or a man page.

## 3. The template app (`/rsx/`)

**Make it demonstrate the new way.** The template is the worked example downstream reads; a template still doing it the old way teaches the old way. Grep `/rsx/` for the old spelling and convert every instance, including the reference forms and the CRUD module the docs point at.

## 4. The man page(s)

`system/app/RSpade/man/*.txt` - **the full treatment lives here**, not in always-on knowledge. Terse, complete, expert-level.

**Update it as part of the change, without asking.** A man page describes what the framework ACTUALLY DOES, so a behavior that moved and left its page behind is an unfinished change - and a page describing the old behavior is worse than no page, because it is trusted. The one thing that still goes to the owner is CHANGING a promise rather than RECORDING one: narrowing a guarantee, inventing a contract nothing implements, or ruling that a documented behavior was wrong. See the always-on fragment `82-documentation-obligations.md`.

Note the framework-developer-only pages are stripped at publish (`code_quality`, `manifest_api`, `manifest_build`, `ast_sourcecode_parsers`, `vs_code_extension`) - never point shipped knowledge at one.

## 5. `breaking_changes/*.txt`

The migration: how a downstream developer FINDS and CONVERTS existing code. **Only when the gate below is met** - a document with no `IF YOU DO NOTHING:` line does not exist.

## 6. `man/prelaunch_checklist.txt`

**ONLY if the change does not self-correct** through a thrown exception or failed build, and so needs a human eye to confirm it was applied correctly.

**When #6 applies:** a signature change that fatals, a manifest-build validation, a lint rule - all self-correct; no entry. A silent behavior change (an ordering that quietly stopped being applied, a new declaration nobody is forced to add, a default that changed) does NOT self-correct - entry required.

**Keep the audiences separate.** Always-on knowledge describes how to write code with the feature TODAY. Audit sweeps, "find your existing call sites", grep recipes and conversion steps belong in `breaking_changes/`; recurring pre-launch verification belongs in the prelaunch checklist. Mixing migration prose into always-on knowledge bloats what every reader loads on every task.

---

## Which audit artifact? A decision tree

```
Is the obligation RECURRING (must be re-checked by every app, forever)?
├─ yes, by DOWNSTREAM apps        -> an ENTRY in man/prelaunch_checklist.txt
│                                    (distributes via framework pull; NO breaking_changes doc)
├─ yes, by RSpade CORE on itself  -> a row in docs.dev/audits/framework_internal_audit_checklist.md
│                                    (a living discipline, not a gate)
└─ no - it is ONE-TIME, triggered by this change
    └─ must a downstream developer CHANGE CODE THEY WROTE? (the three tests below)
        ├─ yes -> a breaking_changes document, carrying its IF YOU DO NOTHING line
        └─ no  -> NOTHING. Do not write a document.
```

A downstream app also keeps its OWN pre-launch items in `rsx/resource/audits/prelaunch_checklist.md`; framework-required audits never go there.

---

## Authoring a breaking_changes document

**THE GATE: would an untouched fork have to edit its own code?**

A document exists ONLY when a downstream developer must CHANGE CODE THEY WROTE. **It is NOT a changelog** - "what changed" is already carried by the framework-update commit body / `git log -- system/` / `framework_update_history.dat`. Self-applying behavior needs no document, however large the change.

Three tests, in order. Any single "no" ends it:

1. **The untouched fork.** Take a developer who checked out the template months ago and has never overridden a framework core class. To keep working, or to get the new behavior, would they have to make a local change of their own? If no - no document.
2. **The black box.** Is the changed thing something an application is SUPPOSED TO KNOW ABOUT at all? How bundles compile, how the manifest is built, how a request dispatches, which node processes exist - all internal. If it is internal, no document, however dramatic the change. And do not write for the reader who hijacked an internal: they have already diverged, our updates do not apply cleanly to them anyway, and the document would not help.
3. **The library API test (the jQuery standard).** Does the CALL SHAPE of a function or class DESIGNED FOR APPLICATION USE actually change? jQuery publishes no migration note for rewriting its internal selector engine; it publishes one when `$.fn.foo(a, b)` becomes `$.fn.foo({a, b})`. Same bar here.

**A document reporting work the framework already did is noise, and noise trains developers to ignore the whole directory** - so the cost of a needless document is paid by the next one, the one that actually matters.

### `IF YOU DO NOTHING:` is mandatory

Every document carries a line beginning exactly `IF YOU DO NOTHING: ` - column 0, uppercase, colon - naming ONE concrete breakage: what fails, silently or loudly, if the developer ignores it. It sits in the preamble, after the CATEGORY/TITLE/DATE header block and before the first body section.

```
IF YOU DO NOTHING: every Rsx_Mail::send() call in your app fatals - the method
    no longer exists - and that mail is never sent.
```

**If you cannot write a specific breakage, the document must not exist.** Not a vaguer line, not a document without the line - no document. "Your fork could adopt this", "be aware that", "we have improved X" are not breakages. **Write the line FIRST**: if it is honest and concrete you have a document, and if writing it is a struggle that is your answer.

`bin/publish` refuses to release a document that has no such line, by name, before any release work begins.

Worked examples:

| Change | Document? | Why |
|---|---|---|
| A new framework migration ships | **No** | `migrate` applies it. Nothing left for the developer. |
| A new environment update script | **No** | `post-update.sh` self-applies it, silently. |
| Any file under `system/` changed | **No** | ALL of `system/` is overwritten by the pull. |
| An internal compiler step rewritten (concatenation, SCSS, bundling, manifest) | **No** | Black box - test 2. No app calls it. |
| A node helper becomes a daemon; a shell-out disappears | **No** | Black box - test 2. |
| A new or stricter LINT RULE | **No** | `rsx:check` goes red and names the fix. It self-corrects. |
| An artisan command removed or renamed | **No** | The first call fails loud, naming the command. |
| A new optional feature or capability | **No** | Nothing existing behaves differently. Adoption is not a breakage. |
| A performance or behavior change with no contract change | **No** | Nothing downstream is written or invoked differently. |
| A `/rsx/` template UI improvement | **No** | The fork may adopt it at leisure, or never. It ships in the reference app. |
| A `/rsx/` TEMPLATE CHANGE | **ONLY** when the fork BREAKS or loses a documented capability without it - never because it COULD adopt something new |
| A DEFAULT changed silently (old code keeps running, differently) | **Yes** | Nothing fails; only an audit finds it. Also a prelaunch-checklist candidate. |
| An exposed dependency retired or majored | **Yes** (Category 2) | Must include the adopt/re-record remedy. |
| A deleted or re-signatured API that call sites depend on | **Yes** | Call sites must be FOUND and converted; a production path nobody exercises locally fatals otherwise. |
| A data migration leaving rows that need a human decision | **Yes** | Only the developer knows what those rows were meant to mean. |
| A REQUESTED FEATURE whose implementation includes a template app UI | **Yes** (Category 3) | The engine arrives; the interface does not. Unusable in their app until ported. |

**Format, naming and the three category structures are defined by the charter** - `system/app/RSpade/breaking_changes/CLAUDE.md`. Read it before writing; do not invent a shape. In short: `{feature}_{MM}_{DD}.txt`; **Category 1** = `/rsx/` template diff with exact copy-paste code (only when the fork BREAKS without it); **Category 2** = a PUBLIC API call-shape change whose body is **ACTION REQUIRED** (what downstream must audit in their OWN code), never a description of the core fix; **Category 3** = an owner-requested feature whose implementation includes a template UI - the engine ships automatically and the interface does not, so the document maps the reference implementation under `system/app/RSpade/resource/reference_app/` and asks the operator to port it into their own app's equivalent surface, on their own navigation and permission model. **Show the code** for 1 and 2 - a document a developer has to diff against is a document that failed. For 3, show the MAP, not a blind diff.

Downstream consumption (what your document will be read through): `rsx:framework:breaking_changes` / `:show <name>` / `:mark <name> --fulfilled`. Full detail: `rsx:man breaking_changes`.

---

## Authoring a prelaunch checklist entry

`man/prelaunch_checklist.txt` is a **sanctioned enforcement channel**: when a lint rule is impractical, "add this to the prelaunch checklist" is a good answer - and the RSpade LLM may proactively suggest it to the owner. It is ADVISORY (nothing blocks a build), and an entry INVENTS an obligation rather than recording behavior that already shipped - which is the owner's call, not yours - so **propose the entry and get the owner's go-ahead**.

An entry follows the house shape of the existing ones:

```
ENTRY N: SHORT TITLE (what it is about)

    What to audit
        The exact property to verify, and a pointer to the man page that holds
        the patterns. Do NOT restate the patterns here - direct the reader.

    Why it matters
        The failure this catches, concretely.

    Guidelines
        How to perform the audit: what to enumerate, what to check per item.

    Dos and don'ts
        The specific right and wrong spellings.
```

Entry 1 (login-redirect wiring) is the model: enumeration-driven, pointing at `rsx:man login_redirect` for the patterns rather than duplicating them.

---

## External requests: INDEX and archive

`docs.dev/external_requests/` holds pre-authorized requests from external environments Brian controls. The directory is tracked by a self-maintained **`INDEX.md`**.

Workflow, every time you touch one:

1. **Handle the request** (implement, partially implement, fold into another effort, supersede, or decline).
2. **Record it in `INDEX.md`** - one row: filename, disposition, the commit/artifact that fulfilled it, and an **absolute-date** timestamp. Update the row whenever the status changes.
3. **If it is FULLY processed** (`IMPLEMENTED` / `FOLDED` / `SUPERSEDED` / `DECLINED`), **MOVE the file into `docs.dev/external_requests/archive/`** and make its INDEX row carry the `archive/` path.

So `archive/` is the done pile; a file still at the top level is OPEN (`PARTIAL` / `NOT DONE` / awaiting review) and stays there until finished.

---

## Final check

Before calling the change shipped, re-read the six items and name, out loud, which ones applied and which did not and why. **"I updated the code and the man page" is not shipping** if the template still teaches the old way or a silent behavior change went out with no checklist entry.

Details: `system/app/RSpade/breaking_changes/CLAUDE.md`, `rsx:man breaking_changes`, `rsx:man prelaunch_checklist`. Related: `rspade:publishing-a-release`.
