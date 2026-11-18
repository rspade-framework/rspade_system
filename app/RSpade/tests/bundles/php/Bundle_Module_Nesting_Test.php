<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use RuntimeException;
use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;
use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A MODULE BUNDLE CANNOT INCLUDE ANOTHER MODULE BUNDLE (BUNDLE-INCLUDE-01).
 *
 * Module bundles are top-level page entry points; shared code between two of them is an
 * asset bundle both include. Rsx_Module_Bundle_Abstract::validate_include() refuses the
 * nesting with remediation text naming both bundles and the asset-bundle alternative, and
 * BundleCompiler calls it for every module bundle it resolves under another.
 *
 * Two halves: validate_include() directly (a module bundle refused, an asset bundle and a
 * non-bundle passed), and the compiler path end to end - a CHILD build of a fixture tree into
 * a scratch build root, then a child `rsx:bundle:compile` of the outer bundle, which must fail
 * naming the refusal. The fixture tree sits under a `resource/` directory, which no ordinary
 * build descends into, so a deliberately uncompilable bundle never reaches the running
 * manifest or a whole-tree compile.
 */
class Bundle_Module_Nesting_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const REFUSAL = 'Module bundle cannot include another module bundle';

    private static string $root = '';

    private static string $build_root = '';

    public static function test_validate_include_refuses_a_module_bundle()
    {
        $inner = new class extends Rsx_Module_Bundle_Abstract {
            public static function define(): array
            {
                return ['include' => []];
            }
        };

        $e = static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Module_Bundle_Abstract::validate_include(get_class($inner), 'Probe_Outer_Bundle'),
            self::REFUSAL
        );

        static::__assert_contains('Parent bundle: Probe_Outer_Bundle', $e->getMessage());
        static::__assert_contains('Attempted to include: ' . get_class($inner), $e->getMessage());
        static::__assert_contains('create an Asset Bundle', $e->getMessage(), 'the remediation names the alternative');
        static::__assert_contains('extends Rsx_Asset_Bundle_Abstract', $e->getMessage(), 'and shows it');
    }

    public static function test_validate_include_passes_an_asset_bundle_and_a_non_bundle()
    {
        $asset = new class extends Rsx_Asset_Bundle_Abstract {
            public static function define(): array
            {
                return ['include' => []];
            }
        };

        Rsx_Module_Bundle_Abstract::validate_include(get_class($asset), 'Probe_Outer_Bundle');
        Rsx_Module_Bundle_Abstract::validate_include(\stdClass::class, 'Probe_Outer_Bundle');

        static::__pass('an asset bundle and a non-bundle class are not refused');
    }

    // =====================================================================
    // The compiler path, in a child build
    // =====================================================================

    private static function __fixture(): array
    {
        $outer = static::$root . '/bmn_outer_bundle.php';
        $inner = static::$root . '/bmn_inner_bundle.php';
        $script = static::$root . '/bmn_probe.js';

        $bundle = static function (string $path, string $class, string $include): string {
            $namespace = Rsx_Namespace::from_path($path);

            return "<?php\n\nnamespace {$namespace};\n\n"
                . "use App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;\n\n"
                . "class {$class} extends Rsx_Module_Bundle_Abstract\n{\n"
                . "    public static function define(): array\n    {\n"
                . "        return ['include' => ['{$include}']];\n    }\n}\n";
        };

        return [
            $script => "class Bmn_Probe {\n    static marker() {\n        return 'bmn';\n    }\n}\n",
            $inner => $bundle($inner, 'Bmn_Inner_Bundle', $script),
            $outer => $bundle($outer, 'Bmn_Outer_Bundle', 'Bmn_Inner_Bundle'),
        ];
    }

    private static function __child(string $command, array $args, array &$output): int
    {
        ensure_directory(static::$build_root);
        ensure_directory(static::$build_root . '-tmp');

        return Rsx_Artisan::run($command, array_merge($args, [
            '--_rsx-build-root=' . static::$build_root,
            '--_rsx-tmp-root=' . static::$build_root . '-tmp',
            '--_manifest-extra-scan-roots=' . static::$root,
        ]), $output);
    }

    private static function __remove(): void
    {
        if (static::$root !== '' && is_dir(base_path(static::$root))) {
            rmdir_recursive(base_path(static::$root));
        }

        if (static::$build_root !== '' && is_dir(dirname(static::$build_root))) {
            rmdir_recursive(dirname(static::$build_root));
        }
    }

    public static function test_the_compiler_refuses_a_nested_module_bundle()
    {
        static::$root = 'app/RSpade/temp/resource/bmn_' . getmypid();
        static::$build_root = Rsx_Project_Paths::tmp_path('bundle-module-nesting/' . getmypid() . '/build');
        static::__remove();

        try {
            foreach (static::__fixture() as $path => $content) {
                ensure_directory(dirname(base_path($path)));
                file_put_contents(base_path($path), $content);
            }

            $output = [];
            static::__assert_equals(0, static::__child('rsx:manifest:build', [], $output), "the fixture build failed:\n" . implode("\n", $output));

            $output = [];
            $exit = static::__child('rsx:bundle:compile', ['Bmn_Outer_Bundle'], $output);
            $text = implode("\n", $output);

            static::__assert_not_equals(0, $exit, "compiling a module bundle that includes a module bundle fails:\n{$text}");
            // The console wraps a long error line at its width, so names are looked for with
            // every whitespace run removed.
            $compact = (string) preg_replace('/\s+/', '', $text);

            static::__assert_contains(str_replace(' ', '', self::REFUSAL), $compact, 'and says why');
            static::__assert_contains('Bmn_Inner_Bundle', $compact, 'naming the included bundle');
            static::__assert_equals([], glob(static::$build_root . '/bundles/Bmn_Outer_Bundle__*') ?: [], 'and writes no artifact');
        } finally {
            static::__remove();
        }
    }
}
