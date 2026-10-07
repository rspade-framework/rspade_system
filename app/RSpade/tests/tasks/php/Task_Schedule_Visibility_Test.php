<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Health_Checks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * A repeatedly-failing schedule must be VISIBLE.
 *
 * Each failing run is FAILED, but the schedule runs again at its next cadence, so it looks
 * alive while the work never succeeds; only _task_schedules.consecutive_failures distinguishes
 * "retrying" from "broken every run". These tests pin the two surfaces that report it: the
 * rsx:health row and the Schedules table of rsx:tasks:list.
 *
 * Schedule rows are planted directly and roll back with the per-test transaction; every
 * other schedule's streak is neutralized first, so the findings are about the planted rows.
 */
class Task_Schedule_Visibility_Test extends Rsx_Test_Abstract
{
    /**
     * Plant a schedule with a failure streak and return its method name.
     */
    private static function __plant_schedule(int $failures, ?string $last_error = 'Fixture explosion in the night'): string
    {
        $method = 'visibility_fixture_' . uniqid();

        DB::table('_task_schedules')->insert([
            'class' => Task_Exec_Fixture_Service::class,
            'method' => $method,
            'cron_expression' => '0 3 * * *',
            'next_run_at' => now()->addHour(),
            'last_error' => $failures > 0 ? $last_error : null,
            'last_error_at' => $failures > 0 ? now() : null,
            'consecutive_failures' => $failures,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $method;
    }

    private static function __neutralize(): void
    {
        DB::table('_task_schedules')->update(['consecutive_failures' => 0]);
    }

    public static function test_failing_schedule_warns_and_names_the_offender()
    {
        static::__neutralize();
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after', 3);
        $method = static::__plant_schedule($threshold);

        $result = Task_Health_Checks::task_schedule_failures();

        static::__assert_equals('WARN', $result['status']);
        static::__assert_contains('Task_Exec_Fixture_Service::' . $method, $result['detail']);
        static::__assert_contains($threshold . ' consecutive failures', $result['detail']);
        static::__assert_contains('Fixture explosion', $result['detail']);
    }

    public static function test_schedule_below_threshold_is_ok()
    {
        static::__neutralize();
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after', 3);
        static::__plant_schedule(max(0, $threshold - 1));

        $result = Task_Health_Checks::task_schedule_failures();

        static::__assert_equals('OK', $result['status']);
        static::__assert_contains('no schedule failing repeatedly', $result['detail']);
    }

    /**
     * rsx:tasks:list renders every schedule with its cadence and failure record; the failure
     * columns are blank for a schedule whose streak is 0.
     */
    public static function test_tasks_list_renders_the_schedules_table()
    {
        static::__neutralize();
        $failing = static::__plant_schedule(4, "Database went away\nsecond line of the trace");
        $healthy = static::__plant_schedule(0);

        static::__assert_equals(0, Artisan::call('rsx:tasks:list'));
        $output = Artisan::output();

        static::__assert_contains('Schedules', $output);
        static::__assert_contains('Pool', $output, 'the pools table comes first');
        foreach (['on_demand', 'scheduled', 'kill'] as $pool) {
            static::__assert_contains($pool, $output);
        }

        $failing_line = static::__line_containing($output, $failing);
        static::__assert_contains('0 3 * * *', $failing_line);
        static::__assert_contains('| 4 ', $failing_line, 'the streak is shown');
        static::__assert_contains('Database went away', $failing_line, 'with the first line of the last error');
        static::__assert_false(str_contains($failing_line, 'second line'), 'and only the first line');

        $healthy_line = static::__line_containing($output, $healthy);
        static::__assert_contains('| - ', $healthy_line, 'no streak: a dash');
        static::__assert_false(str_contains($healthy_line, 'Fixture explosion'), 'and no error');
    }

    private static function __line_containing(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        static::__fail("no line contains {$needle}:\n{$text}");
    }
}
