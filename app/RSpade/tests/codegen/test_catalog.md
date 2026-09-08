# Codegen - Test Catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CODEGEN-01 | Hand-written property between brace and first fence survives (`$realtime`, `$type_ref_columns`) | php | CR fixture | both present in result | implemented | 2026-07-15 |
| CODEGEN-02 | Hand-written method after the fences survives | php | CR fixture | `hello()` + body present | implemented | 2026-07-15 |
| CODEGEN-03 | Trailing / inline hand-written comments survive | php | CR fixture | both comments present | implemented | 2026-07-15 |
| CODEGEN-04 | Hand-written bytes are byte-identical after a rewrite (strict) | php | CR fixture | strip(orig) === strip(result) | implemented | 2026-07-15 |
| CODEGEN-05 | Stale generated docblock/const is regenerated inside the fences | php | CR fixture | new column/consts in, stale out | implemented | 2026-07-15 |
| CODEGEN-06 | Rewrite output is valid PHP | php | CR fixture | parses | implemented | 2026-07-15 |
| CODEGEN-07 | Rewrite is idempotent | php | CR fixture | run twice === run once | implemented | 2026-07-15 |
| CODEGEN-08 | Insertion into a model with no existing auto regions | php | bare model | docblock before class, const after brace, hand-written preserved | implemented | 2026-07-15 |
| CODEGEN-09 | Old-format B2 fence holding enum consts is MIGRATED (removed) into a canonical B1, not stacked beside a fresh B1 | php | old-format fixture (fence, no B1) | consts appear once, fence gone, hand-written byte-identical, parses | implemented | 2026-07-15 |
| CODEGEN-21 | Old-format migration is idempotent (2nd pass on migrated output is a no-op) | php | old-format fixture | run twice === run once | implemented | 2026-07-15 |
| CODEGEN-22 | Doubly-corrupt (stale B1 + old-format consts fence) consolidates to ONE canonical block | php | mixed fixture | one const set, stale/fence gone, parses | implemented | 2026-07-15 |
| CODEGEN-23 | An EMPTY old-format fence (already-migrated state) is left untouched (no churn) | php | B1 + empty fence | fence preserved, consts once | implemented | 2026-07-15 |
| CODEGEN-24 | A fence holding non-constant content cannot be classified and fails loud | php | fence with a hand-written member inside | RuntimeException ("cannot be classified") | implemented | 2026-07-15 |
| CODEGEN-25 | Parse-guard refuses a candidate that would declare a duplicate class constant | php | constants block with a repeated name | RuntimeException ("Parse-guard FAILED") | implemented | 2026-07-15 |
| CODEGEN-10 | strip_owned_regions removes every marker family | php | CR fixture | no `_AUTO_GENERATED_` left, hand-written kept | implemented | 2026-07-15 |
| CODEGEN-11 | strip of a model with no fences returns the whole source | php | plain model | unchanged | implemented | 2026-07-15 |
| CODEGEN-12 | Fail loud: two pre-class auto docblocks | php | duplicated docblock | RuntimeException | implemented | 2026-07-15 |
| CODEGEN-13 | Fail loud: unbalanced fence markers | php | open without close | RuntimeException | implemented | 2026-07-15 |
| CODEGEN-14 | Fail loud: target class not found | php | wrong basename | RuntimeException | implemented | 2026-07-15 |
| CODEGEN-15 | Fail loud: parse error | php | malformed PHP | RuntimeException | implemented | 2026-07-15 |
| CODEGEN-16 | Self-check aborts when a hand-written line is dropped (strict) | php | seam-broken candidate | RuntimeException | implemented | 2026-07-15 |
| CODEGEN-17 | Self-check passes for identical hand-written content | php | no-op candidate | no throw | implemented | 2026-07-15 |
| CODEGEN-20 | End-to-end command against template app leaves non-fence bytes unchanged | cli | run `rsx:constants:regenerate`, git diff | only auto-region lines change | deferred (writer verified manually; a DB-backed cli test would re-assert) | 2026-07-30 |
| CODEGEN-30 | B-108: a framework file importing a class that rsx/ overrides gets the import REWRITTEN to the rsx/ FQCN, never dropped | php | synthetic file map with both twins + a consumer type-hinting the class | `use Rsx\Models\...;` present, framework FQCN gone | implemented (Php_Fixer_Class_Override_Import_Test) | 2026-09-08 |
| CODEGEN-31 | B-108: an already-correct `Rsx\` import of an overridden class survives two fixer passes - the delete pass and the re-add pass agree | php | same map, import already correct | import unchanged after two passes | implemented (Php_Fixer_Class_Override_Import_Test) | 2026-09-08 |
| CODEGEN-32 | B-108: resolution is deterministic - rsx/ wins whatever order the file map is iterated in | php | file map reversed | same rewrite | implemented (Php_Fixer_Class_Override_Import_Test) | 2026-09-08 |
| CODEGEN-26 | Command output spans CTI detail columns `(detail: <table>)`, emits BEM/typed enum members, and DATE/DATETIME as `string` not Carbon | php | `build_metadata(Party_Model)` | detail lines + `type_id__label` + `string $created_at` present, no `\Carbon\Carbon` | implemented (Constants_Regenerate_Metadata_Test) | 2026-07-30 |

Php_Fixer source safety (backlog B-68 + the relationship-attribute defect), via
`Php_Fixer_Import_Safety_Test`. Php_Fixer REWRITES SOURCE on every build, so these are
damage-prevention tests, not reporting tests.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| FIXER-REF-01 | A trait `use` in a class body counts as a class reference (THE B-68 shape) | php | `class M { use Portal_Authorizable; }` | name returned by the scanner | implemented | 2026-08-11 |
| FIXER-REF-02 | `use A, B;` yields both traits | php | two traits in one statement | both names returned | implemented | 2026-08-11 |
| FIXER-REF-03 | The `use A { foo as bar; }` block form is found | php | conflict-resolution form | name returned | implemented | 2026-08-11 |
| FIXER-REF-04 | A typed property counts as a reference, incl. `?Type` | php | `protected Portal_User_Model $user; private ?Site_Model $site;` | both names returned | implemented | 2026-08-11 |
| FIXER-REF-05 | A closure's `use ($captured)` is NOT a trait use | php | closure capture list | name absent | implemented | 2026-08-11 |
| FIXER-GUARD-01 | A PARTIAL class index blocks all import deletion (guard 2) | php | app entries present, framework `class` metadata missing | index reported unhealthy | implemented | 2026-08-11 |
| FIXER-GUARD-02 | A class in an unscanned framework zone is proof it exists (guard 1) | php | `Maint_Migrate` (unscanned Commands/) vs a made-up name | true, then false | implemented | 2026-08-11 |
| FIXER-GUARD-03 | The register-phase set holds the four classes the field report named (guard 3) | php | `Pre_Autoload_Reachability::contains_file()` for the BundleIntegration files | all in the set | implemented | 2026-08-27 |
| FIXER-GUARD-04 | The set is DERIVED, not universal - it discriminates | php | `Api_Catalog.php`, `rsx/models/client_model.php` | both outside the set | implemented | 2026-08-27 |
| FIXER-GUARD-05 | A register-phase file never loses an import, even on an unconditional delete verdict | php | the `Route` rule against each of the four files | kept (false) | implemented | 2026-08-27 |
| FIXER-GUARD-06 | POSITIVE CONTROL: a genuinely redundant import elsewhere is still stripped | php | the `Route` rule against an app file and a non-register-phase framework file | removed (true) | implemented | 2026-08-27 |
| FIXER-ATTR-01 | An attribute ARGUMENT does not hide `#[Relationship]` (the field repro) | php | `#[Relationship]` + `#[Auth('is_logged_in')]` before the function | detected | implemented | 2026-08-11 |
| FIXER-ATTR-02 | Nested brackets in a later attribute do not break the group walk | php | `#[Api_Param('x', opts: [1, 2])]` | detected | implemented | 2026-08-11 |
| FIXER-ATTR-03 | The ordinary single-attribute case still works | php | plain `#[Relationship]` | detected | implemented | 2026-08-11 |
| FIXER-ATTR-04 | Absence is still reported as absence (the fixer must still ADD it) | php | no `#[Relationship]` present | not detected | implemented | 2026-08-11 |
| FIXER-ATTR-05 | Existing duplicates collapse to one, other attributes untouched | php | three stacked `#[Relationship]` + `#[Auth]` | 1 remaining, `#[Auth]` kept | implemented | 2026-08-11 |
| FIXER-ATTR-06 | De-duplication does not merge across separate methods | php | two methods, one attribute each | 2 remaining | implemented | 2026-08-11 |

FIXER-ATTR-01 is the defect a downstream field report raised on 2026-08-08: the old detector walked backwards
token-by-token against an allow-list that did not include `T_CONSTANT_ENCAPSED_STRING`, so an
attribute argument aborted the walk and a duplicate `#[Relationship]` was inserted on EVERY
build (observed 1 -> 3 -> 7). Detection now walks whole attribute GROUPS by bracket count, so
argument contents are structurally irrelevant.

FIXER-GUARD-03..06 are a downstream field report from 2026-08-25. The fixer stripped the imports out of
`Jqhtml_BundleIntegration`, `IntegrationRegistry`, `Database_BundleIntegration` and
`Controller_BundleIntegration` - four classes the service-provider REGISTER phase loads, long before
`Rsx_Framework_Provider::boot()` calls `Autoloader::register()`. Composer resolves FQCNs and nothing else,
so `extends BundleIntegration_Abstract` then resolved into each file's own namespace and every artisan
command and HTTP request died at boot - including the build that would have undone the edit. Guard 3
derives that set from disk (`Pre_Autoload_Reachability`: the two config lists the register phase iterates,
closed transitively over every `App\RSpade\` name those files contain) and suppresses deletions inside it.
Deliberately one-directional, like guard 2: rewrites and additions still run.

## Model_Stub_Appends_Test (php)

The generated Base_*_Model.js DECLARES a model's derived properties ($appends). Without it a
derived property arrives on the fetched record with no declared surface on the stub at all - no
autocomplete, and nothing telling a reader the property exists. Fixtures: Appends_Fixture_Model
(two appended names, no table) and No_Appends_Fixture_Model (the negative case).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CODEGEN-APPENDS-DECLARED | each $appends entry is named as a JSDoc @property in the class docblock, in declaration order | php | Appends_Fixture_Model | both @property lines, display_id before is_flagged, @Instantiatable intact | implemented | 2026-09-08 |
| CODEGEN-APPENDS-NOT-A-COLUMN | a derived property never becomes a field_length entry - it has no column behind it | php | Appends_Fixture_Model | field_length() still emitted; the name is in no length map | implemented | 2026-09-08 |
| CODEGEN-APPENDS-NONE | a model declaring no $appends gains no lines - the feature is opt-in by declaration | php | No_Appends_Fixture_Model | no @property anywhere in the stub | implemented | 2026-09-08 |
| CODEGEN-APPENDS-REAL | the framework model that uses the pattern gets all four names declared | php | File_Attachment_Model | is_image / is_video / is_document / can_open_inline declared | implemented | 2026-09-08 |
| CODEGEN-SPLIT-REFLECTS-BASE | a SPLIT model's stub is generated for the concrete and declares the members its abstract base holds | php | Split_Fixture_Model (empty shell) + Split_Fixture_Model_Abstract ($appends + constants) | base_display_id declared, SPLIT_FIXTURE_STATE_OPEN present, field_length() emitted | implemented | 2026-09-08 |
| CODEGEN-SPLIT-STALENESS-KEY | the staleness key that decides whether to rewrite a stub covers the whole LINEAGE, stopping before Rsx_Model_Abstract - a key over the concrete alone cannot move when the base moves | php | Model_Lineage_Fingerprint over Split_Fixture_Model | concrete AND abstract in the file list, Rsx_Model_Abstract absent, a different lineage gives a different hash | implemented | 2026-09-08 |

CODEGEN-SPLIT-* pin B-109's codegen half. A core model carries its members on an abstract base
and ships a three-line concrete an application replaces, so everything the build derives from a
model is a function of the whole lineage. Fixtures: Split_Fixture_Model_Abstract (the base, with
an appended property and two constants) and Split_Fixture_Model (the shell).
