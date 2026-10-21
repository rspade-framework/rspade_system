<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use Composer\Autoload\ClassLoader;
use App\RSpade\Core\Autoloader;
use App\RSpade\Core\Files\Markdown_Rendition;
use App\RSpade\Core\Overrides\Class_Override_Loader;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Class_Override_Loader, in process: the narrow loader in front of composer that makes a
 * framework class name answer with the application class overriding it (rsx:man
 * class_override).
 *
 * Every case builds its OWN fixture under tmp/ - a framework-shaped base, a framework shell
 * (the shadowed file), a framework consumer whose signatures name the shell, and an override in
 * an application namespace - with names unique to the case, because a declared class can never
 * be undeclared. The classes live in no scanned tree and no composer mapping, so the only way
 * any of them loads is the way under test. Each case puts the loader's map back as it found it.
 *
 * Pure logic, no DB.
 */
class Override_Loader_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Write one fixture set and return its names and paths.
     *
     * @return array{fw: string, by: string, abstract: string, consumer: string, fw_file: string, by_file: string, base_file: string, consumer_file: string, dir: string}
     */
    private static function __fixture(string $tag): array
    {
        $unique = $tag . '_' . bin2hex(random_bytes(4));
        $dir = Rsx_Project_Paths::tmp_path('class-override-loader-test/' . $unique);
        mkdir($dir, 0777, true);

        $fw_namespace = 'App\\RSpade\\Tests\\Override_Loader_Fixture_' . $unique;
        $by_namespace = 'Rsx\\Override_Loader_Probe_' . $unique;

        $base_file = $dir . '/Target_Abstract.php';
        file_put_contents($base_file, "<?php\nnamespace {$fw_namespace};\n\nabstract class Target_Abstract\n{\n    public function who(): string { return 'framework'; }\n}\n");

        $fw_file = $dir . '/Target.php';
        file_put_contents($fw_file, "<?php\nnamespace {$fw_namespace};\n\nclass Target extends Target_Abstract {}\n");

        $consumer_file = $dir . '/Consumer.php';
        file_put_contents($consumer_file, "<?php\nnamespace {$fw_namespace};\n\nclass Consumer\n{\n"
            . "    public static function take(Target \$target): string { return \$target->who(); }\n"
            . "    public static function give(object \$target): Target { return \$target; }\n}\n");

        $by_file = $dir . '/override_target.php';
        file_put_contents($by_file, "<?php\nnamespace {$by_namespace};\n\nclass Target extends \\{$fw_namespace}\\Target_Abstract\n{\n    public function who(): string { return 'override'; }\n}\n");

        require_once $base_file;
        require_once $consumer_file;

        return [
            'fw' => $fw_namespace . '\\Target',
            'by' => $by_namespace . '\\Target',
            'abstract' => $fw_namespace . '\\Target_Abstract',
            'consumer' => $fw_namespace . '\\Consumer',
            'fw_file' => $fw_file,
            'by_file' => $by_file,
            'base_file' => $base_file,
            'consumer_file' => $consumer_file,
            'dir' => $dir,
        ];
    }

    /** The live map plus one fixture entry. */
    private static function __map_with(array $fixture): array
    {
        $map = Class_Override_Loader::map();
        $map[$fixture['fw']] = ['by_fqcn' => $fixture['by'], 'by_file' => $fixture['by_file'], 'file' => $fixture['fw_file']];

        return $map;
    }

    private static function __composer(): ClassLoader
    {
        $vendor = realpath(base_path('vendor'));

        foreach (ClassLoader::getRegisteredLoaders() as $vendor_dir => $loader) {
            if (realpath($vendor_dir) === $vendor) {
                return $loader;
            }
        }

        static::__fail('the framework composer loader is not registered');
    }

    private static function __cleanup(array $fixture): void
    {
        foreach (glob($fixture['dir'] . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($fixture['dir']);
    }

    // -------------------------------------------------------------------------

    /** The loader is first on the autoload stack (post_init re-prepends it). */
    public static function test_the_loader_is_first_on_the_autoload_stack()
    {
        $first = spl_autoload_functions()[0] ?? null;

        static::__assert_equals([Class_Override_Loader::class, 'load'], $first, 'the override loader must be in front of every other autoloader');
    }

    /** The HTML sanitizers' first use (HTMLPurifier, loaded on demand) leaves the loader first. */
    public static function test_the_html_sanitizers_leave_the_loader_first()
    {
        sanitize_rich_text_html('<p>text</p>');
        Markdown_Rendition::render("# Heading\n\ntext");

        $first = spl_autoload_functions()[0] ?? null;

        static::__assert_equals([Class_Override_Loader::class, 'load'], $first, 'a library used on demand must not put an autoloader in front of the override loader');
    }

    /** A mapped framework name resolves to the override, and the shadowed framework file is never included. */
    public static function test_a_hit_aliases_the_framework_name_to_the_override()
    {
        $fixture = static::__fixture('hit');
        $original = Class_Override_Loader::map();

        try {
            Class_Override_Loader::set_map(static::__map_with($fixture));

            static::__assert_true(class_exists($fixture['fw']), 'the framework name must resolve');
            static::__assert_equals($fixture['by'], (new \ReflectionClass($fixture['fw']))->getName(), 'the framework name is an alias of the override');
            static::__assert_equals('override', (new $fixture['fw']())->who());
            static::__assert_false(
                Autoloader::_included_under_any_case($fixture['fw_file'], get_included_files()),
                'the shadowed framework file must never be included'
            );
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** Anything not an exact key of the map is declined at once, for the next loader to answer. */
    public static function test_a_miss_falls_through()
    {
        static::__assert_false(Class_Override_Loader::load('App\\RSpade\\Tests\\No_Such_Override_Target'), 'an unmapped name is not this loader\'s');
        static::__assert_false(Class_Override_Loader::load('Illuminate\\Support\\Collection'), 'a simple-name match is never enough');
    }

    /**
     * An instance of the override reaches a framework parameter typed with the framework name
     * when it was loaded by its OWN name through the RSX include seam - before anything autoloaded
     * the framework name. PHP's type checks never autoload, so the alias has to exist already.
     */
    public static function test_an_override_instance_satisfies_a_framework_typed_parameter()
    {
        $fixture = static::__fixture('typed');
        $original = Class_Override_Loader::map();

        try {
            Class_Override_Loader::set_map(static::__map_with($fixture));

            static::__assert_false(class_exists($fixture['fw'], false), 'nothing has asked for the framework name yet');

            Autoloader::include_declaration($fixture['by_file'], $fixture['by']);
            $instance = new $fixture['by']();

            static::__assert_equals('override', $fixture['consumer']::take($instance), 'a parameter typed with the framework name accepts the override');
            static::__assert_true($instance === $fixture['consumer']::give($instance), 'a return type naming the framework name accepts the override');
            static::__assert_true($instance instanceof $fixture['fw'], 'instanceof the framework name holds');
            static::__assert_true(is_a($fixture['by'], $fixture['fw'], true), 'string is_a() against the framework name holds');
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** alias_all() makes instanceof hold for an override that was declared behind the seam's back. */
    public static function test_alias_all_makes_instanceof_hold_before_any_autoload()
    {
        $fixture = static::__fixture('eager');
        $original = Class_Override_Loader::map();

        try {
            Class_Override_Loader::set_map(static::__map_with($fixture));

            require_once $fixture['by_file'];
            $instance = new $fixture['by']();

            static::__assert_false($instance instanceof $fixture['fw'], 'instanceof does not autoload: without the alias it is false');

            Class_Override_Loader::alias_all();

            static::__assert_true($instance instanceof $fixture['fw'], 'after the eager pass instanceof holds');
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** A framework name already declared as the framework's own class can never become an alias: loud. */
    public static function test_an_already_declared_framework_class_throws()
    {
        $fixture = static::__fixture('declared');
        $original = Class_Override_Loader::map();

        try {
            require_once $fixture['fw_file'];

            Class_Override_Loader::set_map(static::__map_with($fixture));

            static::__assert_throws(\LogicException::class, fn () => Class_Override_Loader::load($fixture['fw']), 'was already declared as');
            static::__assert_throws(\LogicException::class, fn () => Class_Override_Loader::alias_all(), $fixture['by']);
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** Composer asked for an overridden name answers with the trip-wire, which names the class. */
    public static function test_the_tripwire_fires_when_composer_is_asked_first()
    {
        $fixture = static::__fixture('tripwire');
        $original = Class_Override_Loader::map();
        $composer = static::__composer();

        try {
            Class_Override_Loader::set_map(static::__map_with($fixture));

            static::__assert_equals(Class_Override_Loader::tripwire_file(), $composer->findFile($fixture['fw']), 'composer\'s entry points at the trip-wire');

            $thrown = static::__assert_throws(\LogicException::class, fn () => $composer->loadClass($fixture['fw']), 'composer was asked for it before the');
            static::__assert_contains($fixture['fw'], $thrown->getMessage(), 'the trip-wire names the requested class');
            static::__assert_contains($fixture['by'], $thrown->getMessage(), 'the trip-wire names the override');
            static::__assert_false(class_exists($fixture['fw'], false), 'composer declared nothing');
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** A key that leaves the map gives composer its own answer back. */
    public static function test_leaving_the_map_restores_composer()
    {
        $fixture = static::__fixture('restore');
        $original = Class_Override_Loader::map();
        $composer = static::__composer();

        try {
            Class_Override_Loader::set_map(static::__map_with($fixture));
            Class_Override_Loader::set_map($original);

            static::__assert_equals($fixture['fw_file'], $composer->findFile($fixture['fw']), 'composer answers with the framework file again');
            static::__assert_false(Class_Override_Loader::load($fixture['fw']), 'the loader no longer answers for it');
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** The include seam refuses the shadowed framework file. */
    public static function test_include_declaration_refuses_a_shadowed_file()
    {
        $fixture = static::__fixture('shadowed');
        $original = Class_Override_Loader::map();

        try {
            Class_Override_Loader::set_map(static::__map_with($fixture));

            static::__assert_throws(
                \RuntimeException::class,
                fn () => Autoloader::include_declaration($fixture['fw_file'], $fixture['fw']),
                'which the application overrides'
            );
            static::__assert_false(
                Autoloader::_included_under_any_case($fixture['fw_file'], get_included_files()),
                'the refused file was not included'
            );
        } finally {
            Class_Override_Loader::set_map($original);
            static::__cleanup($fixture);
        }
    }

    /** The artifact round-trips: written relative, read back, resolved against the framework tree. */
    public static function test_the_artifact_round_trips_and_a_missing_one_is_empty()
    {
        $dir = Rsx_Project_Paths::tmp_path('class-override-loader-test/artifact_' . bin2hex(random_bytes(4)));
        $path = $dir . '/manifest/class_overrides.php';
        $map = [
            'App\\RSpade\\Core\\Qr\\Rsx_Qr_Code' => [
                'by_fqcn' => 'Rsx\\Lib\\Rsx_Qr_Code',
                'by_file' => 'rsx/lib/rsx_qr_code.php',
                'file' => 'app/RSpade/Core/Qr/Rsx_Qr_Code.php',
            ],
        ];

        try {
            static::__assert_equals([], Class_Override_Loader::read_artifact($path), 'a missing artifact is an empty map');

            Class_Override_Loader::write_artifact($path, $map);
            $mtime = filemtime($path);

            static::__assert_equals($map, Class_Override_Loader::read_artifact($path), 'what was written is what is read');

            clearstatcache();
            Class_Override_Loader::write_artifact($path, $map);
            static::__assert_equals($mtime, filemtime($path), 'an unchanged map is not rewritten');
            static::__assert_equals([], glob($dir . '/manifest/*.tmp.*') ?: [], 'no temp file is left behind');
        } finally {
            @unlink($path);
            @rmdir($dir . '/manifest');
            @rmdir($dir);
        }
    }
}
