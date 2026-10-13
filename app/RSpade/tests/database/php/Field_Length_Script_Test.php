<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\Field_Length_Controller;
use App\RSpade\Core\Database\Field_Length_Script;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Field_Length_Fixture_Model;
use App\RSpade\Tests\Database\Php\Schema_Absent_Table_Fixture_Model;

/**
 * A BUNDLE'S FIELD-LENGTH SCRIPT - Field_Length_Script, the controller that serves it and
 * the tag a page loads it with.
 *
 * The lengths a page's JavaScript reads are not in the model stubs: they are served, per
 * compiled bundle, as `Rsx.__field_length_data = {...};`. What is pinned here:
 *
 *   - the data is the PHP model's own field_length() answer for every varchar/char column
 *     and for nothing else, a class-table-inheritance base's detail columns included;
 *   - a single-underscore SYSTEM column is never published;
 *   - a model whose table does not exist gets an empty entry, not an absence;
 *   - only the name a compiled bundle's own app script carries is a script; every other
 *     name is a 404;
 *   - it is served immutable for the current state key's ?v= and uncacheable otherwise;
 *   - the page's tag follows the app script, and a bundle with no model emits none.
 *
 * A compiled bundle is STAGED - a scratch build root holding one app script and its model
 * list - so nothing here depends on which bundles an application declares. The named
 * models are framework models on framework tables; the class-table-inheritance case is
 * derived from the registry and skips in an application that declares none.
 */
class Field_Length_Script_Test extends Rsx_Test_Abstract
{
    // The system-column case creates and drops a table: DDL commits whatever is open.
    protected static $use_database_transactions = false;

    private const BUNDLE = 'Field_Length_Probe_Bundle';

    private const HASH = 'ab12cd34';

    private static string $root = '';

    public static function teardown()
    {
        static::__unstage();
    }

    /**
     * Stage a compiled bundle carrying $models in a scratch build root and point the path
     * owner at it. Returns the script's name.
     *
     * @param string[] $models
     */
    private static function __stage(array $models): string
    {
        static::__unstage();

        static::$root = Rsx_Project_Paths::tmp_path('test-field-length-script/' . getmypid());
        Rsx_Project_Paths::_override(['build' => static::$root]);

        ensure_directory(Rsx_Project_Paths::bundles_dir());
        ensure_directory(Rsx_Project_Paths::bundle_models_dir());

        file_put_contents(Rsx_Project_Paths::bundles_dir() . '/' . self::BUNDLE . '__app.' . self::HASH . '.js', "// app script\n");
        file_put_contents(
            Rsx_Project_Paths::bundle_models_file(self::BUNDLE),
            json_encode(['bundle' => self::BUNDLE, 'app_hash' => self::HASH, 'models' => $models])
        );

        // The body is cached per bundle and hash, and every test stages the same pair.
        RsxCache::clear();

        return self::BUNDLE . '__' . self::HASH . '.js';
    }

    private static function __unstage(): void
    {
        if (static::$root === '') {
            return;
        }

        Rsx_Project_Paths::_clear_overrides();

        if (is_dir(static::$root)) {
            rmdir_recursive(static::$root);
        }

        static::$root = '';
    }

    /** The object a script body assigns. */
    private static function __data(string $body): array
    {
        static::__assert_true(
            (bool) preg_match('/^Rsx\.__field_length_data = (\{.*\});\n$/s', $body, $matches),
            'the body is one assignment to Rsx.__field_length_data: ' . substr($body, 0, 80)
        );

        return json_decode($matches[1], true);
    }

    // -------------------------------------------------------------------------
    // The data
    // -------------------------------------------------------------------------

    public static function test_every_length_is_the_models_own_answer()
    {
        $data = Field_Length_Script::lengths_for_models(['Login_User_Model', 'User_Model']);

        static::__assert_equals(['Login_User_Model', 'User_Model'], array_keys($data), 'one entry per model asked for');
        static::__assert_equals(Login_User_Model::field_length('email'), $data['Login_User_Model']['email']);
        static::__assert_equals(User_Model::field_length('first_name'), $data['User_Model']['first_name']);

        foreach (['Login_User_Model' => Login_User_Model::class, 'User_Model' => User_Model::class] as $model => $fqcn) {
            foreach ($data[$model] as $column => $length) {
                static::__assert_equals($fqcn::field_length($column), $length, "{$model}.{$column} is the model's answer");
            }

            foreach (array_keys(Rsx_Schema::model_columns($model)) as $column) {
                $is_system = str_starts_with($column, '_') && !str_starts_with($column, '__');

                static::__assert_equals(
                    !$is_system && $fqcn::field_length($column) !== null,
                    array_key_exists($column, $data[$model]),
                    "{$model}.{$column} is published exactly when it is a publishable column with a length"
                );
            }
        }

        static::__assert_false(array_key_exists('id', $data['User_Model']), 'a bigint has no entry');
        static::__assert_false(array_key_exists('created_at', $data['User_Model']), 'nor has a datetime');
    }

    public static function test_a_system_column_is_never_published()
    {
        $table = Field_Length_Fixture_Model::get_table_static();

        DB::statement("DROP TABLE IF EXISTS `{$table}`");
        DB::statement(
            "CREATE TABLE `{$table}` (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NULL,"
            . ' _flag VARCHAR(20) NULL, body TEXT NULL)'
        );
        Rsx_Schema::forget();

        try {
            static::__assert_equals(20, Field_Length_Fixture_Model::field_length('_flag'), 'sanity: PHP answers for the system column');

            static::__assert_equals(
                ['Field_Length_Fixture_Model' => ['name' => 40]],
                Field_Length_Script::lengths_for_models(['Field_Length_Fixture_Model']),
                'the varchar is published; the system varchar and the text column are not'
            );
        } finally {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            Rsx_Schema::forget();
        }
    }

    public static function test_a_model_with_no_table_gets_an_empty_entry()
    {
        static::__assert_false(Rsx_Schema::has_table(Schema_Absent_Table_Fixture_Model::get_table_static()), 'sanity: no such table');

        static::__assert_equals(
            ['Schema_Absent_Table_Fixture_Model' => []],
            Field_Length_Script::lengths_for_models(['Schema_Absent_Table_Fixture_Model'])
        );

        static::__stage(['Schema_Absent_Table_Fixture_Model']);

        static::__assert_equals(
            "Rsx.__field_length_data = {\"Schema_Absent_Table_Fixture_Model\":{}};\n",
            Field_Length_Script::body(self::BUNDLE),
            'and the script carries it as an empty OBJECT, so field_length() answers null instead of reporting a missing script'
        );
    }

    public static function test_a_cti_base_model_publishes_its_detail_columns()
    {
        $subject = null;

        $models = Manifest::get_full_manifest()['data']['models'];
        ksort($models);

        foreach ($models as $model => $record) {
            foreach (Rsx_Schema::model_columns($model) ?? [] as $column => $shape) {
                if ($shape['source_table'] !== $record['table'] && $shape['max_length'] !== null) {
                    $subject = [$model, $column, $shape['max_length']];
                    break 2;
                }
            }
        }

        if ($subject === null) {
            static::__skip('this application declares no class-table-inheritance model with a varchar detail column');

            return;
        }

        [$model, $column, $length] = $subject;

        static::__assert_equals(
            $length,
            Field_Length_Script::lengths_for_models([$model])[$model][$column] ?? null,
            "{$model}.{$column} lives on a detail table and the base model's entry carries it"
        );
    }

    public static function test_a_name_that_is_not_a_model_fails_loud()
    {
        static::__assert_throws(
            \Throwable::class,
            fn () => Field_Length_Script::lengths_for_models(['Rsx_Test_Abstract']),
            'is not a model'
        );
    }

    // -------------------------------------------------------------------------
    // Which names are a script
    // -------------------------------------------------------------------------

    public static function test_only_the_bundles_own_app_script_name_resolves()
    {
        $script = static::__stage(['User_Model']);

        static::__assert_equals(self::BUNDLE, Field_Length_Script::resolve($script));

        static::__assert_null(Field_Length_Script::resolve(self::BUNDLE . '__00000000.js'), 'another hash');
        static::__assert_null(Field_Length_Script::resolve('No_Such_Probe_Bundle__' . self::HASH . '.js'), 'a bundle with no model list');
        static::__assert_null(Field_Length_Script::resolve(self::BUNDLE . '.js'), 'no hash at all');
        static::__assert_null(Field_Length_Script::resolve(self::BUNDLE . '__' . self::HASH), 'no extension');
        static::__assert_null(Field_Length_Script::resolve('../' . $script), 'a path');
        static::__assert_null(Field_Length_Script::resolve(''), 'nothing');

        // The list alone is not enough: the app script it names must be on disk.
        unlink(Rsx_Project_Paths::bundles_dir() . '/' . self::BUNDLE . '__app.' . self::HASH . '.js');
        static::__assert_null(Field_Length_Script::resolve($script), 'a list whose app script is gone');
    }

    // -------------------------------------------------------------------------
    // The served response
    // -------------------------------------------------------------------------

    public static function test_the_script_is_served_with_the_bundles_models()
    {
        $script = static::__stage(['Login_User_Model', 'User_Model']);
        $state_key = Manifest::get_state_key();

        $response = Field_Length_Controller::script(
            Request::create(Field_Length_Script::URL_PREFIX . $script, 'GET', ['v' => $state_key]),
            ['script' => $script, 'v' => $state_key]
        );

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_contains('text/javascript', (string) $response->headers->get('Content-Type'));

        static::__assert_equals(
            Field_Length_Script::lengths_for_models(['Login_User_Model', 'User_Model']),
            static::__data($response->getContent()),
            'the body assigns the lengths of exactly the models in the list'
        );

        static::__assert_equals(
            $response->getContent(),
            Field_Length_Script::body(self::BUNDLE),
            'and a second read (the cached one) is the same bytes'
        );
    }

    public static function test_it_is_immutable_for_the_current_state_key_only()
    {
        $script = static::__stage(['User_Model']);
        $state_key = Manifest::get_state_key();
        $request = Request::create(Field_Length_Script::URL_PREFIX . $script, 'GET');

        $current = Field_Length_Controller::script($request, ['script' => $script, 'v' => $state_key]);
        $cache_control = (string) $current->headers->get('Cache-Control');

        static::__assert_contains('immutable', $cache_control);
        static::__assert_contains('public', $cache_control);
        static::__assert_contains('max-age=31536000', $cache_control);

        foreach ([['v' => str_repeat('0', 32)], []] as $other) {
            $response = Field_Length_Controller::script($request, ['script' => $script] + $other);

            static::__assert_equals(200, $response->getStatusCode(), 'another key is still answered - with the current schema');
            static::__assert_contains('no-store', (string) $response->headers->get('Cache-Control'));
            static::__assert_false(
                str_contains((string) $response->headers->get('Cache-Control'), 'immutable'),
                'and never stored under a URL that names another state'
            );
        }
    }

    public static function test_any_other_name_is_a_404()
    {
        static::__stage(['User_Model']);

        foreach ([self::BUNDLE . '__00000000.js', 'No_Such_Probe_Bundle__' . self::HASH . '.js', 'x.js', ''] as $name) {
            $response = Field_Length_Controller::script(
                Request::create(Field_Length_Script::URL_PREFIX . $name, 'GET'),
                ['script' => $name]
            );

            static::__assert_equals(404, $response->getStatusCode(), "'{$name}' is not a script");
            static::__assert_false(str_contains($response->getContent(), '__field_length_data'), 'and carries no data');
        }
    }

    // -------------------------------------------------------------------------
    // The tag
    // -------------------------------------------------------------------------

    public static function test_the_compiler_names_a_script_only_for_a_bundle_with_models()
    {
        $compiler = new BundleCompiler();
        (new ReflectionProperty(BundleCompiler::class, 'bundle_name'))->setValue($compiler, self::BUNDLE);
        $name = new ReflectionMethod(BundleCompiler::class, '_field_length_script_name');

        $app_script = self::BUNDLE . '__app.' . self::HASH . '.js';

        $script = static::__stage(['User_Model']);
        static::__assert_equals($script, $name->invoke($compiler, $app_script), 'a bundle carrying a model names its script');

        static::__stage([]);
        static::__assert_null($name->invoke($compiler, $app_script), 'a bundle carrying none names nothing');

        static::__assert_null($name->invoke($compiler, null), 'and so does a bundle with no app script');
    }

    public static function test_the_tag_follows_the_app_script_and_a_modelless_bundle_emits_none()
    {
        $generate = new ReflectionMethod(Rsx_Bundle_Abstract::class, '__generate_html');

        $compiled = [
            'vendor_js_bundle_path' => self::BUNDLE . '__vendor.11111111.js',
            'app_js_bundle_path' => self::BUNDLE . '__app.' . self::HASH . '.js',
            'field_length_script' => self::BUNDLE . '__' . self::HASH . '.js',
        ];

        $html = $generate->invoke(null, $compiled, []);

        $expected_src = Field_Length_Script::URL_PREFIX . self::BUNDLE . '__' . self::HASH . '.js?v=' . Manifest::get_state_key();
        $tag = '<script src="' . $expected_src . '" defer></script>';

        static::__assert_contains($tag, $html, 'the tag is deferred and carries the state key');
        static::__assert_greater_than(
            strpos($html, '/_compiled/' . self::BUNDLE . '__app.' . self::HASH . '.js'),
            strpos($html, $tag),
            'and it follows the app script, so Rsx is defined when it runs'
        );
        static::__assert_equals(1, substr_count($html, Field_Length_Script::URL_PREFIX), 'exactly one such tag');

        $compiled['field_length_script'] = null;

        static::__assert_false(
            str_contains($generate->invoke(null, $compiled, []), Field_Length_Script::URL_PREFIX),
            'a bundle with no model emits no tag at all'
        );
    }
}
