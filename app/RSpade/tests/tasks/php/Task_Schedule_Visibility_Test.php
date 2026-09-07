<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Health_Checks;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A repeatedly-failing schedule must be VISIBLE.
 *
 * A cron tracker is never terminal - a run that throws recycles it to PENDING - so its
 * status looks identical to a healthy one and only consecutive_failures distinguishes
 * "retrying" from "broken every run". These tests pin the rsx:health surface that reports
 * that difference.
 *
 * Read-only against _tasks apart from the planted rows, so the default per-test
 * transaction isolation is enough.
 */
class Task_Schedule_Visibility_Test extends Rsx_Test_Abstract
{
    /**
     * Plant a tracker row with a live failure streak.
     *
     * @param int $failures consecutive_failures to record.
     * @return string The method name of the planted tracker.
     */
    private static function __plant_failing_tracker(int $failures): string
    {
        $method = 'visibility_fixture_' . uniqid();

        DB::table('_tasks')->insert([
            'class' => 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Exec_Fixture_Service',
            'method' => $method,
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => now()->addHour(),
            'cron_expression' => '0 3 * * *',
            'error' => 'Fixture explosion in the night',
            'status_reason' => 'failed (recycled): Fixture explosion in the night',
            'last_error_at' => now(),
            'consecutive_failures' => $failures,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $method;
    }

    /**
     * A tracker at the configured threshold is reported as WARN, naming the offender.
     */
    public static function test_failing_schedule_warns_and_names_the_offender()
    {
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after', 3);
        $method = static::__plant_failing_tracker($threshold);

        $result = Task_Health_Checks::task_schedule_failures();

        static::__assert_equals('WARN', $result['status']);
        static::__assert_contains($method, $result['detail']);
        static::__assert_contains($threshold . ' consecutive failures', $result['detail']);
        static::__assert_contains('Fixture explosion', $result['detail']);
    }

    /**
     * A streak below the threshold is still just retrying - not a health finding.
     */
    public static function test_schedule_below_threshold_is_ok()
    {
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after', 3);

        // Neutralize any tracker the surrounding suite left behind (rolled back with the
        // test transaction), so the assertion is about the planted row alone.
        DB::table('_tasks')->whereNotNull('next_run_at')->update(['consecutive_failures' => 0]);

        static::__plant_failing_tracker(max(0, $threshold - 1));

        $result = Task_Health_Checks::task_schedule_failures();

        static::__assert_equals('OK', $result['status']);
        static::__assert_contains('no schedule failing repeatedly', $result['detail']);
    }
}
