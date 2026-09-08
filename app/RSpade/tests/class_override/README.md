# Concern: class_override

## Domain

The class-override system (`rsx:man class_override`) lets an application replace a
framework class with its own copy by placing a same-simple-named class under
`rsx/`. During the manifest rebuild the framework file is renamed to
`<Name>.php.upstream` and the `rsx/` copy becomes authoritative; references to the
old framework FQCN keep resolving via the manifest simple-name loader plus
`class_alias`.

It also covers **OVERRIDE DRIFT** - the other half of the mechanism's cost. An
override is a COPY, frozen the moment it was taken, while the framework file it
replaced keeps moving with every pull. When the framework adds a member and then
CALLS it - core calling into a class it believes is its own - the call lands on
the application's older copy. A downstream field report on 2026-09-07 records
exactly that: three members appeared upstream and never reached the app copy, one
path answered 500 and another (a background render queue) silently enqueued
nothing for months, and nothing anywhere compared the two files.
`CLASS-OVERRIDE-DRIFT-01` is that comparison, plus an advisory `rsx:health` row.

This concern covers the **stale-classmap self-healing** that keeps those references
resolving even though composer's committed classmap still maps the old framework
FQCN to the now-renamed `.php` path. Two orthogonal mechanisms (owner ruling
2026-07-24, Option B):

1. **Runtime tolerance (Autoloader).** A scoped PHP error-handler carve-out swallows
   ONLY the include/include_once warning that originates from composer's
   `vendor/composer/ClassLoader.php` (composer's classmap branch returns a path with
   no `file_exists()` check, then bare-`include`s it). Without the carve-out,
   Laravel's `HandleExceptions` promotes that warning to a fatal `ErrorException`
   mid-autoload, defeating the RSX fallback loader. Everything else keeps existing
   fail-loud behavior.
2. **Data hygiene (Manifest rebuild).** Immediately after the override rename/restore
   pass settles, the rebuild validates composer's classmap against the filesystem and,
   if any entry points at a missing file, runs a blocking `composer dump-autoload` to
   regenerate it. Dev-mode / rebuild-only; prod seals already regenerate the composer
   autoloader in `rsx:prod:build`.

The two are complementary: the tolerance is the runtime guarantee for the current
process (whose in-memory classmap is already loaded and cannot be un-staled
mid-request); the dump fixes the on-disk data so future processes never hit the miss.

## Source under test

- `app/RSpade/Core/Manifest/Class_Override_Drift.php` - the analysis: `pairs()` (every
  `.php.upstream` sidecar paired with the class shadowing it), `declared_members()` (a token
  pass, since reflection is unavailable on an archived file and would report the LINEAGE on
  the override), `analyze_pair()` / `analyze_all()`, `describe()`.
- `app/RSpade/CodeQuality/Rules/Convention/ClassOverrideDrift_CodeQualityRule.php` -
  CLASS-OVERRIDE-DRIFT-01: HIGH, never a manifest-build fatal, `is_incremental() = false`;
  `evaluate_pair()` is the seam.
- `app/RSpade/Core/Health/Class_Override_Drift_Health_Checks.php` - the WARN-never-FAIL row.
- `app/RSpade/Core/Autoloader.php` - `register()` installs the tolerance;
  `_handle_php_error()` / `_should_tolerate_classloader_warning()` are the carve-out.
- `app/RSpade/Core/Manifest/Manifest_Indexer.php` - `_validate_composer_classmap()`,
  `_find_stale_classmap_entries()`, `_run_composer_dump()` (+ the `$_composer_dump_runner`
  test seam); also `_check_unique_base_class_names()` (the override/restore pass).
- `app/RSpade/Core/Manifest/Manifest.php` - calls `_validate_composer_classmap()` in the
  rebuild pipeline after the settled override pass.
- `vendor/composer/ClassLoader.php` - the composer behavior being tolerated (findFile
  no-file_exists classmap branch + bare include closure).

## Man pages

- `class_override.txt` - section "HOW REFERENCES KEEP RESOLVING" documents all three
  resolution layers + the scoped tolerance and why it is not a fail-loud violation;
  "WHAT THE BUILD PRINTS WHEN IT ARCHIVES" and "WHEN THE BUILD REFUSES TO ARCHIVE"
  document the archive notice and the two conditions that decline the rename.

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| Error-handler predicate (which warnings are tolerated) | php | pure - covered |
| Error-handler branches (swallow vs delegate) | php | handler invoked directly; delegate via injected spy - covered |
| Classmap staleness detector | php | fixture classmap - covered |
| Validator dump-seam invocation (fires iff stale) | php | `$_composer_dump_runner` spy - covered |
| Archive guard: index names an rsx/ twin that is not on disk | php | synthetic file list + real probe files - covered (`Override_Archive_Guard_Test`) |
| Archive guard: build already marked its manifest bad | php | covered - archiving is skipped entirely |
| Archive notice names the archived file and the rsx/ twin | php | covered - asserted on the real rename path |
| Member reader: methods (static/instance), properties, trait adoptions; NOT privates, NOT locals, NOT nested anonymous classes | php | token pass over a fixture - covered |
| Missing method / property / trait adoption each reported, one finding per member | php | fixture pairs - covered |
| An override that lacks nothing reports nothing; its own additions are context, not drift | php | covered |
| `@CLASS-OVERRIDE-DRIFT-01-EXCEPTION` read off the OVERRIDE file | php | covered - the checker's own file-level check never sees it |
| Health row: OK with no overrides, OK when an override carries everything, WARN + count when it does not | php | synthetic manifest file list - covered |
| Full override -> rename -> validator dump -> alias resolution | e2e | proven manually during ticket verification (tinker); not automated (mutates the real vendor tree + framework files) |
| Mid-transition (stale classmap still resolves via tolerance) | e2e | proven manually; same reason |
| Real `composer dump-autoload` fail-loud on non-zero exit | - | deferred - would require breaking composer; the seam covers invocation |
