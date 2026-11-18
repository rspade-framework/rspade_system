<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\JavaScript\AsyncOnCreate_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * JS-ON-CREATE-01: a component's on_create() is synchronous, refused at manifest build.
 *
 * The rule scopes itself through the manifest lineage (Manifest::js_is_subclass_of), so
 * each fixture names a REAL class as its metadata 'class': Spa_Action and Spa_Layout (both
 * extend Component) for the positives, Rsx (no component) for the out-of-scope negative.
 * The source is handed straight to check(); nothing is written to the tree. The offending
 * declaration is assembled from a constant so this file holds none.
 */
class Async_On_Create_Rule_Test extends Rsx_Test_Abstract
{
    // Pure text analysis; no database.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'JS-ON-CREATE-01';

    /** `async on_create(` as source text. */
    private const ASYNC_HOOK = 'as' . 'ync on_create(';

    private static function __check(string $js, string $class_name = 'Spa_Action'): array
    {
        $collector = new ViolationCollector();
        (new AsyncOnCreate_CodeQualityRule($collector))->check(
            base_path('rsx/app/fixture/fixture_probe.js'),
            $js,
            ['class' => $class_name]
        );

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    private static function __class(string $method_line): string
    {
        return "class Fixture_Probe extends Spa_Action {\n"
            . "    " . $method_line . "\n"
            . "        this.state.ready = false;\n"
            . "    }\n"
            . "}\n";
    }

    public static function test_an_async_on_create_is_a_violation()
    {
        $violations = static::__check(static::__class(self::ASYNC_HOOK . ') {'));

        static::__assert_count(1, $violations, 'the async hook is refused');
        static::__assert_equals(2, $violations[0]->line_number, 'at its declaration');
        static::__assert_contains('must be synchronous', $violations[0]->message);
        static::__assert_contains('on_load()', $violations[0]->suggestion, 'the fix names the hook the work belongs in');
        static::__assert_equals('critical', $violations[0]->severity);
    }

    public static function test_every_component_lineage_is_judged()
    {
        static::__assert_count(1, static::__check(static::__class(self::ASYNC_HOOK . ') {'), 'Spa_Layout'), 'a layout is a component');
    }

    public static function test_a_synchronous_on_create_passes()
    {
        static::__assert_count(0, static::__check(static::__class('on_create() {')));
    }

    public static function test_other_async_hooks_pass()
    {
        static::__assert_count(0, static::__check(static::__class('as' . 'ync on_load() {')), 'on_load is asynchronous by design');
        static::__assert_count(0, static::__check(static::__class('as' . 'ync on_create_extra() {')), 'a longer name is another method');
    }

    public static function test_a_static_method_of_that_name_is_not_the_hook()
    {
        static::__assert_count(0, static::__check(static::__class('static ' . self::ASYNC_HOOK . ') {')));
    }

    public static function test_a_commented_out_declaration_is_not_one()
    {
        static::__assert_count(0, static::__check(static::__class('// ' . self::ASYNC_HOOK . ') {')));
        static::__assert_count(0, static::__check("/*\n    " . self::ASYNC_HOOK . ") {\n*/\nclass Fixture_Probe extends Spa_Action {}\n"));
    }

    public static function test_a_class_outside_the_component_lineage_is_not_judged()
    {
        static::__assert_count(0, static::__check(static::__class(self::ASYNC_HOOK . ') {'), 'Rsx'));
    }
}
