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
use App\RSpade\Tests\Codegen\Php\Column_Kinds_Fixture_Model;
use App\RSpade\Tests\Codegen\Php\No_Appends_Fixture_Model;

/**
 * The JS model stub declares a model's MONEY columns: a baked
 * `static __MONEY_COLUMNS = [...]` that Rsx_Js_Model.is_money_column() reads - emitted only
 * when the model has money columns - and editor_component_for() answers 'Money_Input' for one.
 */
class Model_Stub_Money_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __generate(string $fqcn, string $class_name): string
    {
        $method = new ReflectionMethod(Model_Stub_ManifestSupport::class, '_generate_model_stub_content');
        $method->setAccessible(true);

        return $method->invoke(null, $fqcn, $class_name, 'Base_' . $class_name, ['data' => ['models' => []]]);
    }

    // CODEGEN-MONEY-DECLARED
    public static function test_the_stub_bakes_the_money_columns()
    {
        $content = static::__generate(Column_Kinds_Fixture_Model::class, 'Column_Kinds_Fixture_Model');

        static::__assert_contains('    static __MONEY_COLUMNS = ["budget","fee"];', $content, 'in declaration order');
    }

    // CODEGEN-MONEY-NONE
    public static function test_a_model_without_money_columns_bakes_nothing()
    {
        $content = static::__generate(No_Appends_Fixture_Model::class, 'No_Appends_Fixture_Model');

        static::__assert_false(str_contains($content, '__MONEY_COLUMNS'), 'the line is absent, as __DERIVED_COLUMNS is');
    }

    // CODEGEN-MONEY-EDITOR: a money column's editor is Money_Input; a column with neither a
    // money nor a text-type declaration still has none.
    public static function test_editor_component_for_answers_money_input_for_a_money_column()
    {
        $content = static::__generate(Column_Kinds_Fixture_Model::class, 'Column_Kinds_Fixture_Model');
        $start = strpos($content, 'static editor_component_for(column) {');

        static::__assert_true($start !== false, 'the stub declares editor_component_for()');

        $body = substr($content, $start, strpos($content, "\n    }\n", $start) - $start);

        static::__assert_contains("if (this.is_money_column(column)) {\n            return 'Money_Input';", $body);
        static::__assert_true(
            strpos($body, 'is_money_column') < strpos($body, 'text_type_or_null'),
            'the money answer comes before the text-type lookup'
        );
        static::__assert_contains('is neither a money column nor a column with a declared text type', $body, 'any other column still throws');
    }

    // CODEGEN-MONEY-JS-ACCESSOR: the base every stub extends answers from the baked list.
    public static function test_the_js_base_reads_the_baked_list()
    {
        $source = (string) file_get_contents(base_path('app/RSpade/Core/Js/Rsx_Js_Model.js'));

        static::__assert_contains('static is_money_column(column) {', $source);
        static::__assert_contains('CurrentClass.__MONEY_COLUMNS ?? []', $source);
    }
}
