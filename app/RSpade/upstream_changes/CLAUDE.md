# Upstream Changes Log

## THE GATE: manual action required, or no document

An upstream_changes document exists ONLY when the downstream developer must DO
something to their environment or their own code BY HAND, or something concrete
BREAKS if they do not. It is **NOT a changelog** - "what changed upstream" is
already carried by the framework-update commit body / `git log -- system/` /
`framework_update_history.dat`, and self-applying behavior (the submodule pull,
environment updates via `system/bin/environment_updates/`, migrations) needs NO
document, however large the change.

**A document reporting work the framework already did is noise, and noise trains
developers to ignore the whole directory.** That cost is paid by the next
document, the one that actually matters.

### `IF YOU DO NOTHING:` is MANDATORY in every document

Every document carries a line beginning exactly `IF YOU DO NOTHING: ` - at
column 0, uppercase, with the colon - naming ONE concrete breakage: what fails,
silently or loudly, if the developer ignores the document. It sits in the
preamble, after the CATEGORY/TITLE/DATE (or TITLE/Date) header block and before
the first body section. It need not be line 1.

```
IF YOU DO NOTHING: three weeks after this release, every file in storage/logs
    older than 21 days is deleted, permanently and without warning.
```

**If you cannot write a specific breakage, the document must not exist.** Not a
vaguer line, not a document without the line - no document. "Your fork could
adopt this", "be aware that", "we have improved X" are not breakages.

`bin/publish` enforces this: a document with no `IF YOU DO NOTHING:` line
carrying real content FAILS the publish, by name, before any release work
begins.

### Worked examples

| Change | Document? | Why |
|---|---|---|
| A new framework migration ships | **No** | `migrate` applies it. Nothing left for the developer. |
| A new environment update script | **No** | `post-update.sh` self-applies it, silently. |
| Any file under `system/` changed | **No** | ALL of `system/` is overwritten by the pull. |
| A new or stricter LINT RULE | **No** | `rsx:check` goes red and names the fix. It self-corrects. |
| An artisan command removed or renamed | **No** | The first call fails loud, naming the command. |
| A new optional feature or capability | **No** | Nothing existing behaves differently. Adoption is not a breakage. |
| A performance or behavior change with no contract change | **No** | Nothing downstream is written or invoked differently. |
| A `/rsx/` template UI improvement | **No** | The fork may adopt it at leisure, or never. |
| A `/rsx/` TEMPLATE CHANGE | **ONLY** when the fork BREAKS or loses a documented capability without it - never because it COULD adopt something new |
| A DEFAULT changed silently (old code keeps running, differently) | **Yes** | Nothing fails; only an audit finds it. Also a prelaunch-checklist candidate. |
| An exposed dependency retired or majored | **Yes** (Category 2) | Must include the adopt/re-record remedy. |
| A deleted or re-signatured API that call sites depend on | **Yes** | Call sites must be FOUND and converted; a production path nobody exercises locally fatals otherwise. |
| A data migration leaving rows that need a human decision | **Yes** | Only the developer knows what those rows were meant to mean. |

A borderline case is decided by writing the `IF YOU DO NOTHING:` line first. If
the line is honest and concrete, write the document; if writing it is a struggle,
that is the answer.

## Critical: Understanding the Purpose

**The `/rsx/` directory is the starter template that end users fork to create their applications.**

When we make changes to files in `/rsx/`, users who previously forked the template do NOT automatically receive those changes. They must manually update their own copies.

**Upstream changes documents tell users EXACTLY what to change in their forked `/rsx/` code to match the upstream template.**

## Audience

The audience is a developer who:
- Forked the `/rsx/` starter template weeks or months ago
- Has been building their own application on top of it
- Wants to incorporate improvements we've made to the starter template
- Needs to know the EXACT file paths and code changes to make

## Consumption Side (Downstream Tracking)

Each downstream app tracks which of these documents it has already fulfilled
versus which are still pending, in a local manifest managed via the
`rsx:framework:upstream_changes` commands (list / show / mark). That is the
CONSUMER's side of this system; it is documented for downstream developers in
`php artisan rsx:man upstream_changes`.

**THIS file is the AUTHOR's side** - the format and charter for writing the
documents a downstream developer will later consume. When the maintainer asks
to "publish a change that requires downstream code fixes", this file defines
what that document looks like.

## When to Create a Document

There are two categories of upstream change that warrant a document. Both
coexist - any given document belongs to one category or the other.

### Category 1: `/rsx/` template diffs

The fork gets nothing automatically here, so when a document IS warranted it
carries the exact code to copy.

**But "their `/rsx/` is their own fork" is not by itself a reason to write one.**
A template change earns a document ONLY when the fork BREAKS without it, or
loses a capability the framework documents it as having - a template file that
must change because a framework contract moved underneath it, a template screen
whose text now describes a language that no longer exists, a template guard the
absence of which now throws. A template improvement the fork may adopt at
leisure, or never, gets NO document: it ships in the reference app at
`system/app/RSpade/resource/reference_app/`, which is where a developer looking
for the current way finds it.

### Category 2: `/system/` core behavior / API-contract changes

Create a document when a framework-core (`/system/`) change alters behavior or an
API contract in a way that downstream app code might rely on the old behavior,
need to re-verify its assumptions against, or must audit/remove compensating
workarounds for. Examples:
- A core function whose buggy return value (e.g. a wrong sign) is now fixed -
  downstream code may have grown compensating logic around the old broken
  behavior that must now be found and removed, or simply re-verified now that the
  contract is correct.
- A changed method signature, default, or semantic that downstream callers must
  re-check their assumptions against.
- A breaking change to, or the retirement of, a package on the exposed
  dependency lists (`config/rsx.php` `dependencies.exposed_composer` /
  `dependencies.exposed_npm`). Exposing a package is a standing forever
  commitment, so this is rare - but if it happens it is Category 2. The
  post-update dependency reconciler already surfaces affected downstream apps
  automatically (it checks each recorded provided package after every pull), so
  the document exists to explain the change and MUST include the adopt/re-record
  remedy: `php artisan rsx:composer require <pkg>` (or
  `php artisan rsx:npm install <pkg>`) to adopt a dropped package into the app
  layer, or to re-record against the new major after verifying usage.

The core fix itself ships automatically via the submodule merge - that part is
NOT what the document is about. The document exists because downstream may need
to ACT on their OWN code (audit / fix / re-verify), exactly like a `/rsx/`
template diff requires action.

Do NOT create documents for:
- Routine internal `/system/` changes with no downstream-actionable impact (users
  get these automatically via the submodule and simply benefit - an ordinary core
  bug fix that downstream silently inherits needs nothing).
- Internal refactoring that doesn't change functionality users would want.

## What the Document Must Contain

The document must provide everything needed to replicate the change:

1. **AFFECTED FILES** - Exact file paths in `/rsx/` that were changed
2. **WHAT CHANGED** - The specific code additions, modifications, or deletions
3. **HOW TO APPLY** - Step-by-step instructions or copy-paste code blocks

The goal is: a user can read the document and make the exact same changes to their fork without needing to diff files or guess what changed.

## File Naming Convention

```
{feature}_{month}_{day}.txt
```

Examples:
- `modal_events_01_28.txt` - Modal event changes on January 28
- `responsive_12_18.txt` - Responsive system changes on December 18

Use lowercase with underscores. Date is MM_DD format.

## Document Structure

Pick the structure matching the document's category.

### Category 1 structure (`/rsx/` template diff)

```
FEATURE NAME
Date: YYYY-MM-DD

IF YOU DO NOTHING: <the one concrete thing that fails - mandatory, see THE GATE>

SUMMARY
    Brief description of what changed and why users might want it.

AFFECTED FILES
    /rsx/path/to/file.js
    /rsx/path/to/other_file.php

CHANGES REQUIRED

    File: /rsx/path/to/file.js
    -------------------------------------------------------------------------
    [Exact code to add/change, with enough context to locate where]

    File: /rsx/path/to/other_file.php
    -------------------------------------------------------------------------
    [Exact code to add/change]

VERIFICATION
    How to verify the change works after applying it.
```

### Category 2 structure (`/system/` core behavior change)

An `AFFECTED FILES: /rsx/...` list makes no sense here - the changed file lives in
`/system/` and arrives automatically via the submodule. Use this shape instead:

```
FEATURE NAME
Date: YYYY-MM-DD

IF YOU DO NOTHING: <the one concrete thing that fails - mandatory, see THE GATE>

SUMMARY
    Brief description of the core change and who it affects.

THE BUG / THE CHANGE
    What was wrong and why (for a fix), or what changed and why (for a contract
    change).

THE FIX
    What changed in /system/ core. Informational only - downstream receives this
    automatically via the submodule, so there is nothing here to copy.

ACTION REQUIRED
    What downstream must actively check / audit / fix in THEIR OWN code. This is
    the part that makes the document mandatory - not the core fix itself.

VERIFICATION
    How to confirm downstream code is correct against the new contract.
```

## Key Principle

**Show the code.** Don't just describe what changed - show the exact lines to add or modify. Users should be able to copy-paste from the document into their files.
