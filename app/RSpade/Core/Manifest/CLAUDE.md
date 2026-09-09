# Manifest System - Developer Documentation

This documentation is for developers working on Manifest.php itself. For usage documentation, see the man pages via `php artisan rsx:man manifest_api`.

## CRITICAL: Testing Manifest.php Changes

**When modifying Manifest.php or any helper class, you MUST run `php artisan rsx:manifest:build --clean` to test your changes.**

The `--clean` flag performs both `rsx:clean` and a full manifest rebuild, ensuring your modifications are properly tested. Without this, the manifest may use cached or partially-built data that doesn't reflect your changes.

## The build as an object: Manifest_Build

`Manifest` is the STATIC FACADE the whole framework calls. The BUILD underneath it is an
instance of `Manifest_Build`, reachable as `Manifest::build()`:

| It carries | Read by |
|---|---|
| scan roots, relative to `base_path()` | `Manifest_Scanner::_scan_directories()` |
| the storage root (where `rsx-build/` lives) | `Manifest_Store::_get_cache_file_path()` |
| the mode | reporting |
| the build's ONE `Source_Cache` | `Php_Fixer::fix()` (phase 2) and the code-quality driver (phase 7) |

`Manifest_Build::from_config()` is the ordinary answer: `config('rsx.manifest.scan_directories')`
plus the three test trees while `Rsx_Test_Abstract::suite_is_running()`, and `storage_path()`.

**Why it exists: testability.** Until it did, "build a manifest" meant "build THE manifest,
from config, into the developer's own storage directory" - so a test could not build a fixture
tree and assert on the index that came out, and the build could not be held to a MEMORY
BUDGET. `Manifest::_use_build_for_tests()` replaces it in-process, and three
framework-INTERNAL flags (the `--_` convention) drive a CHILD build:

| Flag | Effect |
|---|---|
| `--_manifest-storage-root=<abs>` | write the index under this root |
| `--_manifest-extra-scan-roots=<csv>` | ADD roots to the configured list |
| `--_manifest-scan-roots=<csv>` | replace the list outright |
| `--_manifest-report-peak` | print `MANIFEST_PEAK_BYTES=<n>` after the summary |

An extra root cannot be the ONLY root: the framework's own support modules, models and parent
classes are resolved THROUGH the index, so a build of a fixture tree alone dies at "Manifest
support module must extend ManifestSupport_Abstract". `tests/manifest/` is the worked example.

**It is deliberately minimal.** The phase code lives in `Manifest_Scanner`,
`Manifest_Indexer` and `Manifest_Store` and still reaches `Manifest::$data` directly; what
moved onto the seam is only what a test has to be able to move.

## THE MEMORY BUDGET

Owner ruling: **the build's peak memory is proportional to the INDEX plus a constant bounded
by the largest single file, never proportional to the number of files parsed.** Acceptance: a
cold build of the reference tree under 128 MB, and of a synthetic tree five times its size
under 256 MB. `tests/manifest/php/Manifest_Memory_Gate_Test` is those two numbers, and a
failure there is a FINDING, never a number to raise.

Measured on this box (1,420 files): cold 677 MB -> **105.9 MB**, one-file rebuild 103 MB ->
99.8 MB, no-change 64.4 MB -> 67.5 MB. What was freed was per-rule static AST and token
caches inside the code-quality pass; see `CodeQuality/Support/CLAUDE.md`.

## Architecture Overview

The Manifest is a compiled cache of all file metadata in the RSX application. It replaces Laravel's scattered discovery mechanisms with a unified system that enables path-agnostic class loading.

## THE INDEX IS TWO FILES

| File | What it holds | Who loads it |
|---|---|---|
| `storage/rsx-build/manifest_index.php` | every derived section; `file_index` (path -> `[size, mtime]`) for the WHOLE tree; the `files` entries whose METHOD MAP is read at request time - models and their ancestors, task services, the generated stubs - plus every record that has no method map at all | `init()`, on every request |
| `storage/rsx-build/manifest_files.php` | every other `files` entry, method maps intact | ONCE per process, on demand |

**81% of the index was `files`, and 76% of that was method maps.** A served request reads
almost none of them: `Orm_Controller` and `get_relationships()` read a model's, `Task` and
`Task_Concurrency` read a task service's, and that is the whole list. So the method maps move
to a second file and the request stops paying for them.

`Manifest::_load_cold_files()` merges the cold half into `$data['data']['files']` (hot entries
win) and is called by the accessors that can be asked about a record the hot index does not
carry: `get_all()`, `get_file()` for a cold path, `get_files_by_dir()`, `get_stats()`,
`get_path_by_filename()`, `php_get_extending()`, `js_get_extending()`,
`php_get_metadata_by_class()`, `php_get_metadata_by_fqcn()`. **`get_all()` semantics are
unchanged** - it still returns the whole tree.

**A REBUILD loads the cold half first** (`_refresh_manifest()` does it before carrying
entries forward): a build owns the whole tree and writes both halves.

**When the method map is not what you want, ask for the CLASS record, not the FILE record.**
`Manifest::php_class_metadata($simple_name)` returns
`['file', 'fqcn', 'extends', 'abstract']` from `php_classes` and never touches the cold half;
`php_class_records_extending($parent)` is the same for a whole subclass set. The autoloader,
the dispatcher, `Rsx::Route()`, `Ajax`, `Task` and the bundle resolver all go through it.

`tests/manifest/php/Manifest_Cold_Isolation_Test` is the guarantee: it spawns children with
the `--_manifest-report-cold` internal flag (which makes any artisan process print
`MANIFEST_COLD_LOADS=<n>` at shutdown) and asserts ZERO for a boot, an Ajax call and a model
fetch.

## THE REFERENCE RULE

**An index POINTS AT a record; it does not contain one.** The index broke that in four places
at once and paid for it: `models` carried a verbatim copy of each model's method map (167 KB),
`routes_by_target` was a byte-identical regroup of `routes` (80 KB), every gate list was stored
three times, and every file record repeated its own path as a value (3.3% of `files`).

- `models[*]` carries `columns` and a `file` reference. No method map.
- `routes_by_target` / `portal_routes_by_target` are **DERIVED AT LOAD** from `routes` /
  `portal_routes`, grouped on the row's `target`. Never persisted. PHP arrays are
  copy-on-write, so the regroup shares the rows' storage.
- A route row carries `surface` (its key in `auth.surfaces`) instead of its own gate list;
  every dispatcher resolves it with `Auth_Gates::surface_gates($row['surface'])`.
- A file record does not carry `file`. **The getters that return a record without its key -
  `get_file()`, `php_get_metadata_by_*`, `php_get_extending()`, `js_get_extending()` - put it
  back**, because for their callers it is the only way to learn the path.
- `jqhtml.components[id]` is `['file' => ..., 'js_file' => ...]`.
- `api_endpoints[pattern]` keeps the routing facts plus the two DOCBLOCK-derived fields no
  file record holds (`description`, `response_example`) and the param declarations; the route
  row no longer duplicates them (`Api_Catalog::params_for_pattern()` is the validator's
  source).

`tests/manifest/php/Index_Reference_Rule_Test` is that rule as an assertion.

## THE INDEXES A LOOKUP GOES THROUGH

| Index | Answers | Accessor |
|---|---|---|
| `php_classes` / `js_classes` | class name -> `['file', 'fqcn', 'extends', 'abstract']` | `php_class_metadata()`, `php_find_class()`, `find_php_fqcn()` |
| `php_subclass_index` / `js_subclass_index` | parent -> every DESCENDANT | `php_get_subclasses_of()`, `php_is_subclass_of()` |
| `attribute_index` | attribute simple name -> `[['file','class','member','instances'], ...]` | `by_attribute()`, `get_with_attribute()` |
| `blade_views` | `@rsx_id` -> path (a duplicate id is a BUILD failure) | `find_view()`, `view_exists()` |
| `models_by_table` | table -> model class | `model_for_table()` |
| `file_index` | path -> `[size, mtime]`, whole tree | `_has_changed()`, `_validate_cached_data()` |
| `auth.surfaces` | `Class::method` -> kinds, realm, gates | `Auth_Gates::surface_gates()` |

**Never write the "iterate every file, filter php, read attribute X off public_static_methods"
loop.** It existed in six places; `by_attribute()` is the one answer.

### File map

The build is four classes plus the facade. `Manifest` is the public API and the query
surface; the other four own one phase each and are reached through it.

| File | Owns | Entry points |
|------|------|--------------|
| `Manifest.php` | The public API AND every read accessor, implemented here. Boot (`init`, `post_init`), the build loop `_refresh_manifest()` with its restart accounting, and the lookups: `php_find_class`, `php_class_metadata`, `php_get_extending`, `php_is_subclass_of`, `php_get_lineage`, the `js_*` twins, `find_view`, `by_attribute`, `get_with_attribute`, `get_routes`, `db_get_*`, `model_for_table`, `php_model_columns` | everything a caller outside `Core/Manifest/` uses |
| `Manifest_Build.php` | The build SEAM - an instance carrying scan roots, storage root and mode, constructed from config, and owning the pass's `Source_Cache`. What `tests/manifest/` substitutes to build a fixture tree | `Manifest_Build::from_config()` |
| `Manifest_Scanner.php` | Phases 1-2: directory discovery, per-file change detection, the token parse that produces each file record, `Php_Fixer` with its class-structure delta, and the PHP reflection extract with its derived-cache restore | `_get_rsx_files`, `_has_changed`, `_process_file`, `_run_php_fixer`, `_extract_reflection_for_changed_files` |
| `Manifest_Indexer.php` | Phases 3-6: the derived indexes (autoloader class map, blade views, attribute index, models-by-table, event handlers, classless files), the duplicate-class detectors, the class-override archive pass, the composer classmap validation, the code-quality pass entry and the VS Code stub | `_build_autoloader_class_map`, `_build_attribute_index`, `_check_unique_base_class_names`, `_run_manifest_time_code_quality_checks`, `_generate_vscode_stubs` |
| `Manifest_Store.php` | The index ON DISK: load hot, load cold on demand, derive the load-time indexes, write both halves atomically as compact PHP literals, the build key, validation, the bad-manifest flag | `_load_cached_data`, `_load_cold_files`, `_save`, `_compute_hash`, `_validate_cached_data` |
| `ManifestSupport_Abstract.php`, `Full_ManifestSupport_Abstract.php`, `Modules/` | The module pipeline (phase 5). One module per index section, in one of two kinds | `process(&$data, $changed, $removed)` / `rebuild(&$data)` |
| `Class_Override_Drift.php` | The `CLASS-OVERRIDE-DRIFT-01` analysis of an `rsx/` copy against its archived `.upstream` | `analyze_pair()` |

**Reads live in `Manifest.php`; writes live in the four build classes.** A method that answers
a question about the indexed tree belongs on the facade with its body; a method that produces
or persists a section of the index belongs in the phase class that owns that section. The
`_`-prefixed statics on `Manifest` are the build-phase entry points - public for cross-class
reach, not part of the surface an application calls.

### Core Data Structure

The manifest cache contains:

```php
[
    'hash' => '42b9d0efb5c547eec0fb2ca19bf922e0',
    'data' => [
        'files' => [...],                // Indexed files (the HOT subset until the cold
                                         // half is merged - see above)
        'file_index' => [...],           // path => [size, mtime], the WHOLE tree
        'js_classes' => [...],           // JS class map (the hot class record)
        'php_classes' => [...],          // PHP class map (the hot class record)
        'php_subclass_index' => [...],   // parent => every descendant
        'autoloader_class_map' => [...], // Simple name to FQCNs
        'attribute_index' => [...],      // attribute => declarations
        'blade_views' => [...],          // @rsx_id => path
        'models' => [...],               // Database model metadata (columns + file)
        'models_by_table' => [...],      // table => model class
        'jqhtml' => [...],               // jqhtml component registry
        'routes' => [...],               // pattern => row (routes_by_target is DERIVED)
        'auth' => [...],                 // checks, surfaces, mirror_stubs
    ]
]
```

### Schema Discovery Tool

Use `php artisan rsx:manifest:schema_dump` to inspect the manifest structure:

```bash
# Pretty-printed JSON for human reading
php artisan rsx:manifest:schema_dump

# Compact JSON for LLM parsing (saves tokens)
php artisan rsx:manifest:schema_dump --no-pretty-print
```

This tool deduplicates array structures, showing unique patterns with example values. Essential for understanding the actual data structure when writing code that directly accesses manifest data.

## The build phases

The build runs seven phases, then the code-quality pass. Implementations live in
`Manifest_Scanner` (1-2), `Manifest_Indexer` (3-6) and `Manifest_Store` (7); see the file
map above.

### Phase 1: File Discovery
- Implemented in `Manifest_Scanner::_get_rsx_files()`
- Scans the directories `Manifest_Scanner::_scan_directories()` returns (facade: `Manifest::scan_directories()`) - the ONE answer to "does this build index that path"
- Configured list (relative to `base_path()` = `system/`): `['rsx', 'app/RSpade/Core', 'app/RSpade/Integrations', 'app/RSpade/Bundles', 'app/RSpade/Breadcrumbs', 'app/RSpade/CodeQuality', 'app/RSpade/Lib', 'app/RSpade/Sys']` - `app/RSpade/Sys` is the framework's own application (the /_sys control panel)
- **THE TEST TREES ARE NOT IN IT.** `app/RSpade/tests`, `app/RSpade/temp` and `rsx/tests` are appended ONLY while `Rsx_Test_Abstract::suite_is_running()` (the `--_test-run` internal flag `system/artisan` declares pre-boot for `rsx:test` and `Rsx_Artisan` forwards to every child). A fixture is real indexed source - a route, an Ajax surface, an `#[Auth]` naming a check - and a served site must not carry one; the outage that set this rule was a fixture whose `#[Auth]` named an application-only check, which failed the manifest build of every install that scanned it. `rsx/tests` lives inside the `rsx/` root, so it is additionally skipped BY PATH when it is not in the list
- The transition costs one rebuild in each direction and nothing else: the first ordinary request after a test run drops the fixtures again through the normal add/remove path (measured on this box: 5.7 s for that request, 0.11 s steady)
- A missing scan path is a FATAL, the three TEST TREES excepted: they are added by the test RUN rather than by the operator, and each is legitimately absent (`app/RSpade/temp` is the framework developer's scratch tree; an application that keeps its suites beside the code they test has no `rsx/tests`). A missing root that came from config stays fatal
- Excludes filenames via `config('rsx.manifest.excluded_files')` and path segments via `config('rsx.manifest.excluded_dirs')` (vendor, node_modules, storage, .git, public, resource, Core/Manifest)
- Returns array of file paths with basic stats (mtime, size)

### Phase 2: Token Parsing
- Implemented in `Manifest_Scanner::_process_file()`
- Uses `token_get_all()` for fast PHP parsing without loading
- Extracts: namespace, class name, extends, implements
- **Normalizes all class references to simple names** (strips namespace qualifiers)
- Builds dependency graph for loading order

### Phase 3: Dependency Loading
- Implemented in `Manifest_Scanner::_load_changed_php_files()`
- Loads PHP files in dependency order (parents before children)
- Uses `_load_class_hierarchy()` to ensure parent classes exist
- Critical for reflection to work properly

### Phase 4 - 7: what is INCREMENTAL and what is not

| Phase | Was | Is |
|---|---|---|
| 4 reflection | every PHP file every build, re-reading 1,025 derived JSON files to restore data already in memory | the CHANGED set only; an unchanged record carries its reflection forward and the build ASSERTS it (`__assert_reflection_carried_forward` - a class record with no `abstract` key is a broken index) |
| 4 class map | `_scan_directory_for_classes()` re-tokenized every framework php file, the scanned ones included | the indexed half comes from the files map; the UNINDEXED framework subtrees (Commands, Database, Http, Ide, ...) are walked only when their stat fingerprint moved, memoized in the PERSISTENT cache |
| 5 modules | twelve full O(tree) passes | the diff contract above |
| 6 stubs | model stubs rewritten unconditionally; per-model `stat` + `sha1_file` + `glob` | content-compared; the sweep is one pass over a set, skipped entirely on a no-change build |
| 7 `_sweep_derived_caches` | every build, content-hashing every file | when a file was REMOVED, else at most hourly (stamp under `rsx-tmp/derived/`). It reclaims DISK, never correctness, so a deferred sweep costs nothing |
| 7 `view:clear` | every build | only when a `.blade.php` changed or was removed - it exists to stop a stale `@rsx_extends` surviving a rename, and nothing else can create one |
| 7 override pass | re-adjudicated all 640 class names | acts only on names a dirty file declares; the restore pass gets its `.upstream` entries from the same single pass that builds the active class map |
| 7 composer classmap | every rebuild (~15k warm stats) | only when the override pass actually renamed something (`Manifest::$_override_pass_renamed`, sticky across restarts) |
| 7 duplicate-class check | three detectors | ONE: `_collate_files_by_classes()`. The copy in `_validate_manifest_data()` is gone |
| 2 Php_Fixer | five whole-map scans PER FILE; a full pass over the tree on any structural change | one class index per RUN (`Php_Fixer::begin_run()`); a structural change fixes the changed files plus the files that REFERENCE a class in the delta |

**The fixer's memory is its own file.** `storage/rsx-build/php_fixer_structure.php`
holds `class name => "file|parent"` for the whole tree, and it is written AFTER the
re-parse of what the fixer rewrote - recording the PRE-fix shape guaranteed a second
full pass on the next build. It is not in the index because the hot half is included
on every request and a request reads none of it, and the cold half is a flat `files`
map with no room for another section. A lost copy costs one full fixer pass.

### Phase 4: Reflection
- Implemented in `Manifest_Scanner::_extract_reflection_data()`, over the CHANGED set only
- PHP and JavaScript are parsed directly by the scanner (NOT modules) - PHP attribute/method reflection and JS class extraction are first-class
- Stores ALL attributes without validation (agnostic extraction)

### Phase 5: Modules
- Per-file modules (`config('rsx.manifest_modules')`) already ran during the scan, in `priority()` order: `Blade_ManifestModule`, `Scss_ManifestModule` (`Integrations/Scss`)
- Support modules (`config('rsx.manifest_support')`) run here, in the listed order, each handed the CHANGED and REMOVED sets - see THE INCREMENTAL MODULE CONTRACT
- The last three entries are the STUB GENERATORS, which are ordinary support modules that happen to write files (see THE STUB GENERATORS ARE MODULES)

### Phase 6: Stub Generation
- `Controller_Stub_ManifestSupport` -> `storage/rsx-build/js-stubs/`
- `Model_Stub_ManifestSupport` -> `storage/rsx-build/js-model-stubs/`
- `Auth_Stub_ManifestSupport` -> `storage/rsx-build/js-auth-stubs/`
- Every generator CONTENT-COMPARES before writing, so a rebuild that changes no source rewrites no stub and recompiles no bundle

### Phase 7: Cache Writing
- Implemented in `Manifest_Store::_save()`
- Writes `storage/rsx-build/manifest_index.php` and `manifest_files.php` (see THE INDEX IS
  TWO FILES, above)
- Emits a COMPACT PHP literal (short arrays, no whitespace), STREAMED to the temp file in
  chunks - `var_export()` built the whole 8.8 MB file as one string first
- Both halves temp-then-rename with an `opcache_invalidate()` each; `build_key` renamed LAST,
  so a reader that sees a new key can read both halves
- No `generated` timestamp in the body, in any mode: two builds of an unchanged tree are
  byte-identical

## Key Implementation Details

### Class Name Normalization Philosophy

**CRITICAL**: RSX enforces unique simple class names across the entire codebase. This architectural constraint allows the manifest to normalize ALL class references to simple names, eliminating an entire class of bugs related to namespace format variations.

**The Problem**: PHP allows multiple ways to reference classes:
- `\Rsx\Lib\DataGrid` (leading backslash)
- `Rsx\Lib\DataGrid` (no leading backslash)
- `DataGrid` (simple name)

**The Solution**: `Manifest::_normalize_class_name()` strips namespace qualifiers:

```php
public static function _normalize_class_name(string $class_name): string
{
    // Strip leading backslash
    $class_name = ltrim($class_name, '\\');

    // Extract just the class name (last part after final backslash)
    $parts = explode('\\', $class_name);
    return end($parts);
}
```

**Applied At**:
1. **Token parsing** - `Php_Parser::_extract_class_info()` normalizes `extends` at extraction
2. **Parent lookups** - `Manifest::_load_class_hierarchy()` normalizes before comparison
3. **All class name operations** - Any code comparing class names uses normalization

**Why This Works**: RSX's unique simple class name enforcement means we only need FQCNs at actual `include_once` time. Throughout the manifest, simple names are sufficient and eliminate format inconsistencies.

### Static State Management

The Manifest uses public static properties (accessible by helper classes):

```php
public static ?array $data = null;           // Cached manifest data
public static bool $_has_init = false;       // Initialization flag
public static bool $_needs_manifest_restart = false; // Restart signal
public static string $_restart_reason = '';  // WHY the pass asked to restart
public static ?ManifestKernel $kernel = null; // Kernel instance
public static array $_changed_files = [];    // Files changed in THIS BUILD (all passes)
public static ?Manifest_Build $_build = null; // The build underneath the facade
```

### RESTART SEMANTICS

`_refresh_manifest()` uses a `goto manifest_start` to start the build over when a pass changed
the tree under itself - a class override archived, a file auto-renamed. Four rules govern it:

1. **The change memo is cleared at `manifest_start`.** `_has_changed()` memoizes "this file
   matches what the index records", and a pass that rewrote source (the fixer) or renamed a
   file has just made every entry a lie. It used to survive the goto, and a file the fixer had
   just rewritten reported UNCHANGED in pass two - its new bytes never reached the index.
   (`_load_cached_data()` clears it too, for the same reason on a different path.)
2. **`$_changed_files` ACCUMULATES across passes** rather than being overwritten. A restart
   re-runs discovery against a tree the previous pass already brought up to date, so pass two
   legitimately sees fewer changed files - and the quality gate and the `rsx.rebuilt` payload
   are about the whole build. Overwriting meant a build that restarted could run its quality
   gate over nothing.
3. **Restarts are BOUNDED at `Manifest::MAX_BUILD_RESTARTS` (3)**, then throw, naming the
   reason the last restart was requested. Three is the honest ceiling: an override archive, a
   restore and a rename can each fire once legitimately; a fourth means two passes are undoing
   each other. `flag_needs_restart($reason)` is what records the reason.
4. **The build lock is released in a `finally`.** A code-quality violation, an unparseable
   file or a bounded restart loop all leave `init()` by exception, and each used to leave the
   system build lock HELD - a lock with no lease and no TTL, so the next process to want it
   waited forever on a build that had already failed. The same `finally` releases the build's
   source cache.

### Public API Methods

#### Initialization & Loading
- `init()` - Ensures manifest is loaded (auto-rebuilds in dev mode)
- `get_full_manifest()` - Returns complete manifest structure with metadata
- `clear()` - Clears the in-memory cache

#### File Lookups
- `get_all()` - Returns all files array
- `get_file($path)` - Get specific file metadata (throws if not found)
- `get_files_by_dir($dir)` - Get all files in directory
- `get_changed_files()` - Get files that changed in most recent manifest scan (for incremental code quality checks)

#### PHP Class Resolution
- `php_find_class($name)` - Find by simple class name
- `find_php_fqcn($fqcn)` - Find by fully qualified name
- `php_get_metadata_by_class($name)` - Get metadata by class name
- `php_get_metadata_by_fqcn($fqcn)` - Get metadata by FQCN
- `php_get_extending($parent)` - Find all concrete classes extending parent (filters out abstract)
- `php_is_subclass_of($sub, $super)` - Check inheritance

#### JavaScript Class Resolution
- `js_find_class($name)` - Find JavaScript class file
- `js_get_extending($parent)` - Find extending JS classes
- `js_is_subclass_of($sub, $super)` - Check JS inheritance

#### View Resolution
- `find_view($id)` - Find by dot notation (e.g., 'frontend.index')
- `find_view_by_rsx_id($id)` - Find by @rsx_id value

#### Attribute & Route Discovery
- `get_with_attribute($attr)` - Find all files with specific attribute
- `get_routes()` - Extract all route definitions from attributes

#### Utility Methods
- `get_build_key()` - Get manifest hash for cache validation
- `get_stats()` - Get statistics (file counts, build time)
- `get_autoloader_class_map()` - Get class-to-file mappings
- `is_built()` - Check if manifest exists
- `_normalize_class_name($class_name)` - Strip namespace qualifiers to get simple name

### Attribute Extraction Philosophy

The manifest practices **agnostic attribute extraction**:

1. **No Validation** - Stores all attributes without checking validity
2. **No Instantiation** - Never creates attribute objects
3. **No Class Loading** - Attributes don't need backing classes
4. **Raw Storage** - Stores exactly what reflection provides

Example from `Manifest_Scanner::_extract_reflection_data()`:
```php
foreach ($method->getAttributes() as $attribute) {
    $attributes[] = [
        'name' => $attribute->getName(),
        'arguments' => $attribute->getArguments()
    ];
}
```

### Change Detection

Files are tracked by:
- `mtime` - Modification time (primary)
- `size` - File size in bytes (secondary)
- `hash` - SHA1, only computed when mtime/size change

The `Manifest_Scanner::_has_changed()` method compares these to detect changes.

### Error Handling

All lookup methods throw `RuntimeException` when items not found:
```php
if (!isset(Manifest::$data['data']['php_classes'][$class_name])) {
    throw new RuntimeException("PHP class not found in manifest: {$class_name}");
}
```

This ensures **fail-fast behavior** - no silent failures.

## Module System

PHP and JavaScript are parsed directly in Manifest.php and are NOT modules.
Modules cover additional file types and post-scan derivations. There are two
kinds, both abstract base classes (NOT an interface).

### Built-in Per-File Modules (`config('rsx.manifest_modules')`)

- `Blade_ManifestModule` (`Core/Manifest/Modules/`) - Blade directive parsing
- `Scss_ManifestModule` (`Integrations/Scss/`) - SCSS metadata

### Built-in Support Modules (`config('rsx.manifest_support')`, run in order)

- `Route_ManifestSupport`, `Portal_Route_ManifestSupport`, `Portal_Spa_ManifestSupport`
- `Model_ManifestSupport` (`Core/Manifest/Modules/`) - database schema extraction
- `Jqhtml_ManifestSupport` (`Integrations/Jqhtml/`) - jqhtml component registration
- `Spa_ManifestSupport`, `Api_Endpoint_ManifestSupport`

### Creating a Per-File Module

Extend `ManifestModule_Abstract`:

```php
abstract class ManifestModule_Abstract {
    abstract public function handles(): array;   // extensions, no dot
    abstract public function priority(): int;    // lower = earlier
    abstract public function process(string $file_path, array $metadata): array;
}
```

Register the class in `config('rsx.manifest_modules')`.

### Creating a Support Module

Extend `ManifestSupport_Abstract` (runs after the full manifest is built;
mutates the data by reference):

```php
abstract class ManifestSupport_Abstract {
    abstract public static function process(
        array &$manifest_data,
        array $changed_files,
        array $removed_files
    ): void;
    abstract public static function get_name(): string;
    public static function should_run(): bool { return true; }
}
```

Register the class in `config('rsx.manifest_support')`.

## THE TWO MODULE CONTRACTS

**A module is one of two kinds, and the difference is COST.**

**FULL (`Full_ManifestSupport_Abstract`, implements `rebuild(&$data)`).** A module whose
whole job is to read values ALREADY INDEXED in the manifest and regroup them derives its
section outright, every build. It never sees a changed set - `process()` is `final` on the
base and discards it - and carries no dirty machinery at all. It assigns its section rather
than merging into it, so the result depends on nothing but the manifest it was handed.

**DELTA (`ManifestSupport_Abstract`, implements `process(&$data, $changed, $removed)`).**
For modules that do real per-file work: read source off disk, `include_once` a class to
reflect on it, or write a generated stub. Its section is CARRIED FORWARD (see
`Manifest::MODULE_OWNED_SECTIONS`), so its job is a diff - drop every entry derived from a
changed or removed file, then re-derive what the changed files declare now. On a cold build
both sets name the whole tree, so the diff IS the full build.
`dirty_set($changed, $removed)` on the base is the hash set rows are tested against.

**WHY THE SPLIT IS A CORRECTNESS PROPERTY, NOT TIDINESS (owner ruling 2026-09-09).** A
diffing section is only ever as good as what it carries forward. Lose it and nothing
restores it, because restoration only happens for files that CHANGE and an unchanged tree
has none. That state was reached: the standard route table was found EMPTY against a fully
populated file index - every page 404, all eight bundles failing to compile - and no
ordinary rebuild fixed it. Touching one controller restored exactly that controller's
routes. `rsx:manifest:build --force` was the only cure, and it works solely by making every
file dirty at once. Deriving in full makes that state unreachable, and the cost is a loop
over a few thousand in-memory records on the only occasion it runs, which is a code change
- never a served request.

| Kind | Modules |
|---|---|
| **FULL** | `Route`, `Portal_Route`, `Spa`, `Portal_Spa`, `Api_Endpoint`, `Auth`, `Jqhtml`, `Externals`, `Bundle_Alias` |
| **DELTA** | `Model` (includes the model file to reflect on it), `Task_Command` and `Email` (read source), and the three STUB GENERATORS `Controller_Stub` / `Model_Stub` / `Auth_Stub` (write generated files) |

`Api_Endpoint` is the one full module that touches disk: it reads docblocks for the API
catalog, but only for files that actually declare an endpoint, and through the build's
`Source_Cache`. Paying that every build is the deliberate trade against a catalog that can
silently empty itself.

**A full module still OWNS ONLY ITS OWN ROWS.** `routes` is shared by three modules
(`standard`, `spa`, `api`) and `portal_routes` by two, so each resets by TYPE rather than
clearing the section.

**ORDER IS UNCHANGED AND STILL LOAD-BEARING.** Both kinds live in the one ordered
`config('rsx.manifest_support')` list and run in that order, because several modules read a
section an earlier one produced (`Api_Endpoint` reads `routes`). The full contract is a
different CALLING CONVENTION, not a separate phase.

**ORDER IS NORMALIZED CENTRALLY.** Several modules write the same section (Route, Spa
and Api_Endpoint all write `routes`) and an incremental update APPENDS to a
carried-forward array, so Phase 5 ksorts every module-owned section once, after the
last module. Key order is part of the index's bytes and therefore part of the build
key: without this, two identical trees could disagree about their own hash. The files
map is ksorted immediately after Phase 2 for the same reason - `attribute_index`'s
per-attribute rows, the event-handler index and `classless_php_files` are LISTS whose
order is the file map's.

`tests/manifest/php/Manifest_Incremental_Modules_Test` is the contract as an
assertion: build a fixture tree, edit one JS file, rebuild incrementally, build the
same tree cold in a fresh storage root, and require every derived section and every
file record to match.

## THE STUB GENERATORS ARE MODULES

`Controller_Stub_ManifestSupport`, `Model_Stub_ManifestSupport` and
`Auth_Stub_ManifestSupport` are the last three entries in the SAME ordered list.
There is no `IntegrationRegistry` sweep by `method_exists` any more, and
`BundleIntegration_Abstract::generate_manifest_stubs()` is gone; the
`*_BundleIntegration` classes keep only their integration duties (the auth one keeps
`STUB_DIR` and the compiler-facing `get_mirror_stub_paths()`).

**Every generator CONTENT-COMPARES before writing.** A write that changes nothing
still moves the mtime, and a moved mtime recompiles every bundle carrying the stub.
The model generator additionally records WHICH INPUTS its metadata hash was computed
for (`model_metadata_inputs` = model file hash + column-map hash), so the expensive
reflection behind that hash is not run in order to decide whether to run it.
`tests/manifest/php/Manifest_Stub_Rewrite_Test` is the mtime assertion.

## Performance Considerations

### Caching Strategy
- **Development**: Auto-rebuilds on file changes
- **Production**: Manual rebuild required, loads from cache file
- **Memory**: Full manifest kept in static variable after first load

### Optimization Points
- Token parsing avoids loading PHP files unnecessarily
- Dependency ordering minimizes class loading failures
- SHA1 hashing only when size/mtime indicate changes
- A compact PHP literal (short arrays, no whitespace) STREAMED to the temp file - `var_export()`
  built the whole 8.8 MB file as one string first - and included, so OPcache serves it where
  OPcache is on

### The numbers, and where they come from

Cold ~6.5 s / 107.5 MB peak; no-change rebuild 0.26 - 0.37 s; a one-file rebuild inside a web
request 0.37 s (scss) to 0.69 s (php); the test-tree transition 2.3 - 3.2 s in and ~0.7 s out.
The largest item in a cold build is the code-quality pass, and inside it the four cross-file
ANCESTRY rules (~1.5 s between them, down from 5.2 s once `Source_Cache::declared_members()`
let them stop re-parsing the tree one after another).

`console_debug('MANIFEST', ...)` prints a per-module and a per-cross-file-rule wall time on
every build, so the attribution is readable without a probe:
`CONSOLE_DEBUG_FILTER=MANIFEST php artisan rsx:manifest:build`. **A run that lost the build
lock measured a LOAD, not a build** (peak ~46 MB, no rule lines) - discard it.

## Common Issues & Solutions

### Issue: "Class not found" during reflection
**Cause**: Parent class not loaded before child
**Solution**: Check `Manifest::_load_class_hierarchy()` is working correctly

### Issue: Parent class not found (extends mismatch)
**Cause**: Namespace format variations in `extends` declarations (e.g., `\Rsx\Lib\DataGrid` vs `Rsx\Lib\DataGrid`)
**Solution**: Use `Manifest::_normalize_class_name()` to strip namespace qualifiers before comparison

### Issue: Attributes not appearing in manifest
**Cause**: Method not public static
**Solution**: Only public static methods are indexed

### Issue: Manifest not updating
**Cause**: In production mode or file timestamps unchanged
**Solution**: Run `php artisan rsx:manifest:build --force`

### Issue: JavaScript stubs not generating
**Cause**: Missing `Ajax_Endpoint` attribute or method not public static
**Solution**: Check method has attribute and correct visibility

## The override pass and the SPLIT refusal

`Manifest_Indexer::_check_unique_base_class_names()` archives the framework twin
when exactly one `rsx/` file declares the same simple name. Two of its four exits are FATAL.
The first refuses an `rsx/` class that declares `extends <its own name>`. The second is the
SPLIT refusal - when the framework file about to be archived declares nothing but
`class X extends X_Abstract`, the `rsx/` class MUST extend that same `X_Abstract`, and
anything else (a copy of the framework file, a different parent, no parent) throws
"Fatal: Invalid override of the split framework class 'X'" naming the override file, the
parent it declares and the base it must extend. A copy is a second implementation of a class
the framework keeps developing: every member added to the base afterwards is missing from it
and core calls those members regardless, which is the failure the split exists to end - so it
is refused rather than reported. The other two exits stay informational (a stale index entry,
a poisoned manifest) and archive nothing. Contract: `rsx:man class_override`, section WHEN
THE BUILD REFUSES.

## Class_Override_Drift (the sidecar comparison)

`Core/Manifest/Class_Override_Drift.php` is a plain helper beside the manifest, not part
of the build. It pairs every `php.upstream` entry with the active `php` entry of the same
simple `class` (which, by construction of the override pass, is the rsx/ file shadowing
it) and compares the members each FILE declares.

**It reads the files, not the manifest's method metadata, and that is forced.**
`_extract_reflection_data()` runs only for extension `php` — an archived file is never
loaded, because its class name belongs to the override — so an `.upstream` entry carries
`class`/`namespace`/`extends`/`static_properties` and no methods at all. And reflection on
the override would report its whole LINEAGE, when the only thing a re-clone can be measured
against is what the file itself declares. So `declared_members()` is a `PhpToken` pass over
each file: public/protected methods, declared properties, and class-body `use Trait;`
adoptions, keyed `method:` / `property:` / `trait:` so two files compare directly.

**A SPLIT class is skipped.** When the archived file declares nothing but
`class X extends X_Abstract` and the override extends that same base, there is no frozen copy
to measure - the override inherits every member the base has now and every member it gains
later - so `analyze_pair()` returns an empty result rather than naming the base's members as
missing. `declared_parent()` is the token reader that decides it (simple name, qualified
spelling accepted). An override of a split class that does NOT extend the base never reaches
here: `_check_unique_base_class_names()` refuses it, naming the abstract.

Consumed by `CLASS-OVERRIDE-DRIFT-01` (`CodeQuality/Rules/Convention/`) and by the
`Class Override Drift` health row. Contract: `rsx:man class_override`, section DRIFT.

## Code Quality Integration

`Manifest_Indexer::_run_manifest_time_code_quality_checks()` is one call into
`App\RSpade\CodeQuality\Manifest_Rule_Driver`, handing it the build's changed-file list and
the build's `Source_Cache`. The DRIVER owns everything else - discovery, patterns, reading,
parsing, the per-file ledger skip and the cross-file dependency gate. See
`CodeQuality/CLAUDE.md` and `rsx:man code_quality`.

**Three classes, and the build owns none of their internals.** `Manifest_Rule_Driver` is the
pass (`rsx:check` runs the same one); `Support/Source_Cache` is the ONE reader, tokenizer and
parser, shared with `Php_Fixer` so a file read in phase 2 is not read again in the pass, and
LRU-bounded at 16 during a build (`Manifest_Build::BUILD_SOURCE_CACHE_CAPACITY`);
`Support/Validation_Ledger` is the ONE memory of "already passed", at
`storage/rsx-tmp/persistent/validation_ledger.php`, keyed on the manifest's own file hash so a
verdict survives a manifest clear. The build hands over the changed set and the cache and takes
back a verdict; everything else is the driver's contract, in `rsx:man code_quality` and skill
`rspade:code-quality-rules`.

**A manifest-time RULE or MODULE cannot use the build-scoped `RsxCache`.** Its key is prefixed
with `Manifest::get_build_key()`, and during a build the manifest is not ready - the accessor
raises `shouldnt_happen('called before manifest was loaded')`. `RsxCache::get_persistent()` /
`set_persistent()` keyed on a CONTENT HASH is the tool; `Manifest_Indexer`'s unindexed-framework
-class map (keyed on a stat fingerprint, prefix `..._v1_`) is the worked example.

There is no `code_quality_metadata` in the index any more, and no `on_manifest_file_update`
hook: a rule that used to compute findings in a pre-pass and store them for its own `check()`
to read now simply computes them in `check()`, which the driver already hands the content.

### Manifest-Time Checks Run AFTER the Save (save-then-check)

The manifest-time violation pass runs AFTER the manifest is persisted, not before:

1. `_save()` writes the fully-built manifest. At this moment `$_manifest_is_bad` is still
   `false`, so a CLEAN manifest is written and every processed file's mtime/size/hash is
   current on disk. `_save()` also CLEARS the bad-manifest flag: a build that completed
   supersedes whatever poisoned the one before it.
2. `_run_manifest_time_code_quality_checks()` runs the pass, gated to development + a
   non-empty changed set + not a migration context.

A single violation calls `_set_manifest_is_bad()` and the driver throws
`YoureDoingItWrongException` to abort the request/build.

### The `manifest_is_bad` flag - a Failed Rule Keeps Failing Until Fixed

The guarantee does NOT come from change tracking: the clean `_save()` already refreshed the
offending file's mtime/size/hash, so incremental detection alone would consider it unchanged.
It comes from the flag.

**The flag is a SIDECAR FILE**, `storage/rsx-build/manifest_is_bad`, and raising it is ALL
`_set_manifest_is_bad()` does. Its existence is the whole signal; its content is a sentence
for a human. `_load_cached_data()` refuses the cache outright while it exists, so `init()`
forces a FULL rebuild, the pass re-fires the same violation, and the build aborts again -
every request, until the source is fixed.

**It used to call `_save()` from inside a FAILING build**, which overwrote the last good index
with the half-built one the process happened to be holding, plus a `build_key` computed from
it. Never write an index from a failure path; write the flag.

### Incremental behaviour

`get_changed_files()` returns the files that changed in THIS BUILD, accumulated across
restarts. The driver uses it as the outer loop of the per-file pass; cross-file rules do not
read it at all, because their gate is the fingerprint of what they declare in `depends_on()`.

## Debugging

Enable verbose output:
```php
Manifest::$_debug_options['verbose'] = true;
```

Or via command:
```bash
php artisan rsx:manifest:build --verbose
```

## Testing Considerations

When testing manifest functionality:
1. Build a FIXTURE TREE rather than mocking: `Manifest::_use_build_for_tests()` swaps the
   `Manifest_Build` in process, and the four `--_manifest-*` internal flags drive a CHILD
   build into a scratch storage root. `tests/manifest/` is the worked example, and an extra
   root can never be the only root (the framework's own support modules and parent classes
   resolve THROUGH the index)
2. Use `Manifest::clear()` between tests to reset state
3. There is no `scan()` / `rebuild()`: `init()` is the single entry point, and
   `_refresh_manifest()` is the loop underneath it
4. Verify exception throwing for not-found cases
5. Check stub generation for API methods - and their mtimes, which is what
   `Manifest_Stub_Rewrite_Test` asserts
6. An incremental index must be BYTE-IDENTICAL to a cold one over the same tree
   (`Manifest_Incremental_Modules_Test`); two determinism defects were found by requiring it

## Important Constants & Paths

- Cache files: `storage/rsx-build/manifest_index.php` (hot) and `manifest_files.php` (cold)
- JS stubs: `storage/rsx-build/js-stubs/`
- Model stubs: `storage/rsx-build/js-model-stubs/`
- Scan dirs (`Manifest::scan_directories()`): `['rsx', 'app/RSpade/Core', 'app/RSpade/Integrations', 'app/RSpade/Bundles', 'app/RSpade/Breadcrumbs', 'app/RSpade/CodeQuality', 'app/RSpade/Lib', 'app/RSpade/Sys']` - the last is the framework's own application tree (the /_sys control panel) - plus `app/RSpade/tests`, `app/RSpade/temp` and `rsx/tests` while the process is a test run

## Direct Data Access

When bypassing helper methods to access manifest data directly:

```php
$manifest = Manifest::get_full_manifest();

// Access files
$file_data = $manifest['data']['files']['path/to/file.php'];

// Access class maps
$class_record = $manifest['data']['php_classes']['ClassName'];  // ['file','fqcn','extends','abstract']
$js_record = $manifest['data']['js_classes']['JsClass'];        // ['file','extends']

// Access models with schema
$model = $manifest['data']['models']['User_Model'];
$columns = $model['columns'];
```

Use `rsx:manifest:schema_dump` to understand the exact structure before writing direct access code.
