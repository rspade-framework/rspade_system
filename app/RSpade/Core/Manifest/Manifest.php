<?php

namespace App\RSpade\Core\Manifest;

use Exception;
use Illuminate\Support\Facades\File;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use App\RSpade\Core\ExtensionRegistry;
use App\RSpade\Core\Kernels\ManifestKernel;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\Manifest_Build;
use App\RSpade\Core\Manifest\Manifest_Indexer;
use App\RSpade\Core\Manifest\Manifest_Scanner;
use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Rsx;

/**
* Manifest - RSX File Discovery and Metadata Management System
*
* PURPOSE: Discovers, indexes, and caches metadata about all RSX files for fast lookups
*
* PATH HANDLING:
* - All file paths are stored as RELATIVE paths from base_path()
* - Use base_path($relative) to get absolute paths when needed
* - Laravel's base_path() always returns the project root directory
*
* PROCESSING MODEL - 5-PHASE ARCHITECTURE:
*
* When manifest needs updating (scan() or rebuild()):
*   Phase 1: File Discovery - Scan directories and detect changes
*   Phase 2: Parse Metadata - Token parsing for PHP/JS structure
*            - NO reflection used, NO files loaded
*   Phase 3: Load Dependencies - Load PHP classes in dependency order
*            - Ensures all classes are available for reflection
*   Phase 4: Extract Reflection - PHP reflection data extraction
*            - Attributes, methods, parameters, etc.
*   Phase 5: Process Modules - Run manifest support modules, build autoloader
*            - Every module gets the CHANGED and REMOVED sets and updates its own
*              section incrementally (ManifestSupport_Abstract)
*   Phase 6: Generate Stubs - JavaScript API, model and auth-mirror stubs. These are
*            the last three modules in the Phase-5 list, not a second module system
*   Phase 7: Save & Finalize - Write cache, clear views, run quality checks
*
* When loading from valid cache:
*   - Only loads cached data, NO file scanning or processing
*   - Autoloader handles on-demand class loading
*
* CORE OPERATIONS:
* 1. init() - Ensures manifest is ready (loads cache + scans for updates, or rebuilds if no cache)
* 2. scan() - Incrementally updates manifest for changed files only (called by init())
* 3. rebuild() - Forces complete re-scan of all RSX files
* 4. clear() - Removes all cached manifest data
*
* FILE PROCESSING:
* - Scans /rsx/ directory recursively (excludes vendor, node_modules, etc.)
* - Extracts metadata from PHP files (classes, methods, attributes, namespaces)
* - Parses JavaScript/TypeScript files (imports, exports, classes)
* - Indexes Blade templates and views (sections, extends, view IDs)
* - Uses token parsing for basic info, reflection for detailed metadata
*
* QUERY METHODS:
* - php_find_class() - Locate PHP class by name
* - find_php_fqcn() - Locate PHP class by fully qualified name
* - js_find_class() - Locate JavaScript class
* - find_view() - Locate view template by ID
* - get_extending() - Find all classes extending a parent
* - get_with_attribute() - Find classes/methods with specific attributes
* - get_routes() - Extract all route definitions from attributes
* - get_stats() - Statistical summary of manifest contents
*
* DEBUGGING:
* - Use `php artisan manifest:dump` to view complete manifest contents
* - Supports JSON (default), YAML, and PHP export formats
* - Can filter by path or class name for targeted debugging
*
* DATA STRUCTURE (static::$data):
* [
*   'rsx/path/to/file.php' => [  // Keys are relative to base_path()
*     'file' => 'rsx/path/to/file.php',  // Relative to base_path()
*     'hash' => 'sha1_hash_of_file_contents',
*     'mtime' => unix_timestamp,
*     'size' => bytes,
*     'extension' => 'php',
*
*     // PHP-specific fields:
*     'namespace' => 'App\\Controllers',
*     'class' => 'UserController',
*     'fqcn' => 'App\\Controllers\\UserController',
*     'extends' => 'BaseController',
*     'implements' => ['Interface1', 'Interface2'],
*     'traits' => ['TraitName'],
*     'attributes' => [
*       'Route' => [['pattern' => '/users', 'methods' => ['GET']]],
*       'Cache' => [['ttl' => 3600]]
*     ],
*     'methods' => [
*       'index' => [
*         'name' => 'index',
*         'static' => false,
*         'visibility' => 'public',
*         'attributes' => ['Route' => [['/users', 'GET']]],
*         'parameters' => [
*           ['name' => 'request', 'type' => 'Request', 'nullable' => false]
*         ]
*       ]
*     ],
*     'properties' => [
*       ['name' => 'prop', 'visibility' => 'private', 'static' => false]
*     ],
*
*     // JavaScript-specific fields:
*     'imports' => [['from' => 'react', 'imports' => 'React']],
*     'exports' => ['ComponentName'],
*     'default_export' => 'MainComponent',
*     'public_static_methods' => ['getInstance'],
*     'static_properties' => ['instance'],
*
*     // View-specific fields:
*     'view_id' => 'user-profile',
*     'sections' => ['content', 'sidebar'],
*     'extends' => 'layouts.main'
*   ],
*   // ... more files
* ]
*
* CACHING:
* - Stores as two compact PHP literals under /storage/rsx-build/ for fast include()
* - Also exports as JSON for JavaScript tooling compatibility
* - Uses file size + mtime for change detection (fast, avoids unnecessary hashing)
*/
class Manifest
{
    /**
    * Debug options for controlling manifest behavior from commands
    * Set by commands like rsx:manifest:build to control processing
    */
    public static $_debug_options = [];

    /**
    * Special directories that are excluded from manifest
    * @deprecated Use config('rsx.manifest.excluded_dirs') instead
    */
    public const EXCLUDED_DIRS = ['resource', 'public', 'vendor', 'node_modules', '.git', 'storage'];

    /**
    * File extensions to process
    * @deprecated Use ExtensionRegistry::get_all_extensions() instead
    */
    public const PROCESSABLE_EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'phtml', 'scss', 'less', 'css', 'blade.php'];

    /**
    * The HOT index, RELATIVE to the storage root (storage_path()).
    *
    * Every derived section plus the `files` entries whose method map is read at request time.
    * This is the only file a served request includes.
    */
    public const CACHE_FILE = 'rsx-build/manifest_index.php';

    /**
    * The COLD half, RELATIVE to the storage root: every other `files` entry, method maps
    * intact. Loaded ONCE per process, by the first accessor that needs a record the hot file
    * does not carry. Boot, dispatch, an Ajax call and a model fetch never do.
    */
    public const COLD_FILE = 'rsx-build/manifest_files.php';

    /**
    * The fixer's memory of the tree's class SHAPE (class name => "file|parent").
    *
    * Its OWN file, deliberately: it is build-only state that no request reads, and the two
    * places it could otherwise live are both wrong. The hot index is included on every
    * request, so 640 rows of build bookkeeping would be a per-request tax on data nothing
    * serves; the cold file is a flat `files` map with no room for a second section. It is
    * not atomic with the index and does not need to be - a lost or stale copy costs one
    * full Php_Fixer pass and nothing else.
    */
    public const PHP_FIXER_STRUCTURE_FILE = 'rsx-build/php_fixer_structure.php';

    /**
    * The loaded manifest data structure:
    * [
    *   'hash' => the build key,
    *   'data' => ['files' => [...file metadata...]]
    * ]
    */
    public static ?array $data = null;

    /**
    * Whether data has been loaded
    */
    public static bool $_has_init = false;

    /**
    * Flag to signal manifest needs to restart due to file rename
    */
    public static bool $_needs_manifest_restart = false;

    /**
    * Why the current pass asked to restart - named by the throw that bounds the loop.
    */
    public static string $_restart_reason = '';

    /**
    * How many times ONE _refresh_manifest() call may start over before it is a loop rather
    * than a settling tree. Three is the honest ceiling: an override archive, a restore and a
    * rename can each legitimately fire once in one build, and a fourth means the passes are
    * undoing each other.
    */
    public const MAX_BUILD_RESTARTS = 3;

    // The manifest kernel instance (cached) (???)
    public static ?ManifestKernel $kernel = null;

    public static $_manifest_compile_lock;

    public static $_get_rsx_files_cache = null;

    public static array $_has_changed_cache = [];

    /**
    * Whether the COLD half of the index has been merged into $data['data']['files'].
    */
    public static bool $_cold_loaded = false;

    /**
    * How many times this process merged the cold half. The point of the split is that a
    * served request never does, so the count is the evidence - the --_manifest-report-cold
    * internal flag prints it at shutdown and tests/manifest asserts on it.
    */
    public static int $_cold_load_count = 0;

    public static bool $_has_manifest_ready = false;

    public static bool $_manifest_is_bad = false;

    // Track if we've already shown the manifest rescan message this page load
    public static bool $__shown_rescan_message = false;

    // Files that changed in the most recent manifest scan (for incremental code quality checks)
    public static array $_changed_files = [];

    /**
    * Did the class-override pass RENAME anything on disk during this build?
    *
    * Sticky for the whole build (it survives a restart, because the rename is what CAUSED
    * the restart and the composer classmap is only validated once the tree has settled).
    * A build that archived, removed or restored nothing cannot have staled composer's
    * committed classmap, and the ~15k warm stats that validation costs are then work for
    * nothing.
    */
    public static bool $_override_pass_renamed = false;

    // Files that LEFT the tree in the most recent manifest scan, accumulated across restarts.
    // The other half of the support modules' diff contract: a module cannot know which of its
    // rows to drop from the changed list alone, because a deleted file appears in no list a
    // scan produces.
    public static array $_removed_files = [];

    /**
    * The index sections a SUPPORT MODULE owns and maintains INCREMENTALLY.
    *
    * These are carried forward across a rebuild instead of being reset, because that is what
    * makes `ManifestSupport_Abstract::process($data, $changed, $removed)` a diff rather than a
    * full pass: the module drops the entries its dirty files produced and re-derives only
    * those. A cold build starts with none of them present, so the same code path builds the
    * whole section.
    *
    * A section NOT listed here is a pure function of the files map and is rebuilt every pass
    * (php_classes, attribute_index, blade_views, event_handlers, autoloader_class_map, ...).
    */
    public const MODULE_OWNED_SECTIONS = [
        'routes',
        'portal_routes',
        'api_endpoints',
        'jqhtml',
        'external_resources',
        'task_commands',
        'emails',
        'bundle_aliases',
        'auth',
        'models',
    ];

    /**
    * The build underneath this facade - scan roots, storage root, mode, source cache.
    * Constructed from config on first use; a test replaces it to build a fixture tree
    * under a scratch storage root.
    */
    public static ?Manifest_Build $_build = null;

    // Flag to allow forced rebuilding in production-like modes (used by rsx:prod:build)
    public static bool $_force_build = false;

    // True when this process's init() actually (re)scanned/rebuilt the manifest
    // (incremental update WITH changes, or a no-cache full build). Stays false on a
    // warm boot that loaded a valid cache. Drives the rsx.rebuilt* lifecycle events
    // and is exposed cheaply via rebuild_occurred(). One value per process.
    public static bool $_rebuild_occurred = false;

    // ========================================
    // Query Methods
    // ========================================

    /**
    * Get all manifest data (just the files, not metadata)
    */
    public static function get_all(): array
    {
        static::init();
        static::_load_cold_files();

        return static::$data['data']['files'] ?? [];
    }

    /**
    * Merge the COLD half of the index into `files`, once per process.
    *
    * Every accessor that can be asked about a file the hot index does not carry calls this
    * first. Semantics are unchanged for every caller - get_all() still returns the whole
    * tree - and the only observable difference is that a process which never asks never pays.
    */
    public static function _load_cold_files(): void
    {
        Manifest_Store::_load_cold_files();
    }

    /**
    * Whether this process has merged the cold half, and how many times.
    */
    public static function cold_is_loaded(): bool
    {
        return static::$_cold_loaded;
    }

    public static function cold_load_count(): int
    {
        return static::$_cold_load_count;
    }

    /**
    * The baked #[Command] table: command name => ['class', 'method', 'description'].
    *
    * Populated by Task_Command_ManifestSupport at build time and read by
    * Task_Command_Registrar at console boot. Absent (empty) on a tree whose manifest has
    * never been built - the aliases appear with the first build, like every other thing
    * the manifest provides.
    *
    * @return array<string, array{class: string, method: string, description: string}>
    */
    public static function get_task_commands(): array
    {
        static::init();

        return static::$data['data']['task_commands'] ?? [];
    }

    /**
    * Get the autoloader class map for simplified class name resolution
    * @return array Map of simple class names to arrays of FQCNs
    */
    public static function get_autoloader_class_map(): array
    {
        static::init();

        return static::$data['data']['autoloader_class_map'] ?? [];
    }

    /**
    * Get the list of files that changed in the most recent manifest scan
    *
    * Used by code quality rules that need to know which files changed for
    * incremental processing. Returns empty array if manifest was fully rebuilt.
    *
    * @return array Array of relative file paths that changed
    */
    public static function get_changed_files(): array
    {
        return static::$_changed_files;
    }

    /**
    * Get the list of files that LEFT the tree in the most recent manifest scan.
    *
    * @return array Array of relative file paths removed from the index
    */
    public static function get_removed_files(): array
    {
        return static::$_removed_files;
    }

    /**
    * Whether this process's init() actually (re)built the manifest.
    *
    * True when the scan found changed files (incremental) or performed a no-cache
    * full build; false on a warm boot that loaded a valid cache. Cheap in-memory
    * introspection for callers and tests (mirrors the rsx.ready payload's `rebuilt`).
    */
    public static function rebuild_occurred(): bool
    {
        return static::$_rebuild_occurred;
    }

    /**
    * Get data for a specific file
    */
    public static function get_file(string $file_path): array
    {
        static::init();

        // Normalize path to forward slashes
        $file_path = str_replace('\\', '/', $file_path);

        // Convert to relative path if absolute
        $base_path_normalized = str_replace('\\', '/', base_path());
        if (str_starts_with($file_path, $base_path_normalized)) {
            $file_path = str_replace($base_path_normalized . '/', '', $file_path);
        }

        // Remove leading slash if present
        $file_path = ltrim($file_path, '/');

        if (!isset(static::$data['data']['files'][$file_path])) {
            // Not in the hot half - it may still be an ordinary indexed file.
            static::_load_cold_files();
        }

        if (!isset(static::$data['data']['files'][$file_path])) {
            throw new \RuntimeException("File not found in manifest: {$file_path}");
        }

        // The path is the KEY, so the record no longer stores it as a value too (3.3% of the
        // files map was the key repeated). The getters that return a record WITHOUT its key -
        // this one, php_get_metadata_by_*, php_get_extending, js_get_extending - put it back,
        // because for their callers it is the only way to learn the path.
        return ['file' => $file_path] + static::$data['data']['files'][$file_path];
    }

    /**
    * Find a PHP class by name
    */
    public static function php_find_class(string $class_name): string
    {
        self::init();

        if (!isset(self::$data['data']['php_classes'][$class_name])) {
            throw new \RuntimeException("PHP class not found in manifest: {$class_name}");
        }

        return self::$data['data']['php_classes'][$class_name]['file'];
    }

    /**
    * Find a PHP class by fully qualified name.
    *
    * RSX enforces unique SIMPLE class names, so an FQCN's last segment is its key in the
    * class map and the lookup is O(1). This used to be a linear scan of every indexed file
    * comparing `fqcn` - on the request path, behind twelve call sites, several of them
    * per-model and per-render.
    */
    public static function find_php_fqcn(string $fqcn): string
    {
        self::init();

        $simple = self::_normalize_class_name($fqcn);
        $record = self::$data['data']['php_classes'][$simple] ?? null;

        if ($record !== null && ($record['fqcn'] ?? null) === ltrim($fqcn, '\\')) {
            return $record['file'];
        }

        throw new \RuntimeException("PHP class with FQCN not found in manifest: {$fqcn}");
    }

    /**
    * Get manifest metadata by PHP class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_class(string $class_name): array
    {
        $file = self::php_find_class($class_name);

        return self::get_file($file);
    }

    /**
    * Get manifest metadata by PHP fully qualified class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_fqcn(string $fqcn): array
    {
        $file = self::find_php_fqcn($fqcn);

        return self::get_file($file);
    }

    /**
    * Merged column map for a model class, or null when the class is not an indexed model.
    *
    * O(1) class-keyed - the same array the JS stub generator consumes, so Class-Table
    * Inheritance is already spanned (Model_ManifestSupport::__merge_detail_columns() merges a
    * base model's detail-table columns into its map before this is ever read). Column entries
    * carry the FULL metadata (type, max_length, nullable, ...), unlike db_get_table_columns()
    * which flattens each column to its type string.
    *
    * @param string $class_name Class name (FQCN or simple - normalized either way)
    * @return array|null column_name => metadata array, or null if not an indexed model
    */
    public static function php_model_columns(string $class_name): ?array
    {
        self::init();

        $class_name = self::_normalize_class_name($class_name);

        return self::$data['data']['models'][$class_name]['columns'] ?? null;
    }

    /**
    * Find a JavaScript class
    */
    public static function js_find_class(string $class_name): string
    {
        self::init();

        if (!isset(self::$data['data']['js_classes'][$class_name])) {
            throw new \RuntimeException("JavaScript class not found in manifest: {$class_name}");
        }

        return self::$data['data']['js_classes'][$class_name]['file'];
    }

    /**
    * The path of a Blade view, by its @rsx_id.
    *
    * One lookup in `blade_views`. It used to scan every indexed file for a matching `id`, on
    * every hop of every layout chain of every rendered page, and to raise the DUPLICATE-ID
    * error at render time - which is a build-time fact, and is now a build failure naming
    * both files (Manifest_Indexer::_build_blade_view_index()).
    */
    public static function find_view(string $id): string
    {
        self::init();

        $path = self::$data['data']['blade_views'][$id] ?? null;

        if ($path === null) {
            throw new \RuntimeException("View not found in manifest: {$id}");
        }

        return $path;
    }

    /**
    * Find a view by RSX ID (path-agnostic identifier)
    */
    public static function find_view_by_rsx_id(string $id): string
    {
        // This method now properly checks for duplicates
        return self::find_view($id);
    }

    /**
    * Get path for a file by its filename only (quick and dirty lookup)
    *
    * This is a convenience method for finding files when you know the filename is unique.
    * Only works for files in the /rsx directory. Fatal errors if:
    * - File not found in manifest
    * - Multiple files with the same name exist
    * - File is outside /rsx directory
    *
    * @param string $filename Just the filename with extension (e.g., "Counter_Widget.jqhtml")
    * @return string The relative path to the file (e.g., "rsx/app/demo/components/Counter_Widget.jqhtml")
    * @throws RuntimeException If file not found, multiple matches, or outside /rsx
    */
    public static function get_path_by_filename(string $filename): string
    {
        $files = self::get_all();

        $matches = [];

        foreach ($files as $path => $metadata) {
            // Only consider files in /rsx directory
            if (!Rsx_Paths::is_application($path)) {
                continue;
            }

            // Extract just the filename from the path
            $file_basename = basename($path);

            if ($file_basename === $filename) {
                $matches[] = $path;
            }
        }

        if (empty($matches)) {
            throw new \RuntimeException(
                "Fatal: File not found in manifest: {$filename}\n" .
'This method only searches files in the /rsx directory.'
            );
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(
                "Fatal: Multiple files with name '{$filename}' found in manifest:\n" .
'  - ' . implode("\n  - ", $matches) . "\n" .
'This method requires unique filenames.'
            );
        }

        return $matches[0];
    }

    /**
    * Get all classes extending a parent (filters out abstract classes by default)
    * Returns FULL file metadata indexed by class name - so it loads the cold half of the
    * index. Callers that only need names or the structural fields want
    * php_get_subclasses_of() and php_class_metadata() instead.
    */
    public static function php_get_extending(string $parentclass): array
    {
        // Get concrete subclasses only (abstract filtered out by default)
        $subclasses = self::php_get_subclasses_of($parentclass, true);

        $classpile = [];
        foreach ($subclasses as $classname) {
            $record = self::$data['data']['php_classes'][$classname] ?? null;

            if ($record !== null) {
                $classpile[$classname] = self::get_file($record['file']);
            }
        }

        return $classpile;
    }

    /**
    * The HOT CLASS RECORDS of every class extending a parent, keyed by class name.
    *
    * php_get_extending()'s cheap sibling: same set, but each value is
    * ['file', 'fqcn', 'extends', 'abstract'] rather than the class's whole file record. Use
    * it wherever the answer is "which classes, and where do they live" - the request-path
    * `Main_Abstract` and `Portal_Main_Abstract` lookups are the archetype - and reserve
    * php_get_extending() for callers that genuinely want the method map.
    *
    * @return array<string, array>
    */
    public static function php_class_records_extending(string $parentclass, bool $concrete_only = true): array
    {
        $records = [];

        foreach (self::php_get_subclasses_of($parentclass, $concrete_only) as $class) {
            $record = self::$data['data']['php_classes'][$class] ?? null;

            if ($record !== null) {
                $records[$class] = $record;
            }
        }

        return $records;
    }

    /**
    * Get all JavaScript classes extending a parent
    * Returns array of class metadata indexed by class name
    */
    public static function js_get_extending(string $parentclass): array
    {
        // Get all subclasses (JavaScript has no abstract concept)
        $subclasses = self::js_get_subclasses_of($parentclass);

        $classpile = [];
        foreach ($subclasses as $classname) {
            $record = self::$data['data']['js_classes'][$classname] ?? null;

            if ($record !== null) {
                $classpile[$classname] = self::get_file($record['file']);
            }
        }

        return $classpile;
    }

    /**
    * Check if a class is a subclass of another by traversing the inheritance chain
    *
    * @param string $subclass The child class name (simple name, not FQCN)
    * @param string $superclass The parent class name to check for (simple name, not FQCN)
    * @return bool True if subclass extends superclass (directly or indirectly), false otherwise
    */
    public static function php_is_subclass_of(string $subclass, string $superclass): bool
    {
        self::init();

        $subclass = self::_normalize_class_name($subclass);
        $superclass = self::_normalize_class_name($superclass);

        $current_class = $subclass;
        $visited = []; // Prevent infinite loops in case of circular inheritance

        while ($current_class) {
            if (isset($visited[$current_class])) {
                return false;
            }

            $visited[$current_class] = true;

            $record = self::$data['data']['php_classes'][$current_class] ?? null;

            if ($record === null || empty($record['extends'])) {
                return false;
            }

            if ($record['extends'] === $superclass) {
                return true;
            }

            // Move up the chain to the parent class
            $current_class = $record['extends'];
        }

        return false;
    }

    /**
    * Check if a PHP class is abstract
    *
    * @param string $class_name The class name to check (simple name, not FQCN)
    * @return bool True if the class is abstract, false if concrete or not found
    */
    public static function php_is_abstract(string $class_name): bool
    {
        self::init();

        $record = self::$data['data']['php_classes'][self::_normalize_class_name($class_name)] ?? null;

        return (bool) ($record['abstract'] ?? false);
    }

    /**
    * Get the full inheritance lineage (ancestry) of a PHP class
    *
    * Returns an array of parent class names from immediate parent to top-level ancestor.
    * Example: For class C extends B extends A, returns ['B', 'A']
    *
    * @param string $class_name The class name (FQCN or simple name)
    * @return array Array of parent class simple names in order from immediate parent to root
    */
    public static function php_get_lineage(string $class_name): array
    {
        self::init();

        $lineage = [];
        $current_class = self::_normalize_class_name($class_name);
        $visited = [];

        while ($current_class) {
            if (isset($visited[$current_class])) {
                break;
            }

            $visited[$current_class] = true;

            $record = self::$data['data']['php_classes'][$current_class] ?? null;

            if ($record === null || empty($record['extends'])) {
                break;
            }

            $lineage[] = $record['extends'];
            $current_class = $record['extends'];
        }

        return $lineage;
    }

    /**
    * Check if a class name corresponds to a PHP model class (exists in models index)
    *
    * This is used by the JS model system to recognize PHP model class names that may
    * appear in JS inheritance chains but don't exist as JS classes in the manifest.
    * PHP models like "Project_Model" generate JS stubs during bundle compilation.
    *
    * @param string $class_name The class name to check
    * @return bool True if this is a PHP model class name
    */
    public static function is_php_model_class(string $class_name): bool
    {
        return isset(self::$data['data']['models'][$class_name]);
    }

    /**
    * Check if a class is a subclass of another by traversing the inheritance chain
    *
    * @param string $subclass The child class name (simple name, not FQCN)
    * @param string $superclass The parent class name to check for (simple name, not FQCN)
    * @return bool True if subclass extends superclass (directly or indirectly), false otherwise
    */
    public static function js_is_subclass_of(string $subclass, string $superclass): bool
    {
        // Strip namespace if FQCN was passed (contains backslash)
        if (strpos($subclass, '\\') !== false) {
            // Get the class name after the last backslash
            $parts = explode('\\', $subclass);
            $subclass = end($parts);
        }

        if (strpos($superclass, '\\') !== false) {
            // Get the class name after the last backslash
            $parts = explode('\\', $superclass);
            $superclass = end($parts);
        }

        self::init();

        $current_class = $subclass;
        $visited = []; // Prevent infinite loops in case of circular inheritance

        while ($current_class) {
            if (isset($visited[$current_class])) {
                return false;
            }

            $visited[$current_class] = true;

            // HACK #1 - JS Model shortcut: When checking against Rsx_Js_Model, if we encounter
            // a PHP model class name (like "Project_Model"), we know it's a model that will have
            // a generated Base_ stub extending Rsx_Js_Model. Return true immediately.
            if ($superclass === 'Rsx_Js_Model' && self::is_php_model_class($current_class)) {
                return true;
            }

            $record = self::$data['data']['js_classes'][$current_class] ?? null;

            if ($record === null || empty($record['extends'])) {
                return false;
            }

            if ($record['extends'] === $superclass) {
                return true;
            }

            // Move up the chain to the parent class
            $current_class = $record['extends'];
        }

        return false;
    }

    /**
    * Get the complete inheritance chain for a JavaScript class
    * Returns array of parent class names in order from immediate parent to root
    *
    * @param string $class_name The class name to get lineage for
    * @return array Array of parent class names (empty if class not found or has no parents)
    * Example: If A extends B and B extends C, js_get_lineage('A') returns ['B', 'C']
    */
    public static function js_get_lineage(string $class_name): array
    {
        // Strip namespace if FQCN was passed
        if (strpos($class_name, '\\') !== false) {
            $parts = explode('\\', $class_name);
            $class_name = end($parts);
        }

        self::init();

        $lineage = [];
        $current_class = $class_name;
        $visited = []; // Prevent infinite loops

        while ($current_class) {
            if (isset($visited[$current_class])) {
                break;
            }

            $visited[$current_class] = true;

            $record = self::$data['data']['js_classes'][$current_class] ?? null;

            if ($record === null || empty($record['extends'])) {
                break;
            }

            $lineage[] = $record['extends'];
            $current_class = $record['extends'];
        }

        return $lineage;
    }

    /**
    * Get all direct subclasses of a given PHP class using the pre-built index
    *
    * @param string $class_name The parent class name (simple name, not FQCN)
    * @param bool $concrete_only Whether to filter out abstract classes (default: true)
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function php_get_subclasses_of(string $class_name, bool $concrete_only = true): array
    {
        self::init();

        $class_name = self::_normalize_class_name($class_name);

        $subclasses = self::$data['data']['php_subclass_index'][$class_name] ?? [];

        // If not filtering for concrete classes, return all subclasses
        if (!$concrete_only) {
            return $subclasses;
        }

        // Filter out abstract classes
        $concrete_subclasses = [];
        foreach ($subclasses as $subclass) {
            $record = self::$data['data']['php_classes'][$subclass] ?? null;

            if ($record === null) {
                shouldnt_happen(
                    "Fatal: PHP class '{$subclass}' found in subclass index but not in php_classes.\n" .
"This indicates a major data integrity issue with the manifest.\n" .
'Try running: php artisan rsx:manifest:build --clean'
                );
            }

            if (!isset($record['abstract'])) {
                shouldnt_happen(
                    "Fatal: Abstract property missing for PHP class '{$subclass}' in manifest data.\n" .
"This indicates a major data integrity issue with the manifest.\n" .
'Try running: php artisan rsx:manifest:build --clean'
                );
            }

            if (!$record['abstract']) {
                $concrete_subclasses[] = $subclass;
            }
        }

        return $concrete_subclasses;
    }

    /**
    * Get all direct subclasses of a given JavaScript class using the pre-built index
    *
    * @param string $class_name The parent class name
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function js_get_subclasses_of(string $class_name): array
    {
        // Return empty array if class not in subclass_index
        if (!isset(self::$data['data']['js_subclass_index'][$class_name])) {
            return [];
        }

        return self::$data['data']['js_subclass_index'][$class_name];
    }

    /**
    * Get all classes with a specific attribute.
    *
    * The shape callers have always seen (file / class / fqcn / type / method / instances),
    * assembled from `by_attribute()` plus the class map. It used to walk every file and every
    * method map in the index.
    */
    public static function get_with_attribute(string $attribute_class): array
    {
        $results = [];

        foreach (self::by_attribute($attribute_class) as $row) {
            $record = $row['class'] !== null
                ? (self::$data['data']['php_classes'][$row['class']] ?? null)
                : null;

            $result = [
                'file' => $row['file'],
                'class' => $row['class'],
                'fqcn' => $record['fqcn'] ?? null,
                'type' => $row['member'] === null ? 'class' : 'method',
                'instances' => $row['instances'],
            ];

            if ($row['member'] !== null) {
                $result['method'] = $row['member'];
            }

            $results[] = $result;
        }

        // Sort alphabetically by class name to ensure deterministic behavior and prevent race condition bugs
        usort($results, function ($a, $b) {
            return strcmp($a['class'] ?? '', $b['class'] ?? '');
        });

        return $results;
    }

    /**
    * Every class and member declaration carrying an attribute, as REFERENCES.
    *
    * Rows are ['file' => ..., 'class' => ?string, 'member' => ?string, 'instances' => [...]],
    * straight out of `attribute_index` - no file record is touched, so a caller asking about
    * `#[Emitter]` or `#[Schedule]` does not load the cold half of the index to learn where
    * they are. The name is matched by its SIMPLE spelling, which is how RSX writes attributes
    * everywhere else; a namespaced argument is reduced to it.
    *
    * @return array<int, array{file: string, class: ?string, member: ?string, instances: array}>
    */
    public static function by_attribute(string $attribute_name): array
    {
        self::init();

        $simple = self::_normalize_class_name($attribute_name);

        return self::$data['data']['attribute_index'][$simple] ?? [];
    }

    /**
    * Whether a Blade view id is indexed. The non-throwing half of find_view(), for callers
    * that are ASKING rather than resolving.
    */
    public static function view_exists(string $id): bool
    {
        self::init();

        return isset(self::$data['data']['blade_views'][$id]);
    }

    /**
    * The HOT class record for a simple class name, or null:
    * ['file' => ..., 'fqcn' => ..., 'extends' => ?string, 'abstract' => bool].
    *
    * Every structural question about a class is answered from this - it is what
    * php_is_subclass_of(), php_is_abstract() and php_get_lineage() read. Use it instead of
    * php_get_metadata_by_class() whenever the METHOD MAP is not what you want: the full
    * record lives in the cold half of the index and fetching one loads all of it.
    */
    public static function php_class_metadata(string $simple_name): ?array
    {
        self::init();

        return self::$data['data']['php_classes'][$simple_name] ?? null;
    }

    /**
    * The model class that owns a table, or null.
    */
    public static function model_for_table(string $table): ?string
    {
        self::init();

        return self::$data['data']['models_by_table'][$table] ?? null;
    }

    /**
    * Get all routes from the manifest
    *
    * Returns unified route structure: $routes[$pattern] => route_data
    * where route_data contains:
    *   - methods: ['GET', 'POST']
    *   - type: 'spa' | 'standard'
    *   - class: Full class name
    *   - method: Method name
    *   - file: File path
    *   - require: Auth requirements
    *   - js_action_class: (SPA routes only) JavaScript action class
    */
    public static function get_routes(): array
    {
        self::init();

        return self::$data['data']['routes'] ?? [];
    }

    /**
    * Get statistics about the manifest
    */
    public static function get_stats(): array
    {
        static::init();
        $files = static::get_all();

        $stats = [
            'total_files' => count($files),
            'php' => 0,
            'js' => 0,
            'blade' => 0,
            'scss' => 0,
            'css' => 0,
            'other' => 0,
            'classes' => 0,
            'routes' => 0,
        ];

        foreach ($files as $file => $metadata) {
            $ext = $metadata['extension'] ?? '';

            switch ($ext) {
                case 'php':
                    $stats['php']++;
                    break;
                case 'blade.php':
                    $stats['blade']++;
                    break;
                case 'js':
                case 'jsx':
                case 'ts':
                case 'tsx':
                    $stats['js']++;
                    break;
                case 'scss':
                case 'less':
                    $stats['scss']++;
                    break;
                case 'css':
                    $stats['css']++;
                    break;
                default:
                    $stats['other']++;
            }

            if (isset($metadata['class'])) {
                $stats['classes']++;
            }
        }

        $routes = static::get_routes();
        $stats['routes'] = count($routes);

        return $stats;
    }

    /**
    * Check if manifest is built
    */
    public static function is_built(): bool
    {
        return file_exists(static::_get_cache_file_path());
    }

    /**
    * Get files from manifest by directory path
    *
    * @param string $directory Directory path without wildcards (e.g., 'rsx/ui')
    * @return array Array of manifest entries for files in the directory
    */
    public static function get_files_by_dir(string $directory): array
    {
        static::init();
        static::_load_cold_files();

        $files = [];
        // Normalize directory to forward slashes
        $directory = str_replace('\\', '/', $directory);
        $directory = rtrim($directory, '/'); // Remove trailing slash if present

        // Check if we have cached data
        if (empty(static::$data['data']['files'])) {
            return $files;
        }

        // Iterate through all files in the manifest
        foreach (static::$data['data']['files'] as $file_path => $file_data) {
            // Normalize the file path to use forward slashes
            $normalized_path = str_replace('\\', '/', $file_path);

            // Check if the file is in the specified directory
            if (str_starts_with($normalized_path, $directory . '/')) {
                $files[$file_path] = $file_data;
            }
        }

        // Sort alphabetically by filename to ensure deterministic behavior and prevent race condition bugs
        ksort($files);

        return $files;
    }

    /**
    * Get the full manifest structure including metadata
    * Used for debugging and manifest:dump command
    * Returns by reference to avoid copying large array
    */
    public static function &get_full_manifest(): array
    {
        static::init();

        return static::$data;
    }

    /**
     * Check if current CLI command is "safe" - doesn't require manifest to be built
     *
     * These commands can run in production mode without a pre-built manifest because
     * they don't actually use manifest data (e.g., rsx:clean just deletes directories).
     */
    protected static function _is_safe_command(): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];
        if (count($argv) < 2) {
            return false;
        }

        // When a sealed prod build is in force, the ONLY context permitted to
        // bypass the pre-built-manifest requirement is an authorized rebuild
        // (rsx:prod:enable / rsx:prod:refresh, which pass the --authorized
        // invocation flag to the build subprocess). rsx:clean is refused entirely
        // while sealed, and rsx:mode:set delegates to the authorized enable/disable
        // commands - so neither may claim "safe" status here anymore.
        if (\App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()) {
            return \App\RSpade\Core\Prod\Rsx_Prod_Seal::is_authorized();
        }

        // Not sealed: these commands can run without a pre-built manifest because
        // they build it themselves, tear it down, or manage the build lifecycle.
        // The prod-mode lifecycle commands MUST be able to boot even when the
        // manifest is absent (e.g. after an interrupted build, or to recover a
        // prod-ish RSX_MODE back to development).
        $safe_commands = [
            'rsx:clean',
            'rsx:prod:build',    // Builds the manifest itself
            'rsx:mode:set',      // Delegates to the prod-mode commands
            'rsx:prod:enable',   // Runs the build pipeline (may rebuild from scratch)
            'rsx:prod:refresh',  // Rebuilds the sealed assets
            'rsx:prod:disable',  // Returns to development (recovery)
            'rsx:prod:verify',   // Inspects the seal; boots to report drift
        ];

        $command = $argv[1] ?? '';
        return in_array($command, $safe_commands, true);
    }

    /**
     * The always-runnable escape hatch: CLI invocations that skip framework/manifest
     * boot entirely, so they still work when a manifest-time code quality violation
     * has poisoned the manifest and every other command aborts.
     *
     * This is the same carve-out rsx:clean has always had (previously a hardcoded
     * string in Rsx_Framework_Provider::boot), widened to the pure-introspection
     * invocations. Membership requires needing NO manifest data whatsoever:
     *
     *   - rsx:clean  - deletes build artifacts; the remediation command itself
     *   - rsx:man    - prints static man page .txt files
     *   - list/help  - Symfony's own command introspection
     *   - bare `php artisan`, --version/-V/-h/--help - Symfony introspection
     *
     * rsx:health is deliberately ABSENT: its check inventory is discovered through
     * manifest attribute scanning (Manifest::get_with_attribute('Health_Check')), so
     * without the manifest it would report an empty or stale check set - a silent
     * under-report, which is worse than refusing to run.
     *
     * The skip is read-only: nothing is loaded, rebuilt, or written, so the poison
     * flag on disk survives and the next real command re-fires the violation.
     */
    public static function __cli_skips_manifest_boot(): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        // Bare `php artisan` - Symfony prints the command list
        if (count($argv) < 2) {
            return true;
        }

        $introspection_commands = [
            'rsx:clean',
            'rsx:man',
            'list',
            'help',
            '--version',
            '-V',
            '--help',
            '-h',
        ];

        return in_array($argv[1], $introspection_commands, true);
    }

    /**
     * Boot-safe check for a CLI invocation FLAG by inspecting $_SERVER['argv'].
     *
     * Pre-handler code (Manifest::init, the seal authorization check) runs during
     * framework boot, before any command's handle() executes - so per-invocation
     * intent cannot come from a command handler or $this->option() at that point.
     * Reading argv is the sanctioned channel for boot-time code to see invocation
     * flags, the same technique _is_safe_command() uses to read the command name.
     *
     * Invocation intent is ALWAYS a --flag, never an environment variable: env vars
     * describe the ENVIRONMENT (PATH-like, deployment facts), not the parameters of
     * a single program invocation. This helper is the boot-time replacement for the
     * old KEY=VALUE env prefixes the prod-mode pipeline used to carry.
     */
    public static function __cli_has_flag(string $flag): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        return in_array($flag, $_SERVER['argv'] ?? [], true);
    }

    /**
    * TODO: UPDATE DOCUMENTATION
    * Initialize and ensure manifest is loaded
    * Handles all loading/rebuilding logic internally:
    * - If already initialized, returns immediately
    * - If cache exists, loads it and updates only changed files
    * - If no cache exists, processes all files (equivalent to rebuild)
    */
    public static function init(): void
    {
        // Already initialized, nothing to do
        if (static::$_has_init) {
            return;
        }

        static::$_has_init = true;

        // Scan handles both incremental updates and full rebuilds

        // Load cached data if it exists
        $cache_file_path = self::_get_cache_file_path();
        console_debug('MANIFEST', 'Checking for manifest cache', $cache_file_path);
        $loaded_cache = self::_load_cached_data();

        // In production-like modes (debug/production), require a pre-built manifest.
        // A (re)build is permitted when either:
        //   - $_force_build is set in-process (rsx:prod:build's handler sets it), or
        //   - this is a "safe" lifecycle command that builds/manages the manifest.
        // _is_safe_command() already grants rsx:prod:build boot access (and, when a
        // sealed build is in force, gates that on the `--authorized` invocation flag
        // via Rsx_Prod_Seal::is_authorized()), so the old RSX_FORCE_BUILD env token
        // was redundant with it and has been removed. Invocation intent lives in
        // flags (argv), never in environment variables.
        $force_build = self::$_force_build || self::_is_safe_command();
        if (Rsx::is_production() && !$force_build) {
            if (!$loaded_cache) {
                $rebuild_command = \App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()
                    ? 'rsx:prod:refresh'
                    : 'rsx:prod:build';
                throw new \RuntimeException(
                    "Manifest not built for production mode. Run: php artisan {$rebuild_command}"
                );
            }

            console_debug('MANIFEST', 'Manifest cache loaded (production mode)');
            self::post_init();
            self::__fire_lifecycle_events();

            return;
        }

        // Development mode: validate cache and rebuild if needed
        if ($loaded_cache) {
            console_debug('MANIFEST', 'Manifest cache loaded (development mode), validating...');
            if (self::_validate_cached_data()) {
                console_debug('MANIFEST', 'Manifest is valid');

                self::post_init();
                self::__fire_lifecycle_events();

                return;
            }
            console_debug('MANIFEST', 'Manifest is out of date');
        } else {
            console_debug('MANIFEST', 'Manifest could not be loaded');
        }

        console_debug('MANIFEST', 'Acquiring manifest build lock');

        // Get a manifest build lock
        // SYSTEM lock: the manifest cache is an artifact on THIS box's local disk, so a
        // concurrent build on another server is not a conflict. Waits forever - a build can
        // legitimately take minutes and the process behind it has nothing to do but wait.
        // Box-physical resource (the build dir), unscoped across databases.
        self::$_manifest_compile_lock = RsxLocks::system_lock(RsxLocks::LOCK_MANIFEST_BUILD, null, false);

        console_debug('MANIFEST', 'Manifest build lock acquired, checking to see if manifest cache was updated');

        // Maybe the manifest was regenerated again? double check now that we are exclusive
        $cache_loaded = self::_load_cached_data();
        $cache_valid = self::_validate_cached_data();

        if (!$cache_valid) {
            // Log only for full rebuilds (cache doesn't exist)
            // Incremental updates are normal and don't need logging
            $cache_file = self::_get_cache_file_path();
            if (!$cache_loaded) {
                console_debug('MANIFEST', 'Manifest cache does not exist, performing full rebuild', $cache_file);
            }

            // Content changed (or no cache existed): scan/update work begins here.
            // This is the single point BOTH rebuild paths pass through - an incremental
            // update with changes and a no-cache full build - so it is the honest place
            // to record that this process rebuilt (drives the rsx.rebuilt* events).
            static::$_rebuild_occurred = true;

            // THE BUILD LOCK IS RELEASED ON EVERY EXIT, including a throw. A code-quality
            // violation, an unparseable file or a bounded restart loop all leave this
            // function by exception, and until this finally existed each of them left the
            // system build lock HELD - so the next process to want it waited forever (the
            // lock has no lease and no TTL, by design) on a build that had already failed.
            try {
                // zug zug
                self::_refresh_manifest();
                // jobs done
                console_debug('MANIFEST', 'Refreshing manifest *completed*');

                // Verify cache was written successfully
                if (file_exists($cache_file)) {
                    $file_size = filesize($cache_file);
                    $file_perms = substr(sprintf('%o', fileperms($cache_file)), -4);
                    console_debug('MANIFEST', 'Cache file written successfully', [
                        'path' => $cache_file,
                        'size' => $file_size,
                        'permissions' => $file_perms,
                    ]);
                } else {
                    console_debug('MANIFEST', 'WARNING: Cache file does not exist after rebuild!', $cache_file);
                }
            } finally {
                // The build read a great many files through ONE cache; nothing about them
                // outlives the build.
                static::build()->release_source_cache();

                RsxLocks::release_lock(self::$_manifest_compile_lock);
                console_debug('MANIFEST', 'Released manifest build lock');
            }
        } else {
            RsxLocks::release_lock(self::$_manifest_compile_lock);
            console_debug('MANIFEST', 'Released manifest build lock');

            console_debug('MANIFEST', 'Manifest cache is valid, no rebuild needed');
        }

        self::post_init();
        self::__fire_lifecycle_events();
    }

    /**
    * Fire the framework's manifest lifecycle events at the tail of init().
    *
    * THIS IS THE "post manifest rebuild" / "manifest ready" extension point. It runs
    * ONCE per process (init() early-returns on $_has_init, so the body runs once and
    * this fires from exactly one of init()'s three exit paths), immediately AFTER all
    * init work (post_init has registered the autoloader and loaded classless files),
    * just before init() returns control.
    *
    * Fired in order:
    *   1. rsx.rebuilt          - only if this process rebuilt (dev: on the next request
    *                             after any source change; prod: once inside the authorized
    *                             rsx:prod:build during enable/refresh). Payload: the changed
    *                             file list under 'files' and the removed one under
    *                             'removed' (see below).
    *   2. rsx.rebuilt.dev|.prod - same condition, immediately after; .prod when
    *                             Rsx::is_production() (ANY prod mode incl. debug), else .dev.
    *   3. rsx.ready            - ALWAYS (every init completion, warm boot included).
    *                             Payload: ['rebuilt' => bool].
    *
    * Handlers run INLINE here, on the request/CLI boot path - heavy work MUST be handed
    * to Task::dispatch(), never done synchronously. A handler may safely call manifest
    * query APIs (init has completed). A handler that WRITES source files does NOT loop
    * this process (the scan already ran); it dirties the tree for the NEXT request only.
    *
    * The payload is what the scanner HONESTLY knows: 'files' is a flat list of the relative
    * paths that changed (new + modified combined) and 'removed' is the paths that left the
    * tree. The scan still does not cheaply distinguish added-vs-modified, so no such split
    * is invented; removals it does track, because the support modules' incremental diff is
    * built on them.
    *
    * public + __ prefix: framework-internal (never call from app code); public only so
    * the lifecycle-event test can drive it as a seam.
    */
    public static function __fire_lifecycle_events(): void
    {
        if (static::$_rebuild_occurred) {
            // `removed` is a SEPARATE key, not a merge into `files`: a handler that
            // regenerates a derived artifact wants the paths that still exist, and one that
            // prunes wants the paths that do not. The build tracks both now (the support
            // modules' diff contract is built on them), so the payload states both.
            $payload = [
                'files' => static::$_changed_files,
                'removed' => static::$_removed_files,
            ];

            Rsx::trigger_action('rsx.rebuilt', $payload);

            $mode_event = Rsx::is_production() ? 'rsx.rebuilt.prod' : 'rsx.rebuilt.dev';
            Rsx::trigger_action($mode_event, $payload);
        }

        Rsx::trigger_action('rsx.ready', ['rebuilt' => static::$_rebuild_occurred]);
    }

    /**
     * Post-initialization hook called after manifest is fully loaded
     *
     * Called at the end of init() after manifest data is loaded (either from cache
     * or after scanning/rebuilding). At this point, the manifest is complete and
     * ready for queries.
     *
     * Current responsibilities:
     * - Sets $_has_manifest_ready flag to indicate manifest is available
     * - Registers the autoloader (which depends on manifest data)
     * - Loads classless PHP files (helpers, constants, procedural code)
     *
     * This is the appropriate place to perform operations that require the complete
     * manifest to be available, such as loading non-class PHP files that were
     * indexed during the scan.
     */
    public static function post_init() {
        self::$_has_manifest_ready = true;

        // THE COLD-LOAD PROBE. The whole point of the split index is that a served request
        // never merges the cold half; the only honest way to assert that is to ask a process
        // that has finished doing its work. Framework-internal (the `--_` convention: no
        // InputOption, stripped from argv pre-boot), so it appears in no help output and can
        // never raise an unknown-option error. tests/manifest reads these two lines.
        //
        // IT REPORTS WHETHER IT REBUILT, TOO, and that is not decoration. A BUILD owns the
        // whole tree and loads the cold half on purpose, so a cold-load count is only a
        // statement about the REQUEST PATH when the process did not build. On a developer's
        // box - or any box with a health check hitting a URL - the index legitimately moves
        // underneath a test between one child and the next (a served request must not index
        // the test trees, so it rewrites the index that a test child then puts back), and
        // without this line a test could not tell "the request path read a cold record"
        // from "this child happened to be the one that rebuilt".
        if (\App\RSpade\Core\Console\Rsx_Internal_Flags::has('--_manifest-report-cold')) {
            register_shutdown_function(static function (): void {
                fwrite(STDOUT, "\nMANIFEST_COLD_LOADS=" . static::$_cold_load_count . "\n");
                fwrite(STDOUT, 'MANIFEST_REBUILT=' . (static::$_rebuild_occurred ? '1' : '0') . "\n");
            });
        }

        \App\RSpade\Core\Autoloader::register();

        // Load classless PHP files (helper functions, constants, etc.)
        $classless_files = self::$data['data']['classless_php_files'] ?? [];
        foreach ($classless_files as $file_path) {
            $full_path = base_path($file_path);
            if (file_exists($full_path)) {
                include_once $full_path;
            }
        }
    }

    /**
    * Clear the manifest cache
    */
    public static function clear(): void
    {
        static::$data = [
            'hash' => '',
            'data' => [
                'files' => [],
                'file_index' => [],
                'autoloader_class_map' => [],
            ],
        ];

        static::$_has_init = false;
        static::$_has_manifest_ready = false;

        foreach ([static::_get_cache_file_path(), Manifest_Store::_get_cold_file_path()] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Unlink the manifest cache file only (fast rebuild trigger)
     *
     * This removes only the manifest cache file, preserving all parsed AST data
     * and incremental caches. On next load, the manifest will do a full scan and
     * reindex but will reuse existing parsed metadata where files haven't changed.
     *
     * This is much faster than rsx:clean which wipes all caches including parsed
     * AST data, forcing expensive re-parsing of all PHP/JS files.
     *
     * Use this after database migrations or schema changes that affect model
     * metadata without changing the actual source code.
     */
    public static function _unlink_cache(): void
    {
        Manifest_Store::_unlink_cache();
    }

    /**
    * Signal that the manifest build must start over, and SAY WHY.
    *
    * The reason is not decoration: restarts are bounded, and the throw that ends a restart
    * loop can only name the cause if the cause was recorded. Called when a pass changed the
    * tree under itself - a class override archived, a file auto-renamed.
    */
    public static function flag_needs_restart(string $reason = 'unspecified'): void
    {
        static::$_needs_manifest_restart = true;
        static::$_restart_reason = $reason;
    }

    // =========================================================================
    // Build Mode Semantic Helpers
    // =========================================================================

    /**
     * Check if manifest/bundles should auto-rebuild on file changes
     *
     * Only in development mode.
     */
    public static function _should_auto_rebuild(): bool
    {
        return \App\RSpade\Core\Rsx::is_development();
    }

    /**
     * Check if JS/CSS should be minified
     *
     * Strict production mode ONLY. Debug mode keeps bundles unminified so the
     * shipped code stays readable and its sourcemap comments survive (minification
     * is what strips them). This is the single source of truth for the minify
     * decision - BundleCompiler consults it for both JS and CSS.
     */
    public static function _should_minify(): bool
    {
        return \App\RSpade\Core\Rsx::is_production() && !\App\RSpade\Core\Rsx::is_debug();
    }

    /**
     * Whether inline sourcemaps survive into the shipped bundle.
     *
     * There is no independent sourcemap toggle: the compiler never adds maps, it
     * only removes the inline sourceMappingURL comments carried by the source, and
     * that removal happens inside minification (the minify subsystem strips them). Maps
     * therefore survive exactly when minification does NOT run - i.e. in
     * development and debug, and never in strict production. This helper documents
     * that truth as the logical inverse of _should_minify(); it does not gate a
     * distinct code path.
     */
    public static function _should_inline_sourcemaps(): bool
    {
        return !self::_should_minify();
    }

    /**
     * Check if console_debug() calls should be stripped from compiled output
     *
     * Strict production mode ONLY. Debug builds keep console_debug working. Feeds
     * the Terser pure_funcs strip flag on the minify RPC request (BundleCompiler).
     */
    public static function _should_strip_console_debug(): bool
    {
        return \App\RSpade\Core\Rsx::is_production() && !\App\RSpade\Core\Rsx::is_debug();
    }

    /**
     * Check if the console_debug config block should be injected into window.rsxapp
     *
     * Development AND debug modes. This is what makes browser console_debug() work
     * in debug mode (matching the root CLAUDE.md mode table). Strict production
     * omits the block entirely - and its call sites are stripped from the bundle
     * by _should_strip_console_debug().
     */
    public static function _should_include_debug_info(): bool
    {
        return !\App\RSpade\Core\Rsx::is_production() || \App\RSpade\Core\Rsx::is_debug();
    }

    /**
     * Normalize class name to simple name (strip namespace qualifiers)
     *
     * Since RSX enforces unique simple class names across the codebase,
     * we normalize all class references to simple names for consistent
     * comparison and storage. FQCNs are only needed at actual class loading time.
     *
     * Examples:
     *   \Rsx\Lib\DataGrid -> DataGrid
     *   Rsx\Lib\DataGrid -> DataGrid
     *   DataGrid -> DataGrid
     *
     * @param string $class_name Class name in any format (with or without namespace)
     * @return string Simple class name without namespace
     */
    public static function _normalize_class_name(string $class_name): string
    {
        // Strip leading backslash
        $class_name = ltrim($class_name, '\\');

        // Extract just the class name (last part after final backslash)
        $parts = explode('\\', $class_name);

        return end($parts);
    }

    /**
    * Get the build key for cache prefixing
    * This is the manifest hash that uniquely identifies the current code state
    *
    * IMPORTANT: This will throw a fatal error if called before the manifest is loaded
    * The manifest must have completed loading (either from cache or scan) before this is available
    *
    * @return string The manifest build key (hash)
    * @throws RuntimeException if manifest is not yet loaded
    */
    public static function get_build_key(): string
    {
        // Check if manifest has been loaded
        if (!static::$_has_manifest_ready) {
            shouldnt_happen('Manifest::get_build_key() called before manifest was loaded. The manifest must complete loading before the build key is available.');
        }

        // Also verify we actually have a hash
        if (empty(static::$data['hash'])) {
            shouldnt_happen('Manifest is loaded but has no build hash. This should not happen.');
        }

        return static::$data['hash'];
    }

    // ------------------------------------------------------------------------
    // ---- RSpade Public Internal Methods:
    // ------------------------------------------------------------------------

    public static function _refresh_manifest()
    {
        $restarts = 0;
        $changed_across_passes = [];
        $removed_across_passes = [];
        static::$_override_pass_renamed = false;

        manifest_start:

        // Reset caches at the beginning of each pass (important for restarts)
        static::$_needs_manifest_restart = false;
        self::$_get_rsx_files_cache = null;

        // THE CHANGE MEMO IS A STATEMENT ABOUT THE PREVIOUS PASS. _has_changed() memoizes
        // "this file matches what the index records", and a pass that rewrote source (the
        // fixer) or renamed a file (the override archive) has just made every entry in it a
        // lie. It survived the goto for as long as the goto existed: a file the fixer had
        // just rewritten reported UNCHANGED in pass two and its new bytes never reached the
        // index.
        static::$_has_changed_cache = [];

        // A REBUILD OWNS THE WHOLE TREE. init() loaded only the hot half of the index, which
        // is all a served request needs; a build re-derives every section from the complete
        // files map and writes both halves, so the cold half has to be in memory before the
        // first entry is carried forward. (Costing a rebuild one include is the trade the
        // split is FOR: the warm path never pays it.)
        static::_load_cold_files();

        // Reset manifest structure, retaining only existing files data
        $existing_files = static::$data['data']['files'] ?? [];
        // THE MODULE-OWNED SECTIONS ARE CARRIED FORWARD, not reset. A support module updates
        // its own section from the changed and removed sets, which it can only do if the
        // section it is updating is still there. Everything else below is re-derived from the
        // files map every pass and is therefore reset by omission.
        $carried_sections = [];
        foreach (self::MODULE_OWNED_SECTIONS as $section) {
            if (isset(static::$data['data'][$section])) {
                $carried_sections[$section] = static::$data['data'][$section];
            }
        }

        static::$data = [
            'hash' => '',
            'data' => $carried_sections + [
                'files' => $existing_files,
                'autoloader_class_map' => [],
                'routes' => [],
            ],
        ];

        // The files map IS the authority for the rest of this pass; the stale index carried
        // forward would only mislead _has_changed().
        static::$data['data']['file_index'] = [];

        // =======================================================
        // Phase 1: Collect all files in manifest scan directories
        // =======================================================
        $files = static::_get_rsx_files();
        $changes = false;

        // Check if any files have changed
        $files_to_process = [];
        foreach ($files as $file) {
            if (static::_has_changed($file)) {
                $files_to_process[] = $file;
                $changes = true;
            }
        }

        // Store changed files for incremental code quality checks.
        //
        // ACCUMULATED ACROSS PASSES, not overwritten. A restart re-runs discovery against a
        // tree the previous pass already brought up to date, so pass two legitimately sees
        // FEWER changed files - and the quality gate and the rsx.rebuilt payload are about
        // the whole build, not about its last pass. Overwriting meant a build that restarted
        // could run its quality gate over nothing at all.
        $changed_across_passes = array_values(array_unique(array_merge($changed_across_passes, $files_to_process)));
        static::$_changed_files = $changed_across_passes;

        console_debug('MANIFEST', 'Phase 1: File Discovery - ' . count($files) . ' files, ' . count($files_to_process) . ' changed');

        // If any files have changed and we're not in production, run auto-reformat
        if ($changes && Rsx::is_development()) {
            $formatter_path = base_path('bin/rsx-format');
            // Lets rethink this before we enable iut again
            // if (file_exists($formatter_path)) {
            //     // Run the formatter with the hidden --auto-reformat-periodic flag
            //     // This ensures formatting happens BEFORE any RSX file is loaded/parsed
            //     $command = escapeshellcmd($formatter_path) . ' --auto-reformat-periodic 2>&1';
            //     \exec_safe($command, $output, $return_code);

            //     // Only log errors, not normal operation
            //     if ($return_code !== 0) {
            //         error_log('RSX auto-reformat-periodic failed: ' . implode("\n", $output));
            //     }
            // }
        }

        // =======================================================
        // Phase 2: Parse Metadata - Extract basic metadata via token parsing
        // =======================================================
        console_debug('MANIFEST', 'Phase 2: Parse Metadata - Processing ' . count($files_to_process) . ' files');

        // Filter out storage files from the manifest
        static::$data['data']['files'] = array_filter(
            static::$data['data']['files'],
            function ($key) {
                return !str_starts_with($key, 'storage/');
            },
            ARRAY_FILTER_USE_KEY
        );

        // Remove deleted files from manifest BEFORE processing
        $existing_files = array_flip($files);
        $removed_this_pass = [];
        foreach (array_keys(static::$data['data']['files']) as $cached_file) {
            if (!isset($existing_files[$cached_file])) {
                unset(static::$data['data']['files'][$cached_file]);
                $removed_this_pass[] = $cached_file;
                $changes = true;
            }
        }

        // ACCUMULATED like the changed set, and for the same reason: a support module's diff
        // is about the whole build, and a pass-two discovery runs against a tree pass one
        // already pruned.
        $removed_across_passes = array_values(array_unique(array_merge($removed_across_passes, $removed_this_pass)));
        static::$_removed_files = $removed_across_passes;

        foreach ($files_to_process as $file) {
            static::$data['data']['files'][$file] = static::_process_file($file);
        }

        // THE FILE MAP IS SORTED HERE, NOT AT SAVE TIME.
        //
        // Everything derived downstream iterates it, and several of those indexes are LISTS
        // whose order is the file map's (attribute_index's per-attribute rows, the event
        // handler index, classless_php_files). A cold build reads the tree in scan order
        // while an incremental one carries the previous map forward and APPENDS the files it
        // re-parsed, so leaving the sort until _save() meant two builds of an identical tree
        // could produce different derived sections - and a different build key.
        ksort(static::$data['data']['files']);

        // Skip validation message if no changes detected
        if (!$changes && file_exists(static::_get_cache_file_path())) {
            // This case shouldn't happen as it should have been caught earlier
            // but we don't need to log it as it's not an error condition
        }

        // ==================================================================================
        // PHP FIXER INTEGRATION POINT
        // ==================================================================================
        // CRITICAL: Php_Fixer MUST run BEFORE _check_unique_base_class_names() so that
        // when a class override is detected and framework files are renamed to .upstream,
        // all use statements have already been updated to point to the rsx/ location.
        // This prevents autoloader failures when the manifest restarts.
        //
        // WHAT PHP_FIXER DOES:
        // 1. Fixes namespaces to match file paths
        // 2. Redirects use statements to correct FQCN based on manifest (rsx/ takes priority)
        // 3. Replaces FQCNs like \Rsx\Models\User_Model with simple names User_Model
        // 4. Adds #[Relationship] attributes to model ORM methods
        // 5. Removes leading backslashes from attributes: #[\Route] → #[Route]
        //
        // SMART REBUILDING:
        // - Tracks SHA1 hash of all class structures (ClassName:ParentClass)
        // - If structure changed: Fixes ALL files (cascading updates needed)
        // - If structure unchanged: Fixes ONLY $files_to_process (incremental)
        //
        // RE-PARSING LOOP BELOW:
        // - If Php_Fixer modified files, we MUST re-parse them
        // - This updates manifest with corrected namespace/class/FQCN data
        // - Without this, manifest would reference old class locations
        // ==================================================================================

        $php_fixer_modified_files = [];
        // RSX_MODE IS THE ONE MODE ORACLE. `app()->environment()` DERIVES from it and
        // reports 'local' for BOTH development and debug, so a test written against it is a
        // test against a value the framework computes for Laravel's benefit rather than the
        // switch the framework actually has.
        if (!Rsx::is_production()) {
            $php_fixer_modified_files = static::_run_php_fixer($files_to_process);

            // Re-parse files that Php_Fixer modified to update manifest with corrected metadata
            // This ensures namespace/class/fqcn data matches what's actually in the file
            // CRITICAL: Without this, we'd have stale FQCNs from before Php_Fixer ran
            if (!empty($php_fixer_modified_files)) {
                console_debug('MANIFEST', 'Re-parsing ' . count($php_fixer_modified_files) . ' files modified by Php_Fixer');
                foreach ($php_fixer_modified_files as $file_path) {
                    // Re-extract metadata with corrected namespace
                    $absolute_path = base_path($file_path);
                    $php_metadata = \App\RSpade\Core\PHP\Php_Parser::parse($absolute_path);

                    // Update manifest with corrected metadata
                    static::$data['data']['files'][$file_path] = array_merge(
                        static::$data['data']['files'][$file_path],
                        $php_metadata
                    );

                    // Recalculate file hash since file was modified
                    clearstatcache(true, $absolute_path);
                    $updated_stat = stat($absolute_path);
                    static::$data['data']['files'][$file_path]['hash'] = sha1_file($absolute_path);
                    static::$data['data']['files'][$file_path]['mtime'] = $updated_stat['mtime'];
                    static::$data['data']['files'][$file_path]['size'] = $updated_stat['size'];
                }
            }

            // THE FIXER'S MEMORY IS STORED AFTER THE RE-PARSE, not before it. A fix that
            // moved a namespace changes the shape the build leaves behind, and recording
            // the PRE-fix shape guaranteed the next build saw a "structure change" it had
            // already applied - a second full pass, every time, for nothing.
            Manifest_Scanner::_store_class_structure();
        }

        // ==================================================================================
        // CLASS OVERRIDE DETECTION
        // ==================================================================================
        // Check for duplicate class names. When rsx/ contains a class that also exists in
        // app/RSpade/, rename the framework file to .upstream and restart the manifest.
        // At this point, Php_Fixer has already updated use statements to point to rsx/.
        // ==================================================================================
        static::_check_unique_base_class_names($changed_across_passes);

        // If a class override was detected (rsx/ overriding app/RSpade/), restart manifest build
        if (static::$_needs_manifest_restart) {
            console_debug('MANIFEST', 'Class override detected, restarting manifest build');
            static::__count_restart($restarts);
            goto manifest_start;
        }

        // Override/restore renames are now settled for this rebuild (getting past the
        // restart check above means neither the restore nor the override pass changed
        // anything this iteration). Heal composer's committed classmap if any entry now
        // points at a framework file the override pass renamed to .upstream - otherwise
        // composer would `include` a missing file on future requests (tolerated at
        // runtime by Autoloader's scoped warning carve-out, but the on-disk data is
        // wrong). Rebuild-only cost; idempotent (a clean classmap triggers no dump).
        // ONLY when this build actually renamed a framework file. Composer's committed
        // classmap can only have gone stale (or been un-staled) by an archive or a restore,
        // and validating it costs ~15k warm stats.
        if (static::$_override_pass_renamed) {
            static::_validate_composer_classmap();
        }

        // Phase 2 complete.  At this point we have a list of all files, and for php and js, their class data

        // =======================================================
        // Phase 3: Load Dependencies - Load PHP files in dependency order
        // =======================================================
        console_debug('MANIFEST', 'Phase 3: Load Dependencies');

        // The class map is what _load_class_hierarchy() resolves a parent through - one
        // lookup per hop instead of the nested linear scan of every indexed file it used to
        // be - so it has to exist BEFORE the loading pass, not only after reflection. This
        // early pass gets `file`, `fqcn` and `extends` right; `abstract` comes from
        // reflection and is filled in by the Phase-4 rebuild below, which is the only reader
        // of it and runs before anything asks.
        static::_collate_files_by_classes();

        // Only load PHP files that have actually changed
        static::_load_changed_php_files($files_to_process);

        // =======================================================
        // Phase 4: Extract Reflection - Extract reflection data from PHP classes
        // =======================================================
        console_debug('MANIFEST', 'Phase 4: Extract Reflection');
        // Extract reflection data for changed PHP files only.
        //
        // THE ACCUMULATED SET, not this pass's. A restart can happen BEFORE Phase 4 (the
        // class-override archive fires at the end of Phase 2), so a file re-parsed in pass
        // one reaches pass two with a record that was rebuilt from tokens and never
        // reflected - and pass two, seeing it unchanged on disk, would skip it forever. Its
        // reflection would then be missing from the saved index, which is exactly what
        // __assert_reflection_carried_forward() refuses to let happen silently.
        static::_extract_reflection_for_changed_files($changed_across_passes);

        // Collate files by classes - MUST be called after reflection extraction
        // so that abstract property is available for subclass filtering
        static::_collate_files_by_classes();

        // Check if a class override was detected and framework file renamed
        if (static::$_needs_manifest_restart) {
            console_debug('MANIFEST', 'Class override detected, restarting manifest build');
            static::__count_restart($restarts);
            goto manifest_start;
        }

        // Build event handler index from attributes
        static::_build_event_handler_index();

        // Build classless PHP files index
        static::_build_classless_php_files_index();

        // Blade view ids and the attribute index. Both are pure functions of the files map
        // and both replace a per-request scan (find_view(); the six copies of "iterate every
        // file looking for attribute X").
        static::_build_blade_view_index();
        static::_build_attribute_index();

        // =======================================================
        // Phase 5: Process Modules - Run manifest support modules and build autoloader
        // =======================================================
        console_debug('MANIFEST', 'Phase 5: Process Modules');

        // Build autoloader class map
        // The major thing that happens here is this also scans app/RSpade for additional classes which aren't on manifest,
        // which is a somewhat expensive operation (50 ms).  This is acceptable for a incremental manifest rebuild
        static::$data['data']['autoloader_class_map'] = static::_build_autoloader_class_map();

        // Register the RSX autoloader before running support modules. Support modules
        // (e.g. Model_ManifestSupport) include_once unchanged model files to reflect on
        // them, which forces PHP to resolve their parent classes. On an incremental
        // rebuild where neither the model nor its abstract parent changed, Phase 3 loads
        // neither, so the parent must resolve via autoload. The RSX autoloader is
        // otherwise only registered in post_init() (after this method returns), leaving
        // only Composer's PSR-4 loader active here, which cannot resolve RSX classes.
        // init() set $_has_init = true at its top, so the re-entrant init() inside
        // register() returns immediately; the autoloader reads php_classes /
        // autoloader_class_map, both built by Phase 4 above.
        \App\RSpade\Core\Autoloader::register();

        // Process manifest support modules.
        //
        // ONE ORDERED LIST, one module system. Every module - the derived-index modules and
        // the three STUB GENERATORS at the end of the list - receives the same two sets and
        // updates its own section incrementally. There is no second dispatch by
        // method_exists() over the integration registry any more.
        $support_modules = config('rsx.manifest_support', []);
        foreach ($support_modules as $support_module_class) {
            if (!class_exists($support_module_class)) {
                throw new \RuntimeException("Manifest support module class not found: {$support_module_class}");
            }

            if (!self::php_is_subclass_of($support_module_class, ManifestSupport_Abstract::class)) {
                throw new \RuntimeException("Manifest support module must extend ManifestSupport_Abstract: {$support_module_class}");
            }

            if ($support_module_class::should_run()) {
                // Per-module wall time on the MANIFEST debug channel. It is what makes "which
                // modules actually did work on this rebuild" answerable from the outside,
                // which is the whole claim the incremental contract makes.
                $module_started = microtime(true);
                $support_module_class::process(static::$data, $changed_across_passes, $removed_across_passes);
                console_debug(
                    'MANIFEST',
                    'Module ' . $support_module_class::get_name() . ': '
                    . round((microtime(true) - $module_started) * 1000, 2) . 'ms'
                );
            }
        }

        // ONE NORMALIZATION, AFTER EVERY MODULE HAS WRITTEN.
        //
        // A module that ksorts its own section is not enough any more: several modules write
        // into the SAME section (Route, Spa and Api_Endpoint all write `routes`), and an
        // incremental update APPENDS to a section carried forward from the previous build,
        // so the key order a section ends up in depends on the history of the index rather
        // than on the tree. That is a determinism defect, not a cosmetic one - the build key
        // hashes the derived sections in their stored order, so two identical trees would
        // disagree about their own hash.
        foreach (self::MODULE_OWNED_SECTIONS as $section) {
            if (isset(static::$data['data'][$section]) && is_array(static::$data['data'][$section])) {
                ksort(static::$data['data'][$section]);
            }
        }

        if (isset(static::$data['data']['jqhtml']['components'])) {
            ksort(static::$data['data']['jqhtml']['components']);
        }

        if (isset(static::$data['data']['auth']['surfaces'])) {
            ksort(static::$data['data']['auth']['surfaces']);
        }

        // The model registry exists now, so its table index can be derived.
        static::_build_models_by_table_index();

        // Note: Validation checks have been moved to code quality rules that run at manifest-time

        // =======================================================
        // Phase 7: Save & Finalize - Save manifest, clear caches, run checks
        // =======================================================
        $php_class_count = count(static::$data['data']['php_classes'] ?? []);
        $js_class_count = count(static::$data['data']['js_classes'] ?? []);
        console_debug('MANIFEST', 'Phase 7: Saving manifest (' . count($files) . " files, {$php_class_count} PHP classes, {$js_class_count} JS classes)");

        static::_generate_vscode_stubs();
        static::_save();

        // Sweep the per-source-file DERIVED caches. This is the one moment the framework
        // holds a complete answer to "which source files still exist", so it is the only
        // place the sweep can be cheap - building the manifest to prune would cost more
        // than the bytes it reclaims. Development only: a sealed build is compiled once,
        // and hashing every file's content there to reclaim disk would be work for nothing.
        if (Rsx::is_development() && static::__derived_sweep_is_due($removed_across_passes)) {
            static::_sweep_derived_caches();
        }

        // Clear the compiled view cache ONLY when a blade actually moved. It exists to stop
        // a stale @rsx_extends surviving a rename, and nothing but a .blade.php change can
        // create one - so a JS or SCSS edit no longer throws away every compiled view and
        // makes the next page render recompile them all.
        $blade_touched = false;

        foreach (array_merge($changed_across_passes, $removed_across_passes) as $touched_file) {
            if (str_ends_with($touched_file, '.blade.php')) {
                $blade_touched = true;
                break;
            }
        }

        if ($blade_touched) {
            \Illuminate\Support\Facades\Artisan::call('view:clear', [], new \Symfony\Component\Console\Output\NullOutput());
        }

        // Run manifest-time code quality checks (development only)
        // Skip during migrations - database may not be provisioned yet
        if (Rsx::is_development() && $changes && !static::_is_migration_context()) {
            static::_verify_database_provisioned();
            static::_run_manifest_time_code_quality_checks($files_to_process);
        }

        // Check if a file was auto-renamed and manifest needs to restart
        if (static::$_needs_manifest_restart) {
            console_debug('MANIFEST', 'File auto-renamed during code quality check, restarting manifest build');
            static::__count_restart($restarts);
            goto manifest_start;
        }
    }

    /**
    * Count one build restart, and refuse a fourth.
    *
    * A restart means a pass changed the tree under itself and the build has to see the new
    * shape. That is legitimate a bounded number of times and then it is a LOOP - two passes
    * undoing each other, which used to spin forever with no error naming either of them.
    */
    private static function __count_restart(int &$restarts): void
    {
        $restarts++;

        if ($restarts <= self::MAX_BUILD_RESTARTS) {
            return;
        }

        $reason = static::$_restart_reason !== '' ? static::$_restart_reason : 'unspecified';

        throw new \RuntimeException(
            'Manifest build restarted ' . $restarts . ' times (the bound is '
            . self::MAX_BUILD_RESTARTS . '). The last restart was requested because: '
            . $reason . '. Two build passes are undoing each other - the tree never settles.'
        );
    }

    /**
    * Is the derived-cache sweep due?
    *
    * The sweep hashes every file in the tree to build the live set, so it is the single
    * most expensive thing Phase 7 can do - and what it reclaims is DISK, never correctness:
    * a dead entry is inert, it is simply not referenced any more. So it runs when a file
    * LEFT the tree (the event that creates dead entries), and otherwise at most hourly.
    *
    * The stamp is one file under rsx-tmp/derived/. A missing or unreadable stamp means
    * "due", which is the safe direction: a sweep that runs when it need not costs a pass,
    * a sweep that never runs leaks.
    */
    private static function __derived_sweep_is_due(array $removed_files): bool
    {
        if (!empty($removed_files)) {
            return true;
        }

        $stamp = storage_path('rsx-tmp/derived/last_sweep');
        $now = time();

        if (is_file($stamp)) {
            $last = (int) @file_get_contents($stamp);

            // ONE HOUR is a housekeeping cadence, not a deadline on any operation: nothing
            // waits on it, nothing fails if it is late, and the work it defers is disk
            // reclamation. (The no-timeout mandate is about bounding WORK; this bounds how
            // often optional housekeeping repeats.)
            if ($last > 0 && ($now - $last) < 3600) {
                return false;
            }
        }

        ensure_directory(dirname($stamp));
        @file_put_contents($stamp, (string) $now);

        return true;
    }

    /**
    * Remove derived-cache entries whose source file the manifest no longer knows.
    *
    * The live set is the UNION of both identities a source file can be keyed by: the
    * manifest's own sha1 (what the reflection cache keys on) and _rsx_file_hash_for_build()
    * (what every other derived cache keys on). A superset is harmless - sweep() only ever
    * removes an entry that matches nothing at all.
    */
    public static function _sweep_derived_caches(): void
    {
        $live = [];

        foreach (static::$data['data']['files'] ?? [] as $file => $metadata) {
            if (isset($metadata['hash']) && $metadata['hash'] !== '') {
                $live[] = $metadata['hash'];
            }

            $absolute_path = Rsx_Paths::real($file);

            if ($absolute_path !== '' && is_file($absolute_path)) {
                $live[] = _rsx_file_hash_for_build($absolute_path);
            }
        }

        $removed = \App\RSpade\Core\Cache\File_Content_Cache::sweep_all($live);

        if ($removed > 0) {
            console_debug('MANIFEST', "Derived cache sweep removed {$removed} dead entries");
        }
    }

    /**
    * Load a class and all its parent classes from manifest data
    *
    * This utility method ensures a class and its entire parent hierarchy
    * are loaded before doing reflection or other operations.
    * Classes are loaded in dependency order (parents first).
    * Used by stub generators and reflection extraction.
    *
    * The hierarchy is walked through the CLASS MAP - simple name to record, one lookup per
    * hop. It used to be a nested linear scan of every indexed file per hop, per class.
    *
    * @param string $fqcn Fully qualified class name to load
    * @param array $manifest_data The manifest data array (unused; the class map is the source)
    * @return void
    * @throws \RuntimeException if class or parent cannot be loaded
    */
    public static function _load_class_hierarchy(string $fqcn, array $manifest_data): void
    {
        // Already loaded? Nothing to do
        if (class_exists($fqcn, false) || interface_exists($fqcn, false) || trait_exists($fqcn, false)) {
            return;
        }

        $classes = $manifest_data['data']['php_classes'] ?? self::$data['data']['php_classes'] ?? [];

        // Build list of classes to load in hierarchy order (parents first)
        $hierarchy = [];
        $current_fqcn = $fqcn;
        $visited = [];

        while ($current_fqcn) {
            if (isset($visited[$current_fqcn])) {
                break;
            }

            $visited[$current_fqcn] = true;

            $simple = self::_normalize_class_name($current_fqcn);
            $record = $classes[$simple] ?? null;

            if ($record === null || ($record['fqcn'] ?? null) !== ltrim($current_fqcn, '\\')) {
                // Not an indexed class. A built-in or vendor class simply autoloads.
                if (class_exists($current_fqcn, true) ||
                interface_exists($current_fqcn, true) ||
                trait_exists($current_fqcn, true)) {
                    break;
                }

                shouldnt_happen("Parent class {$current_fqcn} not found in manifest or autoloader for {$fqcn}");
            }

            array_unshift($hierarchy, ['fqcn' => $current_fqcn, 'file' => $record['file']]);

            if (empty($record['extends'])) {
                break;
            }

            $parent = $classes[self::_normalize_class_name($record['extends'])] ?? null;
            $current_fqcn = $parent['fqcn'] ?? null;
        }

        // Load classes in order (parents first)
        foreach ($hierarchy as $class_info) {
            if (!class_exists($class_info['fqcn'], false) &&
            !interface_exists($class_info['fqcn'], false) &&
            !trait_exists($class_info['fqcn'], false)) {
                $full_path = base_path($class_info['file']);
                if (!file_exists($full_path)) {
                    shouldnt_happen("Class file not found: {$full_path} for {$class_info['fqcn']}");
                }

                // This includes the file.
                // A side effect of this include is this line also lints the file.  Past this point, we can assume all php
                // files (well, class files) have valid syntax.
                include_once $full_path;

                // Verify the class loaded successfully
                if (!class_exists($class_info['fqcn'], false) &&
                !interface_exists($class_info['fqcn'], false) &&
                !trait_exists($class_info['fqcn'], false)) {
                    shouldnt_happen("Failed to load class {$class_info['fqcn']} from {$full_path}");
                }
            }
        }
    }

    /**
    * Mark the manifest bad, so the next load rebuilds from scratch.
    *
    * THE FLAG IS A SIDECAR FILE, and writing it is ALL this does. It used to call _save(),
    * which is how a build that had already failed overwrote its own good index with the
    * half-built one it was holding at the moment of the failure - and a build_key computed
    * from that partial index. The contract the flag has to keep is only that
    * _load_cached_data() refuses the cache while it exists; a file beside the cache keeps it
    * without touching a byte of the index.
    *
    * A successful _save() removes the flag: a build that completed supersedes the poisoning
    * that preceded it.
    */
    public static function _set_manifest_is_bad(): void
    {
        static::$_manifest_is_bad = true;

        Manifest_Store::_write_bad_flag();
    }

    // ------------------------------------------------------------------------
    // ---- Private / Protected Methods:
    // ------------------------------------------------------------------------
    // DEAD CODE REMOVED: _generate_js_api_stubs()
    // Controller stub generation now handled by Controller_BundleIntegration
    // ------------------------------------------------------------------------

    // ------------------------------------------------------------------------
    // DEAD CODE REMOVED: _generate_js_model_stubs()
    // Model stub generation now handled by Database_BundleIntegration
    // ------------------------------------------------------------------------

    /**
    * Get or create the kernel instance
    */
    public static function _get_kernel(): ManifestKernel
    {
        return Manifest_Store::_get_kernel();
    }

    /**
    * Get the full cache file path
    */
    public static function _get_cache_file_path(): string
    {
        return Manifest_Store::_get_cache_file_path();
    }

    /**
    * The build this facade is driving.
    *
    * Nothing inside the build resolves its own scan roots or storage path any more: it asks
    * this instance, so a test can point a build at a fixture tree and a scratch storage root
    * without touching config or the developer's own index.
    */
    public static function build(): Manifest_Build
    {
        if (static::$_build === null) {
            static::$_build = Manifest_Build::from_config();
        }

        return static::$_build;
    }

    /**
    * Point the facade at another build (null restores the config one) and forget every
    * loaded index, memo and change verdict. The test seam, and the ONLY caller that may
    * move the roots or the storage path.
    */
    public static function _use_build_for_tests(?Manifest_Build $build): void
    {
        static::$_build = $build;
        static::$data = null;
        static::$_has_init = false;
        static::$_has_manifest_ready = false;
        static::$_manifest_is_bad = false;
        static::$_get_rsx_files_cache = null;
        static::$_has_changed_cache = [];
        static::$_changed_files = [];
        static::$_removed_files = [];
        static::$kernel = null;
    }

    // move to lower soon
    public static function _validate_cached_data()
    {
        return Manifest_Store::_validate_cached_data();
    }

    /**
    * Check for duplicate base class names within the same file type
    *
    * This method handles two scenarios:
    * 1. RESTORE: If a .upstream file exists but no active override exists, restore it
    * 2. OVERRIDE: When a class exists in both rsx/ and app/RSpade/, rename framework to .upstream
    *
    * Throws a fatal error if duplicates exist within the same area (both rsx/ or both app/RSpade/)
    */
    public static function _check_unique_base_class_names(array $dirty_files = []): void
    {
        Manifest_Indexer::_check_unique_base_class_names($dirty_files);
    }

    /**
    * Validate composer's committed classmap against the filesystem and regenerate it
    * (blocking composer dump-autoload) when the class-override rename/restore pass has
    * left an entry pointing at a renamed/removed file. Runs in the rebuild pipeline
    * immediately after the override pass settles. See
    * Manifest_Indexer::_validate_composer_classmap.
    */
    public static function _validate_composer_classmap(): void
    {
        Manifest_Indexer::_validate_composer_classmap();
    }

    /**
    * Restore orphaned .upstream files when their override no longer exists
    *
    * Scans all php.upstream files in the manifest. For each one, checks if a .php file
    * with the same class name exists. If not, the override was removed and we should
    * restore the framework file.
    */
    public static function _restore_orphaned_upstream_files(): void
    {
        Manifest_Scanner::_restore_orphaned_upstream_files();
    }

    /**
    * Collate files by class names and build inheritance indices
    *
    * This method creates two types of indices for both JavaScript and PHP classes:
    *
    * 1. Class indices (js_classes, php_classes):
    *    Maps class names to their file metadata for O(1) lookups by class name
    *
    * 2. Subclass indices (js_subclass_index, php_subclass_index):
    *    Maps each parent class name to an array of its direct subclasses
    *    Example: Rsx_Controller_Abstract => ['Demo_Index_Controller', 'Backend_Controller', ...]
    *
    * The subclass indices enable efficient inheritance checking without iterating
    * through the entire manifest. Instead of O(n) complexity for finding subclasses,
    * we get O(1) direct subclass lookups.
    *
    * @return void
    */
    public static function _collate_files_by_classes()
    {
        return Manifest_Indexer::_collate_files_by_classes();
    }

    /**
     * Build event handler index from OnEvent attributes
     *
     * Scans all PHP files for methods with #[OnEvent] attributes and builds
     * an index of event_name => [handlers] for fast event dispatching.
     *
     * Index structure:
     * ['event_handlers'] => [
     *     'event.name' => [
     *         ['class' => 'Class_Name', 'method' => 'method_name', 'priority' => 100],
     *         ['class' => 'Other_Class', 'method' => 'other_method', 'priority' => 200],
     *     ]
     * ]
     *
     * Handlers are sorted by priority (lower numbers execute first).
     *
     * @return void
     */
    public static function _build_event_handler_index()
    {
        return Manifest_Indexer::_build_event_handler_index();
    }

    /**
     * Build index of classless PHP files
     *
     * Creates a simple array of file paths for all PHP files in the manifest
     * that do not contain a class. These files typically contain helper functions,
     * constants, or other procedural code that needs to be loaded during post_init().
     *
     * Stored in manifest data at: $data['data']['classless_php_files']
     */
    public static function _build_classless_php_files_index()
    {
        return Manifest_Indexer::_build_classless_php_files_index();
    }

    public static function _build_blade_view_index()
    {
        return Manifest_Indexer::_build_blade_view_index();
    }

    public static function _build_attribute_index()
    {
        return Manifest_Indexer::_build_attribute_index();
    }

    public static function _build_models_by_table_index()
    {
        return Manifest_Indexer::_build_models_by_table_index();
    }

    /**
    * Load changed PHP files and their dependencies
    *
    * This method loads only the PHP files that have changed and ensures
    * their parent class hierarchies are loaded first.
    *
    * @param array $changed_files Array of changed file paths
    * @return void
    */
    public static function _load_changed_php_files(array $changed_files): void
    {
        Manifest_Scanner::_load_changed_php_files($changed_files);
    }

    /**
    * Extract reflection data only for changed files
    * Uses caching to avoid re-extracting unchanged files
    */
    public static function _extract_reflection_for_changed_files(array $changed_files): void
    {
        Manifest_Scanner::_extract_reflection_for_changed_files($changed_files);
    }

    /**
    * Load all PHP files in dependency order
    * This ensures base classes are loaded before their subclasses
    * Must be called after Phase 2 (basic metadata extraction) completes
    * @deprecated Use _load_changed_php_files() for incremental builds
    */
    /**
    * Run Php_Fixer on all PHP files in rsx/ and app/RSpade/
    * Called before Phase 2 parsing to ensure all files are fixed
    *
    * SMART REBUILD STRATEGY:
    * This method implements an intelligent rebuild strategy to avoid unnecessary file writes:
    *
    * 1. STRUCTURE HASH: Creates SHA1 hash of "ClassName:ParentClass" for ALL classes
    *    - Detects when classes are added, removed, renamed, or inheritance changes
    *
    * 2. FULL REBUILD TRIGGERS:
    *    - New class added (may need new use statements elsewhere)
    *    - Class renamed (all references need updating)
    *    - Inheritance changed (may affect use statement resolution)
    *    → When triggered: Fix ALL PHP files in rsx/ and app/RSpade/
    *
    * 3. INCREMENTAL REBUILD:
    *    - Structure hash unchanged (no new/renamed classes)
    *    - Only fixes files that actually changed on disk
    *    → More efficient, avoids touching unchanged files
    *
    * WHY THIS MATTERS:
    * - use statement management depends on knowing all available classes
    * - FQCN replacement needs to check class name uniqueness
    * - When class structure changes, files referencing those classes need updating
    * - When structure stable, only changed files need processing
    *
    * @param array $changed_files List of changed files from Phase 1
    * @return array List of files that were modified by Php_Fixer
    */
    public static function _run_php_fixer(array $changed_files): array
    {
        return Manifest_Scanner::_run_php_fixer($changed_files);
    }

    public static function _load_php_files_in_dependency_order(): void
    {
        Manifest_Scanner::_load_php_files_in_dependency_order();
    }

    // /**
    //  * Extract reflection data for all PHP files
    //  * Must be called after Phase 3 (dependency loading) completes
    //  */
    // public static function __extract_all_reflection_data(): void
    // {
    //     if (!isset(static::$data['data']['files'])) {
    //         throw new \RuntimeException(
    //             'Fatal: Manifest::extract_all_reflection_data() called but manifest data structure is not initialized. ' .
    //             "This shouldn't happen - Phase 2 should have populated the files array."
    //         );
    //     }

    //     foreach (static::$data['data']['files'] as $file_path => &$metadata) {
    //         // Only process PHP files with classes
    //         if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
    //             continue;
    //         }

    //         if (!isset($metadata['fqcn'])) {
    //             continue;
    //         }
    //         var_dump($file_path);
    //         // Extract reflection data (class should already be loaded)
    //         static::_extract_reflection_data(base_path($file_path), $metadata['fqcn'], $metadata);
    //     }
    // }

    /**
    * Build the autoloader class map for simplified class name resolution
    * Maps simple class names to their fully qualified class names
    * @return array Map of simple names to arrays of FQCNs
    */
    public static function _build_autoloader_class_map(): array
    {
        return Manifest_Indexer::_build_autoloader_class_map();
    }

    /**
    * Scan a directory for PHP classes, excluding vendor directories
    * @param string $directory The directory to scan
    * @return array Map of simple class names to FQCNs
    */
    public static function _extract_classes_from_php_file(string $path): array
    {
        return Manifest_Scanner::_extract_classes_from_php_file($path);
    }

    public static function _scan_directory_for_classes(string $directory): array
    {
        return Manifest_Scanner::_scan_directory_for_classes($directory);
    }

    /**
    * Process a single file and extract comprehensive metadata
    * @param string $file_path Relative path to file
    */
    public static function _process_file(string $file_path): array
    {
        return Manifest_Scanner::_process_file($file_path);
    }

    /**
    * Extract public static methods and their attributes using PHP reflection
    * Note: This is called in Phase 4 after all PHP files have been loaded
    */
    public static function _extract_reflection_data(string $file_path, string $full_class_name, array &$data): void
    {
        Manifest_Scanner::_extract_reflection_data($file_path, $full_class_name, $data);
    }

    /**

    /**
    * Extract view information from template files
    */
    public static function _extract_view_info(string $file_path, array &$data): void
    {
        Manifest_Scanner::_extract_view_info($file_path, $data);
    }

    /**
    * Check if a file has changed (expects relative path)
    */
    public static function _has_changed(string $file): bool
    {
        return Manifest_Scanner::_has_changed($file);
    }

    /**
    * Get all files in configured scan directories (returns relative paths)
    */
    public static function _get_rsx_files(): array
    {
        return Manifest_Scanner::_get_rsx_files();
    }

    /**
    * The directories THIS build indexes: the configured list, plus the test trees while the
    * process is a test run. The ONE answer to "does the manifest see this path".
    *
    * @return array<int,string>
    */
    public static function scan_directories(): array
    {
        return Manifest_Scanner::_scan_directories();
    }

    /**
    * Load cached manifest data
    */
    public static function _load_cached_data()
    {
        return Manifest_Store::_load_cached_data();
    }

    /**
    * Validate manifest data for consistency
    */
    public static function _validate_manifest_data(): void
    {
        Manifest_Store::_validate_manifest_data();
    }

    /**
    * Check if metadata represents a controller class
    * @param array $metadata File metadata
    * @return bool True if class extends Rsx_Controller_Abstract
    */
    public static function _is_controller_class(array $metadata): bool
    {
        $extends = $metadata['extends'] ?? '';

        if ($extends === 'Rsx_Controller_Abstract') {
            return true;
        }

        // Check parent hierarchy
        $current_class = $extends;
        $max_depth = 10;

        while ($current_class && $max_depth-- > 0) {
            try {
                $parent_metadata = self::php_get_metadata_by_class($current_class);
                if (($parent_metadata['extends'] ?? '') === 'Rsx_Controller_Abstract') {
                    return true;
                }
                $current_class = $parent_metadata['extends'] ?? '';
            } catch (\RuntimeException $e) {
                // Check FQCN match
                if ($current_class === 'Rsx_Controller_Abstract' ||
                $current_class === 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract') {
                    return true;
                }
                break;
            }
        }

        return false;
    }

    /**
    * Generate VS Code IDE helper stubs for attributes and class aliases
    */
    public static function _generate_vscode_stubs(): void
    {
        Manifest_Indexer::_generate_vscode_stubs();
    }

    /**
    * Save manifest data to cache
    */
    public static function _save(): void
    {
        Manifest_Store::_save();
    }

    /**
    * Run code quality checks during manifest scan
    * Only runs in development mode after manifest changes
    * Throws fatal exception on first violation found
    *
    * Supports two types of rules:
    * - Incremental rules (is_incremental() = true): Only check changed files
    * - Cross-file rules (is_incremental() = false): Run once with full manifest context
    *
    * @param array $changed_files Files that changed in this manifest scan
    */
    public static function _run_manifest_time_code_quality_checks(array $changed_files = []): void
    {
        Manifest_Indexer::_run_manifest_time_code_quality_checks($changed_files);
    }

    /**
     * Check if we're running in a migration context
     *
     * Returns true if running migrate, make:migration, or other DB setup commands.
     * Used to skip code quality checks that depend on database state.
     */
    public static function _is_migration_context(): bool
    {
        // Check if running from CLI
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        // Get the artisan command being run
        $argv = $_SERVER['argv'] ?? [];
        if (count($argv) < 2) {
            return false;
        }

        // Commands that should skip code quality checks
        $migration_commands = [
            'migrate',
            'migrate:fresh',
            'migrate:install',
            'migrate:refresh',
            'migrate:reset',
            'migrate:status',
            'migrate:normalize_schema',
            'make:migration',
            'make:migration:safe',
            'db:seed',
            'db:wipe',
        ];

        $command = $argv[1] ?? '';
        return in_array($command, $migration_commands, true);
    }

    /**
     * Verify database has been provisioned before running code quality checks
     *
     * Checks that:
     * 1. The _migrations table exists (created by Laravel migrate)
     * 2. At least one migration has been applied
     *
     * This prevents confusing code quality errors when the real issue is
     * that migrations haven't been run yet.
     */
    public static function _verify_database_provisioned(): void
    {
        $migrations_table = config('database.migrations', 'migrations');

        // Check if migrations table exists
        if (!\Illuminate\Support\Facades\Schema::hasTable($migrations_table)) {
            throw new \RuntimeException(
                "Database not provisioned - migrations table '{$migrations_table}' does not exist.\n\n" .
                "Run migrations before continuing:\n" .
                "    php artisan migrate\n\n" .
                "If this is a fresh installation, you may also need to:\n" .
                "    1. Create the database\n" .
                "    2. Configure .env with correct DB_* settings\n" .
                "    3. Run: php artisan migrate"
            );
        }

        // Check if at least one migration has been applied
        $result = \Illuminate\Support\Facades\DB::select("SELECT COUNT(*) as cnt FROM `{$migrations_table}`");
        if ($result[0]->cnt === 0) {
            throw new \RuntimeException(
                "Database not provisioned - no migrations have been applied.\n\n" .
                "Run migrations before continuing:\n" .
                "    php artisan migrate"
            );
        }
    }

    /**
     * Get list of all database tables from manifest model metadata
     *
     * Returns only tables that have been indexed via Model_ManifestSupport
     * (i.e., tables with corresponding model classes)
     *
     * @return array Array of table names
     */
    public static function db_get_tables(): array
    {
        self::init();

        if (!isset(self::$data['data']['models'])) {
            return [];
        }

        $tables = [];
        foreach (self::$data['data']['models'] as $model_data) {
            if (isset($model_data['table'])) {
                $tables[] = $model_data['table'];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Get columns for a specific table with their types from manifest
     *
     * Returns simplified column information extracted during manifest build.
     * Types are from Model_ManifestSupport::__parse_column_type() which simplifies
     * MySQL types (e.g., 'bigint', 'varchar(255)' -> 'integer', 'string')
     *
     * @param string $table Table name
     * @return array Associative array of column_name => type, or empty if table not found
     */
    public static function db_get_table_columns(string $table): array
    {
        self::init();

        // One lookup through models_by_table, rather than a linear walk of the model
        // registry per call.
        $model = self::model_for_table($table);

        if ($model === null) {
            return [];
        }

        $result = [];

        foreach (self::$data['data']['models'][$model]['columns'] ?? [] as $column_name => $column_data) {
            $result[$column_name] = $column_data['type'] ?? 'unknown';
        }

        return $result;
    }

    /**
     * Get columns of a specific type for a table from manifest
     *
     * Useful for finding all boolean columns (tinyint), integer columns, etc.
     *
     * @param string $table Table name
     * @param string $type Type to filter by (from Model_ManifestSupport simplified types)
     * @return array Array of column names matching the type
     */
    public static function db_get_columns_by_type(string $table, string $type): array
    {
        $columns = self::db_get_table_columns($table);

        return array_keys(array_filter($columns, function ($col_type) use ($type) {
            return $col_type === $type;
        }));
    }
}