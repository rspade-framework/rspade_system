<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Rules\Models\ModelMoneyColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;
use App\RSpade\Tests\Money\Php\Money_Wide_Fixture_Model;

/**
 * The money column kind's declaration rules.
 *
 * MODEL-COLUMNS-01 (manifest-time, fatal) knows the `money` key: a literal-true flag, one kind
 * per column (never beside enum, text_type or type_ref), legal beside derived, never_export and
 * revision_exclude; and no explicit cast - the $casts default or a casts() answer - may name a
 * money column of a concrete model, inherited or declared. Each fixture model is written to a
 * temporary file and loaded outside the manifest, then handed to the rule's evaluate_class()
 * seam (the Model_Columns_Rule_Test shape).
 *
 * MODEL-MONEY-01 (rsx:check) reads the live schema: a money column must be DECIMAL with scale 2
 * and precision at most 18. Driven over the money_fixtures table through the rule's
 * evaluate_class() seam, which is also where the schema's precision/scale exposure is pinned.
 */
class Model_Money_Rules_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const COLUMNS_RULE_ID = 'MODEL-COLUMNS-01';

    private const MONEY_RULE_ID = 'MODEL-MONEY-01';

    private const BASE = '\\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract';

    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    /**
     * Load the fixture source (one or more class declarations) once, evaluate $class_name
     * with MODEL-COLUMNS-01, and return its violations.
     *
     * @return array<int, object>
     */
    private static function __columns_violations(string $class_name, string $classes_source): array
    {
        $path = Rsx_Project_Paths::tmp_path('model_money_rule_' . $class_name . '.php');
        file_put_contents($path, "<?php\n" . $classes_source . "\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            $collector = new ViolationCollector();
            (new ModelColumns_CodeQualityRule($collector))->evaluate_class($class_name, $path);

            return array_values($collector->get_by_rule(self::COLUMNS_RULE_ID));
        } finally {
            @unlink($path);
        }
    }

    private static function __model(string $class_name, string $body): string
    {
        return "class {$class_name} extends " . self::BASE . "\n{\n    protected \$table = 'fixture';\n{$body}\n}";
    }

    private static function __messages(array $violations): string
    {
        return implode("\n", array_map(fn ($v) => $v->message, $violations));
    }

    // =====================================================================
    // MODEL-COLUMNS-01
    // =====================================================================

    public static function test_money_is_a_known_flag_and_an_exclusive_kind()
    {
        static::__assert_true(in_array('money', ModelColumns_CodeQualityRule::ALLOWED_KEYS, true));
        static::__assert_true(in_array('money', ModelColumns_CodeQualityRule::FLAG_KEYS, true));
        static::__assert_true(in_array('money', ModelColumns_CodeQualityRule::EXCLUSIVE_KEYS, true));
    }

    public static function test_a_money_declaration_and_its_legal_combinations_pass()
    {
        $violations = static::__columns_violations('Money_Rule_Fixture_Valid_Model', static::__model('Money_Rule_Fixture_Valid_Model',
            "    public static \$columns = [\n"
            . "        'budget' => ['money' => true],\n"
            . "        'secret_fee' => ['money' => true, 'never_export' => true],\n"
            . "        'fee' => ['money' => true, 'revision_exclude' => true],\n"
            . "        'total' => ['money' => true, 'derived' => 'derive_total', 'from' => ['budget']],\n"
            . "    ];\n"
            . "    public function derive_total() { return \$this->budget; }"));

        static::__assert_count(0, $violations, 'money alone, with never_export, revision_exclude and derived: ' . static::__messages($violations));
    }

    public static function test_a_money_flag_that_is_not_true_is_fatal()
    {
        $violations = static::__columns_violations('Money_Rule_Fixture_One_Model', static::__model('Money_Rule_Fixture_One_Model',
            "    public static \$columns = ['budget' => ['money' => 1]];"));

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains("['money'] is 1: a flag is the literal true", $violations[0]->message);
    }

    public static function test_money_beside_another_kind_is_fatal()
    {
        $violations = static::__columns_violations('Money_Rule_Fixture_Enum_Model', static::__model('Money_Rule_Fixture_Enum_Model',
            "    public static \$columns = ['budget' => ['money' => true, 'enum' => [1 => ['label' => 'A']]]];"));

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('declares enum and money', $violations[0]->message);

        $violations = static::__columns_violations('Money_Rule_Fixture_Text_Model', static::__model('Money_Rule_Fixture_Text_Model',
            "    public static \$columns = ['budget' => ['money' => true, 'text_type' => 'Some_Text']];"));

        static::__assert_contains('declares text_type and money', static::__messages($violations));
    }

    public static function test_a_casts_entry_shadowing_a_money_column_is_fatal()
    {
        $violations = static::__columns_violations('Money_Rule_Fixture_Shadowed_Model', static::__model('Money_Rule_Fixture_Shadowed_Model',
            "    protected \$casts = ['budget' => 'decimal:2'];\n"
            . "    public static \$columns = ['budget' => ['money' => true]];"));

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_equals(
            "Money_Rule_Fixture_Shadowed_Model declares a cast for 'budget' ('decimal:2'), which is a money column. The money cast is "
            . 'attached only where no cast exists, so this entry shadows it: the column would read as a string and accept a float.',
            $violations[0]->message
        );
    }

    public static function test_a_casts_method_shadowing_a_money_column_is_fatal()
    {
        $violations = static::__columns_violations('Money_Rule_Fixture_Casts_Method_Model', static::__model('Money_Rule_Fixture_Casts_Method_Model',
            "    protected function casts(): array { return ['budget' => 'float']; }\n"
            . "    public static \$columns = ['budget' => ['money' => true]];"));

        static::__assert_contains("declares a cast for 'budget' ('float')", static::__messages($violations));
    }

    public static function test_a_cast_shadowing_an_inherited_money_column_is_reported_at_the_child()
    {
        $parent = 'Money_Rule_Fixture_Declaring_Parent_Model';
        $child = 'Money_Rule_Fixture_Casting_Child_Model';

        $violations = static::__columns_violations($child,
            "abstract class {$parent} extends " . self::BASE . "\n{\n    public static \$columns = ['budget' => ['money' => true]];\n}\n"
            . "class {$child} extends {$parent}\n{\n    protected \$table = 'fixture';\n    protected \$casts = ['budget' => 'string'];\n}");

        static::__assert_contains("{$child} declares a cast for 'budget'", static::__messages($violations), 'a child declaring no $columns of its own is still judged');
    }

    public static function test_a_cast_on_a_column_that_is_not_money_is_ordinary()
    {
        $violations = static::__columns_violations('Money_Rule_Fixture_Plain_Cast_Model', static::__model('Money_Rule_Fixture_Plain_Cast_Model',
            "    protected \$casts = ['hours' => 'float'];\n"
            . "    public static \$columns = ['budget' => ['money' => true], 'hours' => []];"));

        static::__assert_count(0, $violations, static::__messages($violations));
    }

    // =====================================================================
    // Rsx_Schema exposes precision and scale; MODEL-MONEY-01
    // =====================================================================

    public static function test_the_schema_exposes_decimal_precision_and_scale()
    {
        $columns = Rsx_Schema::table_columns(Money_Fixture_Tables::TABLE);

        static::__assert_equals([15, 2], [$columns['amount']['precision'], $columns['amount']['scale']]);
        static::__assert_equals([19, 2], [$columns['wide']['precision'], $columns['wide']['scale']]);
        static::__assert_equals([8, 3], [$columns['rate']['precision'], $columns['rate']['scale']]);
        static::__assert_equals('float', $columns['score']['type']);
        static::__assert_null($columns['score']['precision'], 'precision is a DECIMAL fact only');
        static::__assert_null($columns['ratio']['scale']);
        static::__assert_null($columns['name']['precision']);
    }

    /** MODEL-MONEY-01 over one fixture model and the live money_fixtures table. */
    private static function __money_violations(string $fqcn): array
    {
        $collector = new ViolationCollector();
        (new ModelMoneyColumns_CodeQualityRule($collector))->evaluate_class(
            $fqcn,
            (new \ReflectionClass($fqcn))->getFileName(),
            Money_Fixture_Tables::TABLE,
            Rsx_Schema::table_columns(Money_Fixture_Tables::TABLE)
        );

        return array_values($collector->get_by_rule(self::MONEY_RULE_ID));
    }

    public static function test_a_money_column_too_wide_or_of_the_wrong_scale_is_reported()
    {
        $violations = static::__money_violations(Money_Wide_Fixture_Model::class);
        $messages = static::__messages($violations);

        static::__assert_count(2, $violations, 'wide and rate; amount is DECIMAL(15,2): ' . $messages);
        static::__assert_contains(
            "Money_Wide_Fixture_Model::\$columns declares 'wide' money, but table 'money_fixtures' stores it as DECIMAL(19,2). "
            . 'A money column is DECIMAL(p,2) with p at most 18',
            $messages
        );
        static::__assert_contains("declares 'rate' money, but table 'money_fixtures' stores it as DECIMAL(8,3)", $messages);
        static::__assert_contains('rsx:man money', $violations[0]->suggestion);
    }

    public static function test_well_formed_money_columns_are_clean()
    {
        $violations = static::__money_violations(Money_Fixture_Model::class);

        static::__assert_count(0, $violations, 'DECIMAL(15,2) and DECIMAL(10,2): ' . static::__messages($violations));
    }

    public static function test_a_column_the_table_lacks_is_left_to_model_enum_01()
    {
        $collector = new ViolationCollector();
        (new ModelMoneyColumns_CodeQualityRule($collector))->evaluate_class(
            Money_Wide_Fixture_Model::class,
            (new \ReflectionClass(Money_Wide_Fixture_Model::class))->getFileName(),
            Money_Fixture_Tables::TABLE,
            ['amount' => Rsx_Schema::table_columns(Money_Fixture_Tables::TABLE)['amount']]
        );

        static::__assert_count(0, $collector->get_by_rule(self::MONEY_RULE_ID), 'nothing to judge');
    }
}
