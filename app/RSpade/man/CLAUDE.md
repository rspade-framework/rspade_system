# RSX Framework Manual Pages

A man page is the **CONTRACT tier**: terse, complete, expert-level, and it describes what the
framework ACTUALLY does. Updating the affected pages is part of any change that moves
observable behaviour (`docs/claude/framework/82-documentation-obligations.md`). Pages are read
with `php artisan rsx:man <topic>` (`Commands/Rsx/Man_Command.php`); a page of the same name
under `rsx/resource/man/` takes priority over the one here (`rsx:man man`).

The format rules shared with an application's own pages live in `rsx/resource/man/CLAUDE.md`;
this file adds what is specific to the framework's pages.

## Format

Plain ASCII text in late-1990s Unix man page style: section headers in ALL CAPS, subsection
headers in Title Case, code indented 4 spaces, no box-drawing characters or Unicode symbols, so
every example copies and pastes as-is. Files are `lowercase_with_underscores.txt`.

## Sections

Standard sections, in order (use those the page needs):

```
NAME
    topic - one-line description

TAGS
    forms, ui

SYNOPSIS
    The most common use, as code

DESCRIPTION
    What the feature is, why it exists, the design decisions behind it

BASIC USAGE / API REFERENCE / EXAMPLES
    How it is used, the full surface, real-world code

RSX VS LARAVEL
    Only where a Laravel developer's expectation would mislead

TROUBLESHOOTING
    Common errors (their literal text) and their fixes

SEE ALSO
    Related man pages and skills
```

**TAGS** comes right after NAME: one indented line of lowercase, comma-separated tags from the
shared vocabulary (`Man_Command::TAG_VOCABULARY`; `rsx:man --tags` lists it with counts), a few
tags, one per subject the page covers. `rsx:man --tag=<tag>` finds pages by them. Only a handful
of pages carry them so far; tagging the rest is backlog B-146.

**RSX VS LARAVEL** is written only where a Laravel developer's expectation would mislead. The
catalogue of divergences from Laravel is `rsx:man framework_divergences` (its ADDING AN ENTRY
section is the contract).

## Cross-references

The cross-reference section is headed `SEE ALSO`, one reference per line. A man-page row is
either the full form `rsx:man topic - description` or, because the heading already
disambiguates it, the compact `topic - description`; a skill row is
`skill rspade:name - description`. A repository path or an artisan command stays spelled as
itself. **In prose and in code comments the spelling is `rsx:man topic`** - never
`topic(7)`, never `topic.txt`, never `php artisan rsx:man topic`. The VS Code extension
resolves exactly these spellings into a highlight and a go-to-definition, so a reference it
cannot resolve is a reference nobody can follow. Every topic named must exist as
`system/app/RSpade/man/<topic>.txt` or `rsx/resource/man/<topic>.txt`, and every skill as a
`SKILL.md` under `docs/skills/{shared,framework}/` or `rsx/resource/skills/`.

## Pages that do not ship

`bin/publish` strips five pages from every release: `code_quality`, `manifest_api`,
`manifest_build`, `ast_sourcecode_parsers`, `vs_code_extension`. They document framework
internals for this monorepo only, so **a shipped page, skill or fragment never points at one**.

## Checking a page

Render it with `php artisan rsx:man <topic>` and read the output: plain text, indentation
intact, examples copyable.
