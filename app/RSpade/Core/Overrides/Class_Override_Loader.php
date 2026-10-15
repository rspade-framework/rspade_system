<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Overrides;

use Composer\Autoload\ClassLoader;
use LogicException;
use RuntimeException;

/**
 * Class_Override_Loader - makes a framework class name resolve to the application class
 * that overrides it (rsx:man class_override).
 *
 * WHAT IT ANSWERS. The OVERRIDE MAP: framework FQCN => the application class that replaces it
 * and the file declaring it. The manifest build derives the map and writes it to
 * Rsx_Project_Paths::class_overrides_file() (build/manifest/class_overrides.php); this class
 * reads it before anything else can ask for a framework class.
 *
 * WHERE IT SITS. app/RSpade/autoload_overrides.php is a composer `files` entry, so it runs
 * inside vendor/autoload.php right after composer registers itself, for EVERY entrypoint
 * (artisan, the web front controller, system/script.php, test-spawned scripts, the IDE
 * bridge). It calls boot(), which registers load() IN FRONT of composer. Manifest::post_init()
 * re-prepends it, because the project-root app-layer composer loader and Laravel's alias
 * loader both prepend themselves later in the boot.
 *
 * WHY NARROW. load() answers for an EXACT key of the map and for nothing else - one array
 * lookup, then false. Autoloader::load() resolves any name by its SIMPLE name and must never
 * sit in front of composer: it would alias, say, Illuminate\Support\Collection to an
 * application class named Collection.
 *
 * COMPOSER IS NEUTRALISED IN MEMORY. composer maps every framework class twice over (its
 * optimised classmap and the App\ => app/ PSR-4 rule), so a framework file left in place
 * would be loaded by composer the moment anything reached composer first. For each key of the
 * map the live framework ClassLoader gets addClassMap([fqcn => trip-wire]); the classmap is
 * consulted before PSR-4 and before the authoritative short-circuit, so in every dump mode
 * composer answers an overridden name with app/RSpade/autoload_overrides_tripwire.php, which
 * throws naming the class. Nothing under vendor/ is written.
 *
 * THE ALIAS EXISTS FROM THE MOMENT THE APPLICATION CLASS DOES. PHP's parameter and return
 * type checks never autoload: a framework method typed `File_Attachment_Model $a` handed an
 * Rsx\Models\File_Attachment_Model throws a TypeError unless the framework name is ALREADY an
 * alias. So the alias is made wherever an override's file is included -
 * Autoloader::include_declaration() calls alias_declared() - and Manifest::post_init()
 * aliases every override eagerly (alias_all()), which also makes instanceof, catch and
 * string is_a() against the framework name hold before anything has loaded it.
 *
 * FAIL LOUD. A framework name already declared as a different class, an alias PHP refuses,
 * an override file that does not declare its class: each throws, naming both classes.
 */
class Class_Override_Loader
{
    /**
     * framework FQCN => ['by_fqcn' => string, 'by_file' => absolute, 'file' => absolute]
     *
     * @var array<string, array{by_fqcn: string, by_file: string, file: string}>
     */
    private static array $map = [];

    /** application FQCN (lowercased) => framework FQCN, for alias-on-include. */
    private static array $by_fqcn_index = [];

    /** shadowed framework file (normalised, lowercased) => framework FQCN. */
    private static array $shadowed_files = [];

    /**
     * framework FQCN => the classmap entry composer held before the trip-wire replaced it,
     * so an entry that leaves the map gives composer its own answer back.
     */
    private static array $composer_displaced = [];

    private static bool $registered = false;

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    /**
     * Read the build's override map and register the loader in front of composer.
     * Called once, by the composer `files` entry app/RSpade/autoload_overrides.php.
     */
    public static function boot(): void
    {
        self::set_map(self::read_artifact(\App\RSpade\Core\Paths\Rsx_Project_Paths::class_overrides_file()));
        self::register();
    }

    /**
     * Read an override-map artifact. A missing file is an empty map: a fresh checkout has no
     * build yet, and the build writes one on every pass.
     *
     * @return array<string, array{by_fqcn: string, by_file: string, file: string}> paths as written
     */
    public static function read_artifact(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $map = include $path;

        if (!is_array($map)) {
            throw new RuntimeException("The class override map {$path} does not return an array. Rebuild the manifest.");
        }

        return $map;
    }

    /**
     * Write an override-map artifact, temp-then-rename, so a reader never sees half a file.
     *
     * @param array<string, array{by_fqcn: string, by_file: string, file: string}> $map paths relative to the framework tree
     */
    public static function write_artifact(string $path, array $map): void
    {
        ksort($map);

        $body = "<?php\n\n// Generated by the manifest build - DO NOT EDIT. rsx:man class_override\n"
            . "// framework FQCN => the application class that overrides it\n\nreturn "
            . var_export($map, true) . ";\n";

        if (is_file($path) && file_get_contents($path) === $body) {
            return;
        }

        \App\RSpade\Core\Paths\Rsx_Project_Paths::assert_build_writable($path, 'class override map');

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $temp = $path . '.tmp.' . getmypid();

        if (file_put_contents($temp, $body) === false) {
            throw new RuntimeException("Could not write the class override map to {$temp}");
        }

        @chmod($temp, 0664);

        if (!rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException("Could not rename {$temp} to {$path}");
        }

        // OPcache is part of the PHP core, and would otherwise serve the superseded map.
        opcache_invalidate($path, true);
    }

    /**
     * Register load() at the FRONT of the spl autoload stack. Calling it again moves the
     * loader back to the front (anything that prepended itself since is put behind it).
     */
    public static function register(): void
    {
        if (self::$registered) {
            spl_autoload_unregister([self::class, 'load']);
        }

        spl_autoload_register([self::class, 'load'], true, true);
        self::$registered = true;
    }

    // -----------------------------------------------------------------------
    // The map
    // -----------------------------------------------------------------------

    /**
     * Replace the live map. Paths may be absolute or relative to the framework tree (the
     * form the build writes). Keys that left the map give composer its own entry back; keys
     * that joined it are pointed at the trip-wire.
     *
     * @param array<string, array{by_fqcn: string, by_file: string, file: string}> $map
     */
    public static function set_map(array $map): void
    {
        $resolved = [];

        foreach ($map as $framework_fqcn => $entry) {
            $framework_fqcn = ltrim((string) $framework_fqcn, '\\');

            if (!is_array($entry) || !isset($entry['by_fqcn'], $entry['by_file'], $entry['file'])) {
                throw new RuntimeException("Malformed class override map entry for {$framework_fqcn}. Rebuild the manifest.");
            }

            $resolved[$framework_fqcn] = [
                'by_fqcn' => ltrim($entry['by_fqcn'], '\\'),
                'by_file' => self::__absolute($entry['by_file']),
                'file'    => self::__absolute($entry['file']),
            ];
        }

        ksort($resolved);

        $loader = self::__framework_composer_loader();

        foreach (array_diff_key(self::$map, $resolved) as $framework_fqcn => $entry) {
            if (isset(self::$composer_displaced[$framework_fqcn])) {
                $loader->addClassMap([$framework_fqcn => self::$composer_displaced[$framework_fqcn]]);
            }

            unset(self::$composer_displaced[$framework_fqcn]);
        }

        $classmap = $loader->getClassMap();
        $tripwire = self::tripwire_file();

        foreach ($resolved as $framework_fqcn => $entry) {
            if (!isset(self::$composer_displaced[$framework_fqcn])) {
                $current = $classmap[$framework_fqcn] ?? null;
                self::$composer_displaced[$framework_fqcn] = ($current !== null && $current !== $tripwire) ? $current : $entry['file'];
            }

            $loader->addClassMap([$framework_fqcn => $tripwire]);
        }

        self::$map = $resolved;
        self::$by_fqcn_index = [];
        self::$shadowed_files = [];

        foreach ($resolved as $framework_fqcn => $entry) {
            self::$by_fqcn_index[strtolower($entry['by_fqcn'])] = $framework_fqcn;
            self::$shadowed_files[self::__path_key($entry['file'])] = $framework_fqcn;
        }
    }

    /**
     * The live map, absolute paths.
     *
     * @return array<string, array{by_fqcn: string, by_file: string, file: string}>
     */
    public static function map(): array
    {
        return self::$map;
    }

    /** The trip-wire file composer is handed for every overridden name. */
    public static function tripwire_file(): string
    {
        return dirname(__DIR__, 2) . '/autoload_overrides_tripwire.php';
    }

    // -----------------------------------------------------------------------
    // Loading
    // -----------------------------------------------------------------------

    /**
     * The spl autoloader. Answers ONLY for an exact framework FQCN in the map.
     */
    public static function load(string $class): bool
    {
        $class = ltrim($class, '\\');

        if (!isset(self::$map[$class])) {
            return false;
        }

        self::__resolve($class);

        return true;
    }

    /**
     * Called by Autoloader::include_declaration() once a file is included: when the class it
     * declares overrides a framework class, the framework name becomes its alias NOW, before
     * any instance can reach a parameter typed with the framework name.
     */
    public static function alias_declared(string $declared_fqcn): void
    {
        if (self::$by_fqcn_index === []) {
            return;
        }

        $framework_fqcn = self::$by_fqcn_index[strtolower(ltrim($declared_fqcn, '\\'))] ?? null;

        if ($framework_fqcn === null || !\App\RSpade\Core\Autoloader::_is_declared(self::$map[$framework_fqcn]['by_fqcn'])) {
            return;
        }

        self::__alias(self::$map[$framework_fqcn]['by_fqcn'], $framework_fqcn);
    }

    /**
     * Alias every override now. Manifest::post_init() calls this once the RSX autoloader is
     * registered, so an override whose parent is itself an application class can load.
     */
    public static function alias_all(): void
    {
        foreach (array_keys(self::$map) as $framework_fqcn) {
            self::__resolve($framework_fqcn);
        }
    }

    /**
     * Refuse to include a framework file the application overrides. Including it would
     * declare the framework class under the framework name, after which no alias can ever be
     * made in this process.
     */
    public static function assert_not_shadowed(string $absolute_path): void
    {
        if (self::$shadowed_files === []) {
            return;
        }

        $framework_fqcn = self::$shadowed_files[self::__path_key($absolute_path)] ?? null;

        if ($framework_fqcn === null) {
            return;
        }

        throw new RuntimeException(
            "Refusing to include {$absolute_path}: it declares {$framework_fqcn}, which the application overrides\n"
            . '  with ' . self::$map[$framework_fqcn]['by_fqcn'] . ' (' . self::$map[$framework_fqcn]['by_file'] . ").\n"
            . "  The framework file must never be loaded while it is overridden. See: php artisan rsx:man class_override"
        );
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** Include the override for one map key and alias the framework name to it. */
    private static function __resolve(string $framework_fqcn): void
    {
        $entry = self::$map[$framework_fqcn];
        $by_fqcn = $entry['by_fqcn'];

        if (!\App\RSpade\Core\Autoloader::_is_declared($by_fqcn)) {
            $already_included = self::__included($entry['by_file']);

            if (!$already_included) {
                \App\RSpade\Core\Autoloader::include_declaration($entry['by_file'], $by_fqcn);
            }

            if (!\App\RSpade\Core\Autoloader::_is_declared($by_fqcn)) {
                if ($already_included) {
                    // The file is in the include table but its class is not declared: it is
                    // being declared right now, and declaring it asked for the framework name.
                    throw new LogicException(
                        "{$framework_fqcn} cannot be resolved while {$by_fqcn} is still being declared.\n"
                        . "  Declaring {$by_fqcn} ({$entry['by_file']}) needed {$framework_fqcn}: a method it\n"
                        . "  redeclares has a signature in a framework ancestor that names {$framework_fqcn}, the class\n"
                        . "  {$by_fqcn} replaces. PHP has to compare the two types while {$by_fqcn} is not yet a class,\n"
                        . "  so the framework name cannot be its alias yet.\n"
                        . "  A framework base never names its own overridable class in a signature: it names the\n"
                        . "  `_Abstract` base instead. If that framework signature is yours to change, change it; otherwise\n"
                        . "  spell the type in your override exactly as the framework does (\\{$framework_fqcn}).\n"
                        . '  See: php artisan rsx:man class_override'
                    );
                }

                throw new LogicException(
                    "{$entry['by_file']} was included to override {$framework_fqcn}, but it does not declare {$by_fqcn}. Rebuild the manifest."
                );
            }
        }

        self::__alias($by_fqcn, $framework_fqcn);
    }

    /** class_alias(), refusing every way it could silently not happen. */
    private static function __alias(string $by_fqcn, string $framework_fqcn): void
    {
        if (\App\RSpade\Core\Autoloader::_is_declared($framework_fqcn)) {
            $declared = (new \ReflectionClass($framework_fqcn))->getName();

            if (strcasecmp($declared, $by_fqcn) === 0) {
                return;
            }

            throw new LogicException(
                "{$framework_fqcn} is overridden by {$by_fqcn}, but {$framework_fqcn} was already declared as {$declared}\n"
                . "  before the override could answer for it. Something loaded the framework class first - by path,\n"
                . "  or before the class override map was known. Classes the framework loads while it boots cannot\n"
                . '  be overridden. See: php artisan rsx:man class_override'
            );
        }

        if (!class_alias($by_fqcn, $framework_fqcn, false)) {
            throw new LogicException("class_alias({$by_fqcn}, {$framework_fqcn}) failed. See: php artisan rsx:man class_override");
        }
    }

    /** The framework's own composer ClassLoader: the one whose vendor dir is beside this tree. */
    private static function __framework_composer_loader(): ClassLoader
    {
        $vendor = dirname(__DIR__, 4) . '/vendor';

        foreach (ClassLoader::getRegisteredLoaders() as $vendor_dir => $loader) {
            if ($vendor_dir === $vendor) {
                return $loader;
            }
        }

        throw new RuntimeException(
            "The framework's composer autoloader ({$vendor}/autoload.php) is not registered in this process,\n"
            . '  so the class override loader cannot fence off overridden framework class names.'
        );
    }

    /** A map path: absolute as given, otherwise relative to the framework tree (system/). */
    private static function __absolute(string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return dirname(__DIR__, 4) . '/' . $path;
    }

    private static function __path_key(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    private static function __included(string $path): bool
    {
        return \App\RSpade\Core\Autoloader::_included_under_any_case($path, get_included_files());
    }
}
