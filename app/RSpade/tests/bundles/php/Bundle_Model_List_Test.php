<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * EVERY COMPILED BUNDLE HAS A MODEL LIST BESIDE IT - build/bundle_models/<Bundle>.json,
 * naming the models whose generated JS stubs the bundle's app script contains.
 *
 * The list is what a bundle's field-length script is built from, so it has to be exactly
 * what the compiled output carries, be written whenever that output is, be rewritten when
 * the two come apart, and be part of the sealed build.
 *
 * The bundle compiled here is the framework's own control-panel bundle, resolved from the
 * manifest; the seal is exercised over a staged build root.
 */
class Bundle_Model_List_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const BUNDLE = '_Sys_Bundle';

    /** Compile the bundle and return [compile result, its model list]. */
    private static function __compile(): array
    {
        $record = Manifest::php_class_metadata(self::BUNDLE);
        static::__assert_not_null($record, 'the framework ships its control-panel bundle');

        $result = (new BundleCompiler())->compile($record['fqcn'], []);
        $list = BundleCompiler::read_model_list(self::BUNDLE);

        static::__assert_not_null($list, 'a compile leaves a model list');

        return [$result, $list];
    }

    private static function __app_hash(array $result): string
    {
        static::__assert_true(
            (bool) preg_match('/__app\.([a-f0-9]+)\.js$/', $result['app_js_bundle_path'], $matches),
            'the compiled app script carries a hash in its name'
        );

        return $matches[1];
    }

    public static function test_the_list_names_exactly_the_models_whose_stubs_are_in_the_bundle()
    {
        [$result, $list] = static::__compile();

        static::__assert_equals(self::BUNDLE, $list['bundle']);
        static::__assert_equals(static::__app_hash($result), $list['app_hash'], 'the list names the app script it describes');

        // A generated stub - and nothing else in a bundle - declares its PHP model's name
        // as `static __MODEL = '<Model>';`.
        $source = file_get_contents(Rsx_Project_Paths::bundles_dir() . '/' . $result['app_js_bundle_path']);
        preg_match_all("/static __MODEL = '([A-Za-z0-9_]+)';/", $source, $matches);

        $in_bundle = array_values(array_unique($matches[1]));
        sort($in_bundle, SORT_STRING);

        static::__assert_not_empty($in_bundle, 'sanity: this bundle carries model stubs');
        static::__assert_equals($in_bundle, $list['models'], 'the list is the stubs in the compiled output, sorted');

        foreach ($list['models'] as $model) {
            static::__assert_not_null(Manifest::php_model_record($model), "{$model} is a model in the registry");
        }
    }

    public static function test_the_compile_result_names_the_bundles_field_length_script()
    {
        [$result, $list] = static::__compile();

        static::__assert_equals(
            self::BUNDLE . '__' . $list['app_hash'] . '.js',
            $result['field_length_script'],
            'a bundle carrying models names its script after its own app script hash'
        );
    }

    /**
     * The staleness gate: a list that is missing, or names another app script, makes the
     * app output stale exactly as a missing compiled file does.
     */
    public static function test_a_missing_or_mismatched_list_is_rewritten_by_the_next_compile()
    {
        [, $list] = static::__compile();
        $path = Rsx_Project_Paths::bundle_models_file(self::BUNDLE);

        unlink($path);
        static::__assert_null(BundleCompiler::read_model_list(self::BUNDLE), 'sanity: the list is gone');

        [, $rewritten] = static::__compile();
        static::__assert_equals($list, $rewritten, 'a missing list is written again');

        file_put_contents($path, json_encode(['bundle' => self::BUNDLE, 'app_hash' => '00000000', 'models' => []]));

        [, $rewritten] = static::__compile();
        static::__assert_equals($list, $rewritten, 'and so is one that names another app script');
    }

    public static function test_an_unreadable_list_fails_loud()
    {
        [, $list] = static::__compile();
        $path = Rsx_Project_Paths::bundle_models_file(self::BUNDLE);

        try {
            file_put_contents($path, 'not json');

            static::__assert_throws(
                \RuntimeException::class,
                fn () => BundleCompiler::read_model_list(self::BUNDLE),
                'not readable as a model list'
            );
        } finally {
            file_put_contents($path, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    public static function test_the_seal_covers_the_model_lists()
    {
        $root = Rsx_Project_Paths::tmp_path('test-bundle-model-list/' . getmypid());

        if (is_dir($root)) {
            rmdir_recursive($root);
        }

        Rsx_Project_Paths::_override(['build' => $root]);

        try {
            ensure_directory(Rsx_Project_Paths::bundles_dir());
            ensure_directory(Rsx_Project_Paths::bundle_models_dir());

            file_put_contents(Rsx_Project_Paths::manifest_index_file(), "<?php\nreturn ['ok' => true];\n");
            file_put_contents(Rsx_Project_Paths::manifest_files_file(), "<?php\nreturn [];\n");
            file_put_contents(Rsx_Project_Paths::build_key_file(), "deadbeefcafef00d\n");
            file_put_contents(Rsx_Project_Paths::bundles_dir() . '/Probe__app.abc12345.js', "console.log(1);\n");
            file_put_contents(Rsx_Project_Paths::bundle_models_file('Probe'), '{"bundle":"Probe","app_hash":"abc12345","models":[]}');

            $seal = Rsx_Prod_Seal::write('production');
            $sealed = array_column($seal['assets'], 'sha256', 'file');
            $list_key = Rsx_Project_Paths::key_for(Rsx_Project_Paths::bundle_models_file('Probe'));
            $list_asset = substr($list_key, strlen(Rsx_Project_Paths::key_for($root)) + 1);

            static::__assert_array_has_key($list_asset, $sealed, 'the model list is a sealed asset');
            static::__assert_equals(
                hash_file('sha256', Rsx_Project_Paths::bundle_models_file('Probe')),
                $sealed[$list_asset]
            );

            // And a list changed after the seal is drift, like any other build asset.
            file_put_contents(Rsx_Project_Paths::bundle_models_file('Probe'), '{"bundle":"Probe","app_hash":"abc12345","models":["X"]}');

            $drift = implode("\n", Rsx_Prod_Seal::verify());
            static::__assert_contains($list_asset, $drift, 'rsx:prod:verify reports a changed model list');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            Rsx_Project_Paths::_clear_overrides();
            rmdir_recursive($root);
        }
    }
}
