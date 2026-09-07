# Code Quality Rules

Tests for individual `rsx:check` rules (`system/app/RSpade/CodeQuality/Rules/`)
whose detection logic is subtle enough to warrant a pinned unit test.

## Source under test

- `system/app/RSpade/CodeQuality/Rules/Manifest/ParentCallChain_CodeQualityRule.php`
  (PHP-PARENT-CHAIN-01) - the default parent-call chaining rule. A method override
  MUST call `parent::<same-method>()` unless the nearest manifest-visible ancestor
  that declares the method is abstract or marked `#[Replaceable]`. Covers static +
  instance methods (including `__construct` / magic); vendor parents are excluded.
- `system/app/RSpade/CodeQuality/Rules/PHP/ModelFetchAuthCheck_CodeQualityRule.php`
  (PHP-MODEL-FETCH-01) - flags a model-borne `#[Ajax_Endpoint]`, which is
  unreachable dead security metadata. Whether a fetch surface is GATED is not a
  lint concern: the gate is an `#[Auth(...)]` attribute and the manifest build
  fails without one (see the `auth_gates` concern). The auth-pattern body scan and
  `@auth-exempt` this rule once honored are retired, as is PHP-AUTH-01 entirely.
- `system/app/RSpade/CodeQuality/Rules/Database/MigrationModelReference_CodeQualityRule.php`
  (MIGRATION-MODEL-01) - a migration must not reference a model class or
  `Type_Ref_Registry`. A migration is a forward-only historical record that must
  replay from scratch forever; a model class is current code that gets renamed and
  deleted, so naming one turns a later replay into a hard failure at a file nobody
  has touched in a year. Type-ref ids are resolved by a get-or-create against
  `_type_refs` with the class-name STRING and a hardcoded table name.
- `system/app/RSpade/CodeQuality/Rules/JavaScript/DomMethod_CodeQualityRule.php`
  (JS-DOM-01) - native DOM methods in application JavaScript. The `<script>` half is
  the part with teeth: `document.createElement('script')` and jQuery script
  construction (`$('<script ...>')`) each get their own HIGH-severity remediation
  pointing at a `*.externals.php` declaration plus `Rsx.load_external()`, because an
  external script is DECLARED (the CSP whitelist derives from the declaration, so a
  hand-injected tag is a blocked tag under an enforcing policy) and jQuery never
  inserts a live script node at all - `domManip` disables it and re-executes through
  `_evalUrl()`, a synchronous XHR plus `globalEval`, which suppresses load/error
  events and bypasses Subresource Integrity. Every other tag keeps the generic
  medium-severity jQuery advice.
- `system/app/RSpade/CodeQuality/Rules/Common/HardcodedInternalUrl_CodeQualityRule.php`
  (URL-HARDCODE-01) - an internal path in an href must be produced by a Route()
  helper. DENY BY DEFAULT: a value starting with "/" is a violation unless it is an
  allowed form (a whole-value Route() call, a fragment, a scheme, the site root, a
  static asset root, a framework `/_` service path, or a known static extension in
  an uninterpolated value). The predecessor flagged a literal only when the STAFF
  dispatcher resolved it, which structurally missed the three cases that matter -
  an interpolated path (the dot in `<%= t.id %>` was read as a file extension), a
  portal path (a separate manifest route table the staff resolver never sees), and
  a route not registered at scan time. A hardcoded portal href shipped a real
  navigation bug through that hole: the portal is browsed under /_portal/,
  Spa.dispatch strips the prefix to MATCH but writes history verbatim, and only
  Rsx_Portal.Route() puts it back.
- `system/app/RSpade/CodeQuality/Rules/PHP/SessionIdNullCheck_CodeQualityRule.php`
  (SESSION-ID-01) - flags a null-ish/zero-ish TEST on a
  `Session::get_session_id()` / `Portal_Session::get_session_id()` result. Both
  calls CREATE a session and are declared `: int`, so the test is unreachable AND
  the session it was meant to prevent has already been created. `has_session()` is
  the question the author meant to ask. FATAL at manifest build.

- `system/app/RSpade/CodeQuality/Rules/Convention/NameReservedReference_CodeQualityRule.php`
  (NAME-RESERVED-02) - the REFERENCE direction of the reserved framework-application
  prefix. Application code under `rsx/` may not name a `_`-prefixed class, jqhtml
  component, Blade `@rsx_id` or `_`/`__`-prefixed static method that the MANIFEST
  knows the framework declares under `app/RSpade/`. The manifest is the whole
  scope, and that is what separates this from a blanket underscore ban: the
  template app alone makes ~938 `static::__helper()` calls, and PHP magic methods,
  `__DIR__` and vendor APIs live in the same character. It FOLDED IN and replaced
  PHP-INTERNAL-01 (which never fired - it resolved receivers through `use`
  statements that RSpade code does not carry) and JS-INTERNAL-01 (which never fired
  either - it compared the manifest's `'extension' => 'js'` against the string
  `'*.js'`, so its class set was always empty).
- `system/app/RSpade/CodeQuality/Support/Validation_Ledger.php` - the ONE store of
  "this file already passed this check", replacing the per-file `.lintpass` flag
  directories. Keyed by the file's sha1 rather than by path+mtime, so a verdict
  survives a manifest clear.

## Testable surface

- **Parent-call chaining (implemented):** `Parent_Call_Chain_Rule_Test` drives the
  rule's testable seam (`evaluate_class_methods()`) over synthetic fixture files so
  lineage resolution, nearest-declarer anchoring, abstract / `#[Replaceable]`
  exemption, and AST-based parent-call detection are all exercised over real AST
  without touching the manifest. Detection is AST-based (nikic/php-parser) so a
  comment or string literal mentioning `parent::method()` cannot spoof it, and it is
  method-name specific (`parent::other()` does not satisfy a `parent::this()`
  obligation).
- **Model-rule lineage (implemented):** `Model_Fetch_Auth_Lineage_Rule_Test` drives
  PHP-MODEL-FETCH-01's public `check()` with synthetic fixture files plus hand-built
  metadata. The rule once compared a class's IMMEDIATE parent by exact string, so an
  intermediate abstract (`Rsx_Site_Model_Abstract`) made it skip the class silently;
  it now walks the lineage with `Manifest::php_is_subclass_of()`. The metadata class
  name must be a real manifest class (that is what the lineage lookup resolves) while
  the fixture supplies the members. One row also pins the RETIREMENT: an unguarded
  `fetch()` raises nothing here, because gate presence is a manifest-build fatal.
- **Session-id absence tests (implemented):** `Session_Id_Null_Check_Rule_Test`
  drives SESSION-ID-01's public `check()` over synthetic fixture files (the rule
  has no per-process guard, so the real entry point IS the unit). Coverage: every
  detected spelling fires; the single-assignment variable form fires and is scoped
  per function body; a rebound variable does not; legitimate USE of the value never
  fires; the two facades' own files and `/CodeQuality/` meta-code are excluded; the
  file-level `@SESSION-ID-01-EXCEPTION` marker suppresses (the rule honors it
  itself, because the manifest-time driver does not apply the checker's generic
  exception handling). The call expressions live in string constants assembled into
  fixtures, so this test file - which the manifest also scans - contains no real
  call of its own.

- **Bundle includes of the framework application tree (implemented):**
  `Bundle_Include_Path_Rule_Test` drives CONV-BUNDLE-02's critical half - an `rsx/`
  bundle may not name anything under `app/RSpade/Sys` (the system control panel), by
  path, by reserved `_`-prefixed bundle class, or in `include_routes`. The rule reads
  `define()` rather than parsing the array literal (the same seam CONV-BUNDLE-04
  uses), so the fixtures are REAL bundle classes and the `rsx/` file path is
  synthetic. The clean row is Frontend_Bundle-shaped - `rsx/` paths, an app bundle
  class, npm-backed aliases and `__DIR__` - because a rule that flagged an ordinary
  include list would be worse than no rule; it carries a rationale'd
  `@CONV-BUNDLE-04-EXCEPTION`, since that rule judges by the file's own location and
  the fixture necessarily lives in the framework tree.

- **Hardcoded internal URLs (implemented):** `Url_Hardcode_Rule_Test` drives
  URL-HARDCODE-01's public `check()` over synthetic fixtures in all four scanned
  kinds (.jqhtml, .blade.php, .js, .php). Coverage: every allowed form is clean
  (both Route spellings per language, fragments, schemes, the site root, static
  and framework `/_` prefixes); every flagged form fires (bare literal,
  interpolated jqhtml and blade, JS concatenation and template literal); a portal
  fixture asserts the `Rsx_Portal.Route(...)` suggestion resolved against the
  PORTAL route table, and a resolvable staff path asserts the destination action
  is NAMED with its parameters carried through; the marker suppresses on its own
  line and the line above but a marker with no rationale suppresses nothing; and
  a value inside any of the five comment syntaxes never fires while the real line
  below it still does, at its own line number. The rule reads the ORIGINAL bytes
  rather than the checker's sanitized copy - the JS sanitizer blanks string
  CONTENTS, which is exactly the text this rule inspects - so comment blanking is
  its own step (`FileSanitizer::blank_template_comments()` /
  `blank_js_comments()`). File-level suppression is the checker's, so that one row
  drives `CodeQualityChecker::check_file()` instead.
- **Migration model references (implemented):** `Migration_Model_Reference_Rule_Test`
  drives MIGRATION-MODEL-01's `check_migration_file()` seam over synthetic fixture
  migrations. Coverage: the `use` import, the `Type_Ref_Registry::` call, a static
  model call, `new` / `instanceof` / `::class` / an FQCN spelling, and per-line
  reporting all fire; the prescribed `_type_refs` closure with a `'Foo_Model'` string
  literal in its SQL is CLEAN (if that flagged there would be nothing to convert to);
  a commented-out reference and a same-named object property do not fire; a
  rationale'd `@MIGRATION-MODEL-01-EXCEPTION` suppresses the file and a bare marker is
  itself a violation. A final row drives the real `check_migrations()` entry point
  over both migration directories, so the enumeration - migrations are outside the
  manifest, and the rule reads the runner's own file list - is covered along with the
  tree's cleanliness.

- **eval / new Function tests (implemented):** `Eval_Usage_Rule_Test` drives
  JS-EVAL-01's public `check()` over synthetic JavaScript fixtures. Coverage: all
  three field-reported eval() shapes fire; `new Function()` fires (the property
  that makes this usable as a CSP-readiness gate - a rule catching only eval()
  would call a still-violating page clean); each occurrence reports on its own
  line; the construct in a comment or a string literal does NOT fire; an
  identifier merely ending in "eval" does NOT fire; an unbundled node script is
  out of scope; vendor/node_modules are skipped; and the resolution text names
  `Manifest.get_class_by_name()` rather than only prohibiting. Fixture paths carry
  a real scan-directory segment, because the rule scopes itself by consulting
  `rsx.manifest.scan_directories`. The construct names live in string constants
  assembled into fixtures, so this test file - which the manifest also scans -
  contains no literal call of its own.

- **JS-DOM-01 script cases (implemented):** `Dom_Method_Script_Rule_Test` drives the
  rule's public `check()` over synthetic JavaScript fixtures. Coverage: both quotings
  of `createElement('script')` and jQuery script construction fire at HIGH severity
  with the `Rsx.load_external()` remediation and never the jQuery-element-creation
  text; an ordinary tag still gets the generic medium-severity advice and is never
  routed to the external-resource registry; a vendored bundle and the mirrored
  `.cdn-cache` are skipped (both are full of literal `<script>` strings that are not
  our code); a comment naming the construct does not fire; and
  `@JS-DOM-01-EXCEPTION` suppresses - that last row drives `CodeQualityChecker` itself,
  because the marker is honored generically by the CHECKER and not by the rule. The
  tag name lives inside a string literal and the sanitizer BLANKS literal contents, so
  the rule tests the structure against the sanitized line and the argument against the
  original; the comment row is what pins that split. Fixture paths carry an `rsx`
  segment (the rule only inspects application code), and the offending expressions are
  assembled from string constants so this test file raises no violation of its own.

- **NAME-RESERVED-01 (implemented):** `Name_Reserved_Prefix_Rule_Test` drives the rule's
  public `check()` over synthetic fixtures written under a throwaway root. Both directions are
  covered - a reserved `_`-name declared in `rsx/` (PHP class, jqhtml `<Define:>`, Blade
  `@rsx_id`) and a BARE name declared under `app/RSpade/Sys/` - along with the two negatives
  that keep the rule honest: an ordinary application name, and framework code outside the Sys
  tree (where `_Manifest_*_Helper` has always been legal). The rule keys on the path prefix,
  so the fixtures are synthetic and judge spelling alone.

- **NAME-RESERVED-02 (implemented):** `Name_Reserved_Reference_Rule_Test` drives the
  rule's public `check()` over synthetic fixtures. The fixtures name REAL framework
  symbols (`_Sys_Controller`, `_Sys_Spa_Controller`, `_Sys_Bundle`, `_Sys_Layout`,
  `_Sys_Sidebar_Nav`, `_Sys_Section`, `_Apidocs_App`, `_Manifest_Cache_Helper`,
  `Ajax::_is_internal_call`, `Rsx_Api_Docs::__restrict_groups`, `Rsx._escape_html`)
  rather than invented ones - an invented name would pass for the wrong reason and
  prove nothing. Coverage: every PHP naming form (extends, new, instanceof,
  `::class`, `use`, type hint, catch, static call); both the `_` and `__` method
  spellings; the JS class and method directions; jqhtml through the indexed
  component list AND through the direct scan; Blade `@rsx_extends` and a component
  tag. The negatives are the half that keeps the rule honest: `static::__helper()` /
  `self::_x()` / `$this->_y()` / `parent::__z()`, `__construct` and `__DIR__`, an
  underscore call on a class the manifest does not know, both PHP and both JS
  sanctioned string carriers, a reserved name inside a JS string, template literal
  or comment, framework code under `app/RSpade/`, and the vendored / node_modules /
  `.cdn-cache` trees. One row pins the ledger: a clean file is banked under an id
  carrying the reserved-name index's own hash, never the bare rule id.
- **TEST-AUTH-01 (implemented):** `Test_Fixture_Auth_Check_Rule_Test` drives the rule's
  public `check()` over in-memory sources - nothing is written, because the rule never
  reads the filesystem. The rule exists because of a live outage: a fixture carrying
  `#[Auth('can_view_data', ...)]` is indexed as a real surface wherever the suite runs, and
  closed-by-default validation then resolves that name against whatever application is
  installed - so an install that does not declare it gets a FAILED MANIFEST BUILD, which is
  a hard-down site rather than a failing test. Coverage: the method-level and class-level
  attribute forms, the `@auth` decorator, `rsx/tests/` in scope alongside the framework
  tree, the four framework checks accepted, and the negatives that keep the rule honest -
  an attribute inside a PHP STRING (this concern's own fixer fixtures are full of them, and
  a text-matching rule would flag every one), an attribute inside a docblock, application
  code out of scope, and a neighbouring attribute's arguments not being read as check names.
  Detection is the PHP token stream, so the string and comment rows hold by construction.

- **The validation ledger (implemented):** `Validation_Ledger_Test` covers the
  properties a flag file gave for free - record/read roundtrip, verdicts not leaking
  across rule ids, flush-then-reload from disk, an unknown hash (an edited file) not
  vouched for, `forget_rule` dropping one rule and only that rule, `prune` dropping
  a hash the manifest no longer knows (and NOT one this process just recorded),
  `clear` emptying disk and memory, and an unrecognized `version` being discarded
  rather than migrated. Every row runs against a throwaway ledger file
  (`_use_path_for_tests`), so the box's own memo is never touched.

## Notes

The rule's public `check()` runs once per process behind a `static $already_checked`
guard (it processes the whole manifest during scan), so the extracted
`evaluate_class_methods()` seam is the deterministic unit under test rather than the
end-to-end `check()`.
