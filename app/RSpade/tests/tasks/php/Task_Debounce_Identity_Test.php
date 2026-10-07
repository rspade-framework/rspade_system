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
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Lock_Holder;

/**
 * The single-instance identity, however a run starts.
 *
 *   #[Exclusive]  identity = class::method          - one job whatever the params
 *   #[Debounce]   identity = class::method + params - each parameter set on its own
 *
 * Per identity: AT MOST ONE PENDING run (dispatch() returns that pending run's id) and AT MOST
 * ONE RUNNING (the identity's run lock, taken by a worker's claim and by an inline run).
 *
 * "Running elsewhere" is staged by Task_Lock_Holder - another process holding the identity's
 * run lock - because RsxLocks is re-entrant within one process. Rows roll back with the
 * per-test transaction; the in-process worker runs inside it too, after the test has removed
 * every other pending row so the worker sees only this test's.
 */
class Task_Debounce_Identity_Test extends Rsx_Test_Abstract
{
    private const SERVICE = Task_Concurrency_Fixture_Service::class;

    /** The run lock name of an identity. */
    private static function __lock_name(string $method, array $params = []): string
    {
        return Task_Concurrency::run_lock_name(Task_Concurrency::identity_key(self::SERVICE, $method, Task_Concurrency::params_hash($params)));
    }

    private static function __pending_count(string $method): int
    {
        return DB::table('_tasks')->where('class', self::SERVICE)->where('method', $method)
            ->where('status_id', Task_Run_Model::STATUS_PENDING)->count();
    }

    // -------------------------------------------------------------------------
    // dispatch() coalesces onto the pending run of the identity
    // -------------------------------------------------------------------------

    public static function test_exclusive_coalesces_across_params()
    {
        $first = Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task', ['a' => 1]);
        $second = Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task', ['a' => 2]);

        static::__assert_equals($first, $second, 'a different parameter set is the same #[Exclusive] job');
        static::__assert_equals(1, static::__pending_count('exclusive_task'));
        static::__assert_equals(['a' => 1], Task_Run_Model::find($first)->params, 'the pending run keeps the params it was created with');
    }

    public static function test_debounce_coalesces_per_parameter_set()
    {
        $first = Task::dispatch('Task_Concurrency_Fixture_Service', 'debounce_task', ['x' => 1, 'y' => 2]);
        $same = Task::dispatch('Task_Concurrency_Fixture_Service', 'debounce_task', ['y' => 2, 'x' => 1]);
        $other = Task::dispatch('Task_Concurrency_Fixture_Service', 'debounce_task', ['x' => 9]);

        static::__assert_equals($first, $same, 'the same parameter set (in any key order) is one identity');
        static::__assert_not_equals($first, $other, 'another parameter set is another identity');
        static::__assert_equals(2, static::__pending_count('debounce_task'));
    }

    public static function test_an_unmanaged_task_never_coalesces()
    {
        $first = Task::dispatch('Task_Concurrency_Fixture_Service', 'plain_task');
        $second = Task::dispatch('Task_Concurrency_Fixture_Service', 'plain_task');

        static::__assert_not_equals($first, $second);
        static::__assert_equals(2, static::__pending_count('plain_task'));
    }

    /**
     * Once the pending run starts, the next dispatch queues the ONE follow-up run - and further
     * dispatches return that follow-up.
     */
    public static function test_at_most_one_running_and_one_pending()
    {
        $first = Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task');
        DB::table('_tasks')->where('id', $first)->update(['status_id' => Task_Run_Model::STATUS_RUNNING]);

        $follow_up = Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task');
        static::__assert_not_equals($first, $follow_up, 'a running instance does not absorb the next dispatch');
        static::__assert_equals($follow_up, Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task'), 'the follow-up absorbs the ones after it');
        static::__assert_equals(1, static::__pending_count('exclusive_task'));
    }

    // -------------------------------------------------------------------------
    // The run lock
    // -------------------------------------------------------------------------

    /**
     * The run lock follows the identity: held for one #[Debounce] parameter set, the same set
     * cannot be claimed and another set can.
     */
    public static function test_the_run_lock_is_per_identity()
    {
        $holder = Task_Lock_Holder::start(static::__lock_name('debounce_task', ['p' => 'a']), 'hold');
        try {
            static::__assert_null(
                Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'debounce_task', Task_Concurrency::params_hash(['p' => 'a'])),
                'the held parameter set is running elsewhere'
            );

            $other = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'debounce_task', Task_Concurrency::params_hash(['p' => 'b']));
            static::__assert_not_null($other, 'another parameter set is free');
            $other->release();
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    /**
     * An inline run of a managed task WAITS for the running instance: Task::internal() parks on
     * the identity's run lock, and runs only after the holder - which releases the moment it
     * sees a writer queued behind it - lets go. The run's started_at is after the release.
     */
    public static function test_an_inline_run_waits_for_the_running_instance()
    {
        $holder = Task_Lock_Holder::start(static::__lock_name('exclusive_task'), 'release_on_waiter');
        try {
            $run = Task::internal('Task_Concurrency_Fixture_Service', 'exclusive_task');

            $released_at = Task_Lock_Holder::released_at($holder);
            static::__assert_not_null($released_at, 'the holder saw the inline run queued behind it, and released');
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the inline run then ran');
            $started_ms = (int) round((float) \Illuminate\Support\Carbon::parse($run->started_at)->format('U.v') * 1000);
            static::__assert_true(
                $started_ms >= (int) floor($released_at * 1000),
                "the run started ({$run->started_at}) after the holder released (" . date('Y-m-d H:i:s', (int) $released_at) . ')'
            );
        } finally {
            Task_Lock_Holder::stop($holder);
        }
    }

    /**
     * A worker sets aside a pending run whose identity is running elsewhere - it stays PENDING
     * and nothing runs - and claims it once the identity is free.
     */
    public static function test_a_worker_skips_an_identity_running_elsewhere()
    {
        DB::table('_tasks')->where('status_id', Task_Run_Model::STATUS_PENDING)->delete();
        $id = Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task');

        $holder = Task_Lock_Holder::start(static::__lock_name('exclusive_task'), 'hold');
        try {
            Artisan::call('rsx:task:worker');
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) Task_Run_Model::find($id)->status_id, 'set aside while the identity runs elsewhere');
        } finally {
            Task_Lock_Holder::release($holder);
        }

        Artisan::call('rsx:task:worker');
        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'claimed and run once the identity is free');
        static::__assert_equals(['ran' => 'exclusive'], $run->state());
    }
}
