---
name: conformance-audits
description: "Running and writing conformance audits - the library of review prompts (system/app/RSpade/conformance/, rsx/resource/conformance/) for contracts no lint rule can enforce, each walked on request by one read-only agent: listing them with rsx:conformance:list [--when=launch] [--severity=], dispatching one Sonnet agent per audit with rsx:conformance:show <id>, verifying every finding against the source before reporting, consulting rsx:conformance:for <path> before changing governed code, and authoring a new audit. Use when asked to \"run the conformance audits\", \"run the launch audits\", \"run conformance audit <id>\", to prepare a site for launch, when writing a new audit or adding an application audit in rsx/resource/conformance/, or when a conformance file fails to parse (\"does not open with a header\", \"unknown header key\", \"is declared twice\")."
---

# Conformance audits

A conformance audit is a markdown prompt describing ONE contract an application must honour that no rule can check mechanically. A developer asks for audits to be run; one read-only agent walks each and reports what does not conform. They never run automatically, they are not tests, and a run changes nothing. Contract, file format and the framework library: `rsx:man conformance_audits`.

```bash
php artisan rsx:conformance:list                     # all, framework first
php artisan rsx:conformance:list --when=launch       # launch | change | periodic
php artisan rsx:conformance:list --severity=high     # high and critical
php artisan rsx:conformance:show <id>                # the prompt itself
php artisan rsx:conformance:for rsx/app/x/y.php      # audits whose scope covers a file
```

## Running a set

The trigger words map to a filter:

| The developer says | List with |
|---|---|
| "run the conformance audits" | `rsx:conformance:list` |
| "run the launch (conformance) audits" | `rsx:conformance:list --when=launch` |
| "run the high-severity audits" | `rsx:conformance:list --severity=high` |
| "run conformance audit `<id>`" | that one id |

Then:

1. **List** the set and tell the developer which audits are about to run (id + severity, one line each).
2. **Dispatch one background agent per audit, all in one message so they run in parallel, on the Sonnet model**, read-only (an exploring agent type when one is available). Use the template below verbatim.
3. **Wait for the notifications.** Never poll, never start a second agent for an audit already running, never present a report you have not received.
4. **Verify every finding** (next section).
5. **Present one consolidated report**: grouped by audit, critical first; each audit's `PASS` line or its verified findings; then a short list of dropped findings with the reason, if any.
6. **Fix nothing** unless the developer asks. When they do, the fixes are ordinary work and follow the normal rules (one writer at a time).

### Dispatch prompt

```
You are running one conformance audit against the RSpade application in this project (run every command from the project root).
You are READ-ONLY: do not edit, create or delete any file, do not commit, do not run rsx:check, rsx:test,
migrate, any build or any command that writes. Reading files, grep, find and the commands the audit names
are allowed.

1. Run: php artisan rsx:conformance:show <id>
2. Read the man pages and skills it names as the source of truth.
3. Follow its "Find every instance" section literally - run every command, read every hit it tells you to read.
4. Judge each case against "What does not conform", including its exceptions. Do not report anything the
   audit says a rule already enforces, and do not report anything outside the audit's contract.
5. Answer ONLY in the audit's Report format: either
       PASS - <one-line reason>
   or one finding per line:
       file:line - what is wrong - the conforming fix
   No preamble, no summary, no other prose.
```

## Verifying findings

A Sonnet reviewer is literal and fast, and it over-reports. Before a finding reaches the developer:

- **Open the cited file at the cited line.** The code must say what the finding claims. Wrong line numbers are corrected; a claim the code does not support is dropped.
- **Check the audit's exceptions.** A one-time-token GET, a staff-or-portal "is anybody signed in" disjunction, an amount that only travels - each audit lists what is NOT a finding, and the reviewer misses some.
- **Check reachability claims** (portal-reachable, called from a GET handler) by tracing the actual callers.
- **Check the fix** is the conforming one the audit or its man page prescribes, not an improvised one.
- **A PASS is not verified by re-running the audit.** Spot-check one or two hits the reviewer classified as fine when the audit is critical or high.

Mark anything you could not settle as `(unverified)` rather than dropping or asserting it.

## Before changing governed code

When writing or changing a file, `php artisan rsx:conformance:for <path>` names the audits that cover it; read each with `rsx:conformance:show <id>` and build to it. That is cheaper than a finding later.

## Writing an audit

- **One contract per audit.** Two contracts with different scopes or different readers are two files.
- **Prefer a lint rule** whenever the pattern is mechanically checkable. An audit covers what a rule cannot see - reachability, intent, helpers followed to the end, data in the database - and says which rule already covers the rest so the reviewer does not re-check it.
- **Where it goes**: a framework contract in `system/app/RSpade/conformance/<id>.md` (framework development only); an application's own contract in `rsx/resource/conformance/<id>.md`. The id is unique across both - an application audit adds to the library and never replaces a framework one.
- **Header**: `id` (= basename), `title`, `severity` (critical | high | medium | low), `when` (launch, change, periodic), `scope` (comma-separated globs from the project root - `**` crosses directories, `*` and `?` stay within one segment; pick globs a developer's edit to a governed file will actually match), optional `related` (`rsx:man <topic>`, `skill rspade:<name>`).
- **Body**, four sections, addressed to a careful literal reviewer:
  - `## The contract` - what must hold, why (one short paragraph), the source-of-truth man page or skill to read first, and what a rule already enforces.
  - `## Find every instance` - exact commands and greps, and what to read for each hit. **Run every command before shipping the audit**; note known noise so the reviewer triages instead of reporting it.
  - `## What does not conform` - the violations, then the legitimate exceptions.
  - `## Report` - `PASS - <reason>` or `file:line - what is wrong - the conforming fix`, no other prose.
- **Write for the reviewer, not the history.** State what the code must be today; no narrative of how the contract came about beyond the one-sentence reason it matters.
- **Never instruct the reviewer to edit**, and never include a step that writes (minting a key, sending mail, a migration).

After adding one, `php artisan rsx:conformance:list` must show it (a malformed header throws there, naming the file) and `rsx:conformance:for` on a governed file must name it.
