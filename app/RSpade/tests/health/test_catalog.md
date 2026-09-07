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
| HEALTH-CHECK-FIXTURE | a discoverable #[Health_Check] fixture exercising real discovery end-to-end | php | fixture check under tests/ | discovered + invoked by rsx:health | deferred (a manifest-discovered #[Health_Check] fixture is LIVE in dev - it would pollute the real rsx:health output; the throw/shape paths are covered via run_one + a non-attributed probe instead) | 2026-07-16 |
| HEALTH-CHECK-DISCOVERY | discover() finds every real #[Health_Check] and fails loud on a missing label | php | manifest scan | all real checks; shouldnt_happen on labelless | deferred (indirectly covered by HEALTH-CMD-JSON-LABELS; a label-less method cannot be added without either shipping a bad real check or a live fixture) | 2026-07-16 |
