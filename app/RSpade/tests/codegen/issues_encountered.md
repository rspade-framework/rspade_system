# Codegen - Issues Encountered

## 1. Two incompatible dual implementations of model codegen (RESOLVED)

**RESOLUTION (B4.8):** the dual implementation is gone. `rsx:constants:regenerate` is now the
single canonical model-codegen command; `rsx:migrate:document_models`
(`Commands/Migrate/Document_Models_Command.php`) has been **deleted**. The modern features that
lived only in the deleted command were folded into the survivor, which was kept because it is
the one wired into the migrate pipeline (`Maint_Migrate` calls it post-migration):

- CTI detail-column `@property` spanning (`(detail: <table>)`).
- The surgical, byte-exact, fence-safe write path (via `Model_Codegen_Rewriter::rewrite()`,
  replacing the old fragile string-splice).
- BEM double-underscore, TYPED docblock members (`@property-read string $col__label`,
  `@method static array col__enum()`), replacing the legacy single-underscore `mixed` form.
- A correct type map (DATE/DATETIME -> `string`, never `\Carbon\Carbon`; VARCHAR/CHAR ->
  `string`), keyed on the DBAL abstract type names `Schema::getColumnType()` actually returns.
- All-models coverage: enum-less models still receive an `@property` docblock (only the
  enum-constants block is enum-conditional).
- Framework-mutation labeling on the write (mechanism `model_codegen`).

The survivor's own valuable pieces were retained: enum integer-value / integer-column-type
validation and the duplicate-constant check (the deleted command had neither).

### Historical context (the finding that drove the consolidation)

The two commands regenerated model docblocks + enum constants in **incompatible formats** and
both wrote in-place into the same model files - the "dual implementations cause PTSD" situation
CLAUDE.md CRITICAL DIRECTIVE #1 forbids. Observed differences at the time:

| | document_models (deleted) | constants_regenerate (survivor, pre-B4.8) |
|--|-----------------|----------------------|
| pre-class docblock | `_AUTO_GENERATED_ Database type hints` | `_AUTO_GENERATED_` |
| enum method tags | `field__enum()` (BEM, matches JS mirror) | `field_enum()` (single underscore) |
| property types | `int` / `string` (string-time philosophy) | `\Carbon\Carbon` (violated the date/time philosophy) |
| enum constants location | `_AUTO_GENERATED_ Enum constants` block after brace | `/** __AUTO_GENERATED: */ ... /** __/AUTO_GENERATED */` fence |
| wiring | run manually | called by `Maint_Migrate` on every migrate |

The B4.8 consolidation adopts the BEM / typed / string-time / fence-safe form (all previously
only in document_models) INTO the wired command, so the migrate pipeline now emits the modern
format directly. Files still in the old fenced (B2) layout are migrated to the canonical (B1)
block in a single pass by `Model_Codegen_Rewriter::rewrite()` (see section below).

### The dual-format MIGRATION is handled in-place (retained)

The duplicate-const fatal - a fresh B1 const block INSERTED while an old fenced const block was
LEFT in place ("Cannot redefine class constant", ~78 downstream models) - is fixed in
`Model_Codegen_Rewriter::rewrite()`: the enum-constants region is recognized in EITHER form. A
B2 fence whose content is enum constants is treated as the old home for that region and MIGRATED
in a single pass - the fence is removed and the canonical B1 block emitted in its place, so the
file ends with exactly ONE const set. Two honesty guards back this:

1. **Classification is fail-loud.** A fence is migrated only when every non-blank line between
   its markers belongs to a class-constant declaration (decided structurally on the AST). An
   EMPTY fence is inert and left untouched. A fence holding any NON-constant content cannot be
   classified and the file is skipped loudly, never guessed.
2. **A parse-guard is unconditional.** After the self-check, the candidate is re-parsed and its
   class constants are walked for duplicate NAMES; any duplicate refuses the write.

## 2. Whitespace-tolerant code-loss guard removed (RESOLVED)

The survivor's old string-splice rewrite renormalized whitespace outside the fences, so it
could not satisfy the strict byte-exact self-check and was given a whitespace-tolerant
`assert_no_handwritten_code_loss()` guard instead. B4.8 replaced the string-splice with the
surgical `Model_Codegen_Rewriter::rewrite()`, which preserves every non-region byte verbatim
and uses the strict `assert_no_handwritten_change()`. The whitespace-tolerant guard (and its
private `__significant_lines()` helper) had no remaining caller and was deleted, along with its
two tests. No framework behavior was changed to make a test pass.
