<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\TextTypeDeclaration_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * TEXT-TYPE-01: a `text_type` column declaration the cast cannot honour is a build failure.
 *
 * Every mistake it catches is silent at runtime, which is why the rule exists: a $casts
 * entry shadowing the text cast stores request markup unfiltered, a non-type entry throws
 * only on first use, and a type without its JS twin fails only in a browser.
 *
 * The rule reflects real classes, so each fixture model is written to a temporary file and
 * loaded - outside the manifest, so a fixture can never fail a build - and handed to the
 * rule's evaluate_class() seam. The text type it names is the framework test fixture
 * Text_Fixture_Plain_Text, which has no JS twin by construction.
 */
class Text_Type_Declaration_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'TEXT-TYPE-01';

    private const PLAIN_TYPE = '\\App\\RSpade\\Tests\\TextTypes\\Php\\Text_Fixture_Plain_Text::class';

    /**
     * Load a fixture model class body and return [violations, class file].
     */
    private static function __evaluate(string $class_name, string $body): array
    {
        $path = Rsx_Project_Paths::tmp_path('text_type_rule_' . $class_name . '.php');

        if (!class_exists($class_name, false)) {
            file_put_contents($path, "<?php\n"
                . "class {$class_name} extends \\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract\n"
                . "{\n{$body}\n}\n");
            require $path;
        }

        try {
            $collector = new ViolationCollector();
            $rule = new TextTypeDeclaration_CodeQualityRule($collector);
            $rule->evaluate_class($class_name, $path);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            @unlink($path);
        }
    }

    public static function test_a_casts_entry_shadowing_a_declared_column_is_fatal()
    {
        $violations = static::__evaluate('Text_Rule_Fixture_Shadowed_Model', "    protected \$table = 'fixture';\n"
            . "    protected \$casts = ['body' => 'string'];\n"
            . "    public static \$columns = ['body' => ['text_type' => " . self::PLAIN_TYPE . "]];");

        $messages = implode("\n", array_map(fn ($v) => $v->message, $violations));

        static::__assert_contains("declares a cast for 'body'", $messages, 'the shadowing cast is reported');
        static::__assert_contains('SHADOWS', $messages, 'with what it does');
    }

    public static function test_a_casts_method_shadowing_a_declared_column_is_fatal()
    {
        $violations = static::__evaluate('Text_Rule_Fixture_Casts_Method_Model', "    protected \$table = 'fixture';\n"
            . "    protected function casts(): array { return ['body' => 'string']; }\n"
            . "    public static \$columns = ['body' => ['text_type' => " . self::PLAIN_TYPE . "]];");

        $messages = implode("\n", array_map(fn ($v) => $v->message, $violations));

        static::__assert_contains("declares a cast for 'body'", $messages, 'a casts() method shadows exactly as $casts does');
    }

    public static function test_an_entry_that_is_not_a_text_type_is_fatal()
    {
        $violations = static::__evaluate('Text_Rule_Fixture_Not_A_Type_Model', "    protected \$table = 'fixture';\n"
            . "    public static \$columns = ['body' => ['text_type' => \\ArrayObject::class]];");

        static::__assert_count(1, $violations, 'one violation for the one bad entry');
        static::__assert_contains('not a class extending Rsx_Text_Abstract', $violations[0]->message, 'naming the problem');
    }

    public static function test_a_type_without_a_js_twin_is_fatal()
    {
        $violations = static::__evaluate('Text_Rule_Fixture_No_Twin_Model', "    protected \$table = 'fixture';\n"
            . "    public static \$columns = ['body' => ['text_type' => " . self::PLAIN_TYPE . "]];");

        static::__assert_count(1, $violations, 'the missing twin is the only problem');
        static::__assert_contains('has no JavaScript twin', $violations[0]->message, 'naming the problem');
    }

    public static function test_a_model_declaring_nothing_is_not_examined()
    {
        $violations = static::__evaluate('Text_Rule_Fixture_Undeclared_Model', "    protected \$table = 'fixture';\n"
            . "    protected \$casts = ['body' => 'string'];");

        static::__assert_count(0, $violations, 'a cast on an undeclared column is ordinary');
    }

    /**
     * Checks 1 and 2 judge the entries a class's OWN $columns literal declares: a subclass
     * that inherits a text-type entry - with or without a $columns of its own - is not
     * re-reported for it.
     */
    public static function test_an_inherited_entry_is_judged_only_where_it_is_declared()
    {
        $parent = 'Text_Rule_Fixture_Declaring_Parent_Model';
        $child = 'Text_Rule_Fixture_Inheriting_Child_Model';
        $path = Rsx_Project_Paths::tmp_path('text_type_rule_' . $child . '.php');

        if (!class_exists($child, false)) {
            file_put_contents($path, "<?php\n"
                . "abstract class {$parent} extends \\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract\n"
                . "{\n    protected \$table = 'fixture';\n"
                . "    public static \$columns = ['body' => ['text_type' => " . self::PLAIN_TYPE . "]];\n}\n"
                . "class {$child} extends {$parent}\n"
                . "{\n    public static \$columns = ['notes' => []];\n}\n");
            require $path;
        }

        try {
            $collector = new ViolationCollector();
            $rule = new TextTypeDeclaration_CodeQualityRule($collector);

            $rule->evaluate_class($child, $path);
            static::__assert_count(
                0,
                $collector->get_by_rule(self::RULE_ID),
                'the child declares no text type of its own, so the missing twin is not its finding'
            );

            $rule->evaluate_class($parent, $path, true);
            static::__assert_count(
                1,
                $collector->get_by_rule(self::RULE_ID),
                'the declaring parent carries the finding'
            );
        } finally {
            @unlink($path);
        }
    }

    public static function test_the_exception_marker_suppresses_the_file()
    {
        $violations = static::__evaluate('Text_Rule_Fixture_Excepted_Model', "    // @TEXT-TYPE-01-EXCEPTION fixture\n"
            . "    protected \$table = 'fixture';\n"
            . "    protected \$casts = ['body' => 'string'];\n"
            . "    public static \$columns = ['body' => ['text_type' => " . self::PLAIN_TYPE . "]];");

        static::__assert_count(0, $violations, 'the marker suppresses every check');
    }
}
