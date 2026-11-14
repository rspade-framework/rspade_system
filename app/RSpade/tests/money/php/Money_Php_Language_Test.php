<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Core;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * What only PHP can show about Rsx_Money: the coercion guards, PHP's own refusals (operators,
 * a missing argument), the float door's independence from the precision ini settings, the
 * PHP-only edge of the residue rule, the checked core's overflow detection on each primitive's
 * edge, the display validation, and json_encode() writing the envelope.
 *
 * The shared behaviour of both languages is Money_Vectors_Test's.
 */
class Money_Php_Language_Test extends Rsx_Test_Abstract
{
    // Pure value logic - no database access.
    protected static $use_database_transactions = false;

    private const PRIMITIVE_MESSAGE = 'Rsx_Money cannot be converted to a string';

    public static function test_string_conversion_throws()
    {
        $m = Rsx_Money::from_decimal('1.00');

        static::__assert_throws(\LogicException::class, fn () => (string) $m, self::PRIMITIVE_MESSAGE);
        static::__assert_throws(\LogicException::class, fn () => 'x' . $m, self::PRIMITIVE_MESSAGE);
        static::__assert_throws(\LogicException::class, fn () => "{$m}", self::PRIMITIVE_MESSAGE);
        static::__assert_throws(\LogicException::class, fn () => strlen($m), self::PRIMITIVE_MESSAGE);
    }

    public static function test_arithmetic_operators_throw_type_error()
    {
        $a = Rsx_Money::from_decimal('1.00');
        $b = Rsx_Money::from_decimal('2.00');

        static::__assert_throws(\TypeError::class, fn () => $a + $b, 'Unsupported operand types');
        static::__assert_throws(\TypeError::class, fn () => $a * 2, 'Unsupported operand types');
    }

    public static function test_a_missing_mode_is_an_argument_count_error()
    {
        // The PHP side of the JavaScript-only vector multiply_272 (a missing mode is
        // `argument` there): PHP refuses the call itself.
        $m = Rsx_Money::from_decimal('10.00');

        static::__assert_throws(\ArgumentCountError::class, fn () => $m->multiply('2'));
    }

    public static function test_a_wrong_type_is_the_money_exception_never_a_type_error()
    {
        $m = Rsx_Money::from_decimal('10.00');

        $e = static::__assert_throws(Rsx_Money_Exception::class, fn () => $m->add(null));
        static::__assert_equals(Rsx_Money_Exception::REASON_ARGUMENT, $e->reason);
        $e = static::__assert_throws(Rsx_Money_Exception::class, fn () => $m->multiply([], Rsx_Money::ROUND_UP));
        static::__assert_equals(Rsx_Money_Exception::REASON_ARGUMENT, $e->reason);
    }

    public static function test_float_door_ignores_the_precision_settings()
    {
        // Each float and the ECMAScript Number::toString() of it.
        $table = [
            [0.1, '0.1'],
            [0.1 + 0.2, '0.30000000000000004'],
            [1234567890123.45, '1234567890123.45'],
            [1e20, '100000000000000000000'],
            [1e21, '1e+21'],
            [1e-6, '0.000001'],
            [1e-7, '1e-7'],
            [123e-20, '1.23e-18'],
            [-0.0, '0'],
            [2.675, '2.675'],
            [1e15, '1000000000000000'],
            [1e16, '10000000000000000'],
            [9007199254740993.0, '9007199254740992'],
            [1 / 3, '0.3333333333333333'],
            [1.1 * 1.1, '1.2100000000000002'],
            [5e-324, '5e-324'],
            [1.7976931348623157e308, '1.7976931348623157e+308'],
            [-1234.5, '-1234.5'],
        ];

        $method = new \ReflectionMethod(Rsx_Money::class, '_float_to_string');

        $saved_serialize = ini_get('serialize_precision');
        $saved_precision = ini_get('precision');
        $settings = [[$saved_serialize, $saved_precision], ['17', '5']];
        foreach ($settings as [$serialize_precision, $precision]) {
            ini_set('serialize_precision', $serialize_precision);
            ini_set('precision', $precision);
            try {
                foreach ($table as [$float, $expected]) {
                    static::__assert_equals(
                        $expected,
                        $method->invoke(null, $float),
                        "float door under serialize_precision={$serialize_precision} precision={$precision}"
                    );
                }

                // The door restores the setting it changes.
                static::__assert_equals($serialize_precision, ini_get('serialize_precision'), 'serialize_precision restored');
                static::__assert_equals($precision, ini_get('precision'), 'precision untouched');

                static::__assert_equals('0.30', Rsx_Money::from_aggregate(0.1 + 0.2)->to_decimal());
                static::__assert_equals('1234567890123.45', Rsx_Money::from_decimal(1234567890123.45)->to_decimal());
            } finally {
                ini_set('serialize_precision', $saved_serialize);
                ini_set('precision', $saved_precision);
            }
        }
    }

    public static function test_a_whole_float_of_sixteen_digits_is_residue_in_php()
    {
        // A JavaScript Number of this value is a safe integer and accepted; a PHP float is
        // read through the float door, whose shortest form has 16 significant digits.
        $float = 1000000000000001.0;

        $e = static::__assert_throws(Rsx_Money_Exception::class, fn () => Rsx_Money::from_decimal($float));
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_RESIDUE, $e->getMessage());

        $e = static::__assert_throws(
            Rsx_Money_Exception::class,
            fn () => Rsx_Money::from_decimal('1.00')->multiply($float, Rsx_Money::ROUND_UP)
        );
        static::__assert_equals(Rsx_Money_Exception::MESSAGE_RESIDUE, $e->getMessage());

        static::__assert_equals('1000000000000001.00', Rsx_Money::from_aggregate($float)->to_decimal());
    }

    public static function test_core_detects_overflow_on_each_primitive_edge()
    {
        $range = function (callable $fn, string $what) {
            $e = static::__assert_throws(Rsx_Money_Exception::class, $fn);
            static::__assert_equals(Rsx_Money_Exception::REASON_RANGE, $e->reason, $what);
        };

        $range(fn () => Rsx_Money_Core::add(PHP_INT_MAX, 1), 'add(MAX, 1)');
        $range(fn () => Rsx_Money_Core::sub(PHP_INT_MIN, 1), 'sub(MIN, 1)');
        $range(fn () => Rsx_Money_Core::mul(PHP_INT_MIN, -1), 'mul(MIN, -1)');
        $range(fn () => Rsx_Money_Core::mul(PHP_INT_MAX, 2), 'mul(MAX, 2)');
        $range(fn () => Rsx_Money_Core::neg(PHP_INT_MIN), 'neg(MIN)');
        $range(fn () => Rsx_Money_Core::abs(PHP_INT_MIN), 'abs(MIN)');
        $range(fn () => Rsx_Money_Core::quo(PHP_INT_MIN, -1), 'quo(MIN, -1)');
        $range(fn () => Rsx_Money_Core::pow10(19), 'pow10(19)');
        $range(fn () => Rsx_Money_Core::int('9223372036854775808'), "int('9223372036854775808')");
        $range(fn () => Rsx_Money_Core::int('-9223372036854775809'), "int('-9223372036854775809')");

        static::__assert_equals(0, Rsx_Money_Core::rem(PHP_INT_MIN, -1), 'rem(MIN, -1)');
        static::__assert_equals(PHP_INT_MAX, Rsx_Money_Core::add(PHP_INT_MAX - 1, 1), 'add to MAX');
        static::__assert_equals(PHP_INT_MIN, Rsx_Money_Core::int('-9223372036854775808'), 'int(MIN)');
        static::__assert_equals(0, Rsx_Money_Core::int('-000'), 'int of a negative zero');
        static::__assert_equals(1000000000000000000, Rsx_Money_Core::pow10(18), 'pow10(18)');
    }

    public static function test_display_validation_refuses_each_key()
    {
        $refusals = [
            'symbol' => ['symbol' => '5'],
            'symbol_position' => ['symbol_position' => 'left'],
            'symbol_spacing' => ['symbol_spacing' => 1],
            'decimal_separator' => ['decimal_separator' => ';'],
            'group_separator' => ['group_separator' => '_'],
            'colour' => ['colour' => 'red'],
        ];

        try {
            foreach ($refusals as $key => $display) {
                static::__assert_throws(
                    \RuntimeException::class,
                    fn () => Rsx_Money::_use_display($display),
                    "rsx.money.display.{$key} must be"
                );
            }

            // The group separator may not equal the decimal separator.
            static::__assert_throws(
                \RuntimeException::class,
                fn () => Rsx_Money::_use_display(['decimal_separator' => ',']),
                'rsx.money.display.group_separator must be'
            );

            // Every symbol character class the rule names.
            foreach (['-', '+', '(', ')', '.', ',', "'", ' ', "\u{00A0}", "kr\u{202F}"] as $symbol) {
                static::__assert_throws(
                    \RuntimeException::class,
                    fn () => Rsx_Money::_use_display(['symbol' => $symbol]),
                    'rsx.money.display.symbol must be'
                );
            }

            Rsx_Money::_use_display(['symbol' => "\u{20AC}", 'symbol_position' => 'after', 'symbol_spacing' => true, 'decimal_separator' => ',', 'group_separator' => '.']);
            static::__assert_equals("1.234,50\u{00A0}\u{20AC}", Rsx_Money::from_decimal('1234.5')->format());
        } finally {
            Rsx_Money::_use_display(null);
        }

        static::__assert_equals('$1,234.50', Rsx_Money::from_decimal('1234.5')->format());
    }

    public static function test_json_encode_writes_the_envelope()
    {
        static::__assert_equals('{"__MONEY":"1.00"}', json_encode(Rsx_Money::from_decimal('1.00')));
        static::__assert_equals('{"total":{"__MONEY":"-0.05"}}', json_encode(['total' => Rsx_Money::from_cents(-5)]));
    }

    public static function test_input_problems_are_format_scale_and_range()
    {
        $reasons = [
            Rsx_Money_Exception::REASON_FORMAT => true,
            Rsx_Money_Exception::REASON_SCALE => true,
            Rsx_Money_Exception::REASON_RANGE => true,
            Rsx_Money_Exception::REASON_ROUNDING => false,
            Rsx_Money_Exception::REASON_DIVISION => false,
            Rsx_Money_Exception::REASON_ARGUMENT => false,
        ];
        foreach ($reasons as $reason => $is_input) {
            static::__assert_equals($is_input, (new Rsx_Money_Exception($reason, 'x'))->is_input_problem(), $reason);
        }

        static::__assert_true(Rsx_Money::from_decimal('1.00') instanceof \JsonSerializable);
        static::__assert_true(new Rsx_Money_Exception('format', 'x') instanceof \InvalidArgumentException);
    }
}
