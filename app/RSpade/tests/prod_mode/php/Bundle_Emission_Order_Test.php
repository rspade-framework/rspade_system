<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Bundle emission ORDER CONTRACT regression tests.
 *
 * A bundle's define() 'include' array order IS the JS evaluation order (with
 * dependency hoisting for known extends/decorator edges). Model JS stubs are
 * emitted AT their owning PHP file's include position, and the concrete model
 * aliases (class X_Model extends Base_X_Model {}) are spliced in immediately
 * after the stubs - so a models directory declared before the module directory
 * means a module class may reference X_Model at EVAL time (static X =
 * Some_Model.CONST at module scope) without dying in the temporal dead zone.
 *
 * Regression: a global resort of the assembled file list (plus tail-appended
 * stubs/aliases) inverted declared order and TDZ-crashed downstream apps.
 * See docs.dev/external_requests/2026_07_15_bundle_emission_order_tdz.md.
 *
 * NOTHING HERE NAMES AN APPLICATION CLASS OR BUNDLE. The bundle is resolved from the
 * manifest (an application module bundle that has been compiled and whose include list
 * declares at least two directories that actually emit JS classes), the two ordered
 * groups are the FIRST and LAST of those directories, and the model chain is found by
 * shape - `class Base_X_Model` and its `class X_Model extends Base_X_Model` alias.
 *
 * The compiled app JS is read from storage/rsx-build/bundles, which dev JIT keeps
 * current. When no application bundle qualifies the tests skip: the property under test
 * is emission order, which is independent of how fresh a bundle's contents are.
 */
class Bundle_Emission_Order_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Line number of the first match of $pattern in $content, or null.
     */
    protected static function __first_line(string $content, string $pattern): ?int
    {
        foreach (explode("\n", $content) as $i => $text) {
            if (preg_match($pattern, $text)) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The application's own module bundles, in name order. Bundles declared under
     * app/RSpade are excluded: the panel's own two are not application bundles, and a
     * bundle fixture in the test tree is never compiled.
     *
     * @return string[]
     */
    protected static function __application_bundle_names(): array
    {
        $names = [];

        foreach (Manifest::php_get_extending('Rsx_Module_Bundle_Abstract') as $name => $metadata) {
            $file = $metadata['file'] ?? '';

            if (str_starts_with($name, '_') || !str_starts_with($file, 'rsx/')) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * The bundle's include entries that are existing directories, as project-relative
     * paths, in DECLARED order - which is the order under test.
     *
     * @return string[]
     */
    protected static function __include_directories(string $bundle_name): array
    {
        $metadata = Manifest::php_get_metadata_by_class($bundle_name);
        $fqcn = $metadata['fqcn'] ?? null;

        if ($fqcn === null || !method_exists($fqcn, 'define')) {
            return [];
        }

        $root = rsx_project_file_path('');
        $directories = [];

        foreach ($fqcn::define()['include'] ?? [] as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $absolute = str_starts_with($entry, '/') ? $entry : rsx_project_file_path($entry);

            if (!is_dir($absolute)) {
                continue;
            }

            $directories[] = trim(str_replace($root, '', $absolute), '/');
        }

        return $directories;
    }

    /**
     * The line of the first top-level JS class the compiled bundle emits that the
     * manifest declares under $directory, or null when it emits none.
     */
    protected static function __first_emitted_class_line(string $content, string $directory): ?int
    {
        $best = null;

        foreach (Manifest::get_full_manifest()['data']['js_classes'] ?? [] as $name => $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? '') : '';

            if (!str_starts_with($file, $directory . '/')) {
                continue;
            }

            $line = static::__first_line($content, '/^class ' . preg_quote($name, '/') . '\b/');

            if ($line !== null && ($best === null || $line < $best)) {
                $best = $line;
            }
        }

        return $best;
    }

    /**
     * An application bundle whose declared include order can actually be observed in its
     * compiled output: it has been compiled, and at least two of its directory includes
     * emit a JS class into it.
     *
     * @return array{name: string, content: string, first: int, last: int}|null
     */
    protected static function __ordering_bundle(): ?array
    {
        foreach (static::__application_bundle_names() as $name) {
            $files = glob(storage_path("rsx-build/bundles/{$name}__app.*.js"));

            if (empty($files)) {
                continue;
            }

            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $content = file_get_contents($files[0]);

            $lines = [];

            foreach (static::__include_directories($name) as $directory) {
                $line = static::__first_emitted_class_line($content, $directory);

                if ($line !== null) {
                    $lines[$directory] = $line;
                }
            }

            if (count($lines) < 2) {
                continue;
            }

            return [
                'name' => $name,
                'content' => $content,
                'first' => reset($lines),
                'last' => end($lines),
                'first_directory' => array_key_first($lines),
                'last_directory' => array_key_last($lines),
            ];
        }

        return null;
    }

    public static function test_models_evaluate_before_later_include_directories()
    {
        $bundle = static::__ordering_bundle();

        if ($bundle === null) {
            static::__skip('no compiled application bundle emits classes from two include directories');

            return;
        }

        $content = $bundle['content'];

        $js_model_base = static::__first_line($content, '/^class Rsx_Js_Model\b/');
        static::__assert_not_null($js_model_base, "Rsx_Js_Model present in {$bundle['name']}");

        $model_name = null;
        $base_model = null;

        foreach (explode("\n", $content) as $i => $text) {
            if (preg_match('/^class Base_(\w+_Model)\b/', $text, $m)) {
                $model_name = $m[1];
                $base_model = $i + 1;
                break;
            }
        }

        if ($model_name === null) {
            static::__skip("{$bundle['name']} emits no model stub to order");

            return;
        }

        $alias = static::__first_line(
            $content,
            '/^class ' . preg_quote($model_name, '/') . ' extends Base_' . preg_quote($model_name, '/') . '/'
        );

        static::__assert_not_null($alias, "the {$model_name} alias is emitted in {$bundle['name']}");

        // The full chain an eval-time model reference depends on:
        // Rsx_Js_Model -> model stubs -> concrete aliases -> classes from a later include.
        static::__assert_true($js_model_base < $base_model, 'Rsx_Js_Model precedes model stubs');
        static::__assert_true($base_model < $alias, 'stub precedes its concrete alias');
        static::__assert_true(
            $alias < $bundle['last'],
            "the model alias (line {$alias}) must precede the first class of the last include "
            . "'{$bundle['last_directory']}' (line {$bundle['last']}) - declared include order is the "
            . 'eval-order contract'
        );
    }

    public static function test_an_earlier_include_directory_precedes_a_later_one()
    {
        $bundle = static::__ordering_bundle();

        if ($bundle === null) {
            static::__skip('no compiled application bundle emits classes from two include directories');

            return;
        }

        static::__assert_true(
            $bundle['first'] < $bundle['last'],
            "a class from the earlier include '{$bundle['first_directory']}' (line {$bundle['first']}) must "
            . "precede one from '{$bundle['last_directory']}' (line {$bundle['last']})"
        );
    }
}
