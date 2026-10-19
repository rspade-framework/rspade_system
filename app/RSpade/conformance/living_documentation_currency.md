---
id: living_documentation_currency
title: Every living CLAUDE.md and app skill describes the code as it is
severity: low
when: launch, periodic
scope: rsx/**/CLAUDE.md, rsx/resource/skills/**
related: rsx:man template_app, skill rspade:shipping-a-contract-change
---

## The contract

Every `CLAUDE.md` under `rsx/` and every application skill under `rsx/resource/skills/` describes its directory as it is today: what is there, how it is used, how it is customized. Every LLM session and every developer reads these files as current, nothing breaks when one stops being true, and the framework never edits them - so a stale inventory, a seam that no longer exists, or a divergence the application has abandoned steers the next change wrong with full confidence.

Read `php artisan rsx:man template_app` (LIVING DOCUMENTATION) and `rsx/resource/skills/CLAUDE.md` (the SKILL.md shape) first. The application is the truth; each file is a claim about it.

## Find every instance

    find rsx -name CLAUDE.md -not -path 'rsx/resource/*'
    ls rsx/resource/*/CLAUDE.md
    ls rsx/resource/skills/*/SKILL.md

Every file printed is read individually against its directory - no sampling. Then the directories with no file:

    find rsx -mindepth 1 -maxdepth 3 -type d -not -path 'rsx/resource*' -not -path 'rsx/public*' -not -path 'rsx/tests*' | sort | while read d; do [ -f "$d/CLAUDE.md" ] || echo "$d"; done

Out of scope: `system/app/RSpade/resource/reference_app/**` (the framework's pristine template copy, overwritten by every pull) and `rsx/resource/CLAUDE.dist.md` (the template's own CLAUDE.md source).

## What does not conform

For each file:

1. WHAT IS HERE does not match `ls` of the directory - an entry for something gone or renamed, or something present a reader would need that is not listed.
2. HOW IT IS USED names a tag, argument, endpoint, method or line number the code no longer has. Follow at least one example into the code.
3. HOW TO CUSTOMIZE names a seam (extend, override, delete, configure) that does not exist as described.
4. A cross-reference that does not resolve: an `rsx:man <topic>` with no page (`php artisan rsx:man <topic>`), a skill that does not exist, a sibling path that is not a directory.
5. A `## DIVERGENCES FROM FRAMEWORK DOCS` paragraph describing a difference that is no longer real (the framework adopted the behaviour, or the application went back), or written as a changelog (dates, "previously", history).
6. A file whose directory was deleted, or a pointer elsewhere in `rsx/` to a deleted directory.
7. A directory the application added that is a customization seam (a module, a component group, a library) with no `CLAUDE.md`. Judge each listed directory: a leaf of an already-documented feature (`list/`, `view/`, `edit/` under a documented module) is not a seam.
8. An app skill whose frontmatter `description` names symbols, commands or error strings no longer spelled that way in the code, a skill about a removed feature, or a skill and a `CLAUDE.md` that contradict each other.
9. A file contradicting a framework man page or skill with no divergence recorded. Report it; whether the code or the documentation is wrong is the developer's decision.
10. A framework man page or skill opening with a TEMPLATE FEATURE notice naming a living file under `rsx/` that does not exist (`grep -rln "TEMPLATE FEATURE" system/app/RSpade/man system/app/RSpade/docs/skills`).

Fixes are always to the documentation, never to the code.

## Report

`PASS - <one line: N files read against their directories>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
