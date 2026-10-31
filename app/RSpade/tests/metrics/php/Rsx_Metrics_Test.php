<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Metrics\Php;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Metrics\Rsx_Metrics;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Metrics keeps the last 100 entries per kind and per-minute totals for two hours: the
 * readers answer what was recorded, a run is spread over the minutes it spanned, the peak is
 * a maximum, nothing is written while disabled, and a store that cannot answer costs the
 * caller nothing. Every key carries the database scope, so the test's entries are its own;
 * each test starts from an empty scope (_reset(); setup() runs once per class).
 */
class Rsx_Metrics_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static $enabled = null;

    public static function setup()
    {
        static::$enabled = config('rsx.metrics.enabled');
        config(['rsx.metrics.enabled' => true]);
        Rsx_Metrics::_reset();
    }

    public static function teardown()
    {
        config(['rsx.metrics.enabled' => static::$enabled]);
        Framework_Maintenance::$force_active_for_tests = null;
        Rsx_Metrics::_reset();
    }

    /**
     * METRICS-01: the recent list is newest first, carries what was recorded, and keeps 100.
     */
    public static function test_the_recent_list_keeps_the_last_hundred()
    {
        Rsx_Metrics::_reset();
        $now = (float) time();
        for ($i = 1; $i <= 105; $i++) {
            Rsx_Metrics::record(Rsx_Metrics::KIND_AJAX, "Call_Controller::n{$i}", 1024 * $i, $now - 1, $now);
        }

        $recent = Rsx_Metrics::recent(Rsx_Metrics::KIND_AJAX, 3);
        static::__assert_count(3, $recent);
        static::__assert_equals('Call_Controller::n105', $recent[0]['label'], 'newest first');
        static::__assert_equals(105.0, (float) $recent[0]['peak_mb']);
        static::__assert_equals(1000, $recent[0]['ms']);

        static::__assert_count(100, Rsx_Metrics::recent(Rsx_Metrics::KIND_AJAX, 500), 'trimmed to 100');
        static::__assert_count(0, Rsx_Metrics::recent(Rsx_Metrics::KIND_PAGE), 'kinds are kept apart');
    }

    /**
     * METRICS-02: the summary averages the kept entries and names the largest.
     */
    public static function test_the_summary_averages_the_kept_entries()
    {
        Rsx_Metrics::_reset();
        static::__assert_equals(0, Rsx_Metrics::average_recent(Rsx_Metrics::KIND_PAGE)['count'], 'nothing recorded');

        $now = (float) time();
        Rsx_Metrics::record(Rsx_Metrics::KIND_PAGE, 'A::a', 10 * 1024, $now - 0.1, $now);
        Rsx_Metrics::record(Rsx_Metrics::KIND_PAGE, 'A::b', 30 * 1024, $now - 0.3, $now);

        $summary = Rsx_Metrics::average_recent(Rsx_Metrics::KIND_PAGE);
        static::__assert_equals(2, $summary['count']);
        static::__assert_equals(20.0, (float) $summary['avg_peak_mb']);
        static::__assert_equals(30.0, (float) $summary['max_peak_mb']);
        static::__assert_equals(200, $summary['avg_ms']);
        static::__assert_equals(300, $summary['max_ms']);
    }

    /**
     * METRICS-03: a run is spread over every minute it spanned; it is counted, and its peak
     * kept, in the minute it ended.
     */
    public static function test_a_run_is_spread_over_the_minutes_it_spanned()
    {
        Rsx_Metrics::_reset();
        $minute = (int) (floor(time() / 60) * 60) - 180;
        // 30 s of the first minute, all of the second, 30 s of the third.
        Rsx_Metrics::record(Rsx_Metrics::KIND_TASK, 'Long_Service::run', 120 * 1024, $minute + 30, $minute + 150);

        $rows = Rsx_Metrics::minutes(4, $minute + 180);
        static::__assert_count(4, $rows);
        static::__assert_equals(gmdate('Y-m-d\TH:i:00\Z', $minute), $rows[0]['minute'], 'oldest first');

        $task = array_map(fn ($row) => $row['kinds']['task'], $rows);
        static::__assert_equals([0.5, 1.0, 0.5, 0.0], array_map('floatval', array_column($task, 'avg_workers')), 'busy time per minute');
        static::__assert_equals([60.0, 120.0, 60.0, 0.0], array_map('floatval', array_column($task, 'avg_mem_mb')), 'memory held per minute');
        static::__assert_equals([0, 0, 1, 0], array_column($task, 'count'), 'counted where it ended');
        static::__assert_equals(120.0, (float) $task[2]['peak_mb']);

        $split = Rsx_Metrics::split_by_minute($minute - 7200.0, (float) $minute);
        static::__assert_count(Rsx_Metrics::MAX_SPAN_MINUTES, $split, 'a longer run counts its last hour');
    }

    /**
     * METRICS-04: a minute's peak is the largest of the entries ending in it.
     */
    public static function test_the_minute_peak_is_the_largest()
    {
        Rsx_Metrics::_reset();
        $now = (float) time();
        foreach ([40, 90, 25] as $mb) {
            Rsx_Metrics::record(Rsx_Metrics::KIND_PAGE, 'P::p', $mb * 1024, $now, $now);
        }

        $row = Rsx_Metrics::minutes(1, (int) $now)[0]['kinds']['page'];
        static::__assert_equals(3, $row['count']);
        static::__assert_equals(90.0, (float) $row['peak_mb']);
    }

    /**
     * METRICS-05: disabled, nothing is written; with the store unavailable a record is a
     * silent no-op and the readers answer empty.
     */
    public static function test_disabled_or_unavailable_records_nothing()
    {
        Rsx_Metrics::_reset();
        $now = (float) time();

        config(['rsx.metrics.enabled' => false]);
        Rsx_Metrics::record(Rsx_Metrics::KIND_API, '/api/v1/off', 1024, $now, $now);
        config(['rsx.metrics.enabled' => true]);
        static::__assert_count(0, Rsx_Metrics::recent(Rsx_Metrics::KIND_API), 'disabled writes nothing');

        Framework_Maintenance::$force_active_for_tests = true;
        Rsx_Metrics::record(Rsx_Metrics::KIND_API, '/api/v1/down', 1024, $now, $now);
        static::__assert_count(0, Rsx_Metrics::recent(Rsx_Metrics::KIND_API), 'no store, no entries');
        static::__assert_equals(0, Rsx_Metrics::minutes(1, (int) $now)[0]['kinds']['api']['count']);
        Framework_Maintenance::$force_active_for_tests = null;

        static::__assert_count(0, Rsx_Metrics::recent(Rsx_Metrics::KIND_API), 'and nothing was queued meanwhile');
        Rsx_Metrics::record(Rsx_Metrics::KIND_API, '/api/v1/up', 1024, $now, $now);
        static::__assert_count(1, Rsx_Metrics::recent(Rsx_Metrics::KIND_API), 'recording resumes');
    }

    /**
     * METRICS-06: a label cannot break the line format, and an unknown kind is ignored.
     */
    public static function test_a_label_is_one_field()
    {
        Rsx_Metrics::_reset();
        $now = (float) time();
        Rsx_Metrics::record(Rsx_Metrics::KIND_PAGE, "A|B\nC", 1024, $now, $now);
        Rsx_Metrics::record('nonsense', 'X', 1024, $now, $now);

        $recent = Rsx_Metrics::recent(Rsx_Metrics::KIND_PAGE);
        static::__assert_count(1, $recent);
        static::__assert_equals('A B C', $recent[0]['label']);
    }
}
