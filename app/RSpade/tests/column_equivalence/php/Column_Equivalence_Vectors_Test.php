<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use App\RSpade\Core\Database\Models\Column_Equivalence;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\ColumnEquivalence\Php\Column_Equivalence_Fixture_Text;

/**
 * Column_Equivalence::same() against the shared parity vectors
 * (../resource/column_equivalence_vectors.json).
 *
 * The browser runs the SAME file through Column_Equivalence.js
 * (playwright/column_equivalence_vectors.js); together the two prove both languages give the
 * same answer for every kind and every never-loosened case. Each case runs in BOTH argument
 * orders - the rule is symmetric. Cases marked `only: js` are skipped here.
 *
 * One test method per group, so a failure names its group; each lists every failing case.
 */
class Column_Equivalence_Vectors_Test extends Rsx_Test_Abstract
{
    /** The decoded vector file, read once. */
    private static ?array $vectors = null;

    public static function test_the_file_is_well_formed()
    {
        $vectors = static::__vectors();
        static::__assert_equals(1, $vectors['schema'], 'vector schema');

        $ids = [];
        foreach ($vectors['cases'] as $case) {
            static::__assert_true(!isset($ids[$case['id']]), 'duplicate case id ' . $case['id']);
            $ids[$case['id']] = true;
            Column_Equivalence::normalize_descriptor(static::__descriptor($case['descriptor']));
        }

        // Every group has a test method below, so no group is silently left unrun; every kind
        // has at least one case.
        $groups = array_unique(array_column($vectors['cases'], 'group'));
        foreach ($groups as $group) {
            if (static::__only_js($group)) {
                continue;
            }
            static::__assert_true(method_exists(static::class, 'test_group_' . $group), "group '{$group}' has no test_group_{$group}() method");
        }

        $kinds = array_unique(array_map(fn ($case) => $case['descriptor']['kind'], $vectors['cases']));
        foreach (Column_Equivalence::KINDS as $kind) {
            static::__assert_true(in_array($kind, $kinds, true), "kind '{$kind}' has a case");
        }
    }

    public static function test_group_money() { static::__run_group('money'); }
    public static function test_group_decimal() { static::__run_group('decimal'); }
    public static function test_group_decimal_scale0() { static::__run_group('decimal_scale0'); }
    public static function test_group_float() { static::__run_group('float'); }
    public static function test_group_integer() { static::__run_group('integer'); }
    public static function test_group_integer_not_null() { static::__run_group('integer_not_null'); }
    public static function test_group_boolean() { static::__run_group('boolean'); }
    public static function test_group_boolean_not_null() { static::__run_group('boolean_not_null'); }
    public static function test_group_date() { static::__run_group('date'); }
    public static function test_group_datetime() { static::__run_group('datetime'); }
    public static function test_group_datetime_fsp0() { static::__run_group('datetime_fsp0'); }
    public static function test_group_time() { static::__run_group('time'); }
    public static function test_group_time_fsp3() { static::__run_group('time_fsp3'); }
    public static function test_group_json() { static::__run_group('json'); }
    public static function test_group_string() { static::__run_group('string'); }
    public static function test_group_string_not_null() { static::__run_group('string_not_null'); }
    public static function test_group_exact() { static::__run_group('exact'); }
    public static function test_group_text_type() { static::__run_group('text_type'); }
    public static function test_group_text_type_decimal() { static::__run_group('text_type_decimal'); }
    public static function test_group_type_ref() { static::__run_group('type_ref'); }
    public static function test_group_never_loosened() { static::__run_group('never_loosened'); }
    public static function test_group_never_loosened_decimal() { static::__run_group('never_loosened_decimal'); }
    public static function test_group_never_loosened_integer() { static::__run_group('never_loosened_integer'); }
    public static function test_group_never_loosened_float() { static::__run_group('never_loosened_float'); }
    public static function test_group_never_loosened_boolean() { static::__run_group('never_loosened_boolean'); }
    public static function test_group_never_loosened_blank_distinct() { static::__run_group('never_loosened_blank_distinct'); }
    public static function test_group_never_loosened_text_blank_distinct() { static::__run_group('never_loosened_text_blank_distinct'); }

    // =========================================================================
    // RUNNER
    // =========================================================================

    /**
     * Run every PHP case of one group, both argument orders; fail once, listing every failure.
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
            $descriptor = static::__descriptor($case['descriptor']);
            $a = static::__decode($case['a']);
            $b = static::__decode($case['b']);

            foreach ([[$a, $b, 'a,b'], [$b, $a, 'b,a']] as [$x, $y, $order]) {
                $actual = Column_Equivalence::same($descriptor, $x, $y);
                if ($actual !== $case['expect']) {
                    $failures[] = "{$case['id']} ({$order}): expected " . json_encode($case['expect']) . ', got ' . json_encode($actual)
                        . ' for ' . json_encode($case['a']) . ' / ' . json_encode($case['b']);
                }
            }
        }

        static::__assert_true($run > 0, "group '{$group}' has no PHP cases");
        static::__assert_true(count($failures) === 0, count($failures) . " failures in '{$group}':\n  " . implode("\n  ", $failures));
    }

    /** A group whose every case is `only: js`. */
    protected static function __only_js(string $group): bool
    {
        foreach (static::__vectors()['cases'] as $case) {
            if ($case['group'] === $group && ($case['only'] ?? null) !== 'js') {
                return false;
            }
        }

        return true;
    }

    /** The descriptor, its text type named by the fixture class. */
    protected static function __descriptor(array $descriptor): array
    {
        if (($descriptor['text_type'] ?? null) === static::__vectors()['text_type_fixture']) {
            $descriptor['text_type'] = Column_Equivalence_Fixture_Text::class;
        }

        return $descriptor;
    }

    /**
     * One side, decoded: a plain JSON value as itself, or a one-key tag - money (an Rsx_Money),
     * money_envelope (the {__MONEY} array), float (a PHP float), text (a fixture text value),
     * obj (a decoded JSON object), list (a decoded JSON list).
     */
    protected static function __decode(mixed $arg): mixed
    {
        if (!is_array($arg)) {
            return $arg;
        }

        $tag = array_key_first($arg);
        $value = $arg[$tag];

        return match ($tag) {
            'money' => Rsx_Money::from_decimal($value),
            'money_envelope' => [Rsx_Money::ENVELOPE_KEY => $value],
            'float' => (float) $value,
            'text' => Column_Equivalence_Fixture_Text::from_storage($value),
            'obj' => (object) $value,
            'list' => array_values($value),
            default => throw new \RuntimeException('unknown vector tag ' . json_encode($tag)),
        };
    }

    /** The vector file, decoded once. */
    protected static function __vectors(): array
    {
        if (self::$vectors === null) {
            $path = __DIR__ . '/../resource/column_equivalence_vectors.json';
            $text = file_get_contents($path);
            if ($text === false) {
                throw new \RuntimeException("cannot read {$path}");
            }
            self::$vectors = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$vectors;
    }
}
