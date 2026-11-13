<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use ReflectionMethod;
use App\RSpade\Core\Database\Model_Stub_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Codegen\Php\No_Appends_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Chain_Fixture_Model;

/**
 * The JS model stub declares a model's DERIVED columns: a JSDoc @property line naming the
 * columns each is computed from, and a baked `static __DERIVED_COLUMNS = [...]` (in
 * dependency order) that Rsx_Js_Model.is_derived_column() reads - emitted only when the model
 * has derived columns, like `__REALTIME`. The column stays an ordinary property: the ORM
 * assigns fetched fields onto the instance.
 */
class Model_Stub_Derived_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __generate(string $fqcn, string $class_name): string
    {
        $method = new ReflectionMethod(Model_Stub_ManifestSupport::class, '_generate_model_stub_content');
        $method->setAccessible(true);

        return $method->invoke(null, $fqcn, $class_name, 'Base_' . $class_name, ['data' => ['models' => []]]);
    }

    // CODEGEN-DERIVED-DECLARED
    public static function test_the_stub_declares_each_derived_column()
    {
        $content = static::__generate(Derived_Chain_Fixture_Model::class, 'Derived_Chain_Fixture_Model');
        $docblock = substr($content, 0, strpos($content, '*/'));

        static::__assert_contains(
            '@property {*} doubled - derived column (computed by the PHP model on save from base_value); read-only',
            $docblock
        );
        static::__assert_contains(
            '@property {*} quadrupled - derived column (computed by the PHP model on save from doubled); read-only',
            $docblock
        );
        static::__assert_contains('    static __DERIVED_COLUMNS = ["doubled","quadrupled"];', $content, 'baked in dependency order');
        static::__assert_false(str_contains($content, 'get doubled'), 'no getter: the ORM assigns fetched fields onto the instance');
    }

    // CODEGEN-DERIVED-NONE
    public static function test_a_model_without_derived_columns_bakes_nothing()
    {
        $content = static::__generate(No_Appends_Fixture_Model::class, 'No_Appends_Fixture_Model');

        static::__assert_false(str_contains($content, '__DERIVED_COLUMNS'), 'the line is absent, as __REALTIME is for a non-realtime model');
        static::__assert_false(str_contains($content, 'derived column'));
    }

    // CODEGEN-DERIVED-JS-ACCESSOR: the base every stub extends answers from the baked list.
    public static function test_the_js_base_reads_the_baked_list()
    {
        $source = (string) file_get_contents(base_path('app/RSpade/Core/Js/Rsx_Js_Model.js'));

        static::__assert_contains('static is_derived_column(column) {', $source);
        static::__assert_contains('CurrentClass.__DERIVED_COLUMNS ?? []', $source);
    }
}
