# health - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| HEALTH-CMD-PLAIN-EXIT | rsx:health exits 0 on a healthy box | php | Artisan::call('rsx:health') | exit 0 | implemented | 2026-07-16 |
| HEALTH-CMD-JSON-PARSES | --json emits parseable JSON with generated_at/ok/checks | php | Artisan::call --json | valid JSON, non-empty checks | implemented | 2026-07-16 |
| HEALTH-CMD-JSON-LABELS | always-present checks appear (MySQL, PHP, storage) | php | --json checks list | labels present | implemented | 2026-07-16 |
| HEALTH-CMD-OK-EXIT-CONSISTENT | ok flag and exit code both track the absence of FAIL rows | php | --json payload + exit | ok === !has_fail; exit === (has_fail?1:0) | implemented | 2026-07-16 |
| HEALTH-NORM-SINGLE | a single assoc row normalizes to one row (label from attribute, remediation null) | php | ['status'=>'OK',...] | 1 row, label inherited, remediation null | implemented | 2026-07-16 |
| HEALTH-NORM-LIST | a list of rows normalizes preserving order + remediation | php | [rowA, rowB] | 2 rows, WARN + remediation on #2 | implemented | 2026-07-16 |
| HEALTH-NORM-LABEL-SUFFIX | missing label inherits attribute label; #2, #3 for later rows; explicit wins | php | 3 rows, last with own label | 'Base', 'Base #2', 'Explicit' | implemented | 2026-07-16 |
| HEALTH-NORM-BAD-STATUS | an invalid status becomes a FAIL row naming the offender | php | status 'GREAT' | FAIL, detail contains fqcn::method | implemented | 2026-07-16 |
| HEALTH-NORM-NON-ARRAY | a non-array return becomes a FAIL row naming the offender | php | 'nope' | FAIL, detail contains identifier | implemented | 2026-07-16 |
| HEALTH-NORM-NO-STATUS | an associative array without a status key becomes a FAIL row | php | ['detail'=>...] | FAIL | implemented | 2026-07-16 |
| HEALTH-NORM-THROW | a throwing check becomes a single FAIL row carrying the exception message | php | run_one on a throwing probe | FAIL, label kept, message carried | implemented | 2026-07-16 |
| HEALTH-NORM-RUNONE-OK | run_one normalizes a well-formed check result | php | run_one on ok probe | OK row | implemented | 2026-07-16 |
| HEALTH-ENV-ENC-SEAM | the check and the healer resolve the SAME encrypted path - a sibling of the root .env, never of system/.env | php | redirected path seam | `<root>/.env.encrypted` | implemented | 2026-08-23 |
| HEALTH-ENV-ENC-OK | no .env.encrypted at all is OK | php | throwaway root, no encrypted file | OK, 'no encrypted copy' | implemented | 2026-08-23 |
| HEALTH-ENV-ENC-WARN | .env.encrypted on a DEVELOPMENT box WARNs and names the remedy (the heal rewrites .env routinely, so the snapshot goes stale) | php | encrypted file + MODE_DEVELOPMENT | WARN; remediation names `env:encrypt --force` | implemented | 2026-08-23 |
| HEALTH-ENV-ENC-INFO | on a sealed build it is the deployed artifact, so INFO and no remediation - for BOTH production and debug | php | encrypted file + MODE_PRODUCTION / MODE_DEBUG | INFO; no remediation key | implemented | 2026-08-23 |
| HEALTH-SKILLS-INSPECT | inspect() classifies linked / unlinked / blocked / dangling / reserved against a sandbox project root; a directory with no SKILL.md is not a skill | php | sandbox tree with one of each | exact per-bucket name lists | implemented | 2026-08-23 |
| HEALTH-SKILLS-FOREIGN | a foreign symlink occupying a skill's name is BLOCKED (never silently counted as linked) | php | .claude/skills/<name> -> elsewhere | blocked=[name], linked empty | implemented | 2026-08-23 |
| HEALTH-SKILLS-EMPTY | a project with no application skills classifies to nothing at all | php | empty sandbox root | every bucket empty | implemented | 2026-08-23 |
| HEALTH-SKILLS-ROW | the "Claude Skills" row is advisory only - OK or WARN, never FAIL - and every WARN names its remedy | php | run_one on the real check | statuses in {OK,WARN}; WARN has remediation | implemented | 2026-08-23 |
| HEALTH-SKILLS-HEAL-DECLARED | rsx:heal declares claude-skills, resolved to Claude_Skills_Health_Checks | php | Heal_Runner::discover() | target present, fqcn matches | implemented | 2026-08-23 |
| HEALTH-SKILLS-HEAL-RUNS | the healer spawns 070_app_skills.sh and reports no repair on a tree that needs none | php | Heal_Runner::run('claude-skills') | HEALED or ALREADY_OK, never REFUSED | implemented | 2026-08-23 |
| HEALTH-SUBMOD-OK | inspect() reads a correctly configured project: submodule declared, ignore=dirty, no repo-wide setting | php | sandbox git repo + .gitmodules | is_submodule_project true, ignore 'dirty', blanket null | implemented | 2026-08-24 |
| HEALTH-SUBMOD-MISSING | a missing ignore (what `git submodule add` leaves) and a WRONG one (`all`, which hides the pointer) are both reported as-found | php | .gitmodules without / with ignore=all | null, then 'all' | implemented | 2026-08-24 |
| HEALTH-SUBMOD-BLANKET | a repo-wide `diff.ignoreSubmodules` in .git/config is read from LOCAL config | php | git config --local diff.ignoreSubmodules all | blanket = 'all' | implemented | 2026-08-24 |
| HEALTH-SUBMOD-NOT-OURS | no .gitmodules, or one declaring only another submodule, is not a submodule project | php | empty root; model-builder entry | is_submodule_project false both times | implemented | 2026-08-24 |
| HEALTH-SUBMOD-ROW | the "Submodule Visibility" row is advisory only - OK/INFO/WARN, never FAIL - and every WARN names its remedy | php | run_one on the real check | statuses in {OK,INFO,WARN}; WARN has remediation | implemented | 2026-08-24 |
| HEALTH-SUBMOD-MONOREPO | in the framework monorepo the row is a single INFO (system/ is authored source, not a gitlink) | php | run_one under is_framework_developer | 1 row, INFO | implemented | 2026-08-24 |
| HEALTH-SUBMOD-HEAL-DECLARED | rsx:heal declares submodule-ignore-dirty, resolved to Submodule_Visibility_Health_Checks | php | Heal_Runner::discover() | target present, fqcn matches | implemented | 2026-08-24 |
| HEALTH-SUBMOD-HEAL-RUNS | the healer spawns 080_submodule_ignore_dirty.sh and reports no repair on a tree that needs none | php | Heal_Runner::run('submodule-ignore-dirty') | HEALED or ALREADY_OK, never REFUSED | implemented | 2026-08-24 |
| HEALTH-PHPREQ-COMPOSER | every ext-* a composer package hard-requires is declared in Rsx_Php_Requirements (derived from installed.json, not pinned) | php | vendor/composer/installed.json require blocks | undeclared set empty | implemented | 2026-08-28 |
| HEALTH-PHPREQ-SET | the declared list is a duplicate-free set of lower-case names | php | REQUIRED_EXTENSIONS | unique, lower-case, non-empty | implemented | 2026-08-28 |
| HEALTH-PHPREQ-ZSTD | zstd is declared (the revision-history compression; php-zstd is in the framework image) | php | REQUIRED_EXTENSIONS | contains 'zstd' | implemented | 2026-08-28 |
| HEALTH-PHPREQ-CLI-TIER | the CLI tier is declared, holds pcntl, and overlaps the runtime tier nowhere | php | REQUIRED_CLI_EXTENSIONS | contains pcntl; no overlap; pcntl absent from the runtime tier | implemented | 2026-08-28 |
| HEALTH-PHPREQ-STDLIB | ldap, imap and sodium are declared (the standard library the app is promised) | php | REQUIRED_EXTENSIONS | all three present | implemented | 2026-08-28 |
| HEALTH-PHPREQ-CLI-MISSING | missing_from() over the CLI list reports a fabricated CLI extension | php | CLI tier + fabricated | [fabricated] | implemented | 2026-08-28 |
| HEALTH-PHPREQ-SAPI | extensions_for_sapi() adds the CLI tier under 'cli' and under no other SAPI | php | 'cli' vs 'fpm-fcgi' | both tiers vs runtime tier only | implemented | 2026-08-28 |
| HEALTH-PHPREQ-CLI-ENFORCE | the boot check applies the composed list per SAPI and throws on a missing CLI-tier member | php | enforce_for_sapi('cli'/'fpm-fcgi') + enforce_list with a fabricated member | no throw; then RuntimeException | implemented | 2026-08-28 |
| HEALTH-PHPREQ-MISSING | missing_from() reports a fabricated name and nothing else | php | ['json', fabricated, 'pcre'] | [fabricated] | implemented | 2026-08-28 |
| HEALTH-PHPREQ-EMPTY | an empty declared list has nothing missing - the guard invents no failure | php | [] | [] | implemented | 2026-08-28 |
| HEALTH-PHPREQ-THROW | the boot check THROWS naming the missing extension and pointing at rsx:health | php | enforce_list(['json', fabricated]) | RuntimeException naming it | implemented | 2026-08-28 |
| HEALTH-PHPREQ-PASS | a satisfied list passes silently | php | enforce_list(['json','pcre']) | no throw | implemented | 2026-08-28 |
| HEALTH-PHPREQ-EXEMPT | rsx:health and rsx:heal are the ONLY boot-check exemptions | php | EXEMPT_COMMANDS | exactly those two | implemented | 2026-08-28 |
| HEALTH-PHPREQ-CONTAINER | inside the container the remediation names the image, the versioned apt package and the Dockerfile | php | marker seam present | 'regenerated' + php<ver>-zstd + Dockerfile path | implemented | 2026-08-28 |
| HEALTH-PHPREQ-HOST | outside the container it stays the plain install line | php | marker seam absent | 'install/enable the php-zstd extension' | implemented | 2026-08-28 |
| HEALTH-PHPREQ-BOOTMSG | the boot throw carries the container recommendation too | php | missing_message + marker present | message contains 'regenerated' | implemented | 2026-08-28 |
| HEALTH-PHPREQ-ROW | the rsx:health PHP rows are built from the shared lists (no second declaration), one summary per tier | php | run_one on php_environment | 'PHP Extensions' + 'PHP CLI Extensions' rows; every listed name is in its own tier | implemented | 2026-08-28 |
| HEALTH-PHPREQ-CLI-SAPI | outside the CLI SAPI (the report run in a web request by the /_sys dashboard) the CLI tier is one INFO row naming rsx:health, never a FAIL per extension php-fpm does not carry | php | cli_tier_rows('fpm-fcgi') vs ('cli') | one INFO row; no INFO under cli | implemented | 2026-09-26 |
| HEALTH-CHECK-FIXTURE | a discoverable #[Health_Check] fixture exercising real discovery end-to-end | php | fixture check under tests/ | discovered + invoked by rsx:health | deferred (a manifest-discovered #[Health_Check] fixture is LIVE in dev - it would pollute the real rsx:health output; the throw/shape paths are covered via run_one + a non-attributed probe instead) | 2026-07-16 |
| HEALTH-CHECK-DISCOVERY | discover() finds every real #[Health_Check] and fails loud on a missing label | php | manifest scan | all real checks; shouldnt_happen on labelless | deferred (indirectly covered by HEALTH-CMD-JSON-LABELS; a label-less method cannot be added without either shipping a bad real check or a live fixture) | 2026-07-16 |

## Project_Tree_Health_Test (php)

The project-tree rows: `storage/` and `tmp/` writable in every mode, `build/` only in
development, the production read-only posture as INFO, and the debug-log-level warning.
`storage_root()` is deliberately not redirectable per process, so the branches are driven
through `Environment_Health_Checks::__tree_row()` against sandbox paths and through the
mode seam.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| HEALTH-TREE-OK | an existing writable tree is OK and carries the label it was given | php | sandbox dir | OK | implemented | 2026-09-15 |
| HEALTH-TREE-CREATE | a missing tree is CREATED rather than reported - the next command would create it anyway | php | absent sandbox path | OK; the directory now exists | implemented | 2026-09-15 |
| HEALTH-TREE-UNCREATABLE | a tree that cannot be created is a FAIL with a remediation | php | a regular file in the way | FAIL; "could not be created" | implemented | 2026-09-15 |
| HEALTH-TREE-UNWRITABLE | an existing unwritable tree is a FAIL | php | 0500 sandbox dir | FAIL; "not writable" (skipped when the suite runs as root) | implemented | 2026-09-15 |
| HEALTH-TREE-DEV-SET | development reports storage/, tmp/ AND build/ | php | dev mode | all three labels | implemented | 2026-09-15 |
| HEALTH-TREE-PROD-SET | a production mode reports storage/ and tmp/ and does NOT require a writable build/ | php | debug + production | build/ absent from the writability rows | implemented | 2026-09-15 |
| HEALTH-TMP-LINKS | tmp/logs and tmp/app are reported and healthy - Laravel's storage path is the tmp tree, so a broken link is a data-loss shape rather than an error | php | live tree | both rows OK | implemented | 2026-09-15 |
| HEALTH-PHP-TEMP-DIR | the PHP temp dir row says whether sys_get_temp_dir() resolves inside the tmp tree, which is where the framework's TMPDIR export points it | php | live process | OK inside the tree, WARN outside | implemented | 2026-09-15 |
| HEALTH-CACHE-SUBDIRS | the regenerable caches are reported under their tmp/ names beside the persistent storage/logs | php | live tree | tmp/thumbnails, tmp/renditions, storage/logs | implemented | 2026-09-15 |
| HEALTH-POSTURE-DEV | development reports nothing about system/, rsx/ or build/ writability | php | dev mode | no such rows | implemented | 2026-09-15 |
| HEALTH-POSTURE-PROD | a production mode reports all three as INFO, naming the expected read-only posture | php | production mode | three INFO rows | implemented | 2026-09-15 |
| HEALTH-LOG-STACK | a stack channel resolves to the LOUDEST level any member accepts | php | stack over error + debug | 'debug' | implemented | 2026-09-15 |
| HEALTH-LOG-UNKNOWN | a channel nothing declares has no level | php | unknown channel name | null | implemented | 2026-09-15 |
| HEALTH-LOG-WARN | debug logging WARNs in a production mode only, naming LOG_LEVEL=info | php | default channel at debug, both modes | WARN in prod, silent in dev | implemented | 2026-09-15 |
| HEALTH-LOG-QUIET | info logging warns about nothing in a production mode | php | default channel at info | no Log Level row | implemented | 2026-09-15 |

## Health_Mode_Axis_Test (php)

The `modes:` argument on `#[Health_Check]`: which checks run in which mode, and the
visibility of the ones that do not. Driven through the pure partition - a forced
production run on an unsealed box fatals at the manifest gate before a row runs.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| HEALTH-MODE-ABSENT | a check declaring no modes applies in every mode | php | normalize_modes(null) | null | implemented | 2026-09-15 |
| HEALTH-MODE-FORMS | a bare string and a list normalize the same way | php | 'development'; ['debug','production'] | ['development']; ['debug','production'] | implemented | 2026-09-15 |
| HEALTH-MODE-TYPO | an unknown or empty mode fails loud naming the offender - a typo would otherwise silence a check on every box | php | 'prod'; [] | RuntimeException naming fqcn::method | implemented | 2026-09-15 |
| HEALTH-MODE-APPLIES | applies_in_mode includes and excludes per declaration | php | null / dev / sealed declarations | true/false per mode | implemented | 2026-09-15 |
| HEALTH-MODE-DEV-SKIP | a development-only check is skipped in production AND listed as skipped, never silently omitted | php | partition(production) | Playwright / Chromium in skipped, not in run | implemented | 2026-09-15 |
| HEALTH-MODE-PROD-SKIP | a sealed-build check is skipped in development and listed | php | partition(development) | Production Seal in skipped | implemented | 2026-09-15 |
| HEALTH-MODE-EVERY | a check with no modes runs in all three | php | partition per mode | PHP in run, never skipped | implemented | 2026-09-15 |
| HEALTH-MODE-TOTAL | every discovered check is either run or skipped, exactly once, in every mode | php | partition per mode | run + skipped == discover() | implemented | 2026-09-15 |
| HEALTH-MODE-DEFAULT | partition() with no argument reports this box's mode | php | partition() | Rsx::get_mode() | implemented | 2026-09-15 |
| HEALTH-MODE-JSON | --json carries `mode` and `skipped`, and a skipped label contributes no row | php | rsx:health --json | mode == current; skipped labels absent from checks | implemented | 2026-09-15 |
| HEALTH-MODE-TABLE | the table run states the mode it ran in | php | rsx:health | output contains 'Mode: <mode>' | implemented | 2026-09-15 |

## Playwright_Stack_Test (php)

The node -> playwright -> chromium chain, the WARN row over it, and the refusal
`rsx:debug` prints. The property under test is ONE STRING, TWO CONSUMERS: the install
command the health row names and the one the refusal names are the same literal.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| HEALTH-PW-PRESENT | the development container ships the whole stack | php | probe() | ok, chromium path resolved | implemented | 2026-09-15 |
| HEALTH-PW-MISSING | each absent link is named with its own install command | php | absent-link seam per link | missing == link; remediation == that install constant | implemented | 2026-09-15 |
| HEALTH-PW-SHORT | the probe stops at the first absent link - the later ones cannot be asked | php | node absent | missing == 'node' | implemented | 2026-09-15 |
| HEALTH-PW-WARN | the row is WARN for every absent link and never FAIL; every WARN names its remedy | php | _row() per link | WARN + remediation; OK when complete | implemented | 2026-09-15 |
| HEALTH-PW-DEV-ONLY | the check is declared development-only - rsx:debug refuses to run anywhere else | php | discover() | modes == ['development'] | implemented | 2026-09-15 |
| HEALTH-PW-REFUSAL | the refusal names the tool, the missing link and the SAME install command the row prints | php | refusal_message + _row | both contain the identical remediation string | implemented | 2026-09-15 |

## Production_Health_Test (php)

The rows that only mean something on a SEALED box. None of these states is one this box
is in and forcing the mode would not create them, so every row builder takes what it
reports as a parameter and is driven directly.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| HEALTH-PROD-MODES | every check in the class declares exactly the two sealed modes, and the test knows about every check the class declares | php | discover() | modes == ['debug','production'] for all six | implemented | 2026-09-17 |
| HEALTH-PROD-SEAL | an intact seal is OK; drift is a FAIL carrying the finding and naming rsx:build --force | php | _seal_row(true, []) / (true, [finding]) | OK; FAIL naming the file and the rebuild | implemented | 2026-09-15 |
| HEALTH-PROD-SEAL-NONE | a missing seal is a FAIL naming the build | php | _seal_row(false, []) | FAIL; rsx:build --force | implemented | 2026-09-15 |
| HEALTH-PROD-URL | https is OK; http, a schemeless value and an empty one all FAIL naming APP_URL | php | _app_url_row per value | OK; three FAILs | implemented | 2026-09-15 |
| HEALTH-PROD-URL-CACHE | the remediation says a .env edit alone changes nothing - the running value came from the cached config the build produced | php | _app_url_row('http://...') | remediation names rsx:build --force | implemented | 2026-09-15 |
| HEALTH-PROD-AUTOFILL | login auto-fill on a sealed build is a FAIL naming RSPADE_LOGIN_AUTOFILL | php | _login_autofill_row(true/false) | OK; FAIL + key | implemented | 2026-09-15 |
| HEALTH-PROD-CONSOLE | the console_debug row is INFO and names the variant (stripped vs intact) | php | _console_debug_row per mode | two INFO rows | implemented | 2026-09-15 |
| HEALTH-PROD-MAIL | the development catcher on a sealed build WARNs and names the silent outage; live is OK | php | _mail_delivery_row('aiosmtpd'/'live') | WARN naming 'no recipient'; OK | implemented | 2026-09-15 |
| HEALTH-PROD-HOST | a .dev. hostname on a sealed build FAILs, naming the host and BOTH outbound consequences (email redirected to the catchall, SMS recorded Suppressed) | php | _hostname_row('app.dev.example.test') | FAIL; detail names the host, 'catchall' and 'Suppressed'; remediation names APP_URL + rsx:build --force | implemented | 2026-09-17 |
| HEALTH-PROD-HOST-OK | an ordinary production hostname is OK with nothing to remediate | php | _hostname_row('app.example.test') | OK; remediation null | implemented | 2026-09-17 |
| HEALTH-PROD-HOST-SUBSTR | the row asks the IDENTICAL question Rsx::is_dev_site() asks - the dotted substring '.dev.', dots on both sides - so a leading 'dev.' label and a trailing '.dev' are both OK | php | _hostname_row per host | OK for dev.example.test / example.dev / devel.* / mydev.*; FAIL for a.dev.b and staging.dev.example.test | implemented | 2026-09-17 |

## Opcache_Advisory_Test (php)

The once-per-six-hours OPcache recommendation written at the end of
`Rsx_Framework_Provider::boot()`. The throttle is what is proved; the window is driven
by the stamp, never by waiting.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| HEALTH-OPCACHE-ONCE | the first call writes exactly one line and a second inside the window writes none | php | _log_once(key) twice | true + 1 captured line; false + 0 | implemented | 2026-09-15 |
| HEALTH-OPCACHE-SCOPE | another scope (another build key) has its own window, and an unstamped scope reads as unstamped | php | two distinct keys | both log; a third key is a cache miss | implemented | 2026-09-15 |
| HEALTH-OPCACHE-STAMP | the stamp is what closes the window, and the window is six hours | php | _log_once + RsxCache::get | stamp present; WINDOW_SECONDS == 21600 | implemented | 2026-09-15 |
| HEALTH-OPCACHE-CLI | a CLI process is never advised and never consumes the live window - OPcache is correctly off for the CLI SAPI | php | check() under the suite's cli SAPI | no line; live key unstamped | implemented | 2026-09-15 |
| HEALTH-OPCACHE-INI | an ini nothing declares reads as OFF, never as enabled | php | _ini_enabled(false/'0'/'1'/'On') | false, false, true, true | implemented | 2026-09-15 |
| HEALTH-OPCACHE-MSG | the line names the exact setting, says it is a recommendation, and states its own cadence | php | message() | contains opcache.enable=1, 'recommendation', '6 hours' | implemented | 2026-09-15 |

## Schema_Contract_Test (php)

The framework's contract on application-owned tables (`Core/Database/Schema_Contract`)
and the `Schema Contract` rsx:health rows over it. The live schema is checked by the
real check; every failure branch is driven through a public row builder with an injected
shape, because a database missing `users.login_user_id` is not one a test may create.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| SCHEMA-CONTRACT-LIVE | the schema this framework ships satisfies its own contract - the regression test that keeps the map honest as the framework's reads move | php | run_one on the real check | zero FAIL rows | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-SHAPE | one row per contracted table plus `schema: foreign keys`, `schema: rows`, `schema: semantics`, and nothing else | php | run_one on the real check | count == tables + 3; every label present | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-MODES | the check declares no mode axis - the question and its consequence are identical on every box | php | discover() | modes === null | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-COLUMN | a missing required column is a FAIL naming the column AND the framework consumer, so the operator knows it is not their feature | php | _table_row('users', ..., columns minus login_user_id) | FAIL; 'users.login_user_id'; 'Session::get_user()'; 'framework requirement' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-TABLE | a missing modelled table is a FAIL naming the framework migration that created it | php | _table_row('login_users', ..., null, []) | FAIL; names 2025_11_04_051746_create_login_users_table | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-INDEX | a missing unique index is a FAIL naming the columns, the conventional index name, and what losing uniqueness silently does | php | _table_row('login_users', ..., all columns, no indexes) | FAIL; 'UNIQUE(email)'; 'uk_login_users_email'; 'non-deterministic' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-INDEX-SET | an index is matched by COLUMN SET, never by name or column order - renaming one breaks nothing | php | _table_row('users', ..., [site_id, login_user_id]) | OK | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-FK | a missing foreign key is a FAIL naming both sides - the four from framework tables into application tables are the contract at its strongest | php | _foreign_keys_row with _sso_identities removed | FAIL; '_sso_identities.login_user_id->login_users.id' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-ROW | a missing sites id 0 is a FAIL naming the row and what it is for | php | _rows_row with site 0 absent | FAIL; 'sites id 0'; 'sessionless site' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-ROW-GUARD | a row guarded by `only_if_any` is not asked about when the guard table is empty - an unseeded database is a fresh install, not a broken one | php | _rows_row with the initial-user pair absent from the map | OK; '2 required row(s)' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-SEMANTIC | an out-of-range dark_mode WARNs and never FAILs, names the column, says what the framework silently does instead, and carries a runnable remedy | php | _semantics_row with the dark_mode finding | WARN; 'login_users.dark_mode'; 'AUTO'; 'UPDATE login_users' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-SEMANTIC-OK | nothing found is nothing reported | php | _semantics_row with two clean probes | OK; '2 semantic probe(s)' | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-ROLE-ENUM | the one PHP-side probe reads the live role declaration and finds permissions + can_admin_roles on every entry | php | _role_enum_finding() | null | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-CONSUMER | every contracted column names the framework code that reads or writes it - that string is the whole value of the map | php | Schema_Contract::tables() | every 'where' non-empty | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-NO-AUDIT | audit pairs and timestamps are deliberately absent - normalize_schema guarantees them, so a finding could only duplicate a repair | php | Schema_Contract::tables() | none of the eight columns declared anywhere | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-DELETED-AT | deleted_at IS contracted, on exactly the four soft-deleting tables - normalization never adds it and losing it fatals every query | php | Schema_Contract::tables() | declared iff table in {users, login_users, portal_users, sites} | implemented | 2026-09-17 |
| SCHEMA-CONTRACT-PROBE-SHAPE | every probe declares a kind the check knows how to run and a remedy; a count probe names its table and predicate | php | Schema_Contract::probes() | kind in {enum,count}; remediation non-empty | implemented | 2026-09-17 |
| SEC-IGNITION-OK | Ignition ships read-only: both flags literally false, the row is OK | php | shipped config | OK | implemented (`Security_Posture_Health_Test`) | 2026-09-25 |
| SEC-IGNITION-FAIL | the Ignition row FAILs when runnable solutions are switched on | php | `enable_runnable_solutions` = true at runtime | FAIL naming the key | implemented (`Security_Posture_Health_Test`) | 2026-09-25 |
| SEC-IMAGICK-OK | under the shipped ImageMagick policy every vector/scripting coder is refused | php | installed policy (the RSpade image) | OK; skipped where the policy is absent | implemented (`Security_Posture_Health_Test`) | 2026-09-25 |
| SEC-IMAGICK-FAIL | the ImageMagick row FAILs, naming the coders, on a box with the distribution policy | php | a box without the RSpade policy | FAIL naming SVG, MSVG, MVG, MSL, TEXT, LABEL | planned (demonstrated by hand on the development container 2026-09-25) | 2026-09-25 |
| LARAVEL-ROUTE-TABLE | the booted Laravel route table is empty and the "Laravel Route Table" row is OK; a registered route turns it into a FAIL naming the route | php | the booted router; then a route registered at runtime | OK; FAIL naming the route | implemented (`Laravel_Route_Table_Health_Test`) | 2026-09-25 |
