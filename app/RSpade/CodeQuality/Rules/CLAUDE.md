# CodeQuality/Rules — the rule classes

Every concrete `rsx:check` rule, one class per rule, extending
`CodeQualityRule_Abstract`. Grouped by the language or concern they inspect:
`Blade/ Common/ Convention/ Database/ JavaScript/ Jqhtml/ Manifest/ Meta/
Models/ PHP/ Scss/`. The subdirectory is organization only — every class here
that extends `CodeQualityRule_Abstract` is **discovered by a recursive walk of
this directory** (`Support/RuleDiscovery.php`), at any depth, so there is no
registry file to edit and nothing to register when you add one.

**This directory holds FRAMEWORK rules only.** An application's own rules are
classes outside it (under `rsx/`), found by class through the manifest, and must
declare an `APP-` id — so no framework rule id may ever start with `APP-`. See
the parent `CLAUDE.md`, "Application rules".

Architecture (the runner, the collector, severities, the config surface) lives in
the parent `CodeQuality/CLAUDE.md`. This file is about writing a rule.

## The contract

Implement `get_id()`, `get_name()`, `get_description()`, `get_file_patterns()`,
`check($file_path, $contents, $metadata)`, and `get_default_severity()`.

- **The id is the API.** `PHP-MASS-01`, `REALTIME-AUTH-01`, `ROUTE-SYNTAX-01` —
  it appears in output, in `@<ID>-EXCEPTION` suppression comments, in
  documentation, and in agents' memories. Pick it once; renaming one breaks every
  suppression comment in every downstream app.
- **Two enforcement tiers.** A normal rule reports a violation. Overriding
  `is_called_during_manifest_scan()` to `true` makes it FATAL — it throws
  `YoureDoingItWrongException` during the manifest build and the code cannot
  ship. Reserve that tier for things that are unambiguously broken, never for
  style.
- **Write the remediation for the reader, not the author.** The rule text is
  read by a developer or an agent at the moment they are blocked: say what is
  wrong, why it is a rule, the exact correct form, and whether to fix it
  autonomously or ask. Agents are instructed to TRUST that text as authoritative
  — so vague remediation produces confident wrong fixes.
- **Cross-file rules also declare `kind()` / `depends_on()`** (parent `CLAUDE.md`,
  "Per-file vs cross-file rules"), and **find their subjects through
  `Manifest::php_get_subclasses_of()` / `Manifest::by_attribute()`, never by
  walking `get_all()` or calling `class_exists()` per file**. PHP never unloads a
  class, so that walk makes a one-file rebuild cost a cold one
  (`Manifest_Memory_Gate_Test` holds the budget).

## Invariants

- **AST, never regex, when inspecting source.** Use the shared parsers
  (`rsx:man ast_sourcecode_parsers`); a regex rule generates false positives that
  train everyone to ignore the checker.
- **Rules run over files the framework does not control.** Never assume a
  parseable file, a known encoding, or that a match implies a defect — a rule
  that cries wolf is worse than no rule.
- Every rule is suppressible by a `@<ID>-EXCEPTION` comment unless it is fatal
  by design. By default the driver honours the marker by skipping the rule for
  the whole file; a rule that returns `true` from `reads_own_exception_markers()`
  is handed every file and judges each line itself, which lets it require a
  rationale (`JS-RAW-HTML-01`, `JavaScript/RawHtmlWrite_CodeQualityRule.php`, is
  the example).
- A new or stricter rule needs its rule text and its man page / skill entry; it
  is **no `breaking_changes` document** (the gate in
  `docs/claude/framework/82-documentation-obligations.md`).

## Pointers

`../CLAUDE.md` (architecture) · `../RuntimeChecks/CLAUDE.md` (the runtime
sibling) · `rsx:man code_quality` (framework-only; stripped from releases) ·
`rsx:man ast_sourcecode_parsers`
