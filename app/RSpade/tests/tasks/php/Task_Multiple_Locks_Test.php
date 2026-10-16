<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\CodeQuality\Rules\PHP\TaskConcurrencyAttributes_CodeQualityRule;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Task\Task_Waiting;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Lock_Holder;

/**
 * A task naming SEVERAL exclusive groups - #[Exclusive('a')] #[Exclusive('b')] - runs only
 * while it holds every group's lock, taken all at once or not at all.
 *
 *   - a run that cannot have them all holds none of them while it waits, so a run needing one
 *     of those locks is never blocked by it;
 *   - nothing reserves a lock for a waiting run: a younger run whose lock is free goes ahead
 *     of an older run needing two, and the older one runs in the first window where both are
 *     free;
 *   - a debounced run that is due only waits for its locks, and a further call joins it
 *     without moving its due time.
 *
 * "A lock is held elsewhere" is staged by Task_Lock_Holder - another process - because
 * RsxLocks is re-entrant within one process. Rows roll back with the per-test transaction.
 */
class Task_Multiple_Locks_Test extends Rsx_Test_Abstract
{
    private const SERVICE = Task_Concurrency_Fixture_Service::class;
    private const SERVICE_NAME = 'Task_Concurrency_Fixture_Service';

    private static function __lock_name(string $group): string
    {
        return Task_Concurrency::run_lock_name(Task_Concurrency::group_identity($group));
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

    public static function test_every_group_is_read_from_the_repeated_attribute()
    {
        static::__assert_equals(['fixture_lock_a', 'fixture_lock_b'], Task_Concurrency::groups_of(self::SERVICE, 'lock_a_and_b'));
        static::__assert_equals(
            ['group::fixture_lock_a', 'group::fixture_lock_b'],
            Task_Concurrency::run_lock_identities(self::SERVICE, 'lock_a_and_b', Task_Concurrency::params_hash([]))
        );
        static::__assert_equals(['fixture_lock_a'], Task_Concurrency::groups_of(self::SERVICE, 'lock_a_only'));
    }

    public static function test_the_locks_are_taken_all_at_once_or_not_at_all()
    {
        $hash = Task_Concurrency::params_hash([]);

        $holder = Task_Lock_Holder::start(static::__lock_name('fixture_lock_a'), 'hold');
        try {
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'lock_a_and_b', $hash), 'a is held elsewhere, so the pair is refused');
            static::__assert_false(Task_Concurrency::is_group_running('fixture_lock_b'), 'and b was not left held by the refused attempt');

            $b_only = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'lock_b_only', $hash);
            static::__assert_not_null($b_only, 'a run needing only b is not blocked by the waiting pair');
            $b_only->release();
        } finally {
            Task_Lock_Holder::release($holder);
        }

        $both = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'lock_a_and_b', $hash);
        static::__assert_not_null($both, 'with both free the pair is taken');
        static::__assert_true(Task_Concurrency::is_group_running('fixture_lock_a'));
        static::__assert_true(Task_Concurrency::is_group_running('fixture_lock_b'));
        $both->release();
        static::__assert_false(Task_Concurrency::is_group_running('fixture_lock_a'), 'releasing the set releases both');
        static::__assert_false(Task_Concurrency::is_group_running('fixture_lock_b'));
    }

    public static function test_a_younger_run_with_a_free_lock_goes_ahead_and_the_older_runs_in_the_next_window()
    {
        static::__clear_queue();

        $pair = Task::dispatch(self::SERVICE_NAME, 'lock_a_and_b');
        DB::table('_tasks')->where('id', $pair)->update(['scheduled_for' => now()->subMinutes(10)]);
        $b_only = Task::dispatch(self::SERVICE_NAME, 'lock_b_only');

        $holder = Task_Lock_Holder::start(static::__lock_name('fixture_lock_a'), 'hold');
        try {
            Artisan::call('rsx:task:worker');

            static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($pair), 'the overdue pair waits while a is held');
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($b_only), 'the younger run needing only b ran');
        } finally {
            Task_Lock_Holder::release($holder);
        }

        Artisan::call('rsx:task:worker');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($pair), 'the pair runs once both locks are free');
    }

    public static function test_a_waiting_run_names_every_held_lock()
    {
        static::__clear_queue();

        $pair = Task::dispatch(self::SERVICE_NAME, 'lock_a_and_b');

        $holder_a = Task_Lock_Holder::start(static::__lock_name('fixture_lock_a'), 'hold');
        $holder_b = Task_Lock_Holder::start(static::__lock_name('fixture_lock_b'), 'hold');
        try {
            $waiting = (new Task_Waiting())->describe(Task_Run_Model::find($pair));
            static::__assert_equals(Task_Waiting::REASON_LOCK, $waiting['reason']);
            static::__assert_equals('waiting for exclusive locks "fixture_lock_a", "fixture_lock_b"', $waiting['text']);
        } finally {
            Task_Lock_Holder::release($holder_b);
        }

        try {
            $waiting = (new Task_Waiting())->describe(Task_Run_Model::find($pair));
            static::__assert_equals('waiting for exclusive lock "fixture_lock_a"', $waiting['text'], 'only the lock still held is named');
        } finally {
            Task_Lock_Holder::release($holder_a);
        }
    }

    public static function test_an_inline_run_takes_every_lock()
    {
        $run = Task::internal(self::SERVICE_NAME, 'lock_a_and_b');

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals(['ran' => 'lock_a_and_b'], $run->state());
        static::__assert_false(Task_Concurrency::is_group_running('fixture_lock_a'), 'both locks released after the run');
        static::__assert_false(Task_Concurrency::is_group_running('fixture_lock_b'));
    }

    public static function test_a_due_debounced_run_waits_for_its_lock_and_a_call_does_not_move_it()
    {
        static::__clear_queue();
        $hash = Task_Concurrency::params_hash(['p' => 'a']);

        $first = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        $due = now()->subMinutes(5)->format('Y-m-d H:i:s.v');
        DB::table('_tasks')->where('id', $first)->update(['scheduled_for' => $due]);

        $holder = Task_Lock_Holder::start(static::__lock_name('fixture_combo_group'), 'hold');
        try {
            Artisan::call('rsx:task:worker');
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($first), 'due, and waiting only for the group');

            static::__assert_equals($first, Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']), 'a further call joins the waiting run');
            static::__assert_equals(
                $due,
                (string) DB::table('_tasks')->where('id', $first)->value('scheduled_for'),
                'and does not move its due time'
            );
        } finally {
            Task_Lock_Holder::release($holder);
        }

        Artisan::call('rsx:task:worker');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($first), 'it runs as soon as the lock is free');
        static::__assert_null(Task_Concurrency::pending_row_id(self::SERVICE, 'combo_debounced', $hash), 'and nothing is left pending');
    }

    public static function test_the_rule_accepts_repeated_groups_and_refuses_a_repeated_debounce()
    {
        static::__assert_equals(
            [],
            TaskConcurrencyAttributes_CodeQualityRule::attribute_problems('fixture', ['Exclusive' => [['a'], ['b']]]),
            'two groups are two instances'
        );
        static::__assert_equals(
            [],
            TaskConcurrencyAttributes_CodeQualityRule::attribute_problems('fixture', ['Exclusive' => [[], ['a']]]),
            'a bare #[Exclusive] beside a group'
        );
        static::__assert_count(
            1,
            TaskConcurrencyAttributes_CodeQualityRule::attribute_problems('fixture', ['Exclusive' => [['a'], ['']]]),
            'each instance is judged on its own'
        );
        static::__assert_count(
            1,
            TaskConcurrencyAttributes_CodeQualityRule::attribute_problems('fixture', ['Debounce' => [[30], [60]]]),
            'a task has one debounce delay'
        );
    }
}
