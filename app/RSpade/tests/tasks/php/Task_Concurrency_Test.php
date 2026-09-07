<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Task_Concurrency_Test - the per-identity coalescing model: policy reading from
 * #[Exclusive]/#[Debounce] attributes, at-most-one-pending coalescing enqueue, the
 * debounce delay anchored to last completion, and the run-lock / re-schedule
 * primitives. (Cross-process run-lock exclusivity is the MySQL GET_LOCK guarantee
 * the queue already relies on; here we assert the single-connection surface.)
 */
class Task_Concurrency_Test extends Rsx_Test_Abstract
{
    private const SERVICE = 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Concurrency_Fixture_Service';

    public static function teardown(): void
    {
        // GET_LOCK is connection-scoped, not transactional - free any test lock.
        DB::selectOne("SELECT RELEASE_ALL_LOCKS()");
    }

    // ---- policy ----

    public static function test_policy_reads_attributes()
    {
        static::__assert_equals('exclusive', Task_Concurrency::get_policy(self::SERVICE, 'exclusive_task')['mode']);
        static::__assert_equals(0, Task_Concurrency::get_policy(self::SERVICE, 'exclusive_task')['delay']);

        $debounce = Task_Concurrency::get_policy(self::SERVICE, 'debounce_task');
        static::__assert_equals('debounce', $debounce['mode']);
        static::__assert_equals(30, $debounce['delay']);

        static::__assert_null(Task_Concurrency::get_policy(self::SERVICE, 'plain_task')['mode']);

        static::__assert_true(Task_Concurrency::is_managed(self::SERVICE, 'exclusive_task'));
        static::__assert_true(Task_Concurrency::is_managed(self::SERVICE, 'debounce_task'));
        static::__assert_false(Task_Concurrency::is_managed(self::SERVICE, 'plain_task'));
    }

    // ---- coalescing enqueue ----

    public static function test_enqueue_coalesces_to_single_pending()
    {
        $id1 = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', [], 'default');
        $id2 = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', [], 'default');
        $id3 = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', [], 'default');

        static::__assert_not_null($id1, 'first enqueue creates a pending row');
        static::__assert_equals($id1, $id2, 'second enqueue coalesces to the same row');
        static::__assert_equals($id1, $id3, 'third enqueue coalesces to the same row');

        $count = DB::table('_tasks')
            ->where('class', self::SERVICE)->where('method', 'exclusive_task')
            ->where('status', Task_Status::PENDING)->whereNull('next_run_at')->count();
        static::__assert_equals(1, $count, 'exactly one pending row for the identity');
    }

    public static function test_exclusive_enqueue_is_due_now()
    {
        $id = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', [], 'default');
        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_true(strtotime($row->scheduled_for) <= time() + 1, 'exclusive (delay 0) is due immediately');
        static::__assert_equals(Task_Concurrency::run_lock_name(self::SERVICE, 'exclusive_task'), $row->lock_key, 'identity lock key stored');
    }

    public static function test_debounce_enqueue_respects_delay_since_last_completion()
    {
        // A prior completed run of this identity, finished ~now.
        DB::table('_tasks')->insert([
            'class' => self::SERVICE, 'method' => 'debounce_task', 'queue' => 'default',
            'status' => Task_Status::COMPLETED, 'params' => '[]',
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'debounce_task', [], 'default');
        $row = DB::table('_tasks')->where('id', $id)->first();

        // scheduled_for should be ~30s in the future (last completion + 30).
        $delta = strtotime($row->scheduled_for) - time();
        static::__assert_true($delta > 20 && $delta <= 31, "debounce delay honored (~30s in future, got {$delta}s)");
    }

    public static function test_reschedule_after_completion_reanchors_pending()
    {
        // A pending (queued) debounce run scheduled in the past...
        $pending_id = DB::table('_tasks')->insertGetId([
            'class' => self::SERVICE, 'method' => 'debounce_task', 'queue' => 'default',
            'status' => Task_Status::PENDING, 'params' => '[]',
            'scheduled_for' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ...gets re-anchored to now + delay when the current run completes.
        Task_Concurrency::reschedule_pending_after_completion(self::SERVICE, 'debounce_task', 'default');

        $row = DB::table('_tasks')->where('id', $pending_id)->first();
        $delta = strtotime($row->scheduled_for) - time();
        static::__assert_true($delta > 20 && $delta <= 31, "pending re-anchored to now+30s (got {$delta}s)");
    }

    // ---- run lock ----

    public static function test_run_lock_acquire_and_release()
    {
        $lock = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'exclusive_task');
        static::__assert_not_null($lock, 'run-lock acquired');

        $peek = new Task_Lock(Task_Concurrency::run_lock_name(self::SERVICE, 'exclusive_task'), 0);
        static::__assert_true($peek->is_in_use(), 'identity lock is in use while held');

        $lock->release();
        static::__assert_false($peek->is_in_use(), 'identity lock free after release');
    }
}
