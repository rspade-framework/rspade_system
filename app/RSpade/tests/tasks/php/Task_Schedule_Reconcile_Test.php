<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The #[Schedule] reconciliation in rsx:task:process
 * (App\RSpade\Commands\Rsx\Task_Process_Command::reconcile_schedules()): each tick brings
 * _task_schedules - one row per declared schedule - in line with the manifest.
 *
 *   - REGISTER : a declaration with no row gets one, next_run_at its next cadence;
 *   - CHANGE   : a row whose cron_expression, resolution (cron_resolved - a moved
 *                rsx.tasks.maintenance_hour) or timezone differs is re-registered IN
 *                PLACE with a recomputed next_run_at, the run statistics kept; a row
 *                with no resolution recorded yet has it recorded and keeps next_run_at;
 *   - MATCH    : a matching row is left untouched;
 *   - REMOVE   : a row the manifest no longer declares is deleted; its past runs keep their
 *                rows, schedule_id cleared;
 *   - --force-scheduled makes every schedule due now.
 *
 * Driven with Artisan::call('rsx:task:process'); under the suite the tick spawns no worker.
 * The tick COMMITS, so this class provisions a clean baseline and opts out of transactions;
 * every test normalizes the state it reads first.
 */
class Task_Schedule_Reconcile_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private static function __first_scheduled_def(): array
    {
        $defs = Task::get_scheduled_tasks();
        if (empty($defs)) {
            static::__skip('No scheduled tasks in the manifest to reconcile');
        }

        return $defs[0];
    }

    private static function __schedule_for(string $class, string $method): ?object
    {
        return DB::table('_task_schedules')->where('class', $class)->where('method', $method)->first();
    }

    public static function test_reconcile_registers_every_declared_schedule()
    {
        DB::table('_task_schedules')->delete();

        Artisan::call('rsx:task:process');

        $defs = Task::get_scheduled_tasks();
        static::__assert_equals(count($defs), DB::table('_task_schedules')->count(), 'one row per declaration');

        foreach ($defs as $def) {
            $schedule = static::__schedule_for($def['class'], $def['method']);
            static::__assert_not_null($schedule, "{$def['class']}::{$def['method']} is registered");
            static::__assert_equals($def['cron_expression'], $schedule->cron_expression);
            static::__assert_greater_than(time() - 1, strtotime($schedule->next_run_at), 'next_run_at is the next cadence, never the past');
            static::__assert_equals(0, (int) $schedule->consecutive_failures);
        }
    }

    public static function test_a_changed_expression_is_reregistered_in_place_keeping_its_statistics()
    {
        $def = static::__first_scheduled_def();
        Artisan::call('rsx:task:process');
        $before = static::__schedule_for($def['class'], $def['method']);

        $wrong_expression = $def['cron_expression'] === '0 0 29 2 *' ? '17 4 1 1 *' : '0 0 29 2 *';
        DB::table('_task_schedules')->where('id', $before->id)->update([
            'cron_expression' => $wrong_expression,
            'next_run_at' => date('Y-m-d H:i:s', time() + 86400 * 365),
            'consecutive_failures' => 2,
            'last_error' => 'kept across the change',
        ]);

        $parser = new Cron_Parser($def['cron_expression']);
        $earliest = $parser->get_next_run_time();
        Artisan::call('rsx:task:process');
        $latest = $parser->get_next_run_time();

        $after = static::__schedule_for($def['class'], $def['method']);
        static::__assert_equals((int) $before->id, (int) $after->id, 'the same row');
        static::__assert_equals($def['cron_expression'], $after->cron_expression, 'the manifest expression is restored');
        $next = strtotime($after->next_run_at);
        static::__assert_true($next >= $earliest && $next <= $latest, "next_run_at {$after->next_run_at} is recomputed from the expression");
        static::__assert_equals(2, (int) $after->consecutive_failures, 'statistics are kept');
        static::__assert_equals('kept across the change', $after->last_error);
    }

    public static function test_a_matching_schedule_is_left_untouched()
    {
        $def = static::__first_scheduled_def();
        Artisan::call('rsx:task:process');
        $before = static::__schedule_for($def['class'], $def['method']);

        Artisan::call('rsx:task:process');

        $after = static::__schedule_for($def['class'], $def['method']);
        static::__assert_equals((int) $before->id, (int) $after->id);
        static::__assert_equals($before->next_run_at, $after->next_run_at, 'next_run_at is not moved');
    }

    public static function test_a_changed_server_timezone_is_reregistered_in_place()
    {
        $def = static::__first_scheduled_def();
        Artisan::call('rsx:task:process');
        $before = static::__schedule_for($def['class'], $def['method']);
        static::__assert_equals(Rsx_Time::get_server_timezone(), $before->timezone, 'the row records the zone it was computed in');

        $zone = $before->timezone === 'America/Chicago' ? 'Asia/Tokyo' : 'America/Chicago';
        $original = config('rsx.datetime.server_timezone');
        config(['rsx.datetime.server_timezone' => $zone]);
        try {
            $parser = new Cron_Parser($def['cron_expression'], $zone);
            $earliest = $parser->get_next_run_time();
            Artisan::call('rsx:task:process');
            $latest = $parser->get_next_run_time();

            $after = static::__schedule_for($def['class'], $def['method']);
            static::__assert_equals((int) $before->id, (int) $after->id, 'the same row');
            static::__assert_equals($zone, $after->timezone, 'the new zone is recorded');
            $next = strtotime($after->next_run_at);
            static::__assert_true($next >= $earliest && $next <= $latest, "next_run_at {$after->next_run_at} is recomputed on the {$zone} wall clock");
        } finally {
            config(['rsx.datetime.server_timezone' => $original]);
            Artisan::call('rsx:task:process');
        }
    }

    public static function test_a_moved_maintenance_hour_is_reregistered_in_place()
    {
        $maintenance = null;
        foreach (Task::get_scheduled_tasks() as $def) {
            if ($def['cron_expression'] === 'maintenance') {
                $maintenance = $def;
                break;
            }
        }
        if ($maintenance === null) {
            static::__skip("No #[Schedule('maintenance')] task in the manifest");
        }

        Artisan::call('rsx:task:process');
        $before = static::__schedule_for($maintenance['class'], $maintenance['method']);
        static::__assert_equals((new Cron_Parser('maintenance'))->get_cron_expression(), $before->cron_resolved, 'the row records its resolution');

        $original = config('rsx.tasks.maintenance_hour');
        $hour = $original === 5 ? 6 : 5;
        config(['rsx.tasks.maintenance_hour' => $hour]);
        try {
            Artisan::call('rsx:task:process');

            $after = static::__schedule_for($maintenance['class'], $maintenance['method']);
            static::__assert_equals((int) $before->id, (int) $after->id, 'the same row');
            static::__assert_equals('maintenance', $after->cron_expression, 'the declaration is unchanged');
            static::__assert_equals("0 {$hour} * * *", $after->cron_resolved, 'the new resolution is recorded');
            $next = (new \DateTime('@' . strtotime($after->next_run_at)))->setTimezone(new \DateTimeZone($after->timezone));
            static::__assert_equals(sprintf('%02d:00', $hour), $next->format('H:i'), 'next_run_at moved to the new hour');
        } finally {
            config(['rsx.tasks.maintenance_hour' => $original]);
            Artisan::call('rsx:task:process');
        }
    }

    public static function test_a_row_with_no_resolution_recorded_has_it_recorded_and_keeps_its_next_run()
    {
        $def = static::__first_scheduled_def();
        Artisan::call('rsx:task:process');
        $before = static::__schedule_for($def['class'], $def['method']);

        DB::table('_task_schedules')->where('id', $before->id)->update(['cron_resolved' => null]);
        Artisan::call('rsx:task:process');

        $after = static::__schedule_for($def['class'], $def['method']);
        static::__assert_equals((new Cron_Parser($def['cron_expression']))->get_cron_expression(), $after->cron_resolved);
        static::__assert_equals($before->next_run_at, $after->next_run_at, 'next_run_at is not moved');
    }

    public static function test_a_schedule_no_longer_declared_is_removed_and_its_runs_kept()
    {
        $schedule_id = DB::table('_task_schedules')->insertGetId([
            'class' => 'App\\Nowhere\\Ghost_Reconcile_Probe_Service',
            'method' => 'ghost',
            'cron_expression' => '0 3 * * *',
            'timezone' => 'UTC',
            'next_run_at' => date('Y-m-d H:i:s', time() + 86400),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $past_run = Task_Runner::insert_row('App\\Nowhere\\Ghost_Reconcile_Probe_Service', 'ghost', [], Task_Run_Model::ORIGIN_SCHEDULED, [
            'status_id' => Task_Run_Model::STATUS_COMPLETED,
            'schedule_id' => $schedule_id,
            'completed_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        Artisan::call('rsx:task:process');

        static::__assert_null(DB::table('_task_schedules')->where('id', $schedule_id)->first(), 'the undeclared schedule is removed');
        $run = Task_Run_Model::find($past_run);
        static::__assert_not_null($run, 'its past run is history and is kept');
        static::__assert_null($run->schedule_id, 'with the schedule cleared');

        DB::table('_tasks')->where('id', $past_run)->delete();
    }

    public static function test_force_scheduled_makes_every_schedule_due_now()
    {
        Artisan::call('rsx:task:process');
        static::__assert_equals(0, DB::table('_task_schedules')->where('next_run_at', '<=', now())->count(), 'fixture: nothing is due');

        $before = time();
        Artisan::call('rsx:task:process', ['--force-scheduled' => true]);

        $latest = DB::table('_task_schedules')->max('next_run_at');
        static::__assert_true(strtotime($latest) <= time() && strtotime($latest) >= $before - 1, 'every schedule is due now');

        // Put the cadences back for whatever runs next.
        DB::table('_task_schedules')->delete();
        Artisan::call('rsx:task:process');
    }
}
