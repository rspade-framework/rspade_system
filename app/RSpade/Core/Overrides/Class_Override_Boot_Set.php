<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Overrides;

/**
 * Class_Override_Boot_Set - the framework classes an application can NOT override.
 *
 * An override works because the framework class name is answered by the application's class
 * the first time anything asks for it. A class the framework has already declared before that
 * answer can be given - required by path ahead of composer, loaded while the framework boots,
 * or loaded by the manifest build before its override pass settles - can never become an alias
 * in that process; an override of it would be silently ignored or would break boot.
 *
 * The set is MEASURED, not guessed: every framework class declared at the end of composer's
 * `files` entries, at Manifest::init(), at the start of the manifest build, at the end of its
 * Phase 2 (where the override map is derived) and at Manifest::post_init(), across artisan,
 * the web front controller, system/script.php and a cold build. It is stated here as directory
 * prefixes where a whole directory is boot or build machinery, as class-name suffixes for the
 * per-integration boot classes, and as single classes otherwise.
 *
 * Three further kinds of framework class are not overridable either, because the override pass
 * never sees them: the framework subtrees the manifest does not scan
 * (Manifest_Indexer::_unindexed_framework_classes() lists their classes), the directories
 * discovery passes over inside the scanned ones (resource/, public/, ...), and the framework
 * application directories outside app/RSpade (unscanned_framework_namespaces()).
 *
 * This class is DATA with an accessor. The manifest-build refusals that read it - plus the
 * refusal of any override whose framework class is already declared in the building process -
 * live in Manifest_Overrides.
 */
class Class_Override_Boot_Set
{
    /** Every class under these namespaces is boot or build machinery. */
    const NAMESPACE_PREFIXES = [
        'App\\RSpade\\Core\\Console\\',
        'App\\RSpade\\Core\\JsParsers\\',
        'App\\RSpade\\Core\\Kernels\\',
        'App\\RSpade\\Core\\Laravel\\',
        'App\\RSpade\\Core\\Locks\\',
        'App\\RSpade\\Core\\Manifest\\',
        'App\\RSpade\\Core\\Naming\\',
        'App\\RSpade\\Core\\Overrides\\',
        'App\\RSpade\\Core\\PHP\\',
        'App\\RSpade\\Core\\Paths\\',
        'App\\RSpade\\Core\\Prod\\',
        'App\\RSpade\\Core\\Providers\\',
    ];

    /** Framework classes with these simple-name suffixes are registered while the framework boots. */
    const CLASS_SUFFIXES = [
        '_BundleIntegration',
        '_ManifestModule',
        '_Service_Provider',
        'ServiceProvider',
    ];

    /** Single classes declared during boot or before the override pass settles. */
    const CLASSES = [
        'App\\RSpade\\CodeQuality\\Support\\FileSanitizer',
        'App\\RSpade\\CodeQuality\\Support\\Source_Cache',
        'App\\RSpade\\Core\\Autoloader',
        'App\\RSpade\\Core\\Bundle\\BundleIntegration_Abstract',
        'App\\RSpade\\Core\\Cache\\File_Content_Cache',
        'App\\RSpade\\Core\\Database\\Query\\Grammars\\Query_MySqlGrammar',
        'App\\RSpade\\Core\\Database\\Schema\\Grammars\\Schema_MySqlGrammar',
        'App\\RSpade\\Core\\Debug\\Console_Debug_Override',
        'App\\RSpade\\Core\\Debug\\Debugger',
        'App\\RSpade\\Core\\Env\\Rsx_App_Url',
        'App\\RSpade\\Core\\Exceptions\\Rsx_Exception_Handler',
        'App\\RSpade\\Core\\ExtensionRegistry',
        'App\\RSpade\\Core\\Health\\Rsx_Php_Requirements',
        'App\\RSpade\\Core\\IntegrationRegistry',
        'App\\RSpade\\Core\\Integration_Service_Provider_Abstract',
        'App\\RSpade\\Core\\Portal\\Rsx_Portal_Url',
        'App\\RSpade\\Core\\Rsx',
        'App\\RSpade\\Core\\Task\\Task_Command_Registrar',
        'App\\RSpade\\Core\\Testing\\Rsx_Test_Abstract',
    ];

    /**
     * Framework application namespaces outside app/RSpade (system/app/{Console,Database,
     * Exceptions,Http,Notifications,Providers}). The manifest scans none of them, and
     * Manifest_Indexer::_unindexed_framework_classes() walks app/RSpade only.
     */
    const UNSCANNED_FRAMEWORK_NAMESPACES = [
        'App\\Console\\',
        'App\\Database\\',
        'App\\Exceptions\\',
        'App\\Http\\',
        'App\\Notifications\\',
        'App\\Providers\\',
    ];

    /**
     * Is this framework class one the framework declares before an override could answer for
     * it? (The unscanned-subtree classes are answered by unscanned_framework_namespaces() and
     * Manifest_Indexer::_unindexed_framework_classes(), not here.)
     */
    public static function is_boot_class(string $framework_fqcn): bool
    {
        $framework_fqcn = ltrim($framework_fqcn, '\\');

        if (in_array($framework_fqcn, self::CLASSES, true)) {
            return true;
        }

        foreach (self::NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($framework_fqcn, $prefix)) {
                return true;
            }
        }

        if (!str_starts_with($framework_fqcn, 'App\\RSpade\\')) {
            return false;
        }

        $simple = substr($framework_fqcn, strrpos($framework_fqcn, '\\') + 1);

        foreach (self::CLASS_SUFFIXES as $suffix) {
            if (str_ends_with($simple, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this framework class in an application namespace outside app/RSpade?
     */
    public static function is_unscanned_framework_class(string $framework_fqcn): bool
    {
        $framework_fqcn = ltrim($framework_fqcn, '\\');

        foreach (self::UNSCANNED_FRAMEWORK_NAMESPACES as $prefix) {
            if (str_starts_with($framework_fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public static function unscanned_framework_namespaces(): array
    {
        return self::UNSCANNED_FRAMEWORK_NAMESPACES;
    }
}
