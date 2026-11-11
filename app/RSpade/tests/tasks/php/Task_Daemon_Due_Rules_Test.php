<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Daemon;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * The due rules the tick, the worker and the task daemon share (Task_Daemon), and the two
 * things the tick gained for the daemon:
 *
 *   - a SCHEDULE is due SCHEDULE_LEAD_SECONDS early, and advances past the run it claims - so
 *     a 3:00 schedule claimed at 2:59:56 runs once, not again at 3:00;
 *   - a run a schedule produced is due early too; a DISPATCHED run only at its time, so a
 *     short #[Debounce] delay is never cut short;
 *   - next_wake_ms(): the next schedule at its own time (never early), the next deferred run, a minute while
 *     anything runs, null when nothing waits;
 *   - the tick prints RSX_TASK_NEXT_WAKE_MS only under --_report-next-wake;
 *   - the tick reconciles the schedules from ONE read of the table and writes nothing when
 *     nothing changed.
 *
 * Workers run in-process (Artisan::call) inside the per-test transaction, after removing every
 * other live run and schedule.
 */
class Task_Daemon_Due_Rules_Test extends Rsx_Test_Abstract
{
    private static function __settle_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    private static function __at(int $seconds_from_now): string
    {
        return now()->addSeconds($seconds_from_now)->format('Y-m-d H:i:s.v');
    }

    /**
     * A schedule whose next run is $due_in_seconds away. Its cadence is hourly at the minute
     * half an hour from now, so the run AFTER the one claimed is never inside the claim window
     * (Task_Daemon::SCHEDULE_LEAD_SECONDS) whenever the test starts. An every-minute cadence
     * made the claim test depend on the wall clock: claimed in a minute's last seconds, the
     * advanced next run (the coming minute) is already due and the worker rightly takes it too.
     */
    private static function __schedule(int $due_in_seconds): int
    {
        // Read on the server timezone's wall clock, as Cron_Parser reads the expression.
        $minute = ((int) (new \DateTime('now', new \DateTimeZone(Rsx_Time::get_server_timezone())))->format('i') + 30) % 60;

        return DB::table('_task_schedules')->insertGetId([
            'class' => Task_Exec_Fixture_Service::class,
            'method' => 'marker_a',
            'cron_expression' => $minute . ' * * * *',
            'timezone' => 'UTC',
            'next_run_at' => static::__at($due_in_seconds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function __runs_of(int $schedule_id): int
    {
        return DB::table('_tasks')->where('schedule_id', $schedule_id)->count();
    }

    public static function test_a_schedule_due_in_a_moment_runs_once_and_advances_past_it()
    {
        static::__settle_queue();
        $due_at = static::__at(3);
        $schedule_id = static::__schedule(3);

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        $worker_output = Artisan::output();

        static::__assert_equals(1, static::__runs_of($schedule_id), 'a schedule 3 s away is due now (the lead); worker: ' . $worker_output);
        $next = DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at');
        static::__assert_true($next > $due_at, "it advanced past the run it claimed ({$next} after {$due_at})");

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        static::__assert_equals(1, static::__runs_of($schedule_id), 'claiming it early never runs that occurrence twice');
    }

    public static function test_a_schedule_beyond_the_lead_waits()
    {
        static::__settle_queue();
        $schedule_id = static::__schedule(Task_Daemon::SCHEDULE_LEAD_SECONDS + 30);

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

        static::__assert_equals(0, static::__runs_of($schedule_id));
    }

    public static function test_a_dispatched_run_is_due_at_its_time_and_a_scheduled_one_early()
    {
        static::__settle_queue();

        $dispatched = Task::dispatch('Task_Exec_Fixture_Service', 'marker_a', [], ['scheduled_for' => static::__at(3)]);
        $scheduled = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_b', [], Task_Run_Model::ORIGIN_SCHEDULED, ['scheduled_for' => static::__at(3)]);

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) DB::table('_tasks')->where('id', $dispatched)->value('status_id'), 'a dispatched run 3 s away is not due');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) DB::table('_tasks')->where('id', $scheduled)->value('status_id'), "a schedule's run 3 s away is due");
    }

    public static function test_the_next_wake()
    {
        static::__settle_queue();
        static::__assert_null(Task_Daemon::next_wake_ms(), 'nothing waiting: no wake');

        static::__schedule(60);
        $wake = Task_Daemon::next_wake_ms();
        static::__assert_true($wake !== null && $wake > (60 - Task_Daemon::SCHEDULE_LEAD_SECONDS) * 1000 && $wake <= 60 * 1000, "a schedule in 60 s wakes at its time, not early (got {$wake})");

        Task::dispatch('Task_Exec_Fixture_Service', 'marker_a', [], ['scheduled_for' => static::__at(20)]);
        $wake = Task_Daemon::next_wake_ms();
        static::__assert_true($wake !== null && $wake <= 20 * 1000, "a run deferred 20 s is sooner (got {$wake})");

        static::__settle_queue();
        $running = Task::dispatch('Task_Exec_Fixture_Service', 'marker_a');
        DB::table('_tasks')->where('id', $running)->update(['status_id' => Task_Run_Model::STATUS_RUNNING]);
        $wake = Task_Daemon::next_wake_ms();
        static::__assert_true($wake !== null && $wake <= Task_Daemon::BUSY_RECHECK_SECONDS * 1000, "a running run asks again within a minute (got {$wake})");
    }

    public static function test_the_tick_reports_its_next_wake_only_when_asked()
    {
        static::__settle_queue();

        Artisan::call('rsx:task:process');
        static::__assert_false(str_contains(Artisan::output(), 'RSX_TASK_NEXT_WAKE_MS='), 'cron never sees the line');

        Rsx_Internal_Flags::set('--_report-next-wake');
        try {
            Artisan::call('rsx:task:process');
        } finally {
            Rsx_Internal_Flags::clear('--_report-next-wake');
        }
        static::__assert_true((bool) preg_match('/^RSX_TASK_NEXT_WAKE_MS=(\d+|none)$/m', Artisan::output()), 'the daemon reads one line: ' . Artisan::output());
    }

    public static function test_an_unchanged_reconcile_reads_the_table_once_and_writes_nothing()
    {
        Artisan::call('rsx:task:process');   // settle: every declaration registered

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, '_task_schedules')) {
                $statements[] = $query->sql;
            }
        });

        Artisan::call('rsx:task:process');

        $reads = array_filter($statements, fn ($sql) => stripos(ltrim($sql), 'select') === 0);
        $writes = array_filter($statements, fn ($sql) => stripos(ltrim($sql), 'select') !== 0);
        static::__assert_equals([], array_values($writes), 'an unchanged tree writes no schedule row');
        // One read for the reconcile and one for "is a schedule due" - never one per schedule.
        static::__assert_true(count($reads) <= 2, count($reads) . ' reads of _task_schedules: ' . implode(' | ', $reads));
    }
}
