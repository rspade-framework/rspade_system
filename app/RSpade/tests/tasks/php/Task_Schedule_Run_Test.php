<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Each run of a #[Schedule] is its own _tasks row; the schedule (_task_schedules) keeps the
 * cadence and the run statistics.
 *
 *   - a run that fails is FAILED like any other run, and the schedule counts it:
 *     consecutive_failures + 1, last_error, last_error_at, last_task_id - and still runs again
 *     at its next cadence;
 *   - a run that succeeds clears the streak: consecutive_failures 0, last_error null,
 *     last_success_at stamped;
 *   - the schedule's runs are its history, one row each.
 *
 * Driven by an in-process scheduled-pool worker over schedule rows pointed at the exec
 * fixture, inside the per-test transaction, after removing every other pending run and
 * schedule.
 */
class Task_Schedule_Run_Test extends Rsx_Test_Abstract
{
    private static function __settle_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    private static function __schedule(string $method, array $fields = []): int
    {
        return DB::table('_task_schedules')->insertGetId(array_merge([
            'class' => Task_Exec_Fixture_Service::class,
            'method' => $method,
            'cron_expression' => 'daily at 3am',
            'next_run_at' => date('Y-m-d H:i:s', time() - 60) . '.000',
            'created_at' => now(),
            'updated_at' => now(),
        ], $fields));
    }

    private static function __make_due(int $schedule_id): void
    {
        DB::table('_task_schedules')->where('id', $schedule_id)->update(['next_run_at' => date('Y-m-d H:i:s', time() - 60) . '.000']);
    }

    private static function __run_worker(): void
    {
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
    }

    private static function __schedule_row(int $schedule_id): object
    {
        return DB::table('_task_schedules')->where('id', $schedule_id)->first();
    }

    public static function test_a_failing_run_is_failed_and_counted_on_its_schedule()
    {
        static::__settle_queue();
        $schedule_id = static::__schedule('always_throws');

        static::__run_worker();

        $run = Task_Run_Model::where('schedule_id', $schedule_id)->first();
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'the run is FAILED like any other');
        static::__assert_equals('Exception: fixture exploded on purpose', $run->error);
        static::__assert_equals(Task_Run_Model::ORIGIN_SCHEDULED, (int) $run->origin_id);

        $schedule = static::__schedule_row($schedule_id);
        static::__assert_equals(1, (int) $schedule->consecutive_failures);
        static::__assert_equals('Exception: fixture exploded on purpose', $schedule->last_error);
        static::__assert_not_null($schedule->last_error_at);
        static::__assert_null($schedule->last_success_at);
        static::__assert_equals((int) $run->id, (int) $schedule->last_task_id);
        static::__assert_greater_than(time(), strtotime($schedule->next_run_at), 'the schedule runs again at its next cadence');
        static::__assert_equals(['THROW'], Task_Exec_Fixture_Service::$run_order, 'the failing task ran exactly once');
    }

    public static function test_a_type_error_is_recorded_like_any_failure()
    {
        static::__settle_queue();
        $schedule_id = static::__schedule('raises_type_error');

        static::__run_worker();

        static::__assert_equals('TypeError: fixture type error on purpose', Task_Run_Model::where('schedule_id', $schedule_id)->value('error'));
        static::__assert_equals(1, (int) static::__schedule_row($schedule_id)->consecutive_failures);
    }

    public static function test_failures_accumulate_and_a_success_clears_the_streak()
    {
        static::__settle_queue();
        $schedule_id = static::__schedule('always_throws');

        static::__run_worker();
        static::__make_due($schedule_id);
        static::__run_worker();
        static::__assert_equals(2, (int) static::__schedule_row($schedule_id)->consecutive_failures, 'two failing runs in a row');

        DB::table('_task_schedules')->where('id', $schedule_id)->update(['method' => 'marker_a']);
        static::__make_due($schedule_id);
        static::__run_worker();

        $schedule = static::__schedule_row($schedule_id);
        static::__assert_equals(0, (int) $schedule->consecutive_failures, 'a success clears the streak');
        static::__assert_null($schedule->last_error);
        static::__assert_not_null($schedule->last_success_at);
        static::__assert_not_null($schedule->last_error_at, 'the last failure stays dated');
    }

    public static function test_each_run_of_a_schedule_is_its_own_row()
    {
        static::__settle_queue();
        $schedule_id = static::__schedule('marker_a');

        static::__run_worker();
        static::__make_due($schedule_id);
        static::__run_worker();

        $runs = Task_Run_Model::where('schedule_id', $schedule_id)->orderBy('id')->get();
        static::__assert_equals(2, $runs->count(), 'two runs, two rows');
        static::__assert_equals([Task_Run_Model::STATUS_COMPLETED, Task_Run_Model::STATUS_COMPLETED], $runs->map(fn ($r) => (int) $r->status_id)->all());
        static::__assert_equals((int) $runs->last()->id, (int) static::__schedule_row($schedule_id)->last_task_id, 'the schedule remembers its latest run');
        static::__assert_equals(['A', 'A'], Task_Exec_Fixture_Service::$run_order);
    }
}
