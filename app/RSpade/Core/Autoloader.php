<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core;

use RuntimeException;
use App\RSpade\Core\Manifest\Manifest;

class Autoloader
{
    // Manifest is now static - no instance needed

    /**
     * Registered class aliases
     */
    protected static $aliases = [];

    /**
     * Whether the autoloader is registered
     */
    protected static $registered = false;

    /**
     * Whether aliases have been loaded
     */
    protected static $aliases_loaded = false;

    /**
     * Cache of the autoloader class map from manifest
     */
    protected static ?array $class_map = null;

    /**
     * Register the RSX autoloader
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        // Initialize static Manifest and aliases
        Manifest::init();
        static::__load_aliases();

        // Register with SPL after Composer
        spl_autoload_register([static::class, 'load'], true, false);

        self::$registered = true;
        console_debug('AUTOLOADER', 'RSX Autoloader has been registered');
    }

    /**
     * THE ONE INCLUDE SEAM. Every framework include of an indexed PHP file goes through
     * here, and nothing is included twice - whoever loaded it first, and under whatever
     * spelling of the path.
     *
     * Composer's autoloader runs ahead of this one. On a case-insensitive mount (a macOS
     * bind mount into the container) a PSR-4 lookup can answer with the real file under a
     * differently-cased path, and PHP's include table is keyed on the path string, so a
     * later require_once of the canonical spelling declares the class a second time and
     * fatals. Two questions settle it, in order:
     *
     *   1. Is the declaration this file carries already present? The manifest records the
     *      FQCN a file declares, so class_exists() (interface, trait, enum alike) answers
     *      without touching the filesystem. Present means somebody included the file - no
     *      matter who, no matter how it was spelled - and there is nothing left to do.
     *   2. For a file that declares nothing (a helper file): was the SAME path already
     *      included under another spelling? get_included_files() compared without case.
     *
     * Only then is the file required. realpath() is deliberately not part of this: a FUSE
     * mount does not answer canonically, which is the whole reason the question exists.
     *
     * CLASS OVERRIDES (rsx:man class_override) pass through here twice over. A framework file
     * the application overrides is REFUSED - including it would declare the framework class
     * under the framework name, after which that name can never become the override's alias
     * in this process. And once a file declaring an override is in, the framework name is made
     * its alias at once (Class_Override_Loader::alias_declared()): PHP's parameter and return
     * type checks never autoload, so an instance of the override handed to a framework method
     * typed with the framework name would otherwise be a TypeError.
     *
     * @param string $absolute_path The file, as the manifest recorded it
     * @param string|null $fqcn The class, interface, trait or enum the file declares, when known
     */
    public static function include_declaration(string $absolute_path, ?string $fqcn = null): void
    {
        if ($fqcn !== null && self::_is_declared($fqcn)) {
            \App\RSpade\Core\Overrides\Class_Override_Loader::alias_declared($fqcn);

            return;
        }

        if (self::_included_under_any_case($absolute_path, get_included_files())) {
            return;
        }

        \App\RSpade\Core\Overrides\Class_Override_Loader::assert_not_shadowed($absolute_path);

        require_once $absolute_path;

        if ($fqcn !== null) {
            \App\RSpade\Core\Overrides\Class_Override_Loader::alias_declared($fqcn);
        }
    }

    /**
     * Whether a class, interface, trait or enum of this name is already declared, without
     * autoloading it.
     */
    public static function _is_declared(string $fqcn): bool
    {
        $fqcn = ltrim($fqcn, '\\');

        return class_exists($fqcn, false)
            || interface_exists($fqcn, false)
            || trait_exists($fqcn, false)
            || enum_exists($fqcn, false);
    }

    /**
     * Whether $path is already in the include table under ANY spelling of its case.
     *
     * Pure over its inputs so a test can hand it a list; the caller passes
     * get_included_files().
     *
     * @param string $path
     * @param array<int, string> $included
     */
    public static function _included_under_any_case(string $path, array $included): bool
    {
        $wanted = str_replace('\\', '/', $path);

        foreach ($included as $file) {
            if (strcasecmp(str_replace('\\', '/', $file), $wanted) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load a class
     */
    public static function load($class)
    {
        // Remove leading backslash
        $requested_class = ltrim($class, '\\');

        // Extract simple class name (after last backslash)
        $simple_name = substr(strrchr($requested_class, '\\'), 1) ?: $requested_class;

        // Try to find the simple class name in the manifest. THE HOT CLASS RECORD, not the
        // file record: autoloading needs the path and the FQCN and nothing else, and
        // php_get_metadata_by_class() would pull the whole cold half of the index into the
        // first class every request touches.
        $metadata = Manifest::php_class_metadata($simple_name);

        if ($metadata !== null) {
            // Load the file
            $file_path = str_replace('\\', '/', $metadata['file']);
            $absolute_path = base_path($file_path);
            if (file_exists($absolute_path)) {
                $actual_fqcn = $metadata['fqcn'];
                self::include_declaration($absolute_path, $actual_fqcn);

                // If the requested FQCN still doesn't exist but the actual class does,
                // create an alias
                if (!class_exists($requested_class, false) &&
                    class_exists($actual_fqcn, false) &&
                    $requested_class !== $actual_fqcn) {
                    class_alias($actual_fqcn, $requested_class);
                }

                return true;
            }
        }

        // Check for special case class patterns that need custom handling

        // Check if it's an RSX class by namespace
        if (strpos($requested_class, 'Rsx\\') === 0) {
            return static::__load_rsx_namespaced_class($requested_class);
        }

        // Check if it's a non-namespaced RSX class (like Rsx_Controller_Abstract)
        if (strpos($requested_class, 'Rsx_') === 0) {
            return static::__load_rsx_base_class($requested_class);
        }

        // Check aliases
        if (isset(static::$aliases[$requested_class])) {
            return static::__load_aliased_class($requested_class);
        }

        // Check for non-namespaced classes in the autoloader class map
        if (strpos($requested_class, '\\') === false) {
            return static::__load_from_class_map($requested_class);
        }

        // Not our responsibility
        return false;
    }

    /**
     * Load an RSX namespaced class
     */
    protected static function __load_rsx_namespaced_class($class)
    {
        // ONE LOOKUP. RSX enforces unique simple class names, so the last segment of the FQCN
        // is its key in the class map, and the record's own fqcn confirms the match. This was
        // a full linear scan of every indexed file - PER AUTOLOAD MISS, which is once per
        // class the request touches.
        $record = Manifest::php_class_metadata(Manifest::_normalize_class_name($class));

        if ($record === null || ($record['fqcn'] ?? null) !== ltrim($class, '\\')) {
            // Class not found in manifest - return false to let other autoloaders try
            return false;
        }

        $absolute_path = base_path(str_replace('\\', '/', $record['file']));

        if (!file_exists($absolute_path)) {
            return false;
        }

        self::include_declaration($absolute_path, $record['fqcn']);

        return true;
    }

    /**
     * Load RSX base classes
     */
    protected static function __load_rsx_base_class($class)
    {
        // Map of base classes to their locations
        $base_classes = [
            'Rsx_Controller_Abstract' => 'app/RSpade/Core/Base/Rsx_Controller_Abstract.php',
        ];

        if (isset($base_classes[$class])) {
            $file = base_path($base_classes[$class]);
            if (file_exists($file)) {
                self::include_declaration($file, $class);

                return true;
            }
        }

        return false;
    }

    /**
     * Load an aliased class
     */
    protected static function __load_aliased_class($class)
    {
        $actual_class = static::$aliases[$class];

        // Try to load the actual class
        if (class_exists($actual_class, true)) {
            class_alias($actual_class, $class);

            return true;
        }

        return false;
    }

    /**
     * Load a class from the autoloader class map
     * @param string $class Simple class name without namespace
     */
    protected static function __load_from_class_map($class)
    {
        // Load class map from manifest if not cached
        if (static::$class_map === null) {
            static::$class_map = Manifest::get_autoloader_class_map();
        }

        // Check if the class exists in the map
        if (!isset(static::$class_map[$class])) {
            return false;
        }

        $fqcns = static::$class_map[$class];

        // If multiple FQCNs exist for this simple name, throw an error
        if (count($fqcns) > 1) {
            $error_msg = "Fatal error: Ambiguous class name '{$class}'. Multiple classes found:\n";
            foreach ($fqcns as $fqcn) {
                $error_msg .= "  - {$fqcn}\n";
            }
            $error_msg .= 'Please use the fully qualified class name (FQCN) to resolve ambiguity.';

            throw new RuntimeException($error_msg);
        }

        // Single FQCN found, try to load it
        $fqcn = $fqcns[0];

        // Try to autoload the actual class
        if (class_exists($fqcn, true) || interface_exists($fqcn, true) || trait_exists($fqcn, true)) {
            // Create an alias for the simple name
            class_alias($fqcn, $class);

            return true;
        }

        return false;
    }

    /**
     * Load class aliases from configuration
     */
    protected static function __load_aliases()
    {
        if (static::$aliases_loaded) {
            return;
        }

        // Future: Load from config/rsx.php
        static::$aliases = [
            // Example: 'UserModel' => 'Rsx\Models\User_Model'
        ];

        static::$aliases_loaded = true;
    }

    /**
     * Refresh the manifest and retry loading
     */
    public static function refresh_and_retry($class)
    {
        // // In development mode, rebuild manifest if class not found
        // if (config('app.env') === 'local') {
        //     Manifest::rebuild();
        //     return static::load($class);
        // }

        return false;
    }
}
