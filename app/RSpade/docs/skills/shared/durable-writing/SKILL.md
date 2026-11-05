---
name: durable-writing
description: "Writing text that outlives the session - commit messages, code comments, docblocks, test names, filenames, man pages, CLAUDE.md, breaking_changes documents - for a reader who never saw the conversation: the reader test, the residue catalog, what is not residue, drafting from requirement plus final diff. Use when writing a commit message, comment, test name, filename or doc, or when tempted to write 'as discussed', 'per your feedback', '(fixed version)' or a v2 filename."
---

# Durable writing: write for the reader of the artifact

Everything that outlives the session - a commit message, a code comment or docblock, a test name, a filename, a man page, a `CLAUDE.md`, a `breaking_changes` document, a summary meant to be pasted somewhere - is read by someone who was never in this conversation. Write it as the author of the artifact, for that reader: describe what the thing IS, never how the conversation arrived at it.

## The reader test

Would the sentence carry its full meaning to someone opening the repository a year from now with no access to this chat? If it only makes sense relative to something said, tried or rejected here, it is residue - cut it, or rewrite it from the final state.

## Residue catalog

| Kind | Example | Why it fails |
|---|---|---|
| Negated draft | `Add debounce without lodash` (nobody required lodash) | Answers a question the reader never asked |
| Chat reference | `as discussed`, `per your feedback`, `as requested` | Points at a conversation the reader cannot open |
| Revision marker | `(fixed version)`, `retry_v2.php` | Names a draft history that does not exist in the repo |
| Apology | "this time the check is correct" | Refers to an earlier attempt nobody can see |
| Announcing the rule | `following the guideline, only the adopted design is shown` | Narrates the drafting, not the artifact |

A rejected draft constrains what you build; it is never content for what you write.

## Not residue

- **A negation that describes the artifact**: `Allow login without password for SSO users` - the "without" is a property of the feature.
- **A reason given in the reader's terms**: `// regex avoided: the grammar is not regular`; `a downstream field report (2026-09-14)`.
- **An incident narrative beside a mandate** passes the test (it explains why the rule exists); the drafting history behind a change never does.

## Procedure

Draft from two inputs only - the original requirement and the final diff - and hand it off with no preamble about how it was cleaned.

