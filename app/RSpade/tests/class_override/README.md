# Concern: class_override

## Domain

The class-override system (`rsx:man class_override`) lets an application replace a
framework file by declaring the same name under `rsx/`: a PHP class, a JS class or a jqhtml
component. A stylesheet is never an override: an application stylesheet wrapped in a
framework component's class is compiled alongside the framework's. The framework file is
never moved, renamed or edited. The manifest derives, in full on every build, ONE record of what is overridden - the
OVERRIDE MAP (`Core/Manifest/Manifest_Overrides.php`, `Manifest::overrides()`) - and takes
each SHADOWED framework file out of the live index (`files` -> `shadowed_files`, still in
`file_index` and in the build key). Every consumer of the index is then correct with no
override awareness; the few that walk the disk or load by path ask the map.

The derivation also REFUSES (manifest-build FATAL): two application files declaring one
name; an override that extends the class it overrides; an override of a SPLIT class that does
not extend its `_Abstract` base; an override of a class the framework declares before an
override can answer for it (`Class_Override_Boot_Set`) or that is already declared in the
building process; an application class named like a framework class in a subtree the manifest
never indexes; and a split base `X_Abstract` naming its own shell `X` in a method signature.

It also covers **OVERRIDE DRIFT** - the other half of the mechanism's cost. An override is a
COPY, frozen the moment it was taken, while the framework file it shadows keeps moving with
every pull. When the framework adds a member and then CALLS it - core calling into a class it
believes is its own - the call lands on the application's older copy. A downstream field report
on 2026-09-07 records exactly that: three members appeared in the framework and never reached
the app copy, one path answered 500 and another (a background render queue) silently enqueued
nothing for months, and nothing anywhere compared the two files. `CLASS-OVERRIDE-DRIFT-01` is
that comparison, plus an advisory `rsx:health` row.

## The override loader

A framework class name resolves to the application class that overrides it through
`Core/Overrides/Class_Override_Loader.php`, a narrow spl loader registered IN FRONT of
composer by the composer `files` entry `app/RSpade/autoload_overrides.php` - so it is first in
every entrypoint - and re-prepended by `Manifest::post_init()`. It answers only for an exact key
of its map (framework FQCN => application class and file) - the PHP entries of the override map
(`Manifest_Overrides::loader_map()`), which the manifest build writes to `build/manifest/class_overrides.php`
(`Rsx_Project_Paths::class_overrides_file()`, sealed with the build). For each key, composer's
live classmap entry is pointed at `app/RSpade/autoload_overrides_tripwire.php`, which throws:
composer asked first can never load the framework class. The framework name becomes an alias
of the override the moment the override's file is included (`Autoloader::include_declaration()`)
and eagerly at `post_init`, because PHP's parameter and return type checks never autoload.
`Core/Overrides/Class_Override_Boot_Set.php` lists the framework classes declared before any
override could answer for them.

## Source under test

- `app/RSpade/Core/Manifest/Manifest_Overrides.php` - `derive()` (the map, the shadowed
  records, every refusal), `loader_map()`; driven by `Manifest::_refresh_manifest()` at the end
  of Phase 2, which also reports a file entering the shadow as REMOVED and one leaving it as
  CHANGED to the support modules. Facade reads on `Manifest`: `overrides()`, `is_shadowed()`,
  `override_for()`, `shadowed_fqcn_map()`. `Manifest_Store` keeps the shadowed records in the
  cold half, in `file_index` and in `_compute_hash()` (path + content hash).
- `app/RSpade/Core/Manifest/Class_Override_Drift.php` - the analysis: `pairs()` (every PHP
  entry of the override map: the framework file in place, and the override), `declared_members()`
  (a token pass, since the shadowed class is never loaded and reflection would report the
  LINEAGE on the override), `analyze_pair()` / `analyze_all()`, `describe()`.
- `app/RSpade/CodeQuality/Rules/Convention/ClassOverrideDrift_CodeQualityRule.php` -
  CLASS-OVERRIDE-DRIFT-01: HIGH, never a manifest-build fatal, `is_incremental() = false`;
  `evaluate_pair()` is the seam.
- `app/RSpade/Core/Health/Class_Override_Drift_Health_Checks.php` - the WARN-never-FAIL row.
- `app/RSpade/Commands/Rsx/Overrides_List_Command.php` - `rsx:overrides:list [--json]`, the
  operator's listing of the map with the PHP drift verdict (`current` / `missing` /
  `extends_base`); `rows()` is the seam.
- `app/RSpade/Core/Overrides/Class_Override_Loader.php` - `boot()`, `set_map()` (the composer
  trip-wire and its restoration), `load()`, `alias_declared()`, `alias_all()`,
  `assert_not_shadowed()`, `read_artifact()` / `write_artifact()`.
- `app/RSpade/autoload_overrides.php` / `app/RSpade/autoload_overrides_tripwire.php` - the
  composer `files` entry and the trip-wire.
- `app/RSpade/Core/Overrides/Class_Override_Boot_Set.php` - the un-overridable set, as data.
- `app/RSpade/Core/PHP/Php_Fixer.php` - a framework file's import of an overridden class
  resolves to the framework FQCN (`begin_run()`), so the fixer never edits a framework file
  for an override; outside framework-developer mode it writes nothing under app/RSpade/.

## Man pages

- `class_override.txt` - the contract.

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| Override map: a real twin is shadowed, recorded and announced; refusals (split, extends-self) | php | synthetic file list - `Override_Shadow_Guard_Test` |
| Guard: index names an rsx/ twin that is not on disk | php | covered - nothing shadowed, entry dropped |
| Guard: build already marked its manifest bad | php | covered - nothing shadowed |
| Templates shadowed by declared name, file by file; two application templates refused; stylesheets never shadowed and never refused | php | `Override_Template_Stylesheet_Test` |
| End to end: add an override of each kind, no restart, unchanged rebuild is a no-op, remove it, nothing in the framework tree written | php (child builds) | `Manifest_Overrides_Test`, child-build seam into a scratch build root |
| Un-overridable refusals (boot class, unindexed namesake in an unscanned subtree, already declared) and the split-signature rule | php | `Manifest_Overrides_Test`, synthetic file list |
| Namesakes of classes in framework data directories (`resource/` - the vendored reference app among them - and `public/`) are ordinary application classes: no refusal, no override, the application FQCN alone in the class map | php | `Manifest_Overrides_Test`, child build |
| Member reader: methods (static/instance), properties, trait adoptions; NOT privates, NOT locals, NOT nested anonymous classes | php | token pass over a fixture - covered |
| Missing method / property / trait adoption each reported, one finding per member | php | fixture pairs - covered |
| An override that lacks nothing reports nothing; its own additions are context, not drift | php | covered |
| `@CLASS-OVERRIDE-DRIFT-01-EXCEPTION` read off the OVERRIDE file | php | covered - the checker's own file-level check never sees it |
| Health row: OK with no overrides, OK when an override carries everything, WARN + count when it does not | php | synthetic override map - covered |
| The override loader and its composer trip-wire | php, cli | `Override_Loader_Test`, `cli/Override_Loader_Cli_Test` |
| `rsx:overrides:list`: empty, drift verdicts, a jqhtml row, `--json` shape | cli (PHP, in-process `Artisan::call`) | synthetic override map - `cli/Overrides_List_Command_Test` |
| The env update that restores pre-shadowing `.upstream` archives | cli (shell) | `tests/environment_updates/cli/t11` |
| The IDE bridge `/_ide/service/overrides` read service | http (shell) | `tests/ide/http/ide_overrides_service.sh` |
| Bundle substitution of a shadowed js/jqhtml; a framework stylesheet compiled beside an application one of the same wrapper class | php (child build + compile) | `tests/bundles/php/Bundle_Override_Substitution_Test` (BUNDLE-OVERRIDE-01..02) |
