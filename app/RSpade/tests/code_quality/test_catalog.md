# Code Quality Rules - Test Catalog

PHP-PARENT-CHAIN-01 (ParentCallChain_CodeQualityRule), via `Parent_Call_Chain_Rule_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| PCC-STATIC-MISSING | a static override with no `parent::<method>()` call is flagged | php | fixture: static override, no parent call | 1 violation | implemented | 2026-08-03 |
| PCC-INSTANCE-MISSING | a non-static override with no `parent::<method>()` call is flagged | php | fixture: instance override, no parent call | 1 violation | implemented | 2026-08-03 |
| PCC-PARENT-CALL-CLEAN | an override that genuinely calls `parent::<method>()` is clean | php | fixture: override with a real parent call | 0 violations | implemented | 2026-08-03 |
| PCC-REPLACEABLE-INSTANCE | `#[Replaceable]` on an instance parent method clears the override | php | fixture: parent method `#[Replaceable]`, instance override, no parent call | 0 violations | implemented | 2026-08-03 |
| PCC-REPLACEABLE-STATIC | `#[Replaceable]` on a static parent method clears the override | php | fixture: parent method `#[Replaceable]`, static override, no parent call | 0 violations | implemented | 2026-08-03 |
| PCC-4LEVEL-CHAIN | method declared only at top A (B, C do not redeclare); D overrides -> D must chain | php | 4-level chain fixture, D lacks parent call | 1 violation | implemented | 2026-08-03 |
| PCC-4LEVEL-REPLACEABLE-TOP | `#[Replaceable]` on A::m clears D through non-declaring links (nearest-declarer resolves to A) | php | 4-level chain, A::m `#[Replaceable]`, D no parent call | 0 violations | implemented | 2026-08-03 |
| PCC-INTERMEDIATE-REDECLARE | an intermediate concrete re-declaration (no attr) re-establishes the chain obligation for its descendants | php | A::m `#[Replaceable]`, B redeclares m concretely, C overrides no parent call | 1 violation | implemented | 2026-08-03 |
| PCC-ABSTRACT-EXEMPT | an abstract parent method cannot be called, so the child override is exempt | php | fixture: abstract parent method, child override | 0 violations | implemented | 2026-08-03 |
| PCC-VENDOR-UNFLAGGED | overriding a vendor method (ancestor not in the manifest) is not flagged | php | fixture: override of a vendor-only method | 0 violations | implemented | 2026-08-03 |
| PCC-WRONG-NAME | `parent::other()` does NOT satisfy a requirement to call `parent::this()` | php | fixture calling a different parent method | 1 violation | implemented | 2026-08-03 |
| PCC-EXCEPTION-MARKER | `@PHP-PARENT-CHAIN-01-EXCEPTION` suppresses a flagged edge case | php | fixture with the exception marker | 0 violations | implemented | 2026-08-03 |

JS-EVAL-01 (EvalUsage_CodeQualityRule), via `Eval_Usage_Rule_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| EVAL-SHAPES | every eval() shape reported from the field is caught, whatever surrounds it | php | fixtures: guarded try/catch assignment, split('.')[0] lookup, bare eval of an arg | 1 violation each | implemented | 2026-08-24 |
| EVAL-FUNCTION-CTOR | new Function() is caught - CSP reports it as `blocked-uri: eval` exactly like eval() | php | fixture: new Function('u','return import(u)') | 1 violation, message names new Function() | implemented | 2026-08-24 |
| EVAL-PER-LINE | every occurrence is reported on its own line, not just the first | php | fixture: eval on line 2, new Function on line 3 | 2 violations, lines 2 and 3 | implemented | 2026-08-24 |
| EVAL-COMMENTS-STRINGS | the construct named in a comment or a string literal is not a call | php | fixture: eval in //, in /* */, and inside a double-quoted string | 0 violations | implemented | 2026-08-24 |
| EVAL-IDENTIFIER-SUFFIX | an identifier merely ENDING in eval belongs to somebody else | php | fixture: medieval(), this._eval(), that.retrieval(), Obj.eval() | 0 violations | implemented | 2026-08-24 |
| EVAL-UNBUNDLED | scope is bundled JS - a standalone node script has no browser and no CSP consequence | php | fixture at bin/standalone_driver.js (no scan-directory segment) | 0 violations | implemented | 2026-08-24 |
| EVAL-THIRD-PARTY | vendor/ and node_modules/ are not ours to rewrite | php | fixture under rsx/node_modules/ and rsx/vendor/ | 0 violations | implemented | 2026-08-24 |
| EVAL-RESOLUTION-TEACHES | the remediation names the supported API, not just the prohibition | php | any flagged fixture | suggestion contains Manifest.get_class_by_name, rsx:man csp, JS-EVAL-01-EXCEPTION | implemented | 2026-08-24 |

JS-DOM-01 (DomMethod_CodeQualityRule), the `<script>` cases, via
`Dom_Method_Script_Rule_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| DOM-SCRIPT-NATIVE | `createElement('script')` is flagged TOWARD the external-resource registry, not toward jQuery | php | fixture: single-quoted native script creation | 1 violation, high, suggestion names Rsx.load_external + externals.php + _evalUrl, never "jQuery element creation" | implemented | 2026-08-31 |
| DOM-SCRIPT-QUOTING | the double-quoted spelling is the same violation | php | fixture: `createElement("script")` | 1 violation, high, same remediation | implemented | 2026-08-31 |
| DOM-SCRIPT-JQUERY | the reverse warning: jQuery script construction is flagged too (it inserts no live script node) | php | fixture: `$('<script src=...>')` | 1 violation, high, same remediation | implemented | 2026-08-31 |
| DOM-GENERIC-UNCHANGED | every other tag keeps the generic jQuery advice at medium | php | fixture: `createElement('div')` | 1 violation, medium, jQuery advice, no Rsx.load_external | implemented | 2026-08-31 |
| DOM-THIRD-PARTY | vendored bundles and the mirrored CDN cache are not ours to rewrite, and are full of literal `<script>` strings | php | fixtures under rsx/theme/vendor/ and rsx/resource/.cdn-cache/ | 0 violations | implemented | 2026-08-31 |
| DOM-COMMENT-SAFE | prose naming the construct is not code (the structural test runs against the sanitized line) | php | fixture: the call inside a `//` comment | 0 violations | implemented | 2026-08-31 |
| DOM-EXCEPTION-MARKER | `@JS-DOM-01-EXCEPTION` suppresses the rule for the file, via the checker's generic mechanism | php | two fixtures through `CodeQualityChecker::check_file()`, one marked | control flagged, marked file clean | implemented | 2026-08-31 |

PHP-MODEL-FETCH-01 (ModelFetchAuthCheck) lineage handling, via
`Model_Fetch_Auth_Lineage_Rule_Test`. PHP-AUTH-01 (EndpointAuthCheck) was RETIRED
with the declarative auth-gate flip and its rule file deleted; gate presence is
enforced by the manifest build (see the `auth_gates` concern).

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MFA-LINEAGE-PREMISE | a site-scoped model reaches Rsx_Model_Abstract only through an intermediate abstract, which the lineage walk still resolves | php | `Portal_Notification_Model` lineage | immediate parent `Rsx_Site_Model_Abstract`, `php_is_subclass_of` true | implemented | 2026-08-05 |
| MFA-NON-MODEL-SKIP | a class outside the model lineage is not checked | php | fixture with a non-model class name carrying `#[Ajax_Endpoint]` | 0 violations | implemented | 2026-08-07 |
| MFA-HEURISTIC-RETIRED | an unguarded `fetch()` body is NOT flagged by this rule - gate presence is a manifest-build fatal, not a lint heuristic | php | fixture fetch() with no auth check | 0 violations | implemented | 2026-08-07 |
| MFA-MODEL-ENDPOINT | a model-borne `#[Ajax_Endpoint]` (unreachable dead security metadata) is flagged | php | fixture model method with `#[Ajax_Endpoint]` | 1 violation, medium, message names the no-effect reason | implemented | 2026-08-05 |
| MFA-FETCH-ATTR-DISTINCT | `#[Ajax_Endpoint_Model_Fetch]` is not mistaken for a model-borne endpoint | php | fixture gated fetch() with the ORM attribute | 0 violations | implemented | 2026-08-05 |
| EAC-CONTROLLER-FLAGGED | RETIRED with PHP-AUTH-01 (2026-08-07) - replaced by the manifest-build validation matrix (`Auth_Validation_Test`) | php | - | - | retired | 2026-08-07 |
| EAC-NON-CONTROLLER-SKIP | RETIRED with PHP-AUTH-01 (2026-08-07) | php | - | - | retired | 2026-08-07 |

SESSION-ID-01 (SessionIdNullCheck_CodeQualityRule), via
`Session_Id_Null_Check_Rule_Test`. A null-ish/zero-ish test on a
`get_session_id()` result is dead code (the call always creates a session and is
`: int`); `has_session()` is the question the author meant to ask.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SID-NULL-CMP | `=== null` / `!== null` / `== null` / `!= null`, either operand order, is flagged | php | 5 fixture bodies comparing the call to null | 1 violation each | implemented | 2026-08-09 |
| SID-ZERO-CMP | `=== 0` / `== 0` / `!== 0` / `!= 0` / `>` / `>=` / `<` / `<= 0` is flagged | php | 9 fixture bodies comparing the call to 0 | 1 violation each | implemented | 2026-08-09 |
| SID-ABSENCE-FUNCS | `is_null()`, `empty()`, `isset()` on the result is flagged | php | fixture bodies wrapping the call / the tracked variable | 1 violation each | implemented | 2026-08-09 |
| SID-COALESCE-TERNARY | `??`, `?:` and a full ternary condition on the result is flagged | php | 3 fixture bodies | 1 violation each | implemented | 2026-08-09 |
| SID-TRUTHINESS | `!call`, `if (call)`, `while (call)` is flagged | php | 3 fixture bodies | 1 violation each | implemented | 2026-08-09 |
| SID-VARIABLE-FORM | a variable assigned exactly once from the call is tracked, and each test on it is flagged once | php | fixture assigning `$sid` then testing it | 1 (and 2 for two tests) violations | implemented | 2026-08-09 |
| SID-REBOUND-VAR | a variable rebound after the call is NOT tracked (conservative: the rule is fatal) | php | fixture reassigning `$sid` before the test | 0 violations | implemented | 2026-08-09 |
| SID-SCOPED-TRACKING | the same variable name assigned once in each of two methods is tracked per function body | php | fixture class with two methods | 2 violations | implemented | 2026-08-09 |
| SID-PORTAL-FACADE | `Portal_Session::get_session_id()` is covered and the remediation names the portal `has_session()` | php | fixture using the portal facade | 1 violation naming Portal_Session | implemented | 2026-08-09 |
| SID-FQCN-CALL | a namespace-qualified call resolves to the staff facade | php | fixture using the FQCN | 1 violation | implemented | 2026-08-09 |
| SID-LEGIT-USE | arithmetic, string use, query arg, property assign, comparison to ANOTHER id are not flagged | php | 5 fixture bodies | 0 violations | implemented | 2026-08-09 |
| SID-OTHER-ACCESSORS | `get_site_id()` / `get_csrf_token()` / an unrelated class are not flagged | php | 3 fixture bodies | 0 violations | implemented | 2026-08-09 |
| SID-FACADE-EXCLUDED | `Core/Session/Session.php` and `Core/Portal/Portal_Session.php` are excluded (they produce the int) | php | fixtures written at those relative paths | 0 violations | implemented | 2026-08-09 |
| SID-CQ-EXCLUDED | code-quality meta-code (`/CodeQuality/`) is excluded | php | fixture written under a CodeQuality path | 0 violations | implemented | 2026-08-09 |
| SID-EXCEPTION-MARKER | `@SESSION-ID-01-EXCEPTION` suppresses (honored by the rule itself, since the manifest driver does not apply it) | php | fixture with the file-level marker | 0 violations | implemented | 2026-08-09 |
| SID-FATAL-WIRING | the rule runs at manifest scan, is per-file, and emits `critical` - the combination that aborts the build | php | rule instance + one violating fixture | flags true, severity critical | implemented | 2026-08-09 |

## SEALED-01 - sealed property redeclaration (Sealed_Property_Rule_Test)

Drives the rule's `evaluate_class_properties()` seam over synthetic fixture files forming
real inheritance chains, so lineage walking, seal discovery and exception markers are all
exercised over real nikic/php-parser output without touching the manifest.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SEALED-DIRECT | a direct subclass redeclaring a `#[Sealed]` property is flagged critical, naming the property and the sealing class | php | parent with a sealed property, child redeclaring it | 1 critical violation | implemented | 2026-08-09 |
| SEALED-DEPTH | the seal binds EVERY descendant, not just direct children | php | 3-level chain, leaf redeclares | 1 violation | implemented | 2026-08-09 |
| SEALED-SAME-VALUE | redeclaring with the SAME value is still a violation (one declaration site is the point) | php | child redeclares with the parent's value | 1 violation | implemented | 2026-08-09 |
| SEALED-GROUPED | a grouped `public $a, $b;` node flags only the sealed name | php | child declaring one sealed + one free name in one statement | 1 violation, naming the sealed one | implemented | 2026-08-09 |
| SEALED-UNSEALED | an ordinary (unsealed) property may be redeclared | php | parent + child both declaring the same free property | 0 violations | implemented | 2026-08-09 |
| SEALED-OTHER-NAME | declaring a differently-named property is not a violation | php | child declaring an unrelated property | 0 violations | implemented | 2026-08-09 |
| SEALED-DECLARER | the class that declares the seal is not in violation of its own seal | php | the sealing class evaluated against its ancestry | 0 violations | implemented | 2026-08-09 |
| SEALED-EXCEPTION | `@SEALED-01-EXCEPTION` on the line above the declaration suppresses | php | child with the marker | 0 violations | implemented | 2026-08-09 |
| SEALED-NO-PROPS | a subclass declaring no properties cannot violate a seal (early out) | php | child with only a method | 0 violations | implemented | 2026-08-09 |

## ACTOR-01 - actor model contract

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| ACTOR-STAMP-TARGET | a class named by `AUDIT_ACTOR_MODELS` that does not extend the actor layer fatals the manifest build | cli | temporarily point the constant at a plain model | build aborts with ACTOR-01 | deferred | 2026-08-09 |
| ACTOR-SOFTDELETE | a concrete actor that lost the SoftDeletes trait is flagged | cli | an actor whose lineage drops the trait | 1 violation | deferred | 2026-08-09 |

Both ACTOR-01 rows are DEFERRED rather than implemented: the rule reads the real
`Rsx_Model_Abstract::AUDIT_ACTOR_MODELS` and the real manifest lineage, so a permanent test
needs the fixture-file harness the rsx:check runner does not have yet (same blocker as
`db-lint-01`). Both branches were demonstrated manually against a temporary probe model
during implementation, and the runtime half of check 1 is covered permanently by
`Actor_Model_Test::test_every_stamp_target_extends_the_actor_layer`.


ARTISAN-SPAWN-01 (ArtisanSubprocessSpawn_CodeQualityRule), via
`Artisan_Subprocess_Spawn_Rule_Test`. The rule requires `php artisan` subprocesses to go
through `Rsx_Artisan`, so a child inherits its parent's lock group instead of deadlocking
against it.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SPAWN-01 | Every banned spawn function is flagged when it runs artisan | php | fixtures for passthru/shell_exec/exec_safe/exec/system/popen/proc_open | 1 violation each | implemented | 2026-08-11 |
| SPAWN-02 | The artisan path held in a VARIABLE is flagged (no literal contains the word) | php | `exec_safe('php ' . escapeshellarg($artisan) . ' --version', ...)` | 1 violation | implemented | 2026-08-11 |
| SPAWN-03 | A subprocess that is not artisan is not this rule's business | php | `passthru('composer dump-autoload --optimize')` | 0 violations | implemented | 2026-08-11 |
| SPAWN-04 | In-process `Artisan::call()` is not flagged (same process, already reentrant) | php | `\Artisan::call('rsx:clean')` | 0 violations | implemented | 2026-08-11 |
| SPAWN-05 | The sanctioned helper does not flag as the thing it replaces | php | `Rsx_Artisan::passthru('rsx:clean', [...])` | 0 violations | implemented | 2026-08-11 |
| SPAWN-06 | Comments and string literals quoting the pattern are not violations | php | `//`, block comment, and a string containing the banned call | 0 violations | implemented | 2026-08-11 |
| SPAWN-07 | A METHOD named like a spawn function is not the global function | php | `$runner->passthru(...)`, `Some_Class::passthru(...)` | 0 violations | implemented | 2026-08-11 |
| SPAWN-08 | The reported line is the REAL file line (regression: sanitizer indices drift) | php | heredoc ahead of the call, so sanitized indices diverge | violation reports line 14 | implemented | 2026-08-11 |

SPAWN-08 exists because the first implementation scanned `FileSanitizer::sanitize_php()['lines']`,
which is not index-aligned with the file (194 entries for a 182-line file) and reported line 163
for a call on line 153. The rule now tokenizes the file and takes the line from the token.
Backlog **B-86** tracks the same latent defect in the other rules that still use that pattern.


RELATIONSHIP-OVERRIDE-01 (RelationshipOverride_CodeQualityRule), via
`Relationship_Override_Rule_Test`. An override of an ancestor's `#[Relationship]` method
must redeclare the attribute - `get_relationships()` unions the lineage, so an
unattributed override splits the declaration from the implementation.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| REL-OVR-01 | an unattributed override of an ancestor relationship method is flagged | php | fixture override, no attribute, ancestor map naming the method | 1 violation, critical, names method + ancestor | implemented | 2026-08-13 |
| REL-OVR-02 | redeclaring `#[Relationship]` clears it | php | fixture override carrying the attribute | 0 violations | implemented | 2026-08-13 |
| REL-OVR-03 | a method no ancestor declares as a relationship is not judged | php | ancestor map naming a different method | 0 violations | implemented | 2026-08-13 |
| REL-OVR-04 | a class with no relationship-declaring ancestors is clean | php | empty ancestor map | 0 violations | implemented | 2026-08-13 |
| REL-OVR-05 | `@RELATIONSHIP-OVERRIDE-01-EXCEPTION` above the declaration suppresses it | php | fixture with the marker | 0 violations | implemented | 2026-08-13 |
| REL-OVR-06 | only the overriding method is flagged, not its neighbours | php | two own methods, one matching | 1 violation, names the overriding one | implemented | 2026-08-13 |

End-to-end (manual, during implementation): planting `public function created_by()` without
the attribute on `Client_Model` aborted `rsx:manifest:build` with the rule's message at
`rsx/models/client_model.php:175`; adding `#[Relationship]` to the same method built clean.

ABSTRACT-ATTR-01 (AbstractRegistryAttribute_CodeQualityRule), via
`Abstract_Registry_Attribute_Rule_Test`. An attribute that enrols its DECLARING class into
a manifest-built runtime registry may not sit on an abstract class - the registration
either reaches no subclass or becomes a phantom entry keyed to the abstract.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| ABS-ATTR-01 | a registry attribute on an abstract class METHOD is flagged | php | fixture abstract with `#[Task]` method | 1 violation, critical, names attribute + method | implemented | 2026-08-13 |
| ABS-ATTR-02 | class-level and method-level declarations each report | php | `#[Route]` on both | 2 violations | implemented | 2026-08-13 |
| ABS-ATTR-03 | every member of the forbidden set fires (the constant is the contract) | php | all 16 attribute names in turn | 1 violation each | implemented | 2026-08-13 |
| ABS-ATTR-04 | lineage-consumed and class-descriptive attributes are NOT flagged | php | Relationship, Auth_Check, Replaceable, Instantiatable, Monoprogenic, Sealed | 0 violations each | implemented | 2026-08-13 |
| ABS-ATTR-05 | `@ABSTRACT-ATTR-01-EXCEPTION` above the declaration suppresses it | php | fixture with the marker | 0 violations | implemented | 2026-08-13 |
| ABS-ATTR-06 | a forbidden attribute beside an allowed one reports exactly once | php | `#[Replaceable]` + `#[Task]` on one method | 1 violation naming Task | implemented | 2026-08-13 |

End-to-end (manual, during implementation): planting a class-level `#[Health_Check]` on
`Rsx_Site_Model_Abstract` aborted `rsx:manifest:build` with the rule's message; removing it
built clean. The tree carries zero violations of either rule (audit Probe A).

MODEL-FETCH-TRASHED-01 (ModelFetchTrashed_CodeQualityRule), via
`Model_Fetch_Trashed_Rule_Test`. `withTrashed()` inside a model's `fetch()`/`portal_fetch()`
body: it misses the batch preload (which runs under default scopes) and breaks the client's
entitlement to assume an ORM-fetched record is not soft-deleted. Ordinary `rsx:check` rule
(not manifest-fatal); the sanctioned path for a deleted record is an explicit
`#[Ajax_Endpoint]` (worked example: `Frontend_Clients_Controller::fetch_deleted`).

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MFT-01 | a `withTrashed()` lookup in `fetch()` is flagged at the call line | php | fixture model fetch body | 1 violation, high, correct line, message names fetch() | implemented | 2026-08-13 |
| MFT-02 | the portal surface is checked too | php | fixture `portal_fetch()` body | 1 violation naming portal_fetch() | implemented | 2026-08-13 |
| MFT-03 | a call nested in a closure inside the body still reports | php | fixture with `without_site_scope(function () {...})` | 1 violation | implemented | 2026-08-13 |
| MFT-04 | a default-scoped `static::find($id)` body is clean | php | fixture plain find | 0 violations | implemented | 2026-08-13 |
| MFT-05 | `withTrashed()` in another method on the same model is legitimate | php | fixture helper method | 0 violations | implemented | 2026-08-13 |
| MFT-06 | a class outside the model lineage has no ORM fetch surface | php | metadata class `Rsx_Test_Abstract` | 0 violations | implemented | 2026-08-13 |
| MFT-07 | `@MODEL-FETCH-TRASHED-01-EXCEPTION` on the line above suppresses | php | fixture with the marker | 0 violations | implemented | 2026-08-13 |
| MFT-08 | the same marker on the call line suppresses | php | fixture with a trailing marker comment | 0 violations | implemented | 2026-08-13 |

End-to-end (during implementation): the six template/framework models that carried
`withTrashed()->find()` in `fetch()` were converted first, so `rsx:check` reports zero
violations of this rule across the tree.

MIGRATION-MODEL-01 (MigrationModelReference_CodeQualityRule), via
`Migration_Model_Reference_Rule_Test`. A migration must replay from scratch forever, so
it may not name a model class or `Type_Ref_Registry`; type-ref ids come from a
get-or-create against `_type_refs` with the class-name STRING. Ordinary `rsx:check` rule
(not manifest-fatal); scope is the migration directories, which the manifest does not
index, so the rule enumerates `MigrationPaths::get_all_migration_files()` itself through
the `check_migrations()` entry point.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MMR-USE | a `use ..._Model;` import couples the migration to current code | php | fixture migration importing `Client_Model` | 1 violation naming the class | implemented | 2026-08-30 |
| MMR-REGISTRY | `Type_Ref_Registry::class_to_id()` - the field offender - is flagged, import and call | php | fixture with the import and the call | 2 violations | implemented | 2026-08-30 |
| MMR-STATIC | a static call on a model class symbol is flagged | php | `Foo_Model::find(1)` | 1 violation naming the model | implemented | 2026-08-30 |
| MMR-SPELLINGS | `new`, `instanceof`, `::class` and a fully-qualified name are all class symbols | php | 4 fixtures | 1 violation each | implemented | 2026-08-30 |
| MMR-PER-LINE | every reference reports on its own line | php | two model calls on consecutive lines | 2 violations, consecutive lines | implemented | 2026-08-30 |
| MMR-CLOSURE-CLEAN | the prescribed `_type_refs` closure, with `'Foo_Model'` as a string literal in SQL, is clean | php | fixture using the closure | 0 violations | implemented | 2026-08-30 |
| MMR-COMMENTS | a commented-out reference documents the conversion; it does not perform it | php | `//` and `/* */` forms | 0 violations | implemented | 2026-08-30 |
| MMR-PROPERTY | `$row->Foo_Model` is a property, not a class symbol | php | fixture property access | 0 violations | implemented | 2026-08-30 |
| MMR-EXCEPTION | a rationale'd `@MIGRATION-MODEL-01-EXCEPTION` suppresses the file | php | fixture with the marker and a rationale | 0 violations | implemented | 2026-08-30 |
| MMR-EXCEPTION-BARE | a marker with NO rationale is itself the violation | php | fixture with a bare marker | 1 violation saying "no rationale" | implemented | 2026-08-30 |
| MMR-TREE | both migration directories are clean, through the real `check_migrations()` entry point | php | the tree | 0 violations | implemented | 2026-08-30 |

The one sanctioned exception in the tree is
`rsx/resource/migrations/2026_07_16_143237_import_sample_documents.php`: template seed
data imported THROUGH the file-attachment pipeline (content-addressed dedup, blob
storage, the extraction-index kick), which raw SQL cannot reproduce.

URL-HARDCODE-01 (HardcodedInternalUrl_CodeQualityRule), via `Url_Hardcode_Rule_Test`.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| URL-ROUTE-CLEAN | a whole-value Route() call is the compliant form in every language and both realms | php | fixtures: `<%= Rsx.Route %>`, `<%= Rsx_Portal.Route %>`, `{{ Rsx::Route }}`, `{{ Rsx_Portal::Route }}` | 0 violations each | implemented | 2026-08-31 |
| URL-ROUTE-JS | the JS expression forms are clean - a `${Rsx.Route(...)}` template slot and `.attr('href', Rsx.Route(...))` | php | fixture .js with both | 0 violations | implemented | 2026-08-31 |
| URL-NON-INTERNAL | a target that is not an internal path is never the rule's business | php | fixture values `#`, `#section`, https://, //cdn, mailto:, tel:, javascript:, `/` | 0 violations each | implemented | 2026-08-31 |
| URL-STATIC-PREFIX | static asset roots and the framework's own `/_` service paths are not page routes | php | fixture values /assets/ /css/ /images/ /fonts/ /storage/ /_compiled/ /_vendor/ /_download/ /_inline/ and /favicon.ico | 0 violations each | implemented | 2026-08-31 |
| URL-BARE-LITERAL | deny-by-default: a clean hardcoded path is a violation with no resolution required | php | fixture: `href="/contacts"` | 1 violation, high, suggestion names rsx:man routing and the marker | implemented | 2026-08-31 |
| URL-INTERPOLATED-JQHTML | the false-skip that shipped the bug: a dot inside `<%= row.id %>` is not a file extension | php | fixture: `href="/contacts/view/<%= row.id %>"` | 1 violation, suggestion names `Rsx.Route('Contacts_View_Action', {id: row.id})` | implemented | 2026-08-31 |
| URL-INTERPOLATED-BLADE | the blade spelling is flagged and gets PHP-side syntax in the suggestion | php | fixture: `href="/contacts/view/{{ $id }}"` | 1 violation, suggestion uses `Rsx::Route(..., ['id' => $id])` | implemented | 2026-08-31 |
| URL-JS-CONSTRUCTION | a path built by concatenation or in a template literal is flagged (string contents survive - the JS sanitizer would blank them) | php | fixtures: `'<a href="/clients/' + id + '">'` and `` `<a href="/contacts/view/${id}">` `` | 1 violation each, JS spelling in the suggestion | implemented | 2026-08-31 |
| URL-PORTAL-SUGGESTION | a file under rsx/portal/ resolves against the PORTAL route table and is told to use the portal helper | php | fixture at portal/messages/probe.jqhtml: `href="/workspace/<%= w.id %>/requests/<%= t.id %>"` | 1 violation, suggestion names `Rsx_Portal.Route('Portal_Request_Thread_Action'` and says why | implemented | 2026-08-31 |
| URL-STAFF-SUGGESTION | a staff file is never told to use the portal helper | php | fixture: `href="/contacts"` outside rsx/portal/ | suggestion contains no `Rsx_Portal.Route('` | implemented | 2026-08-31 |
| URL-COMMENT-SAFETY | an illustrative href in a doc comment is not code, in all five comment syntaxes | php | fixtures: `<%-- --%>`, `{{-- --}}`, `<!-- -->`, `//` + `/* */`, `#` | 0 violations each | implemented | 2026-08-31 |
| URL-COMMENT-LINE-NUMBERS | blanking a comment neither hides the real line below it nor moves its line number | php | fixture: comment on line 1, real violation on line 2 | 1 violation, line_number 2 | implemented | 2026-08-31 |
| URL-EXCEPTION-PLACEMENT | the marker suppresses on its own line and on the line above | php | two fixtures, marker with a rationale in each position | 0 violations each | implemented | 2026-08-31 |
| URL-EXCEPTION-RATIONALE | a bare marker suppresses nothing - the comment closer is not a rationale | php | fixture: `@URL-HARDCODE-01-EXCEPTION` with no text | 1 violation | implemented | 2026-08-31 |
| URL-EXCEPTION-FILE-LEVEL | the checker's generic file-level suppression still applies | php | two fixtures through `CodeQualityChecker::check_file()`, one marked | control flagged, marked file clean | implemented | 2026-08-31 |

EMAIL-TEMPLATE-01 (EmailTemplate_CodeQualityRule), via `Email_Template_Rule_Test`. Note that the
NEGATIVE scope rows carry the weight here: both checks are WRONG on an ordinary page (a page
localizes its datetimes in the browser and keeps its links relative), so a rule that leaked out of
the email directories would be actively harmful rather than merely noisy.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| EMAIL-SCOPE-OUT | the rule refuses to inspect a blade that is not an email template | php | the same offending fixture at `emails/`, `pages/` and `app/frontend/clients/` | 2 violations under emails/, 0 elsewhere | implemented | 2026-08-31 |
| EMAIL-SCOPE-FRAMEWORK | the framework's own templates (beside their class, under `Core/Mail/`) are in scope | php | fixture at `Core/Mail/Probe_Email.blade.php` printing `$sent_at` | 1 violation | implemented | 2026-08-31 |
| EMAIL-DATETIME-RAW | every bare read of an `_at`/`_date` value is flagged - variable, arrow, both string-key subscripts, a nested chain, and a `?? ''` guard | php | six fixture expressions | 1 violation each, high | implemented | 2026-08-31 |
| EMAIL-DATETIME-RAW-ECHO | the unescaped `{!! !!}` form prints the same unformatted string and is flagged too | php | fixture: `{!! $created_at !!}` | 1 violation | implemented | 2026-08-31 |
| EMAIL-DATETIME-REMEDIATION | the remediation names both formatters, the man page and the marker | php | fixture: `{{ $created_at }}` | suggestion contains `Rsx_Time::format_datetime`, `Rsx_Date::format`, `rsx:man time`, the marker | implemented | 2026-08-31 |
| EMAIL-DATETIME-FORMATTED | a value that went through either formatter is clean, FQCN spelling included | php | four fixture expressions using `Rsx_Time::`/`Rsx_Date::` | 0 violations each | implemented | 2026-08-31 |
| EMAIL-DATETIME-UNRELATED | a name that does not end in `_at`/`_date` is never the rule's business | php | six fixture expressions (`$app_name`, `$user->email`, `$expiry_days`, ...) | 0 violations each | implemented | 2026-08-31 |
| EMAIL-URL-RELATIVE | a bare `Route()` call produces a path, not a URL, in both realms and under an FQCN | php | three fixture expressions | 1 violation each, high, suggestion names `rsx_absolute_url(` | implemented | 2026-08-31 |
| EMAIL-URL-ABSOLUTE | `rsx_absolute_url(Route(...))` is the compliant form, with or without inner whitespace and parameters | php | three fixture expressions | 0 violations each | implemented | 2026-08-31 |
| EMAIL-URL-NEIGHBOUR | wrapping is read immediately to the LEFT of each call, so a compliant neighbour on the same line cannot launder a bare one | php | fixture: one wrapped and one bare `Route()` on one line | 1 violation, naming the bare call | implemented | 2026-08-31 |
| EMAIL-URL-DATA | a whole URL handed in as template data is not a `Route()` call | php | fixture: `href="{{ $view_url }}"` | 0 violations | implemented | 2026-08-31 |
| EMAIL-COMMENT-SAFETY | an illustrative expression inside a blade comment is not a message anybody receives | php | fixture: `{{-- ... --}}` carrying both offending shapes | 0 violations | implemented | 2026-08-31 |
| EMAIL-COMMENT-LINE-NUMBERS | blanking a comment neither hides the real line below it nor moves its line number | php | fixture: comment on line 1, real violation on line 2 | 1 violation, line_number 2 | implemented | 2026-08-31 |
| EMAIL-EXCEPTION-PLACEMENT | the marker suppresses on its own line and on the line above - and is read from the ORIGINAL bytes, since it lives inside the comment the sanitizer blanks | php | two fixtures, marker with a rationale in each position | 0 violations each | implemented | 2026-08-31 |
| EMAIL-EXCEPTION-BOTH-CHECKS | one marker covers both checks on the line it guards | php | fixture: marker above a bare `Route()` call | 0 violations | implemented | 2026-08-31 |
| EMAIL-EXCEPTION-RATIONALE | a bare marker suppresses nothing | php | fixture: `@EMAIL-TEMPLATE-01-EXCEPTION` with no text | 1 violation | implemented | 2026-08-31 |
| EMAIL-EXCEPTION-FILE-LEVEL | the checker's generic file-level suppression still applies | php | two fixtures through `CodeQualityChecker::check_file()`, one marked | control flagged, marked file clean | implemented | 2026-08-31 |
| EMAIL-SHIPPED-CLEAN | the rule reports nothing on the templates this repository actually ships (it found one real defect when written - a raw expiry timestamp in the portal shared-content email) | php | every `rsx/emails/*.blade.php` and `Core/Mail/*.blade.php` | 0 violations, and at least one template was found | implemented | 2026-08-31 |

## NAME-RESERVED-01 - the reserved framework-application prefix (Name_Reserved_Prefix_Rule_Test)

NAME-RESERVED-01 (NameReservedPrefix_CodeQualityRule). A SINGLE leading underscore before the
capital is the framework-application prefix: reserved from `rsx/`, required under
`app/RSpade/Sys/`. The rsx/ direction carries the weight - a `_`-name in application code that
matches a framework one is read by the manifest as a CLASS OVERRIDE, which archives the
framework's file as `.upstream` and reports nothing. Direction (b) keys on the PATH prefix, so
it is correct before the Sys tree exists; the fixtures are synthetic.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| NAME-RESERVED-APP-PHP | a `_`-prefixed PHP class in rsx/ is a violation - the silent-override shape | php | fixture `rsx/models/_sys_widget_model.php` declaring `_Sys_Widget_Model` | 1 violation, critical, naming the class and the prefix | implemented | 2026-09-07 |
| NAME-RESERVED-APP-JQHTML | the same for a jqhtml component, which claims its name with `<Define:>` rather than a class | php | fixture `rsx/app/frontend/_root_card.jqhtml` | 1 violation naming `_Sys_Card` | implemented | 2026-09-07 |
| NAME-RESERVED-APP-BLADE | and for a Blade `@rsx_id`, the third way an application claims a name | php | fixture `rsx/app/frontend/_sys_page.blade.php` | 1 violation naming `_Sys_Page` | implemented | 2026-09-07 |
| NAME-RESERVED-APP-CLEAN | an ordinary application name is untouched - the rule must not become a tax on rsx/ | php | fixture `rsx/models/widget_model.php` declaring `Widget_Model` | 0 violations | implemented | 2026-09-07 |
| NAME-RESERVED-ROOT-OK | a prefixed name inside the framework-application tree is compliant | php | synthetic `app/RSpade/Sys/app/sys/_Sys_Controller.php` | 0 violations | implemented | 2026-09-07 |
| NAME-RESERVED-ROOT-BARE | a BARE name inside that tree is the other direction of the rule, and the remediation names the prefixed spelling | php | synthetic `app/RSpade/Sys/app/sys/Sys_Controller.php` | 1 violation; suggestion contains `_Sys_Controller` | implemented | 2026-09-07 |
| NAME-RESERVED-SCOPE | framework code OUTSIDE the Sys tree is governed by neither direction - a `_`-prefixed framework class outside `Sys/` is legal | php | fixture `app/RSpade/Core/Manifest/_Framework_Internal_Example.php` | 0 violations | implemented | 2026-09-07 |

## NAME-RESERVED-02 - referencing a reserved framework name (Name_Reserved_Reference_Rule_Test)

NAME-RESERVED-02 (NameReservedReference_CodeQualityRule). NAME-RESERVED-01 governs who may
DECLARE a `_`-prefixed name; this rule governs who may REFERENCE one, which is the direction
an application actually trips over. Its whole discrimination is the MANIFEST - a `_`-prefixed
name is its business only when the framework really declares that name (or that
`_`/`__`-prefixed static) under `app/RSpade/` - so the fixtures name REAL framework symbols.
An invented name would pass for the wrong reason and prove nothing. It folded in and replaced
PHP-INTERNAL-01 and JS-INTERNAL-01, both of which were structurally dead.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| NR2-PHP-EXTENDS | extending a reserved framework class is the headline case, and the remediation names where the framework declares it | php | fixture `rsx/lib/probe_layout.php` extending `_Sys_Controller` | 1 violation, critical; suggestion names `app/RSpade/Sys/app/sys/_Sys_Controller.php` | implemented | 2026-09-07 |
| NR2-PHP-STATIC-CALL | a static call on a reserved class is reported ONCE, as the class, at the call site | php | fixture calling `_Sys_Spa_Controller::index(request())` | 1 violation, line 7 | implemented | 2026-09-07 |
| NR2-PHP-FORMS | every other PHP naming form reaches the same index | php | five fixtures: `new`, `instanceof`, `::class`, a nullable type hint, `catch (...)` | at least 1 violation each | implemented | 2026-09-07 |
| NR2-PHP-USE | a `use` import names the class as surely as anything else | php | fixture importing the FQCN of `_Sys_Controller` | 1 violation | implemented | 2026-09-07 |
| NR2-PHP-INTERNAL | a `_`-prefixed static on an ORDINARY-named framework class is the second direction | php | fixture calling `Ajax::_is_internal_call()` | 1 violation naming `Ajax::_is_internal_call` | implemented | 2026-09-07 |
| NR2-PHP-PRIVATE | the framework's `__` private spelling is the same violation | php | fixture calling `Rsx_Api_Docs::__restrict_groups([])` | 1 violation | implemented | 2026-09-07 |
| NR2-PHP-OWN-HELPERS | the legitimate ~938: `static::`, `self::`, `parent::` and instance receivers are never checked | php | fixture with all four spellings on one line | 0 violations | implemented | 2026-09-07 |
| NR2-PHP-LANGUAGE | PHP's own vocabulary lives in the same character and is nobody's framework name | php | fixture using `__construct`, `__DIR__`, `__FILE__` | 0 violations | implemented | 2026-09-07 |
| NR2-PHP-UNKNOWN-CLASS | the manifest is the whole discrimination - a class it does not know is somebody else's API | php | fixture calling `Some_Vendor_Client::_call()` | 0 violations | implemented | 2026-09-07 |
| NR2-PHP-CARRIERS | the sanctioned carriers are STRINGS, and PHP is read as an AST where a string is a scalar - legal by construction, no allowlist | php | fixture with `Permission::can_access('_Sys_Dashboard_Action')` and `Rsx::Route(...)` | 0 violations | implemented | 2026-09-07 |
| NR2-JS-CLASS | `new _X(` and `extends _X` are how an application reaches a panel component from JS | php | fixtures `new _Sys_Sidebar_Nav()` and `extends _Sys_Layout` | 1 violation each, line reported | implemented | 2026-09-07 |
| NR2-JS-INTERNAL | the JS method direction, on a class an application uses every day | php | fixture calling `Rsx._escape_html('x')` | 1 violation naming `Rsx._escape_html` | implemented | 2026-09-07 |
| NR2-JS-STRINGS | string CONTENTS and comments are blanked before matching - this is what buys the carriers and kills the allowlist | php | five fixtures: `Rsx.Route(...)`, `Permission.can_access(...)`, a string literal, a template literal, a `//` comment | 0 violations each | implemented | 2026-09-07 |
| NR2-JS-OWN | an application's own `_`-prefixed members are not framework names | php | fixture with `this._helper()` and `Probe_Js_Own.__made_up()` | 0 violations | implemented | 2026-09-07 |
| NR2-JQHTML | the rule reads the manifest's already-indexed component list, and scans when there is no manifest entry | php | one fixture with `components => ['_Sys_Section']`, one with no metadata | 1 violation each, line 2 for the indexed one | implemented | 2026-09-07 |
| NR2-JQHTML-CLEAN | an ordinary component is never the rule's business | php | fixture rendering `<Section>` | 0 violations | implemented | 2026-09-07 |
| NR2-BLADE | Blade reaches a reserved name through `@rsx_extends` and through a component tag | php | fixtures `@rsx_extends('_Apidocs_App')` and `<_Sys_Section>` | 1 violation each | implemented | 2026-09-07 |
| NR2-BLADE-CLEAN | an application page declaring its own id and rendering its own components is clean | php | fixture `@rsx_id('Probe_Clean')` + `<Section>` | 0 violations | implemented | 2026-09-07 |
| NR2-SCOPE-FRAMEWORK | framework code referencing framework names is the point of having them (this also covers the reference_app symlink) | php | fixture at `app/RSpade/Sys/app/sys/dashboard/_Sys_Probe_Action.js` extending `_Sys_Layout` | 0 violations | implemented | 2026-09-07 |
| NR2-SCOPE-THIRD-PARTY | vendored, node_modules and mirrored CDN trees are not ours to rewrite | php | three fixtures under `rsx/theme/vendor/`, `rsx/node_modules/`, `rsx/resource/.cdn-cache/` | 0 violations each | implemented | 2026-09-07 |
| NR2-LEDGER-ID | a clean file is banked under an id carrying the reserved-name INDEX hash, never the bare rule id - a stale index must not vouch for a file | php | clean fixture, then `Validation_Ledger::flush()` | one rule id, containing `NAME-RESERVED-02@`; keyed by the file's sha1 | implemented | 2026-09-07 |

## The validation ledger (Validation_Ledger_Test)

`App\RSpade\CodeQuality\Support\Validation_Ledger` - ONE var_export'd array at
`storage/rsx-tmp/persistent/validation_ledger.php` recording that a file already passed an
expensive check. It replaced 1254 zero-byte `.lintpass` flag files, so the rows are the
properties a flag file gave for free. Every row runs against a throwaway ledger file
(`_use_path_for_tests`), so the box's own memo is never touched.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| LEDGER-ROUNDTRIP | what was recorded is remembered, and nothing else is - including across rule ids | php | record `hash-a` under one rule | `hash-a` passed; `hash-b` not; the same hash under another rule id not | implemented | 2026-09-07 |
| LEDGER-PERSIST | the whole point of a file rather than a static: the verdict outlives the process | php | record, `flush()`, reload the path | the file exists, declares `version => 1`, and a fresh load answers true | implemented | 2026-09-07 |
| LEDGER-UNKNOWN-HASH | the key is the FILE's identity, so an edited file presents a new hash and is re-checked; an empty hash is never a pass | php | record one hash, ask about another and about `''` | false both times | implemented | 2026-09-07 |
| LEDGER-FORGET | the retirement path for a rule whose premise moved drops that rule ONLY | php | two rules holding the same hash, `forget_rule` on one | the forgotten rule empty, the other intact | implemented | 2026-09-07 |
| LEDGER-PRUNE | without pruning the ledger grows by one entry per edit forever - and a pass recorded by THIS process is never pruned | php | record two hashes, flush, reload, `prune(['hash-live'])` | live kept, dead dropped | implemented | 2026-09-07 |
| LEDGER-GENERATION | a generational id (`<rule>@<fingerprint>`) holds ONE generation - recording under a new one retires the old, so a rule whose premise moves does not accumulate dead verdicts | php | record under `GEN-RULE-01@aaaaaa`, then `@bbbbbb`, then a plain id | the old generation is gone, the new one holds, a plain id retires nothing | implemented | 2026-09-07 |
| LEDGER-CLEAR | `clear()` is the rsx:clean shape: nothing on disk, nothing in memory | php | record, flush, `clear()` | the file is gone and the verdict is gone | implemented | 2026-09-07 |
| LEDGER-VERSION | an unrecognized shape is DISCARDED rather than migrated - the whole content is a memo | php | a hand-written ledger declaring `version => 999` | vouches for nothing | implemented | 2026-09-07 |
| CB2-SYS-PATH | an rsx/ bundle naming a path under app/RSpade/Sys is a CRITICAL CONV-BUNDLE-02 finding | php | fixture bundle including `app/RSpade/Sys/theme` | 1 critical violation naming the path, remediation names `_Sys_Dashboard_Action` | implemented | 2026-09-07 |
| CB2-SYS-CLASS | the same dependency spelled as a reserved bundle CLASS is judged identically | php | fixture including `_Sys_Theme_Bundle` | 1 critical violation naming the class | implemented | 2026-09-07 |
| CB2-SYS-ROUTES | `include_routes` is judged like `include` - extraction without assets is still reaching in | php | fixture with `include_routes: app/RSpade/Sys/app/sys` | 1 critical violation naming the list | implemented | 2026-09-07 |
| CB2-SYS-CLEAN | a Frontend_Bundle-shaped include list (rsx/ paths, an app bundle class, npm aliases, __DIR__) is clean | php | fixture bundle | 0 critical violations | implemented | 2026-09-07 |

## TEST-AUTH-01 (TestFixtureAuthCheck_CodeQualityRule)

`App\RSpade\CodeQuality\Rules\Manifest\TestFixtureAuthCheck_CodeQualityRule`, via
`Test_Fixture_Auth_Check_Rule_Test`. A fixture under `tests/` carrying a real
`#[Auth(...)]` / `@auth(...)` is indexed as a real surface wherever the suite runs, so a
name only the reference application declares fails the MANIFEST BUILD on an install that
does not have it. The vocabulary is therefore the framework's own four checks. Sources are
handed to `check()` in memory - the rule never touches the filesystem.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| TA1-APP-CHECK | the outage shape: an application check on a fixture method attribute | php | fixture `#[Auth('can_view_data', 'is_logged_in')]` | 1 violation naming `can_view_data`, remediation names the scanned-into-every-install reason | implemented | 2026-09-07 |
| TA1-CLASS-LEVEL | a class-level attribute is the same declaration, reported on its own line | php | fixture `#[Auth('can_admin_role')]` above the class | 1 violation, line 3 | implemented | 2026-09-07 |
| TA1-JS-DECORATOR | the JS half: a `@route` action fixture is a scanned surface too | php | fixture `@auth('can_export_data')` | 1 violation | implemented | 2026-09-07 |
| TA1-APP-TESTS | `rsx/tests/` is in scope on the same terms as the framework's tree | php | absolute fixture path under `/rsx/tests/` | 1 violation | implemented | 2026-09-07 |
| TA1-FRAMEWORK-CLEAN | the four framework checks are always allowed, including the two-name merge the live auth_gates fixture uses | php | fixture naming `is_logged_in`, `is_sysadmin`, `public`, `closed` | 0 violations | implemented | 2026-09-07 |
| TA1-STRING-SOURCE | THE negative that matters: `#[Auth('x')]` inside a PHP STRING is fixture source a validation test writes to a temp file, not a declaration (tokens, not text) | php | fixture assigning a `$source` string containing the attribute | 0 violations | implemented | 2026-09-07 |
| TA1-COMMENT | a name written in a docblock is prose | php | fixture with `#[Auth('alpha','beta')]` inside a docblock | 0 violations | implemented | 2026-09-07 |
| TA1-SCOPE | application code names application checks - that is what a Permission class is for | php | fixture at `rsx/app/frontend/clients/...` | 0 violations | implemented | 2026-09-07 |
| TA1-OTHER-ATTRIBUTE | a neighbouring attribute's arguments are not check names | php | fixture with `#[Route('/fixture/path')]` above `#[Auth('public')]` | 0 violations | implemented | 2026-09-07 |

## MODEL-TABLE-01 / MODEL-ENUMS-01 lineage (`Model_Table_Lineage_Rule_Test`)

Both rules read one file and called "this file does not declare it" the same thing as "this
model does not have it". A core model is a base plus a shell now, and an application's override
of one declares only the members it changes - so the file-only reading turned the framework's own
recommended shape into two findings telling the developer to copy declarations back out of the
framework, which is exactly the frozen clone B-109 exists to prevent. The regex over the file
stays as the fast path; when it misses, the rules walk the manifest `extends` chain.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| MTL-INHERITED | an override of a split model that declares neither $table nor $enums passes both rules - the base declares them | php | fixture `class User_Model extends User_Model_Abstract` under rsx/ | 0 + 0 violations | implemented | 2026-09-08 |
| MTL-STILL-FIRES | a model whose lineage declares neither is still two findings; Rsx_Model_Abstract's own default $enums does not count, or the rule would be retired everywhere at once | php | fixture `class Client_Model extends Rsx_Site_Model_Abstract`, empty body | 1 + 1 violations naming each property | implemented | 2026-09-08 |
| MTL-FAST-PATH | a model that declares both in its own file passes without the lineage being consulted | php | fixture declaring $table and $enums | 0 + 0 violations | implemented | 2026-09-08 |

## FILE-CASE-01 - filename case under rsx/ (`Filename_Case_Rule_Test`)

The rule never opens a file: it reads the basename, the metadata the driver hands it, and
the manifest's NAME indexes. Every path below is therefore synthetic and need not exist,
and the one case that needs a name the manifest knows uses a FRAMEWORK class name, which
is present in every install.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| FC-UPPER | an uppercase filename carrying no class name is a violation | php | `rsx/lib/Some_Notes.php`, no metadata | 1 violation | implemented | 2026-09-08 |
| FC-PHP-CLASS | a PHP class file named for its class is clean | php | synthetic path + matching `class` metadata | 0 violations | implemented | 2026-09-08 |
| FC-COMPANION | a companion sharing a REAL class's stem is clean | php | `rsx/app/probe/Rsx_Storage.scss`, no metadata | 0 violations (the framework JS class is in the name index) | implemented | 2026-09-08 |
| FC-JS-CLASS | a JS class file named for its class is clean | php | synthetic path + matching `class` metadata | 0 violations | implemented | 2026-09-08 |
| FC-JQHTML | a jqhtml file named for its component is clean | php | synthetic path + matching `id` metadata | 0 violations | implemented | 2026-09-08 |
| FC-UNKNOWN-STEM | a stem in no index is still a violation, whatever class the file declares | php | `rsx/lib/Zz_No_Such_Class.js` + a DIFFERENT `class` | 1 violation | implemented | 2026-09-08 |
| FC-FRAMEWORK | framework files are out of scope | php | a path under `system/app/RSpade` | 0 violations | implemented | 2026-09-08 |

## Framework-suite portability (`Framework_Test_Portability_Test`)

A structural sweep in the shape of `Rule_Private_Cache_Test`, not a code-quality rule: it
answers a question about one directory and about framework core, and would cost something on
every check of every file if it were a rule. NOTHING IS WHITELISTED - a test that wants an
exception wants a fixture.

The boundary is stated in the class docblock: TABLE names, URL paths and BUNDLE names are NOT
guarded, because none of them has an index to resolve against and a regex over plausible
spellings would flag the framework's own. That half of the rule lives in `tests/CLAUDE.md`
(PORTABILITY) and in review.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| FTP-CORE-CONST | framework CORE names no role or permission constant - the mistake that made `rsx:test` itself unrunnable in an application with different roles | php | every `.php` under `app/RSpade` outside `tests/`, comments blanked | 0 hits | implemented | 2026-09-08 |
| FTP-REFERENCES | every simple name a framework test file references resolves (php / js / jqhtml) to a file OUTSIDE `rsx/` | php | `referenced_simple_names` of every manifest record under `app/RSpade/tests/` | 0 hits; a class OVERRIDE (proved by its `.php.upstream` archive) is not one | implemented | 2026-09-08 |
| FTP-TEST-CONST | no framework test names a role or permission constant | php | every `.php`/`.js`/`.sh` under `tests/`, comments blanked | 0 hits | implemented | 2026-09-08 |
| FTP-QUOTED | no framework test embeds an application class name as a STRING - it resolves at runtime, so FTP-REFERENCES cannot see it | php | the same files, minus `tests/code_quality` (whose rule fixtures feed application names to a rule as synthetic INPUT) | 0 hits | implemented | 2026-09-08 |

The constant set is DERIVED, never listed: the role names come from
`User_Model::$enums['role_id'][*]['constant']` (plus the same for `Portal_User_Model` and
`Login_User_Model`), and `PERM_` is matched as a prefix because the manifest indexes no class
constants. A hardcoded list here would be the very mistake the test exists to catch.
