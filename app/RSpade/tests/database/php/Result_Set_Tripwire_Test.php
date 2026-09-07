<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the development row-count tripwire (rsx.database.result_set_warn_threshold).
 *
 * The tripwire is the EMPIRICAL half of the bounded-result-set discipline: $unbounded is a
 * static claim about a table, this fires on what a query actually returned. Its whole value
 * rests on three properties, which is what these tests pin:
 *
 *   - it warns when a get() really did return too much,
 *   - it stays silent otherwise (no false positives - that is why it can be always-on in dev),
 *   - it NEVER truncates. The caller asked for the whole set and gets the whole set.
 *
 * Log output is captured with a spy listener rather than by reading the log file, so the test
 * neither depends on log configuration nor pollutes the real log.
 */
class Result_Set_Tripwire_Test extends Rsx_Test_Abstract
{
    private const ROW_COUNT = 6;

    private static array $_ids = [];

    /** Warning messages captured during one test. */
    private static array $_captured = [];

    public static function setup()
    {
        static::$_ids = [];

        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $site = new Site_Model();
            $site->slug = 'tripwire-fixture-' . $i;
            $site->name = 'tripwire-fixture-' . $i;
            $site->timezone = 'UTC';
            $site->save();

            static::$_ids[] = (int) $site->id;
        }
    }

    public static function teardown()
    {
        Site_Model::whereIn('id', static::$_ids)->forceDelete();
        static::$_ids = [];
    }

    /**
     * Run a query with the tripwire configured, capturing any warnings it logs.
     *
     * @param int|null $threshold Value for rsx.database.result_set_warn_threshold
     * @return array{rows: int, warnings: array<string>}
     */
    private static function __run_with_threshold($threshold): array
    {
        static::$_captured = [];

        $original = config('rsx.database.result_set_warn_threshold');
        config(['rsx.database.result_set_warn_threshold' => $threshold]);

        Log::listen(function ($message) {
            if ($message->level === 'warning' && str_contains($message->message, '[DB-UNBOUNDED]')) {
                static::$_captured[] = $message->message;
            }
        });

        $rows = Site_Model::whereIn('id', static::$_ids)->get()->count();

        config(['rsx.database.result_set_warn_threshold' => $original]);

        return ['rows' => $rows, 'warnings' => static::$_captured];
    }

    // =====================================================================
    // Fires when it should
    // =====================================================================

    public static function test_warns_once_when_a_query_exceeds_the_threshold()
    {
        $result = static::__run_with_threshold(2);

        static::__assert_count(1, $result['warnings'], 'exactly one warning per offending query');
    }

    public static function test_the_warning_names_the_count_and_the_model()
    {
        $result = static::__run_with_threshold(2);
        $warning = $result['warnings'][0] ?? '';

        static::__assert_contains('Site_Model', $warning, 'names the model');
        static::__assert_contains((string) self::ROW_COUNT, $warning, 'names the actual row count');
        static::__assert_contains('result_set', $warning, 'points at the remedy');
    }

    // =====================================================================
    // Stays silent when it should - no false positives
    // =====================================================================

    public static function test_silent_when_the_result_is_under_the_threshold()
    {
        $result = static::__run_with_threshold(self::ROW_COUNT + 1);

        static::__assert_count(0, $result['warnings'], 'a small result never warns');
    }

    public static function test_silent_at_exactly_the_threshold()
    {
        // The check is strictly greater-than: landing exactly on the threshold is fine.
        $result = static::__run_with_threshold(self::ROW_COUNT);

        static::__assert_count(0, $result['warnings']);
    }

    public static function test_zero_disables_the_tripwire()
    {
        $result = static::__run_with_threshold(0);

        static::__assert_count(0, $result['warnings']);
    }

    public static function test_null_disables_the_tripwire()
    {
        $result = static::__run_with_threshold(null);

        static::__assert_count(0, $result['warnings']);
    }

    // =====================================================================
    // The property that matters most: it never truncates
    // =====================================================================

    public static function test_the_caller_still_receives_every_row_when_warning()
    {
        $result = static::__run_with_threshold(1);

        static::__assert_greater_than(0, count($result['warnings']), 'precondition: it warned');
        static::__assert_equals(
            self::ROW_COUNT,
            $result['rows'],
            'the tripwire reports, it never truncates - the caller asked for the whole set'
        );
    }
}
