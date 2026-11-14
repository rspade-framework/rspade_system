<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\CodeQuality\Rules\Models\ModelDecimalColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;
use App\RSpade\Tests\Money\Php\Money_Wide_Fixture_Model;

/**
 * MODEL-DECIMAL-01 (rsx:check): every DECIMAL, FLOAT and DOUBLE column of a model's table has
 * an entry in the model's $columns - ['money' => true], or any other entry for a column that
 * is not money.
 *
 * Driven over the money_fixtures table (DECIMAL columns of several shapes, score FLOAT, ratio
 * DOUBLE) through the rule's evaluate_class() seam. Each fixture model declaring a different
 * set of entries is written to a temporary file and loaded outside the manifest; the indexed
 * Money_Wide_Fixture_Model, which leaves most of the table undeclared, also goes through the
 * production check() path.
 */
class Model_Decimal_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-DECIMAL-01';

    private const BASE = '\\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract';

    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    /** MODEL-DECIMAL-01 over one model class (loaded) and the live money_fixtures table. */
    private static function __violations(string $fqcn, string $file_path): array
    {
        $collector = new ViolationCollector();
        (new ModelDecimalColumns_CodeQualityRule($collector))->evaluate_class(
            $fqcn,
            $file_path,
            Money_Fixture_Tables::TABLE,
            Rsx_Schema::table_columns(Money_Fixture_Tables::TABLE)
        );

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    /**
     * A fixture model over money_fixtures declaring $columns_source, written to a temporary
     * file, loaded, and judged.
     */
    private static function __fixture_violations(string $class_name, string $columns_source): array
    {
        $path = Rsx_Project_Paths::tmp_path('model_decimal_rule_' . $class_name . '.php');
        file_put_contents($path, "<?php\nclass {$class_name} extends " . self::BASE . "\n{\n"
            . "    protected \$table = 'money_fixtures';\n"
            . "    public static \$columns = {$columns_source};\n}\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            return static::__violations($class_name, $path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, string> column => message */
    private static function __by_column(array $violations): array
    {
        $out = [];
        foreach ($violations as $violation) {
            static::__assert_true((bool) preg_match("/has no entry for '([a-z_]+)'/", $violation->message, $m), $violation->message);
            $out[$m[1]] = $violation->message;
        }
        ksort($out);

        return $out;
    }

    private static function __messages(array $violations): string
    {
        return implode("\n", array_map(fn ($v) => $v->message, $violations));
    }

    // MODEL-DECIMAL-DECLARED: every kind of entry counts as a declaration.
    public static function test_money_plain_and_flagged_entries_all_count_as_declared()
    {
        $violations = static::__fixture_violations('Decimal_Rule_Fixture_Declared_Model', "[\n"
            . "        'amount' => ['money' => true],\n"
            . "        'price' => ['money' => true],\n"
            . "        'hidden' => ['money' => true, 'never_export' => true],\n"
            . "        'doubled' => [],\n"
            . "        'wide' => ['revision_exclude' => true],\n"
            . "        'rate' => [],\n"
            . "        'score' => [],\n"
            . "        'ratio' => ['revision_exclude' => true],\n"
            . "        'hours' => [],\n"
            . "    ]");

        static::__assert_count(0, $violations, 'every DECIMAL, FLOAT and DOUBLE column declared: ' . static::__messages($violations));
    }

    // MODEL-DECIMAL-UNDECLARED: exactly the undeclared DECIMAL / FLOAT / DOUBLE columns, and no
    // other type (name VARCHAR, id BIGINT, the TIMESTAMP columns).
    public static function test_each_undeclared_decimal_float_and_double_column_is_reported()
    {
        $violations = static::__fixture_violations('Decimal_Rule_Fixture_Partial_Model', "[\n"
            . "        'amount' => ['money' => true],\n"
            . "        'price' => [],\n"
            . "    ]");

        static::__assert_equals(
            ['doubled', 'hidden', 'hours', 'rate', 'ratio', 'score', 'wide'],
            array_keys(static::__by_column($violations))
        );
    }

    // MODEL-DECIMAL-TEXT-DECIMAL: the owner's finding text for a DECIMAL column, verbatim.
    public static function test_the_decimal_finding_text()
    {
        $messages = static::__by_column(static::__fixture_violations('Decimal_Rule_Fixture_Text_Model', "['amount' => ['money' => true]]"));

        static::__assert_equals(
            "Decimal_Rule_Fixture_Text_Model::\$columns has no entry for 'hours', a DECIMAL(8,2) column of table 'money_fixtures'.\n"
            . "A DECIMAL column looks like it could hold money. Say which it is:\n"
            . "\n"
            . "    'hours' => ['money' => true],   // an amount of money: reads as Rsx_Money, never a float\n"
            . "    'hours' => [],                  // not money (hours, a quantity, a percentage)\n"
            . "\n"
            . 'See: php artisan rsx:man money',
            $messages['hours']
        );
        static::__assert_contains("a DECIMAL(19,2) column of table 'money_fixtures'", $messages['wide'], 'precision and scale as the table has them');
        static::__assert_contains("a DECIMAL(8,3) column", $messages['rate']);
    }

    // MODEL-DECIMAL-TEXT-FLOAT: FLOAT and DOUBLE get the floating-point text, verbatim.
    public static function test_the_float_and_double_finding_text()
    {
        $messages = static::__by_column(static::__fixture_violations('Decimal_Rule_Fixture_Float_Model', "['amount' => ['money' => true]]"));

        $expected = fn (string $column, string $type) => "Decimal_Rule_Fixture_Float_Model::\$columns has no entry for '{$column}', a {$type} column of table 'money_fixtures'.\n"
            . "A floating-point column cannot hold an amount of money exactly. If it holds money, convert it\n"
            . "to DECIMAL(p,2) with a migration and declare it ['money' => true]; if it does not, declare it:\n"
            . "\n"
            . "    '{$column}' => [],                  // not money (a measurement, a ratio, a score)\n"
            . "\n"
            . 'See: php artisan rsx:man money';

        static::__assert_equals($expected('score', 'FLOAT'), $messages['score']);
        static::__assert_equals($expected('ratio', 'DOUBLE'), $messages['ratio']);
    }

    // MODEL-DECIMAL-CHECK: the production path - manifest model record, Rsx_Schema's map - on
    // the indexed fixture model; an inherited entry counts; the suggestion names the man page.
    public static function test_the_check_path_reports_an_indexed_model()
    {
        $file = (new \ReflectionClass(Money_Wide_Fixture_Model::class))->getFileName();
        $collector = new ViolationCollector();
        (new ModelDecimalColumns_CodeQualityRule($collector))->check($file, '', ['class' => 'Money_Wide_Fixture_Model']);
        $violations = array_values($collector->get_by_rule(self::RULE_ID));

        static::__assert_equals(['doubled', 'hidden', 'hours', 'price', 'ratio', 'score'], array_keys(static::__by_column($violations)));
        static::__assert_contains('rsx:man money', $violations[0]->suggestion);
        static::__assert_contains('$columns', $violations[0]->code_snippet ?? '', 'reported at the $columns declaration');
    }

    // MODEL-DECIMAL-ABSENT: a model whose table is not in the database is skipped.
    public static function test_a_model_whose_table_is_absent_is_skipped()
    {
        $file = (new \ReflectionClass(Money_Wide_Fixture_Model::class))->getFileName();

        DB::statement('DROP TABLE ' . Money_Fixture_Tables::TABLE);
        Rsx_Schema::forget();

        try {
            $collector = new ViolationCollector();
            (new ModelDecimalColumns_CodeQualityRule($collector))->check($file, '', ['class' => 'Money_Wide_Fixture_Model']);

            static::__assert_count(0, $collector->get_by_rule(self::RULE_ID), 'nothing to judge without the table');
        } finally {
            Money_Fixture_Tables::create();
        }
    }

    // MODEL-DECIMAL-FRAMEWORK: the framework classifies its own columns - _tasks.progress_percent
    // is declared not money.
    public static function test_the_framework_declares_its_own_decimal_columns()
    {
        static::__assert_true(array_key_exists('progress_percent', Task_Run_Model::columns()));
        static::__assert_false(Task_Run_Model::is_money_column('progress_percent'));
    }
}
