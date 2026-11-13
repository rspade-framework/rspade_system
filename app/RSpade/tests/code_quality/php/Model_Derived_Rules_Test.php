<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Rules\Models\ModelDerivedAssignment_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The derived-column checks of MODEL-COLUMNS-01 (manifest build), its refusal of the column
 * statics the framework does not read, and MODEL-DERIVED-01 (rsx:check), which reports an
 * assignment to a derived column where it is written.
 *
 * MODEL-COLUMNS-01 fixtures are written to a temporary file, loaded outside the manifest and
 * handed to evaluate_class(). MODEL-DERIVED-01 only PARSES its subject, resolving class names
 * through the manifest, so its temporary sources name the indexed fixture model
 * Derived_Plain_Fixture_Model (full_name derived from first_name and last_name) and are never
 * loaded.
 */
class Model_Derived_Rules_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const BASE = '\\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract';

    /**
     * Load $source once, evaluate $class_name with MODEL-COLUMNS-01, and return its violations.
     *
     * @return array<int, object>
     */
    private static function __evaluate(string $class_name, string $source): array
    {
        $path = Rsx_Project_Paths::tmp_path('model_derived_rule_' . $class_name . '.php');
        file_put_contents($path, "<?php\n" . $source . "\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            $collector = new ViolationCollector();
            (new ModelColumns_CodeQualityRule($collector))->evaluate_class($class_name, $path);

            return array_values($collector->get_by_rule('MODEL-COLUMNS-01'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * A fixture model declaring $columns_source plus the methods in $methods_source.
     *
     * @return array<int, object>
     */
    private static function __evaluate_derived(string $class_name, string $columns_source, string $methods_source = ''): array
    {
        return static::__evaluate($class_name, "class {$class_name} extends " . self::BASE . "\n{\n"
            . "    protected \$table = 'fixture';\n"
            . "    public static \$columns = {$columns_source};\n"
            . $methods_source . "\n}");
    }

    private static function __messages(array $violations): string
    {
        return implode("\n", array_map(fn ($v) => $v->message, $violations));
    }

    /**
     * Run MODEL-DERIVED-01 over $source written to a file named $basename.
     *
     * @return array<int, object>
     */
    private static function __assignments(string $source, string $basename = 'model_derived_assignment_fixture.php'): array
    {
        $dir = Rsx_Project_Paths::tmp_path('model_derived_rule_' . uniqid());
        @mkdir($dir, 0777, true);
        $path = $dir . '/' . $basename;
        file_put_contents($path, "<?php\n" . $source . "\n");

        try {
            $collector = new ViolationCollector();
            (new ModelDerivedAssignment_CodeQualityRule($collector))->check($path, '', []);

            return array_values($collector->get_by_rule('MODEL-DERIVED-01'));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    /**
     * The 1-based line numbers of $source (as written, after the opening tag line) that
     * assign ->full_name.
     *
     * @return array<int, int>
     */
    private static function __assignment_lines(string $source): array
    {
        $lines = [];

        foreach (explode("\n", "<?php\n" . $source) as $index => $text) {
            if (preg_match('/->full_name\s*\.?=/', $text)) {
                $lines[] = $index + 1;
            }
        }

        return $lines;
    }

    // =====================================================================
    // MODEL-COLUMNS-01: the derived grammar
    // =====================================================================

    public static function test_a_well_formed_derived_declaration_passes()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Valid_Model', "[\n"
            . "        'total' => ['derived' => 'derive_total', 'from' => ['subtotal', 'tax']],\n"
            . "        'grand' => ['derived' => 'derive_grand', 'from' => ['total'], 'never_export' => true],\n"
            . "        'state_id' => ['derived' => 'derive_state', 'from' => [], 'enum' => [1 => ['label' => 'Open']]],\n"
            . "    ]",
            "    public function derive_total() { return 1; }\n"
            . "    public function derive_grand(\$scale = 1) { return 2; }\n"
            . "    public function derive_state() { return 1; }\n");

        static::__assert_count(0, $violations, 'a chain, an empty from, a combined kind, an optional parameter: ' . static::__messages($violations));
    }

    public static function test_a_missing_method_is_fatal()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_No_Method_Model', "['total' => ['derived' => 'derive_total', 'from' => ['subtotal']]]");

        static::__assert_count(1, $violations, static::__messages($violations));
        static::__assert_contains("['derived'] names derive_total(), which Derived_Rule_Fixture_No_Method_Model does not have", $violations[0]->message);
    }

    public static function test_a_static_private_or_parameterized_method_is_fatal()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Bad_Method_Model', "[\n"
            . "        'a' => ['derived' => 'derive_a', 'from' => []],\n"
            . "        'b' => ['derived' => 'derive_b', 'from' => []],\n"
            . "        'c' => ['derived' => 'derive_c', 'from' => []],\n"
            . "    ]",
            "    public static function derive_a() { return 1; }\n"
            . "    private function derive_b() { return 1; }\n"
            . "    public function derive_c(\$needed) { return 1; }\n");

        $messages = static::__messages($violations);
        static::__assert_count(3, $violations, $messages);
        static::__assert_contains('derive_a(), which is static', $messages);
        static::__assert_contains('derive_b(), which is not public', $messages);
        static::__assert_contains('derive_c(), which has required parameters', $messages);
    }

    public static function test_from_without_derived_and_derived_without_from_are_fatal()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Unpaired_Model', "[\n"
            . "        'a' => ['from' => ['x']],\n"
            . "        'b' => ['derived' => 'derive_b'],\n"
            . "    ]",
            "    public function derive_b() { return 1; }\n");

        $messages = static::__messages($violations);
        static::__assert_count(2, $violations, $messages);
        static::__assert_contains("['a'] has 'from' without 'derived'", $messages);
        static::__assert_contains("['b'] has 'derived' without 'from'", $messages);
    }

    public static function test_a_from_that_is_not_a_list_of_distinct_names_is_fatal()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Bad_From_Model', "[\n"
            . "        'a' => ['derived' => 'derive_a', 'from' => 'x'],\n"
            . "        'b' => ['derived' => 'derive_a', 'from' => [1]],\n"
            . "        'c' => ['derived' => 'derive_a', 'from' => ['x', 'x']],\n"
            . "        'd' => ['derived' => 7, 'from' => []],\n"
            . "    ]",
            "    public function derive_a() { return 1; }\n");

        $messages = static::__messages($violations);
        static::__assert_count(4, $violations, $messages);
        static::__assert_contains("['a']['from'] must be a list of distinct column names", $messages);
        static::__assert_contains("['b']['from'] must be a list", $messages);
        static::__assert_contains("['c']['from'] must be a list", $messages);
        static::__assert_contains("['d']['derived'] is int", $messages);
    }

    public static function test_a_self_reference_is_fatal()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Self_Model', "['total' => ['derived' => 'derive_total', 'from' => ['total', 'tax']]]",
            "    public function derive_total() { return 1; }\n");

        static::__assert_count(1, $violations, static::__messages($violations));
        static::__assert_contains("['total']['from'] names 'total' itself", $violations[0]->message);
    }

    public static function test_a_cycle_is_fatal_and_names_its_path()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Cycle_Model', "[\n"
            . "        'a' => ['derived' => 'derive_a', 'from' => ['b']],\n"
            . "        'b' => ['derived' => 'derive_b', 'from' => ['c']],\n"
            . "        'c' => ['derived' => 'derive_c', 'from' => ['a', 'plain']],\n"
            . "    ]",
            "    public function derive_a() { return 1; }\n"
            . "    public function derive_b() { return 1; }\n"
            . "    public function derive_c() { return 1; }\n");

        static::__assert_count(1, $violations, 'one finding per cycle: ' . static::__messages($violations));
        static::__assert_contains('in a cycle: a -> b -> c -> a', $violations[0]->message);
    }

    public static function test_derived_beside_revision_exclude_is_fatal()
    {
        $violations = static::__evaluate_derived('Derived_Rule_Fixture_Redundant_Model', "['total' => ['derived' => 'derive_total', 'from' => [], 'revision_exclude' => true]]",
            "    public function derive_total() { return 1; }\n");

        static::__assert_count(1, $violations, static::__messages($violations));
        static::__assert_contains("declares both 'derived' and 'revision_exclude'", $violations[0]->message);
    }

    public static function test_a_cycle_through_an_inherited_entry_is_fatal()
    {
        $violations = static::__evaluate('Derived_Rule_Fixture_Inherited_Cycle_Model',
            "abstract class Derived_Rule_Fixture_Inherited_Cycle_Base_Model_Abstract extends " . self::BASE . "\n{\n"
            . "    public static \$columns = ['a' => ['derived' => 'derive_a', 'from' => ['b']]];\n"
            . "    public function derive_a() { return 1; }\n}\n"
            . "class Derived_Rule_Fixture_Inherited_Cycle_Model extends Derived_Rule_Fixture_Inherited_Cycle_Base_Model_Abstract\n{\n"
            . "    protected \$table = 'fixture';\n"
            . "    public static \$columns = ['b' => ['derived' => 'derive_b', 'from' => ['a']]];\n"
            . "    public function derive_b() { return 1; }\n}");

        static::__assert_count(1, $violations, static::__messages($violations));
        static::__assert_contains('a -> b -> a', $violations[0]->message);
    }

    // =====================================================================
    // MODEL-COLUMNS-01: a column static the framework does not read
    // =====================================================================

    public static function test_each_unread_column_static_is_fatal()
    {
        foreach (ModelColumns_CodeQualityRule::UNREAD_STATIC_PROPERTIES as $index => $name) {
            $class_name = 'Derived_Rule_Fixture_Unread_' . $index . '_Model';
            $violations = static::__evaluate($class_name, "class {$class_name} extends " . self::BASE . "\n{\n"
                . "    protected \$table = 'fixture';\n"
                . "    public static \${$name} = ['secret' => true];\n}");

            static::__assert_count(1, $violations, "\${$name}: " . static::__messages($violations));
            static::__assert_contains("{$class_name}::\${$name} declares nothing", $violations[0]->message);
            static::__assert_contains('from $columns only', $violations[0]->message);
            static::__assert_equals(5, $violations[0]->line_number, 'reported at the property');
        }
    }

    public static function test_an_unread_static_is_fatal_on_a_class_that_declares_no_columns_and_on_an_abstract_base()
    {
        $violations = static::__evaluate('Derived_Rule_Fixture_Unread_Abstract_Base_Model_Abstract',
            "abstract class Derived_Rule_Fixture_Unread_Abstract_Base_Model_Abstract extends " . self::BASE . "\n{\n"
            . "    protected static \$type_ref_columns = ['owner_type'];\n}");

        static::__assert_count(1, $violations, static::__messages($violations));
        static::__assert_contains('::$type_ref_columns declares nothing', $violations[0]->message);
    }

    public static function test_the_list_names_the_five_retired_statics()
    {
        static::__assert_equals(
            ['enums', 'text_types', 'type_ref_columns', 'neverExport', 'revision_exclude'],
            ModelColumns_CodeQualityRule::UNREAD_STATIC_PROPERTIES
        );
    }

    public static function test_an_instance_property_of_the_same_name_is_not_judged()
    {
        $violations = static::__evaluate('Derived_Rule_Fixture_Instance_Prop_Model', "class Derived_Rule_Fixture_Instance_Prop_Model extends " . self::BASE . "\n{\n"
            . "    protected \$table = 'fixture';\n"
            . "    public \$enums = [];\n}");

        static::__assert_count(0, $violations, static::__messages($violations));
    }

    // =====================================================================
    // MODEL-DERIVED-01
    // =====================================================================

    public static function test_this_assignment_inside_the_model_is_reported()
    {
        $source = "namespace Fixture_Parse_Only;\n"
            . "class Derived_Plain_Fixture_Model\n{\n"
            . "    public function rename()\n    {\n"
            . "        \$this->first_name = 'x';\n"
            . "        \$this->full_name = 'x';\n"
            . "    }\n}";
        $violations = static::__assignments($source);

        static::__assert_count(1, $violations, static::__messages($violations));
        static::__assert_equals(static::__assignment_lines($source), [$violations[0]->line_number]);
        static::__assert_contains('Derived_Plain_Fixture_Model::full_name is a derived column', $violations[0]->message);
    }

    public static function test_each_inferable_receiver_is_reported()
    {
        $source = "class Derived_Assignment_Fixture_Service\n{\n"
            . "    public static function a()\n    {\n"
            . "        \$r = new Derived_Plain_Fixture_Model();\n"
            . "        \$r->full_name = 'x';\n"
            . "    }\n"
            . "    public static function b()\n    {\n"
            . "        \$r = Derived_Plain_Fixture_Model::find(1);\n"
            . "        \$r->full_name = 'x';\n"
            . "    }\n"
            . "    public static function c()\n    {\n"
            . "        \$r = Derived_Plain_Fixture_Model::where('id', 1)->orderBy('id')->first();\n"
            . "        \$r->full_name = 'x';\n"
            . "    }\n"
            . "    public static function d(Derived_Plain_Fixture_Model \$r)\n    {\n"
            . "        \$r->full_name = 'x';\n"
            . "    }\n"
            . "    public static function e(\$id)\n    {\n"
            . "        /** @var Derived_Plain_Fixture_Model \$r */\n"
            . "        \$r = load_it(\$id);\n"
            . "        \$r->full_name .= 'x';\n"
            . "    }\n"
            . "    public static function f(?Derived_Plain_Fixture_Model \$r)\n    {\n"
            . "        \$each = function () use (\$r) { \$r->full_name = 'x'; };\n"
            . "    }\n"
            . "}";
        $violations = static::__assignments($source);

        $lines = array_map(fn ($v) => $v->line_number, $violations);
        sort($lines);

        static::__assert_count(6, static::__assignment_lines($source), 'six receivers in the fixture');
        static::__assert_equals(static::__assignment_lines($source), $lines, static::__messages($violations));
    }

    public static function test_what_cannot_be_inferred_or_is_not_derived_is_not_reported()
    {
        $violations = static::__assignments("class Derived_Assignment_Fixture_Clean_Service\n{\n"
            . "    public static function a(\$unknown)\n    {\n"
            . "        \$unknown->full_name = 'x';\n"
            . "        \$r = new Derived_Plain_Fixture_Model();\n"
            . "        \$r->first_name = 'x';\n"
            . "        \$other = new Some_Unrelated_Class();\n"
            . "        \$other->full_name = 'x';\n"
            . "        \$this->full_name = 'x';\n"
            . "    }\n"
            . "    public static function b()\n    {\n"
            . "        \$r->full_name = 'x';\n"
            . "    }\n"
            . "}");

        static::__assert_count(0, $violations, 'an unknown receiver, a source column, an unrelated class, a non-model $this, '
            . 'and a variable inferred only in another function: ' . static::__messages($violations));
    }

    public static function test_the_base_model_file_is_not_judged()
    {
        $violations = static::__assignments("class Derived_Plain_Fixture_Model\n{\n"
            . "    public function f() { \$this->full_name = 'x'; }\n}", 'Rsx_Model_Abstract.php');

        static::__assert_count(0, $violations, 'the base writes derived columns through setAttribute()');
    }
}
