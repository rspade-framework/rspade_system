# Codegen (model docblock / constant regeneration)

## Domain

One artisan command regenerates the auto-generated regions of model files from the
database schema and each model's `$enums`:

- `rsx:constants:regenerate` (`Commands/Rsx/Constants_Regenerate_Command.php`) - the single
  canonical model-codegen command, wired into `Maint_Migrate` (runs as a post-migration
  step). It validates each model's `$enums`, then emits a pre-class
  `_AUTO_GENERATED_ Database type hints` docblock (typed `@property` per base + CTI-detail
  column, DATE/DATETIME -> `string`, BEM `field__enum` method tags + `field__label` /
  `field__constant` accessor hints) and an `_AUTO_GENERATED_ Enum constants` block after the
  class brace.

The write goes through the shared, fence-safe rewriter, so the load-bearing contract is:
**everything outside the auto-generated regions is hand-written and must never be silently
altered.** A past regen violated this and deleted hand-written `public static $realtime = true;`
blocks from three models (see the external request); the rewriter's self-check now makes that
structurally impossible.

## Source under test

- `app/RSpade/Core/Codegen/Model_Codegen_Rewriter.php` - the AST-guided, fence-safe rewriter:
  - `rewrite()` - surgical byte-exact in-place rewrite (replaces the auto docblock + enum
    constants region; migrates an old fenced const block into the canonical block).
  - `assert_no_handwritten_change()` - strict byte-exact self-check, embedded in `rewrite()`.
  - `strip_owned_regions()` - removes every owned region (docblock, enum-constants block,
    fence pair); the primitive the self-check compares on.
- `app/RSpade/Commands/Rsx/Constants_Regenerate_Command.php` - the command: enum validation,
  base + detail column spanning, BEM/typed docblock assembly, DATE/DATETIME -> string.

## Governing docs

- `docs.dev/external_requests/2026_07_15_document_models_regen_clobbers_class_top.md`
  (the four-point contract these tests enforce).

## Testable surface

| Concern | Type | Notes |
|---------|------|-------|
| Hand-written content survives a rewrite byte-for-byte | php | implemented |
| Insertion (model with no existing auto regions) | php | implemented |
| Old fenced (B2) enum-const block is MIGRATED into a canonical B1, never duplicated | php | implemented |
| Empty old fence left untouched; non-constant fence content fails loud; parse-guard refuses duplicate-const candidates | php | implemented |
| strip_owned_regions removes all marker families | php | implemented |
| Fail loud: duplicate docblocks / unbalanced fences / missing class / parse error | php | implemented |
| Self-check aborts on dropped hand-written line | php | implemented |
| Command output spans CTI detail columns `(detail: <table>)`, BEM/typed enum members, string dates | php | implemented (Party demo) |

See `issues_encountered.md` for the dual-implementation finding (now resolved).
