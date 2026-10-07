<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use App\RSpade\Commands\Rsx\Task_Worker_Command;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Lock_Holder;

/**
 * The pools and what each one claims.
 *
 *   on_demand   dispatched work only;
 *   scheduled   due dispatched work FIRST, then due #[Schedule]s - creating each schedule's run
 *               row (origin Scheduled) and advancing its next_run_at in one transaction;
 *   kill        the kill workers (Task_Kill_Worker_Test).
 *
 * Each pool's cap is rsx.tasks.pools.<pool>.max_workers, an integer of at least 1.
 *
 * Workers run in-process (Artisan::call) inside the per-test transaction, after the test has
 * removed every pending run and schedule so the worker sees only this test's. Schedules are
 * written directly, pointed at the exec fixture with a cadence far from now, so a run never
 * comes due twice inside one worker loop.
 */
class Task_Pools_Test extends Rsx_Test_Abstract
{
    private const SLOW_CRON = 'daily at 3am';

    private static function __settle_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    /** A schedule row that came due an hour ago. */
    private static function __due_schedule(string $class, string $method): int
    {
        return DB::table('_task_schedules')->insertGetId([
            'class' => $class,
            'method' => $method,
            'cron_expression' => self::SLOW_CRON,
            'next_run_at' => date('Y-m-d H:i:s', time() - 3600) . '.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function __pending(string $method, array $fields = []): int
    {
        return Task_Runner::insert_row(Task_Exec_Fixture_Service::class, $method, [], Task_Run_Model::ORIGIN_DISPATCHED, $fields);
    }

    // -------------------------------------------------------------------------
    // The caps
    // -------------------------------------------------------------------------

    public static function test_max_workers_reads_each_pools_cap()
    {
        $original = config('rsx.tasks.pools');
        try {
            config(['rsx.tasks.pools.on_demand.max_workers' => 4, 'rsx.tasks.pools.scheduled.max_workers' => '2', 'rsx.tasks.pools.kill.max_workers' => 1]);

            static::__assert_equals(4, Task_Pool::max_workers(Task_Pool::ON_DEMAND));
            static::__assert_equals(2, Task_Pool::max_workers(Task_Pool::SCHEDULED), 'an integer string is an integer');
            static::__assert_equals(1, Task_Pool::max_workers(Task_Pool::KILL));
        } finally {
            config(['rsx.tasks.pools' => $original]);
        }

    }

    public static function test_max_workers_refuses_a_cap_below_one()
    {
        $original = config('rsx.tasks.pools');
        try {
            foreach ([0, -1, 1.5, null, 'three'] as $bad) {
                config(['rsx.tasks.pools.on_demand.max_workers' => $bad]);
                static::__assert_throws(
                    \RuntimeException::class,
                    fn () => Task_Pool::max_workers(Task_Pool::ON_DEMAND),
                    'rsx.tasks.pools.on_demand.max_workers must be an integer of at least 1'
                );
            }
        } finally {
            config(['rsx.tasks.pools' => $original]);
        }

        static::__assert_throws(\RuntimeException::class, fn () => Task_Pool::max_workers('nightly'), "Unknown task pool 'nightly'");
    }

    public static function test_a_worker_joins_only_a_task_pool()
    {
        static::__assert_equals(1, Artisan::call('rsx:task:worker', ['--pool' => 'kill']));
        static::__assert_contains('--pool must be on_demand or scheduled', Artisan::output());
    }

    // -------------------------------------------------------------------------
    // What each pool claims
    // -------------------------------------------------------------------------

    /**
     * The scheduled pool runs due dispatched work before a due schedule - whatever their order
     * of insertion or due time - and records which pool ran each.
     */
    public static function test_the_scheduled_pool_takes_dispatched_work_before_a_schedule()
    {
        static::__settle_queue();
        $schedule_id = static::__due_schedule(Task_Exec_Fixture_Service::class, 'marker_a');
        $dispatched = static::__pending('marker_b');

        $parser = new Cron_Parser(self::SLOW_CRON);
        $earliest = $parser->get_next_run_time();
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        $latest = $parser->get_next_run_time();

        static::__assert_equals(['B', 'A'], Task_Exec_Fixture_Service::$run_order, 'dispatched work first');

        $dispatched_run = Task_Run_Model::find($dispatched);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $dispatched_run->status_id);
        static::__assert_equals(Task_Run_Model::POOL_SCHEDULED, (int) $dispatched_run->pool_id, 'run by the scheduled pool');

        $scheduled_run = Task_Run_Model::where('schedule_id', $schedule_id)->first();
        static::__assert_not_null($scheduled_run, 'the schedule\'s run is a row of its own');
        static::__assert_equals(Task_Run_Model::ORIGIN_SCHEDULED, (int) $scheduled_run->origin_id);
        static::__assert_equals(Task_Run_Model::POOL_SCHEDULED, (int) $scheduled_run->pool_id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $scheduled_run->status_id);
        static::__assert_not_null($scheduled_run->worker_id, 'claimed under the worker\'s pool identity');
        static::__assert_equals(['marker' => 'A'], $scheduled_run->state());

        $schedule = DB::table('_task_schedules')->where('id', $schedule_id)->first();
        $next = strtotime($schedule->next_run_at);
        static::__assert_true($next >= $earliest && $next <= $latest, "next_run_at {$schedule->next_run_at} is the next cadence after now");
        static::__assert_equals((int) $scheduled_run->id, (int) $schedule->last_task_id);
        static::__assert_not_null($schedule->last_success_at);
        static::__assert_equals(0, (int) $schedule->consecutive_failures);
    }

    public static function test_the_on_demand_pool_never_runs_a_schedule()
    {
        static::__settle_queue();
        $schedule_id = static::__due_schedule(Task_Exec_Fixture_Service::class, 'marker_a');
        $before = DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at');
        $dispatched = static::__pending('marker_b');

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

        static::__assert_equals(['B'], Task_Exec_Fixture_Service::$run_order);
        static::__assert_equals(Task_Run_Model::POOL_ON_DEMAND, (int) Task_Run_Model::find($dispatched)->pool_id);
        static::__assert_equals(0, Task_Run_Model::where('schedule_id', $schedule_id)->count(), 'no schedule run');
        static::__assert_equals($before, DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at'), 'the schedule is still due');
    }

    /**
     * A worker claims a pending run only when it is due and still PENDING, and each run once:
     * a second pool's worker finds nothing left.
     */
    public static function test_a_worker_claims_each_due_pending_run_once()
    {
        static::__settle_queue();
        $due = static::__pending('marker_a');
        $later = static::__pending('marker_b', ['scheduled_for' => date('Y-m-d H:i:s', time() + 3600) . '.000']);
        $cancelled = static::__pending('marker_b');
        Task_Run_Model::find($cancelled)->cancel();

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

        static::__assert_equals(['A'], Task_Exec_Fixture_Service::$run_order, 'the due run ran exactly once');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) Task_Run_Model::find($due)->status_id);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) Task_Run_Model::find($later)->status_id, 'a run not yet due waits');
        static::__assert_equals(Task_Run_Model::STATUS_CANCELLED, (int) Task_Run_Model::find($cancelled)->status_id, 'a cancelled run never runs');
    }

    /**
     * A due schedule whose identity is already running (an #[Exclusive] task's on-demand run)
     * has this tick coalesced into that run: next_run_at advances and no run is created.
     */
    public static function test_a_schedule_whose_identity_is_running_is_coalesced()
    {
        static::__settle_queue();
        $schedule_id = static::__due_schedule(Task_Concurrency_Fixture_Service::class, 'exclusive_task');
        $lock_name = Task_Concurrency::run_lock_name(Task_Concurrency::identity_key(Task_Concurrency_Fixture_Service::class, 'exclusive_task', Task_Concurrency::params_hash([])));

        $holder = Task_Lock_Holder::start($lock_name, 'hold');
        try {
            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        } finally {
            Task_Lock_Holder::release($holder);
        }

        static::__assert_equals(0, Task_Run_Model::where('schedule_id', $schedule_id)->count(), 'nothing ran');
        static::__assert_greater_than(time(), strtotime(DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at')), 'the tick was spent');
    }

    // -------------------------------------------------------------------------
    // A claim the worker could not run
    // -------------------------------------------------------------------------

    /**
     * A worker that lost its pool connection between the claim and the run hands the claim
     * back: a dispatched run returns to PENDING with started_at and the worker columns cleared;
     * a scheduled run (created by the claim) is deleted; a run the reaper already settled is
     * left as it is. (The loss itself cannot be staged in-process, so the hand-back is driven
     * directly.)
     */
    public static function test_a_claim_lost_before_the_run_is_handed_back()
    {
        $identity = ['wid' => 31, 'generation' => Task_Pool::stats(Task_Pool::ON_DEMAND)['generation']];
        $claim = Task_Runner::running_fields() + ['worker_id' => $identity['wid'], 'worker_generation' => $identity['generation'], 'pool_id' => Task_Run_Model::POOL_ON_DEMAND];

        $dispatched = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, $claim);
        $output = static::__release_unrun_claim($dispatched, $identity);

        $row = Task_Run_Model::find($dispatched);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id);
        foreach (['started_at', 'pool_id', 'worker_pid', 'worker_id', 'worker_generation', 'worker_host'] as $column) {
            static::__assert_null($row->{$column}, "{$column} cleared");
        }
        static::__assert_contains("claiming task {$dispatched}", $output);
        static::__assert_contains('the claim was handed back', $output);

        $scheduled = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_SCHEDULED, $claim);
        static::__release_unrun_claim($scheduled, $identity);
        static::__assert_null(Task_Run_Model::find($scheduled), 'a scheduled run created by the claim is removed');

        $settled = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, $claim);
        DB::table('_tasks')->where('id', $settled)->update(['status_id' => Task_Run_Model::STATUS_FAILED]);
        $output = static::__release_unrun_claim($settled, $identity);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) Task_Run_Model::find($settled)->status_id);
        static::__assert_contains('already settled by the reaper', $output);
    }

    /** Drive Task_Worker_Command::release_unrun_claim() and return what it printed. */
    private static function __release_unrun_claim(int $task_id, array $identity): string
    {
        $buffer = new BufferedOutput();
        $command = new Task_Worker_Command();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        (new \ReflectionMethod(Task_Worker_Command::class, 'release_unrun_claim'))
            ->invoke($command, $task_id, null, $identity, 'test: connection lost');

        return $buffer->fetch();
    }
}
