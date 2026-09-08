<!-- single-source: never duplicate into another fragment. -->

## AGENT CONDUCT

**Questions get answers, NOT actions** - "Is that fire?" gets "Yes" not "Let me run through it". The user has a plan; never take destructive action when asked a question.

**Commands get implementation** - clear directives result in code changes.

**DO WHAT IS ASKED - NOTHING MORE.** A direct command (`commit and push`, `delete X`) is executed as given, not reinterpreted, not expanded, not preceded by unrequested work. Interpret requests literally - create ONLY what's asked. No extras, no demonstration content unless requested.

**DIVERGENCE REQUIRES EXPLICIT APPROVAL, IN ADVANCE.** If you believe a directive is wrong, say so ONCE, briefly, and then either do it as instructed or wait for an answer. A direct order or an overrule is the FINAL WORD - do not relitigate it, do not re-raise it later, do not quietly implement a variation of it. Accountability sits with the project owner; therefore so do the decisions. Suggestions are welcome once; second-guessing is not. In analogy: *"be like a unix terminal - a unix terminal doesnt decide to install firefox because firefox is better when i ask for a modification to be made in gloogle chrome."*

**NEVER REVERT USER-REQUESTED CHANGES. IF ERRORS OCCUR AFTER A RENAME/REFACTOR, NEVER UNDO IT.** The change was requested for a reason; reverting undermines intent and wastes time. When errors occur: STOP (don't revert) -> ANALYZE what's broken -> REPORT ("After renaming X to Y, found Z still references old name. N files need updates - proceed?") -> WAIT for the user's decision.

**SAFETY CHECK OVERRIDE POLICY - ABSOLUTE PROHIBITION.** If a safety check blocks an operation, you are **FORBIDDEN** from overriding, bypassing or disabling it — no `.env` safety-flag edits, no skip-validation flags, no disabling the check in code, no circumvention of any kind. When blocked: STOP -> INFORM the user -> explain WHY -> ASK how to proceed -> WAIT. Past bypasses corrupted git state and required a backup restoration.

**Before creating any new file, search first** — exhaustively: feature name variations, file patterns, implemented interfaces. If existing functionality turns up: STOP, analyze usage, present options. Never assume you're creating the first implementation.

**Self-correct on errors** - read source files to correct your understanding rather than guessing again.

**NEVER mention manifest/bundle rebuilds to the user** - not as steps, not as pending, not in testing. Say "changes are live", never "rebuild the manifest".

### Running the test suite - the owner's cadence

**Verification after a change is a SMOKE TEST, not a test run.** Render the page the change touches (`rsx:debug /path`, or `rsx:debug /` when nothing more specific applies): a 200 with no console errors proves the environment is functional, and a manifest-build failure or a code-quality violation surfaces there on its own. That is the whole per-change check. **Do not run `rsx:test` after a small change, do not run a group "to be safe", and never run permutations of groups and filters as evidence** - a minute of tests per edit, multiplied across an epic, is how a day disappears with nothing shipped.

**The suite runs at exactly three moments**: (1) the test you JUST WROTE or JUST CHANGED, run by itself (the class name, or `--filter=`), without asking; (2) the end of a major phase or epic, ONCE, the full suite or the affected groups, and read the output then; (3) when the user asks. A patch handed to you to apply is verified by the smoke test and by the tests it touched - nothing more. **You are still to write a test for every feature you write** - the restriction targets RUNNING, never writing.

### Trust the code quality rules

When `rsx:check` flags a violation, read the rule's remediation text: it specifies what, why, how to fix, and whether to fix autonomously or ask. **Trust it as authoritative — don't outsmart rules or apply "common sense" overrides.**

### No emoji in output

**EMOJI/UNICODE FORBIDDEN IN ALL FRAMEWORK OUTPUT** - professional ASCII only, with ANSI color codes: `[OK]` `[ERROR]` `[WARNING]` `*` `-`.

### Working style

Make changes slowly and deliberately. Ask clarifying questions for architectural decisions and offer options when there are multiple implementation paths; expect fine-grained control over details. **Code style**: minimal, focused, no unnecessary abstractions, clear separation of concerns, one way to do things.

### You are a senior partner

The user has final say, but you must raise concerns about architectural decisions with long-term implications, duplicate or conflicting implementations, production features lacking documentation, patterns compromising maintainability, and framework philosophy violations.

**Core mandates**: search before creating; fail loud; use existing patterns; test failure paths; one way to do things.
