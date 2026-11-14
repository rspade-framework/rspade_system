<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Money against the shared parity vectors (../resource/money_vectors.json).
 *
 * The browser runs the SAME file through the JavaScript class (playwright/money_vectors.js);
 * together the two prove both languages give the identical result or the identical refusal
 * for every case. Decoding of arguments and encoding of results follow the file's own
 * format exactly, so a case that cannot be decoded is reported as a broken vector, never
 * counted as an expectation met.
 *
 * One test method per group, so a failure names its group; each lists every failing case
 * id with what was expected and what came back. Cases marked `only: js` are skipped here
 * (their PHP side is Money_Php_Language_Test).
 */
class Money_Vectors_Test extends Rsx_Test_Abstract
{
    // Pure value logic - no database access.
    protected static $use_database_transactions = false;

    /** The decoded vector file, read once. */
    private static ?array $vectors = null;

    public static function test_the_file_is_the_one_the_spec_describes()
    {
        $vectors = static::__vectors();
        static::__assert_equals(1, $vectors['schema'], 'vector schema');
        static::__assert_equals(Rsx_Money::DISPLAY_DEFAULTS, $vectors['display_default'], 'display_default is the framework default display');

        $ids = [];
        foreach ($vectors['cases'] as $case) {
            static::__assert_true(!isset($ids[$case['id']]), 'duplicate case id ' . $case['id']);
            $ids[$case['id']] = true;
        }

        // Every group has a test method below, so no group is silently left unrun.
        $groups = array_unique(array_column($vectors['cases'], 'group'));
        foreach ($groups as $group) {
            static::__assert_true(
                method_exists(static::class, 'test_group_' . $group),
                "group '{$group}' has no test_group_{$group}() method"
            );
        }
    }

    public static function test_group_bounds() { static::__run_group('bounds'); }
    public static function test_group_construct_decimal() { static::__run_group('construct_decimal'); }
    public static function test_group_construct_cents() { static::__run_group('construct_cents'); }
    public static function test_group_storage_envelope() { static::__run_group('storage_envelope'); }
    public static function test_group_parse_text() { static::__run_group('parse_text'); }
    public static function test_group_parse_config() { static::__run_group('parse_config'); }
    public static function test_group_submitted() { static::__run_group('submitted'); }
    public static function test_group_aggregate() { static::__run_group('aggregate'); }
    public static function test_group_format() { static::__run_group('format'); }
    public static function test_group_roundtrip() { static::__run_group('roundtrip'); }
    public static function test_group_questions() { static::__run_group('questions'); }
    public static function test_group_add_subtract() { static::__run_group('add_subtract'); }
    public static function test_group_float_residue() { static::__run_group('float_residue'); }
    public static function test_group_multiply() { static::__run_group('multiply'); }
    public static function test_group_multiply_fraction() { static::__run_group('multiply_fraction'); }
    public static function test_group_percent() { static::__run_group('percent'); }
    public static function test_group_divide() { static::__run_group('divide'); }
    public static function test_group_divide_rounded() { static::__run_group('divide_rounded'); }
    public static function test_group_round_to() { static::__run_group('round_to'); }
    public static function test_group_allocate() { static::__run_group('allocate'); }
    public static function test_group_lists() { static::__run_group('lists'); }
    public static function test_group_average() { static::__run_group('average'); }
    public static function test_group_ratio() { static::__run_group('ratio'); }
    public static function test_group_historical() { static::__run_group('historical'); }
    public static function test_group_large_magnitude() { static::__run_group('large_magnitude'); }

    // =========================================================================
    // RUNNER
    // =========================================================================

    /**
     * Run every PHP case of one group; fail once, listing every failing case.
     *
     * @param string $group
     * @return void
     */
    protected static function __run_group(string $group): void
    {
        $run = 0;
        $failures = [];
        foreach (static::__vectors()['cases'] as $case) {
            if ($case['group'] !== $group || ($case['only'] ?? null) === 'js') {
                continue;
            }
            $run++;
            $failure = static::__run_case($case);
            if ($failure !== null) {
                $failures[] = $case['id'] . ': ' . $failure;
            }
        }

        static::__assert_true($run > 0, "group '{$group}' has no PHP cases");
        static::__assert_true(
            count($failures) === 0,
            count($failures) . " of {$run} '{$group}' cases failed:\n  " . implode("\n  ", $failures)
        );
    }

    /**
     * Run one case. Null when it passed, else what went wrong.
     *
     * @param array $case
     * @return string|null
     */
    public static function __run_case(array $case): ?string
    {
        try {
            $on = $case['call']['on'] === null ? null : static::__decode($case['call']['on']);
            $args = static::__decode_list($case['call']['args']);
        } catch (\Throwable $e) {
            return 'broken vector: ' . $e->getMessage();
        }

        $method = $case['call']['method'];
        $expect = $case['expect'];

        Rsx_Money::_use_display([]);
        try {
            if (isset($case['config'])) {
                Rsx_Money::_use_display($case['config']);
            }
            $result = $on === null ? Rsx_Money::$method(...$args) : $on->$method(...$args);
            $actual = ['value' => static::__encode($result)];
        } catch (Rsx_Money_Exception $e) {
            $actual = ['throws' => $e->reason, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return 'expected ' . json_encode($expect) . ', got ' . get_class($e) . ': ' . $e->getMessage();
        } finally {
            Rsx_Money::_use_display(null);
        }

        if (array_key_exists('value', $expect)) {
            if (array_key_exists('value', $actual) && $actual['value'] === $expect['value']) {
                return null;
            }
        } elseif (array_key_exists('throws', $actual) && $actual['throws'] === $expect['throws']
            && ($expect['throws'] === Rsx_Money_Exception::REASON_ARGUMENT || $actual['message'] === $expect['message'])) {
            return null;
        }

        return 'expected ' . json_encode($expect, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode one ARG of the vector format.
     *
     * @param mixed $arg
     * @return mixed
     */
    protected static function __decode(mixed $arg): mixed
    {
        if (!is_array($arg)) {
            return $arg;
        }
        if (array_is_list($arg)) {
            return static::__decode_list($arg);
        }
        if (count($arg) === 1) {
            $tag = array_key_first($arg);
            $value = $arg[$tag];
            switch ($tag) {
                case 'money':
                    if (!is_string($value)) {
                        throw new \RuntimeException('money tag holds a non-string');
                    }

                    return Rsx_Money::from_decimal($value);
                case 'int':
                    if (!is_string($value) || (string) (int) $value !== $value) {
                        throw new \RuntimeException('int tag does not round-trip: ' . json_encode($value));
                    }

                    return (int) $value;
                case 'float':
                    return static::__decode_float($value);
                case 'rounding':
                    $name = Rsx_Money::class . '::' . $value;
                    if (!is_string($value) || !str_starts_with($value, 'ROUND_') || !defined($name)) {
                        throw new \RuntimeException('unknown rounding constant ' . json_encode($value));
                    }

                    return constant($name);
                case 'missing':
                    throw new \RuntimeException('a missing argument exists only in JavaScript');
            }
        }

        $decoded = [];
        foreach ($arg as $key => $value) {
            $decoded[$key] = static::__decode($value);
        }

        return $decoded;
    }

    /**
     * @param array $list
     * @return array
     */
    protected static function __decode_list(array $list): array
    {
        $decoded = [];
        foreach ($list as $value) {
            $decoded[] = static::__decode($value);
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     * @return float
     */
    protected static function __decode_float(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return match (true) {
            $value === 'NaN' => NAN,
            $value === 'INF' => INF,
            $value === '-INF' => -INF,
            $value === '-0' => -0.0,
            // A literal the JSON writer kept as text (5e-324): (float) of the value.
            is_string($value) && is_numeric($value) => (float) $value,
            default => throw new \RuntimeException('unknown float special ' . json_encode($value)),
        };
    }

    /**
     * Encode a result as the vector file writes it.
     *
     * @param mixed $result
     * @return mixed
     */
    protected static function __encode(mixed $result): mixed
    {
        if ($result instanceof Rsx_Money) {
            return ['money' => $result->to_decimal()];
        }
        if (is_array($result)) {
            $encoded = [];
            foreach ($result as $key => $value) {
                $encoded[$key] = static::__encode($value);
            }

            return $encoded;
        }

        return $result;
    }

    /**
     * The vector file, decoded once.
     *
     * @return array
     */
    protected static function __vectors(): array
    {
        if (self::$vectors === null) {
            $path = __DIR__ . '/../resource/money_vectors.json';
            $text = file_get_contents($path);
            if ($text === false) {
                throw new \RuntimeException("cannot read {$path}");
            }
            self::$vectors = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$vectors;
    }
}
