<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Lock_Holder;

/**
 * #[Exclusive('group')] - several task methods serialized against each other.
 *
 *   RUNNING  one across the group: every member shares the group's run lock.
 *   PENDING  one per method: a member queued behind another is never replaced by a third.
 *
 * A pending run whose group is busy is passed over by a worker (it holds no worker slot
 * while it waits) and is claimed once the group is free. A schedule tick held back only by
 * another member of the group is queued as that method's pending run rather than dropped.
 *
 * "The group is running elsewhere" is staged by Task_Lock_Holder - another process holding
 * the group's run lock - because RsxLocks is re-entrant within one process. Rows roll back
 * with the per-test transaction; the in-process worker runs inside it too, after the test has
 * removed every other live row so the worker sees only this test's.
 */
class Task_Exclusive_Group_Test extends Rsx_Test_Abstract
{
    private const SERVICE = Task_Concurrency_Fixture_Service::class;
    private const GROUP = 'fixture_group';

    private static function __group_lock_name(): string
    {
        return Task_Concurrency::run_lock_name('group::' . self::GROUP);
    }

    private static function __clear_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
    }

    private static function __status(int $id): int
    {
        return (int) Task_Run_Model::find($id)->status_id;
    }

    // -------------------------------------------------------------------------
    // Declaration
    // -------------------------------------------------------------------------

    public static function test_the_group_is_read_from_the_attribute()
    {
        static::__assert_equals([self::GROUP], Task_Concurrency::groups_of(self::SERVICE, 'group_alpha'));
        static::__assert_equals([self::GROUP], Task_Concurrency::groups_of(self::SERVICE, 'group_beta'));
        static::__assert_equals('exclusive', Task_Concurrency::get_policy(self::SERVICE, 'group_alpha')['mode']);

        static::__assert_equals([], Task_Concurrency::groups_of(self::SERVICE, 'exclusive_task'), 'a bare #[Exclusive] has no group');
        static::__assert_equals([], Task_Concurrency::groups_of(self::SERVICE, 'debounce_task'));
        static::__assert_equals([], Task_Concurrency::groups_of(self::SERVICE, 'plain_task'));
    }

    // -------------------------------------------------------------------------
    // One running across the group
    // -------------------------------------------------------------------------

    public static function test_every_member_shares_the_groups_run_lock()
    {
        $hash = Task_Concurrency::params_hash([]);

        static::__assert_false(Task_Concurrency::is_group_running(self::GROUP), 'free before anything runs');

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            static::__assert_true(Task_Concurrency::is_group_running(self::GROUP), 'asked without acquiring anything');
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'group_alpha', $hash), 'alpha cannot run');
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'group_beta', $hash), 'nor can beta');

            $outside = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'exclusive_task', $hash);
            static::__assert_not_null($outside, 'a task outside the group is unaffected');
            $outside->release();
        } finally {
            Task_Lock_Holder::release($holder);
        }

        static::__assert_false(Task_Concurrency::is_group_running(self::GROUP), 'free again once the holder lets go');

        $lock = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'group_beta', $hash);
        static::__assert_not_null($lock, 'and a member can take it');
        $lock->release();
    }

    // -------------------------------------------------------------------------
    // One pending per method
    // -------------------------------------------------------------------------

    public static function test_each_member_keeps_its_own_pending_run()
    {
        static::__clear_queue();

        $alpha = Task::dispatch('Task_Concurrency_Fixture_Service', 'group_alpha');
        $beta = Task::dispatch('Task_Concurrency_Fixture_Service', 'group_beta');

        static::__assert_not_equals($alpha, $beta, 'one member queued behind another is not replaced');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($alpha));
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($beta));

        static::__assert_equals($alpha, Task::dispatch('Task_Concurrency_Fixture_Service', 'group_alpha'), 'a repeat of the same method is still coalesced');
    }

    // -------------------------------------------------------------------------
    // The worker
    // -------------------------------------------------------------------------

    public static function test_a_worker_passes_over_a_busy_group_and_runs_everything_else()
    {
        static::__clear_queue();

        $alpha = Task::dispatch('Task_Concurrency_Fixture_Service', 'group_alpha');
        $beta = Task::dispatch('Task_Concurrency_Fixture_Service', 'group_beta');
        $plain = Task::dispatch('Task_Concurrency_Fixture_Service', 'plain_task');

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            Artisan::call('rsx:task:worker');

            static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($alpha), 'alpha waits for the group');
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($beta), 'so does beta');
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($plain), 'the unrelated task ran in the same pass');
        } finally {
            Task_Lock_Holder::release($holder);
        }

        Artisan::call('rsx:task:worker');

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($alpha), 'alpha runs once the group is free');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($beta), 'and beta after it, by the same worker');

        $alpha_run = Task_Run_Model::find($alpha);
        $beta_run = Task_Run_Model::find($beta);
        static::__assert_true(
            strtotime((string) $beta_run->started_at) >= strtotime((string) $alpha_run->completed_at),
            'beta started only after alpha had finished'
        );
    }

    // -------------------------------------------------------------------------
    // Schedules
    // -------------------------------------------------------------------------

    public static function test_a_schedule_tick_held_back_by_another_member_is_queued_not_dropped()
    {
        static::__clear_queue();

        $schedule_id = DB::table('_task_schedules')->insertGetId([
            'class' => self::SERVICE,
            'method' => 'group_beta',
            'cron_expression' => 'daily at 3am',
            'timezone' => 'UTC',
            'next_run_at' => date('Y-m-d H:i:s', time() - 60) . '.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

            $queued = DB::table('_tasks')->where('class', self::SERVICE)->where('method', 'group_beta')->get();
            static::__assert_count(1, $queued, 'the tick became one pending run');
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $queued[0]->status_id);
            static::__assert_equals($schedule_id, (int) $queued[0]->schedule_id, 'filed under its schedule');
            static::__assert_equals(Task_Run_Model::ORIGIN_SCHEDULED, (int) $queued[0]->origin_id);

            $next = DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at');
            static::__assert_true(strtotime((string) $next) > time(), 'and the cadence moved on');
        } finally {
            Task_Lock_Holder::release($holder);
        }

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

        static::__assert_equals(
            Task_Run_Model::STATUS_COMPLETED,
            (int) DB::table('_tasks')->where('class', self::SERVICE)->where('method', 'group_beta')->value('status_id'),
            'it runs once the group is free'
        );
    }
}
