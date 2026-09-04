# Breaking Changes Log

## THE GATE: would an untouched fork have to edit its own code?

A breaking_changes document exists ONLY when a downstream developer must CHANGE CODE THEY
WROTE. It is **not a changelog**, not a release note, and not a report of work the framework
already did for them.

Apply these three tests, in order. Any single "no" ends it - write no document.

### Test 1 - the untouched fork

**Take a developer who checked out the template app months ago and has never overridden a
framework core class. To keep working, or to get the new behavior, would they have to make
any local change of their own?**

If no, there is no document. The framework updated itself; that is what a framework is for.

### Test 2 - the black box

**Is the thing that changed something an application is SUPPOSED TO KNOW ABOUT at all?**

How bundles compile, how the manifest is built, how a request dispatches, which node
processes exist, how caching or concatenation or SCSS compilation happen - all black box.
An application is not supposed to know any of it, and RSpade exists precisely so that
nobody has to. If the answer is no - it is internal - then changing it, however
dramatically, warrants NO document.

The counter-case is not "somebody could theoretically have hooked into it". Somebody who
hijacked an internal compiler step has ALREADY diverged from the framework; our updates do
not apply cleanly to them regardless, and a document would not help them. Do not write for
that reader.

### Test 3 - the library API test (the jQuery standard)

**Does the change alter the CALL SHAPE of a function or class that is designed for
application use - the RSpade library API an app writes code against?**

That is the whole bar. jQuery does not publish a migration note because it rewrote its
internal selector engine; it publishes one when `$.fn.foo(a, b)` becomes `$.fn.foo({a, b})`.
Same standard here: a signature, a return contract, a default, or a semantic that
application code CALLS and therefore depends on.

Renamed internals, new daemons, faster builds, deleted private helpers, stricter lint rules,
new optional features - none of these change a call shape an app wrote against.

### `IF YOU DO NOTHING:` is MANDATORY in every document

Every document carries a line beginning exactly `IF YOU DO NOTHING: ` - at column 0,
uppercase, with the colon - naming ONE concrete thing the DEVELOPER must do or that BREAKS
IN THEIR CODE. It sits in the preamble, after the header block and before the first body
section.

```
IF YOU DO NOTHING: every call to Rsx_Time::diff() in your application returns seconds
    where it used to return minutes, silently, with no error anywhere.
```

**If you cannot write that line about THEIR code, the document must not exist.** "Your fork
could adopt this", "be aware that", "we have improved X", "we removed an internal
limitation" are not breakages. A document reporting work the framework already did is noise,
and noise trains developers to ignore the whole directory - a cost paid by the next
document, the one that actually matters.

`bin/publish` enforces the line's presence: a document without it FAILS the publish by name.
The line's presence is mechanical; its HONESTY is yours.

### Worked examples

| Change | Document? | Why |
|---|---|---|
| A new framework migration ships | **No** | `migrate` applies it. |
| A new environment update script | **No** | `post-update.sh` self-applies it. |
| Any file under `system/` changed | **No** | ALL of `system/` is replaced by the pull. |
| An internal compiler step rewritten (concatenation, SCSS, bundling, manifest) | **No** | Black box. Test 2. |
| A node helper becomes a daemon; a shell-out disappears | **No** | Black box. No app calls it. |
| A new or stricter LINT RULE | **No** | `rsx:check` goes red and names the fix. |
| An artisan command removed or renamed | **No** | The first call fails loud, naming it. |
| A new optional feature nothing else needs | **No** | Adoption is not a breakage. |
| A performance or behavior change with no call-shape change | **No** | Test 3. |
| A `/rsx/` template UI improvement | **No** | The fork may adopt it at leisure, or never. |
| A DEFAULT changed silently (old code keeps running, differently) | **Yes** | Nothing fails; only an audit finds it. |
| An exposed dependency retired or majored | **Yes** (Cat. 2) | Their `use` statements and call sites. |
| A deleted or re-signatured PUBLIC API | **Yes** (Cat. 2) | Call sites must be found and converted. |
| A data migration leaving rows needing a human decision | **Yes** | Only they know what those rows meant. |
| A REQUESTED FEATURE whose implementation includes a template app UI | **Yes** (Cat. 3) | The feature is not usable in their app until it is ported. |

A borderline case is decided by writing the `IF YOU DO NOTHING:` line first. If the line is
honest and concrete and about THEIR code, write the document; if writing it is a struggle,
that is the answer.

## Critical: Understanding the Purpose

**The `/rsx/` directory is the starter template that end users fork to create their
applications.** Everything under `/system/` they receive automatically; nothing under
`/rsx/` they receive at all.

That asymmetry is the reason this directory exists - but it is a reason to write a document
only when the fork must ACT. Their `/rsx/` diverging from ours is the NORMAL, intended state
of every downstream application, not a defect to be reported after every template edit.

**A breaking_changes document tells a developer exactly what to change in code they wrote.**
If there is nothing for them to change, there is no document.

## Audience

The audience is a developer who:
- Forked the `/rsx/` starter template weeks or months ago
- Has been building their own application on top of it
- Wants to incorporate improvements we've made to the starter template
- Needs to know the EXACT file paths and code changes to make

## Consumption Side (Downstream Tracking)

Each downstream app tracks which of these documents it has already fulfilled
versus which are still pending, in a local manifest managed via the
`rsx:framework:breaking_changes` commands (list / show / mark). That is the
CONSUMER's side of this system; it is documented for downstream developers in
`php artisan rsx:ma breaking_changes`.

**THIS file is the AUTHOR's side** - the format and charter for writing the
documents a downstream developer will later consume. When the maintainer asks
to "publish a change that requires downstream code fixes", this file defines
what that document looks like.

## When to Create a Document

There are three categories. Any given document belongs to exactly one.

### Category 1: `/rsx/` template diffs

The fork gets nothing automatically here, so when a document IS warranted it carries the
exact code to copy.

**But "their `/rsx/` is their own fork" is not by itself a reason to write one.** A template
change earns a document ONLY when the fork BREAKS without it, or loses a capability the
framework documents it as having - a template file that must change because a framework
contract moved underneath it, a template guard whose absence now throws. A template
improvement the fork may adopt at leisure gets NO document: it ships in the reference app at
`system/app/RSpade/resource/reference_app/`, which is where a developer looking for the
current way finds it.

### Category 2: a PUBLIC API contract change in `/system/`

Create a document when a framework-core change alters the CALL SHAPE or the semantics of
something application code is designed to call (Test 3 above): a changed signature, a
changed default, a corrected return value that app code may have grown compensating logic
around, or a retired/majored package on the exposed dependency lists.

The core change itself ships automatically via the submodule - that is NOT what the document
is about. The document exists because downstream must ACT on their OWN code: audit, convert,
or re-verify.

An internal change with no call-shape consequence is not Category 2, however large. See
Test 2.

### Category 3: a requested feature that includes a template implementation

The owner asks for a feature; delivering it properly means building it in the framework AND
demonstrating it in the template starter app - a settings screen, an admin control, a
management UI. API key permission scopes is the worked example: the framework gained the
scope engine, and the template gained the UI that mints and edits scoped keys.

A downstream application has its own `/rsx/`, so it receives the engine and NONE of the
interface. The feature is therefore present but unusable there until somebody builds the
equivalent screen.

Such a document describes the feature, states plainly that the reference implementation
ships at `system/app/RSpade/resource/reference_app/`, names the files that implement it,
and asks the operator to port it into whatever the downstream application's equivalent
surface is. It is an offer of a port with a map, not a diff to paste blindly: the downstream
app's own navigation, permissions and design system decide where the feature actually lands.

A Category 3 document is written only for a feature the OWNER requested and that has a
template-side implementation. A framework capability with no UI is not Category 3; a
template polish item with no framework feature behind it is not Category 3 either (that is
Category 1, and usually no document at all).

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

### Category 3 structure (a requested feature with a template implementation)

The reader already HAS the engine; what they lack is the interface. So this document maps
the reference implementation and hands them the decision, rather than pretending their app
has the template's screens.

```
FEATURE NAME
Date: YYYY-MM-DD

IF YOU DO NOTHING: <the capability that exists in your install but cannot be reached or
    administered by anyone, because the interface for it lives only in the template>

SUMMARY
    What the feature does, and what arrived automatically with the framework.

WHAT YOU ALREADY HAVE
    The framework side: the classes, endpoints, config and commands now present in your
    install. Nothing to do here.

WHAT IS MISSING IN YOUR APPLICATION
    The interface, and what it lets somebody do. Be concrete about who is blocked without
    it (an administrator who cannot grant a scope, a user who cannot enroll a factor).

THE REFERENCE IMPLEMENTATION
    Exact paths under system/app/RSpade/resource/reference_app/, with a line each on what
    they contain. This is release-current, readable ground truth on the reader's own disk.

PORTING IT
    The shape of the work, NOT a blind diff: which surface of their app it belongs on,
    which permission gates it needs, what data it reads and writes. Say plainly that their
    navigation, permission model and design system decide the final placement.

VERIFICATION
    How they confirm the feature works end to end once ported.
```

## Key Principle

**Show the code.** Don't just describe what changed - show the exact lines to add or modify. Users should be able to copy-paste from the document into their files.
