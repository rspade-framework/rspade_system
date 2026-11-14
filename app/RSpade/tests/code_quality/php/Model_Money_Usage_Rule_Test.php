<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelMoneyUsage_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-MONEY-02 (rsx:check): an Rsx_Money compared, tested for truth, cast or used in
 * arithmetic with PHP's operators is reported where it is written; a null check, isset(),
 * `??`, `?->`, instanceof, passing it and returning it are not.
 *
 * The rule only PARSES its subject, resolving class names through the manifest, so each
 * fixture is a function written to a temporary file and never loaded. Its receivers name the
 * indexed fixture model Money_Fixture_Model (amount is money; score is declared not money)
 * and Rsx_Money itself, inferred from a typed parameter, a @var docblock, a
 * Money_Fixture_Model::find() assignment and an Rsx_Money::from_*() assignment.
 */
class Model_Money_Usage_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-MONEY-02';

    /**
     * Run MODEL-MONEY-02 over $source (PHP without the opening tag).
     *
     * @return array<int, object>
     */
    private static function __violations(string $source): array
    {
        $dir = Rsx_Project_Paths::tmp_path('model_money_usage_rule_' . uniqid());
        @mkdir($dir, 0777, true);
        $path = $dir . '/money_usage_fixture.php';
        file_put_contents($path, "<?php\n" . $source . "\n");

        try {
            $collector = new ViolationCollector();
            (new ModelMoneyUsage_CodeQualityRule($collector))->check($path, '', []);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    /** One statement inside a function whose $r is a Money_Fixture_Model by its typed parameter. */
    private static function __in_function(string $statement): array
    {
        return static::__violations("function money_usage_fixture(Money_Fixture_Model \$r, Rsx_Money \$m, \$other)\n{\n    {$statement}\n}");
    }

    private static function __messages(array $violations): string
    {
        return implode("\n", array_map(fn ($v) => $v->message, $violations));
    }

    private static function __assert_flagged(string $statement, string $needle): void
    {
        $violations = static::__in_function($statement);

        static::__assert_count(1, $violations, "one finding for: {$statement}\n" . static::__messages($violations));
        static::__assert_contains($needle, $violations[0]->message, $statement);
        static::__assert_equals(4, $violations[0]->line_number, 'reported on the statement\'s line: ' . $statement);
        static::__assert_contains('rsx:man money', $violations[0]->suggestion);
    }

    private static function __assert_clean(string $statement): void
    {
        $violations = static::__in_function($statement);

        static::__assert_count(0, $violations, "nothing for: {$statement}\n" . static::__messages($violations));
    }

    // MONEY-USAGE-COMPARE
    public static function test_comparison_operators_are_flagged()
    {
        static::__assert_flagged('return $r->amount < $other;', "'\$r->amount' is an Rsx_Money: '<' compares the objects' properties, not the amounts. Use ->less_than(\$other) or ->compare(\$other).");
        static::__assert_flagged('return $r->amount <= $other;', '->less_than_or_equal($other)');
        static::__assert_flagged('return $other > $r->amount;', "'>' compares");
        static::__assert_flagged('return $r->amount >= $other;', '->greater_than_or_equal($other)');
        static::__assert_flagged('return $r->amount == $other;', 'Use ->equals($other).');
        static::__assert_flagged('return $r->amount != $other;', "'!='");
        static::__assert_flagged('return $r->amount <> $other;', "'!='");
        static::__assert_flagged('return $r->amount <=> $other;', 'Use ->compare($other).');
        static::__assert_flagged('return $r->amount === $other;', "'===' compares object identity, not the amounts.");
        static::__assert_flagged('return $r->amount !== $other;', "'!=='");
    }

    // MONEY-USAGE-TRUTH
    public static function test_truthiness_is_flagged()
    {
        $always_true = 'tests the object, which is always true - even for $0.00. Use === null for "no value" or ->is_zero() for zero.';

        static::__assert_flagged('if ($r->amount) { return 1; }', "a condition {$always_true}");
        static::__assert_flagged('if ($other) { } elseif ($r->amount) { }', 'a condition');
        static::__assert_flagged('while ($r->amount) { break; }', 'a condition');
        static::__assert_flagged('do { break; } while ($r->amount);', 'a condition');
        static::__assert_flagged('for ($i = 0; $r->amount; $i++) { break; }', 'a condition');
        static::__assert_flagged('return $r->amount ? 1 : 2;', 'a ternary condition');
        static::__assert_flagged('return $r->amount ?: $other;', "'?:'");
        static::__assert_flagged('return !$r->amount;', "'!'");
        static::__assert_flagged('return $r->amount && $other;', "'&&'");
        static::__assert_flagged('return $other || $r->amount;', "'||'");
        static::__assert_flagged('return $r->amount and $other;', "'and'");
        static::__assert_flagged('return $r->amount or $other;', "'or'");
        static::__assert_flagged('return $r->amount xor $other;', "'xor'");
        static::__assert_flagged('return match (true) { $r->amount => 1, default => 2 };', 'a match (true) arm');
        static::__assert_flagged('return empty($r->amount);', "'empty()'");
        static::__assert_flagged('return (bool) $r->amount;', "'(bool)'");
    }

    // MONEY-USAGE-CAST
    public static function test_casts_and_scalar_functions_are_flagged()
    {
        static::__assert_flagged('return (int) $r->amount;', "'(int)' cannot convert it to the amount. Use ->to_decimal() for the exact string, or ->format() for display.");
        static::__assert_flagged('return (float) $r->amount;', "'(float)'");
        static::__assert_flagged('return (string) $r->amount;', "'(string)'");
        static::__assert_flagged('return intval($r->amount);', 'intval() reads it as a number or a string');
        static::__assert_flagged('return floatval($r->amount);', 'floatval()');
        static::__assert_flagged('return boolval($r->amount);', 'boolval()');
        static::__assert_flagged('return strval($r->amount);', 'strval()');
        static::__assert_flagged('return round($r->amount, 2);', 'Use ->round_to($step, $rounding).');
        static::__assert_flagged('return number_format($r->amount, 2);', 'Use ->format().');
        static::__assert_flagged('return abs($r->amount);', 'Use ->abs().');
        static::__assert_flagged('return max($other, $r->amount);', 'Rsx_Money::max($list)');
        static::__assert_flagged('return min($r->amount, $other);', 'Rsx_Money::min($list)');
    }

    // MONEY-USAGE-ARITHMETIC
    public static function test_arithmetic_and_concatenation_are_flagged()
    {
        static::__assert_flagged('return $r->amount + $other;', "'+' is not arithmetic on an amount - it throws. Use ->add(\$other).");
        static::__assert_flagged('return $other - $r->amount;', 'Use ->subtract($other).');
        static::__assert_flagged('return $r->amount * 2;', '->multiply($factor, $rounding)');
        static::__assert_flagged('return $r->amount / 3;', '->divide_rounded($divisor, $rounding)');
        static::__assert_flagged('return $r->amount % 3;', '->divide($n)');
        static::__assert_flagged('return $r->amount ** 2;', "'**'");
        static::__assert_flagged('return -$r->amount;', 'Use ->negate().');
        static::__assert_flagged('$x = $m; $x += $other; return $x;', "'+='");
        static::__assert_flagged('$x = $m; $x -= $other; return $x;', "'-='");
        static::__assert_flagged('$x = $m; $x *= 2; return $x;', "'*='");
        static::__assert_flagged('$x = $m; $x /= 2; return $x;', "'/='");
        static::__assert_flagged('return "Budget: " . $r->amount;', "'.' converts it to a string, which throws.");
        static::__assert_flagged('$s = "x"; $s .= $r->amount; return $s;', "'.'");
        static::__assert_flagged('return "Budget: {$r->amount}";', 'interpolating it into a string');
    }

    // MONEY-USAGE-RECEIVERS: each way a receiver is inferred.
    public static function test_every_receiver_inference_is_recognised()
    {
        static::__assert_flagged('return $m > $other;', "'\$m' is an Rsx_Money");

        $violations = static::__violations("function money_usage_fixture(\$x)\n{\n    /** @var Money_Fixture_Model \$x */\n    return \$x->amount == 0;\n}");
        static::__assert_count(1, $violations, 'a @var docblock: ' . static::__messages($violations));

        $violations = static::__violations("function money_usage_fixture()\n{\n    \$p = Money_Fixture_Model::find(1);\n    return \$p->amount > 0;\n}");
        static::__assert_count(1, $violations, 'a Money_Fixture_Model::find() assignment: ' . static::__messages($violations));

        $violations = static::__violations("function money_usage_fixture()\n{\n    \$p = Money_Fixture_Model::where('name', 'a')->first();\n    return \$p?->amount > 0;\n}");
        static::__assert_count(1, $violations, 'a builder chain ending first(), read through ?->: ' . static::__messages($violations));

        $violations = static::__violations("function money_usage_fixture()\n{\n    \$total = Rsx_Money::from_decimal('1.00');\n    return \$total < 5;\n}");
        static::__assert_count(1, $violations, 'an Rsx_Money::from_*() assignment: ' . static::__messages($violations));
        static::__assert_contains("'\$total' is an Rsx_Money", $violations[0]->message);

        $violations = static::__violations("function money_usage_fixture(Money_Fixture_Model \$r)\n{\n    \$left = \$r->amount->subtract(\$r->price);\n    return \$left < 0 || Rsx_Money::zero() == \$r->price;\n}");
        static::__assert_count(3, $violations, 'an assignment from a money method, a static zero(), and the property itself: ' . static::__messages($violations));
    }

    // MONEY-USAGE-LEGAL: null is how "no amount" is spelled; these never report.
    public static function test_null_checks_and_ordinary_use_are_left_alone()
    {
        static::__assert_clean('return $r->amount === null;');
        static::__assert_clean('return null !== $r->amount;');
        static::__assert_clean('return is_null($r->amount);');
        static::__assert_clean('return isset($r->amount);');
        static::__assert_clean('return $r->amount ?? Rsx_Money::zero();');
        static::__assert_clean('return $r->amount?->format();');
        static::__assert_clean('return $r->amount instanceof Rsx_Money;');
        static::__assert_clean('return json_encode($r->amount);');
        static::__assert_clean('return $r->amount;');
        static::__assert_clean('return $r->amount->less_than($other) && $r->amount->is_zero();');
        static::__assert_clean('if ($r->amount !== null && $r->amount->is_negative()) { return 1; } return 0;');
        static::__assert_clean('return $r->amount->to_decimal() . " USD";');
    }

    // MONEY-USAGE-NOT-MONEY: a column that is not money, an untyped receiver, a reassigned
    // variable and a property of an unknown class are never judged.
    public static function test_what_is_not_evidently_money_is_left_alone()
    {
        static::__assert_clean('return $r->score > 5 && $r->score;');
        static::__assert_clean('return $other->amount > 5;');

        $violations = static::__violations("function money_usage_fixture()\n{\n    \$m = Rsx_Money::zero();\n    \$m = 5;\n    return \$m > 1;\n}");
        static::__assert_count(0, $violations, 'a variable reassigned to something else: ' . static::__messages($violations));

        $violations = static::__violations("function money_usage_fixture(Unknown_Fixture_Class \$u)\n{\n    return \$u->amount > 1;\n}");
        static::__assert_count(0, $violations, static::__messages($violations));
    }
}
