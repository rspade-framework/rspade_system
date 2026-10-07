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
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;

/**
 * Task_Concurrency - the per-identity coalescing primitives: policy read from
 * #[Exclusive]/#[Debounce], the canonical params hash, the identity key and its run-lock
 * name, the at-most-one-pending coalescing enqueue, the debounce delay anchored to the last
 * completion, and the run lock itself. Task::dispatch() and the inline runner over these are
 * Task_Debounce_Identity_Test. Rows roll back with the per-test transaction.
 */
class Task_Concurrency_Test extends Rsx_Test_Abstract
{
    private const SERVICE = Task_Concurrency_Fixture_Service::class;

    /** The insert callable enqueue_coalesced() is handed: write a pending dispatched row. */
    private static function __inserter(string $method, array $params = []): callable
    {
        return fn (string $scheduled_for) => Task_Runner::insert_row(self::SERVICE, $method, $params, Task_Run_Model::ORIGIN_DISPATCHED, ['scheduled_for' => $scheduled_for]);
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

    // ---- params hash and identity ----

    public static function test_params_hash_is_canonical()
    {
        static::__assert_equals(
            Task_Concurrency::params_hash(['a' => 1, 'b' => ['y' => 2, 'x' => 1]]),
            Task_Concurrency::params_hash(['b' => ['x' => 1, 'y' => 2], 'a' => 1]),
            'object key order is not part of a parameter set'
        );
        static::__assert_not_equals(
            Task_Concurrency::params_hash(['list' => [1, 2]]),
            Task_Concurrency::params_hash(['list' => [2, 1]]),
            'list order is'
        );
        static::__assert_not_equals(Task_Concurrency::params_hash(['a' => 1]), Task_Concurrency::params_hash(['a' => 2]));
        static::__assert_equals(64, strlen(Task_Concurrency::params_hash([])), 'a sha256');
    }

    public static function test_identity_key_is_the_method_for_exclusive_and_method_plus_params_for_debounce()
    {
        $hash_a = Task_Concurrency::params_hash(['a' => 1]);
        $hash_b = Task_Concurrency::params_hash(['a' => 2]);

        static::__assert_equals(self::SERVICE . '::exclusive_task', Task_Concurrency::identity_key(self::SERVICE, 'exclusive_task', $hash_a));
        static::__assert_equals(
            Task_Concurrency::identity_key(self::SERVICE, 'exclusive_task', $hash_a),
            Task_Concurrency::identity_key(self::SERVICE, 'exclusive_task', $hash_b),
            'exclusive: one identity whatever the params'
        );

        static::__assert_equals(self::SERVICE . '::debounce_task::' . $hash_a, Task_Concurrency::identity_key(self::SERVICE, 'debounce_task', $hash_a));
        static::__assert_not_equals(
            Task_Concurrency::identity_key(self::SERVICE, 'debounce_task', $hash_a),
            Task_Concurrency::identity_key(self::SERVICE, 'debounce_task', $hash_b),
            'debounce: one identity per parameter set'
        );

        static::__assert_null(Task_Concurrency::identity_key(self::SERVICE, 'plain_task', $hash_a), 'an unmanaged task has no identity');

        static::__assert_equals('rsxtask_run_' . md5('x::y'), Task_Concurrency::run_lock_name('x::y'));
    }

    // ---- coalescing enqueue ----

    public static function test_enqueue_coalesces_to_single_pending()
    {
        $hash = Task_Concurrency::params_hash([]);
        $id1 = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', $hash, static::__inserter('exclusive_task'));
        $id2 = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', $hash, static::__inserter('exclusive_task'));
        $id3 = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', $hash, static::__inserter('exclusive_task'));

        static::__assert_greater_than(0, $id1, 'first enqueue creates a pending row');
        static::__assert_equals($id1, $id2, 'second enqueue coalesces to the same row');
        static::__assert_equals($id1, $id3, 'third enqueue coalesces to the same row');
        static::__assert_equals($id1, Task_Concurrency::pending_row_id(self::SERVICE, 'exclusive_task', $hash));

        $count = DB::table('_tasks')
            ->where('class', self::SERVICE)->where('method', 'exclusive_task')
            ->where('status_id', Task_Run_Model::STATUS_PENDING)->count();
        static::__assert_equals(1, $count, 'exactly one pending row for the identity');
    }

    public static function test_unmanaged_enqueue_is_an_impossible_call()
    {
        static::__assert_throws(\RuntimeException::class, fn () => Task_Concurrency::enqueue_coalesced(self::SERVICE, 'plain_task', Task_Concurrency::params_hash([]), static::__inserter('plain_task')));
    }

    public static function test_exclusive_enqueue_is_due_now()
    {
        $id = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'exclusive_task', Task_Concurrency::params_hash([]), static::__inserter('exclusive_task'));
        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_true(strtotime($row->scheduled_for) <= time(), 'exclusive (delay 0) is due immediately');
    }

    public static function test_debounce_enqueue_respects_delay_since_last_completion()
    {
        $hash = Task_Concurrency::params_hash([]);

        // A prior completed run of this identity, finished ~now.
        Task_Runner::insert_row(self::SERVICE, 'debounce_task', [], Task_Run_Model::ORIGIN_DISPATCHED, [
            'status_id' => Task_Run_Model::STATUS_COMPLETED,
            'completed_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        $before = time();
        $id = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'debounce_task', $hash, static::__inserter('debounce_task'));
        $row = DB::table('_tasks')->where('id', $id)->first();

        // scheduled_for = last completion + 30.
        $delta = strtotime($row->scheduled_for) - $before;
        static::__assert_true($delta >= 29 && $delta <= 30, "debounce delay honored (~30s in future, got {$delta}s)");

        // Another parameter set has no completion of its own: due now.
        $other = Task_Concurrency::params_hash(['other' => true]);
        $other_id = Task_Concurrency::enqueue_coalesced(self::SERVICE, 'debounce_task', $other, static::__inserter('debounce_task', ['other' => true]));
        static::__assert_not_equals($id, $other_id, 'a second parameter set is a second pending row');
        static::__assert_true(strtotime(DB::table('_tasks')->where('id', $other_id)->value('scheduled_for')) <= time(), 'and is due now');
    }

    public static function test_reschedule_after_completion_reanchors_pending()
    {
        $hash = Task_Concurrency::params_hash([]);

        // A pending (queued) debounce run scheduled in the past...
        $pending_id = Task_Runner::insert_row(self::SERVICE, 'debounce_task', [], Task_Run_Model::ORIGIN_DISPATCHED, [
            'scheduled_for' => now()->subMinutes(5)->format('Y-m-d H:i:s.v'),
        ]);

        // ...gets re-anchored to now + delay when the current run completes.
        $before = time();
        Task_Concurrency::reschedule_pending_after_completion(self::SERVICE, 'debounce_task', $hash);

        $delta = strtotime(DB::table('_tasks')->where('id', $pending_id)->value('scheduled_for')) - $before;
        static::__assert_true($delta >= 29 && $delta <= 31, "pending re-anchored to now+30s (got {$delta}s)");
    }

    // ---- run lock ----

    public static function test_run_lock_acquire_and_release()
    {
        $hash = Task_Concurrency::params_hash([]);
        $lock = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'exclusive_task', $hash);
        static::__assert_not_null($lock, 'run-lock acquired');

        $peek = new Task_Lock(Task_Concurrency::run_lock_name(Task_Concurrency::identity_key(self::SERVICE, 'exclusive_task', $hash)), 0);
        static::__assert_true($peek->is_in_use(), 'identity lock is in use while held');

        $lock->release();
        static::__assert_false($peek->is_in_use(), 'identity lock free after release');

        static::__assert_throws(\RuntimeException::class, fn () => Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'plain_task', $hash), 'neither #[Exclusive] nor #[Debounce]');
    }
}
