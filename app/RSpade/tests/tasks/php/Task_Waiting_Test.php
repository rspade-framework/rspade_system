<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Task\Task_Waiting;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Lock_Holder;

/**
 * What a PENDING run is waiting for (Task_Waiting), derived when the run is read and never
 * stored. The answer is {reason, text, lock, held_by_task_id}; the first reason that applies:
 *
 *   scheduled   not due yet                    "scheduled for <time>"
 *   lock        its run lock is held           waiting for exclusive lock "<group>" (held by task #<id>)
 *                                              waiting for exclusive lock (held by task #<id>)
 *               held, no running row yet       the same without the parenthesis
 *   workers     every claiming worker is busy  "waiting for a free worker (<busy> of <max> busy)"
 *   queued      none of those                  "waiting for a worker to start"
 *
 * A run that is not pending answers null. Nothing is written to any row, so a changed holder
 * is simply a different answer at the next read. One Task_Waiting describes many runs with
 * one read of the running rows, however many it is asked about.
 *
 * "The lock is held" is staged by Task_Lock_Holder - another process holding the run lock -
 * because RsxLocks is re-entrant within one process; "the run holding it" is a RUNNING row
 * this test writes. Rows roll back with the per-test transaction; every test first removes
 * the other live rows so it reads only its own.
 */
class Task_Waiting_Test extends Rsx_Test_Abstract
{
    private const SERVICE = Task_Concurrency_Fixture_Service::class;
    private const GROUP = 'fixture_group';

    private static function __clear_live(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
    }

    /**
     * Load the _tasks column map before a query count. A process's first read of a model
     * attribute loads its table's column map (Rsx_Schema), which asks the database for the
     * state key once - a cost of the process, not of the code being measured, and the first
     * thing a fresh test container does.
     */
    private static function __warm_schema(Task_Run_Model $run): void
    {
        $run->status_id;
    }

    private static function __pending(string $method, array $params = [], array $fields = []): Task_Run_Model
    {
        return Task_Run_Model::find(Task_Runner::insert_row(self::SERVICE, $method, $params, Task_Run_Model::ORIGIN_DISPATCHED, $fields));
    }

    /** A RUNNING row; $worker = claimed by a pool worker (counts as a busy worker). */
    private static function __running(string $method, array $params = [], bool $worker = false): int
    {
        return Task_Runner::insert_row(self::SERVICE, $method, $params, Task_Run_Model::ORIGIN_DISPATCHED, Task_Runner::running_fields() + ($worker ? [
            'worker_id' => 71,
            'worker_generation' => 1,
            'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
        ] : []));
    }

    private static function __group_lock(): string
    {
        return Task_Concurrency::run_lock_name('group::' . self::GROUP);
    }

    private static function __identity_lock(string $method, ?array $debounce_params = null): string
    {
        $identity = self::SERVICE . '::' . $method;
        if ($debounce_params !== null) {
            $identity .= '::' . Task_Concurrency::params_hash($debounce_params);
        }

        return Task_Concurrency::run_lock_name($identity);
    }

    /** Run $test with the two task pools capped at $on_demand and $scheduled workers. */
    private static function __with_caps(int $on_demand, int $scheduled, callable $test): void
    {
        $original = config('rsx.tasks.pools');
        config(['rsx.tasks.pools.on_demand.max_workers' => $on_demand, 'rsx.tasks.pools.scheduled.max_workers' => $scheduled]);
        try {
            $test();
        } finally {
            config(['rsx.tasks.pools' => $original]);
        }
    }

    // -------------------------------------------------------------------------
    // Not pending
    // -------------------------------------------------------------------------

    public static function test_a_run_that_is_not_pending_waits_for_nothing()
    {
        static::__clear_live();

        $running = Task_Run_Model::find(static::__running('plain_task'));
        static::__assert_null(Task_Waiting::of($running), 'running');
        static::__assert_null($running->to_status_array()['waiting'], 'and its status says so');

        foreach ([Task_Run_Model::STATUS_COMPLETED, Task_Run_Model::STATUS_FAILED, Task_Run_Model::STATUS_STOPPED, Task_Run_Model::STATUS_KILLED, Task_Run_Model::STATUS_CANCELLED] as $status_id) {
            static::__assert_null(Task_Waiting::of(static::__pending('plain_task', [], ['status_id' => $status_id])), "status {$status_id}");
        }
    }

    // -------------------------------------------------------------------------
    // Reason 1: not due yet
    // -------------------------------------------------------------------------

    public static function test_a_run_not_yet_due_is_scheduled()
    {
        static::__clear_live();
        $at = date('Y-m-d H:i:s', time() + 3600) . '.000';
        $run = static::__pending('plain_task', [], ['scheduled_for' => $at]);

        static::__assert_equals(
            ['reason' => 'scheduled', 'text' => 'scheduled for ' . Rsx_Time::format_datetime($at), 'lock' => null, 'held_by_task_id' => null],
            Task_Waiting::of($run)
        );

        $status = $run->to_status_array();
        static::__assert_equals('scheduled', $status['waiting']['reason'], 'the status array carries it');
        static::__assert_equals(Rsx_Time::to_iso($at), $status['scheduled_for'], 'beside the ISO moment a client formats for itself');
    }

    // -------------------------------------------------------------------------
    // Reason 2: the run lock is held
    // -------------------------------------------------------------------------

    public static function test_a_grouped_run_names_its_group_and_the_run_holding_it()
    {
        static::__clear_live();
        $holder_id = static::__running('group_alpha');
        $run = static::__pending('group_beta');

        $holder = Task_Lock_Holder::start(static::__group_lock(), 'hold');
        try {
            static::__assert_equals(
                ['reason' => 'lock', 'text' => 'waiting for exclusive lock "fixture_group" (held by task #' . $holder_id . ')', 'lock' => 'fixture_group', 'held_by_task_id' => $holder_id],
                Task_Waiting::of($run),
                'the holder is the running run of ANOTHER member of the group'
            );
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    public static function test_an_ungrouped_run_names_only_the_run_holding_its_lock()
    {
        static::__clear_live();
        $holder_id = static::__running('exclusive_task');
        $run = static::__pending('exclusive_task');

        $holder = Task_Lock_Holder::start(static::__identity_lock('exclusive_task'), 'hold');
        try {
            static::__assert_equals(
                ['reason' => 'lock', 'text' => 'waiting for exclusive lock (held by task #' . $holder_id . ')', 'lock' => null, 'held_by_task_id' => $holder_id],
                Task_Waiting::of($run)
            );
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    /** Between a lock being taken and its run being marked running there is no holder row. */
    public static function test_a_held_lock_with_no_running_row_leaves_the_holder_off()
    {
        static::__clear_live();
        $grouped = static::__pending('group_beta');
        $ungrouped = static::__pending('exclusive_task');

        $group_holder = Task_Lock_Holder::start(static::__group_lock(), 'hold');
        $identity_holder = Task_Lock_Holder::start(static::__identity_lock('exclusive_task'), 'hold');
        try {
            static::__assert_equals(
                ['reason' => 'lock', 'text' => 'waiting for exclusive lock "fixture_group"', 'lock' => 'fixture_group', 'held_by_task_id' => null],
                Task_Waiting::of($grouped)
            );
            static::__assert_equals(
                ['reason' => 'lock', 'text' => 'waiting for exclusive lock', 'lock' => null, 'held_by_task_id' => null],
                Task_Waiting::of($ungrouped)
            );
        } finally {
            Task_Lock_Holder::release($group_holder);
            Task_Lock_Holder::release($identity_holder);
        }
    }

    /** A #[Debounce] lock is per parameter set: another set's run holds nothing of this one's. */
    public static function test_a_debounce_run_waits_only_on_its_own_parameter_set()
    {
        static::__clear_live();
        $held_params = ['client' => 1];
        $holder_id = static::__running('debounce_task', $held_params);
        $same = static::__pending('debounce_task', $held_params);
        $other = static::__pending('debounce_task', ['client' => 2]);

        $holder = Task_Lock_Holder::start(static::__identity_lock('debounce_task', $held_params), 'hold');
        try {
            $waiting = new Task_Waiting();
            static::__assert_equals('waiting for exclusive lock (held by task #' . $holder_id . ')', $waiting->describe($same)['text'], 'the same parameter set waits on the lock');
            static::__assert_equals('queued', $waiting->describe($other)['reason'], 'another parameter set does not');
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    /** The lock daemon is the evidence: a running row whose lock is NOT held is no lock wait. */
    public static function test_a_running_row_without_a_held_lock_is_not_a_lock_wait()
    {
        static::__clear_live();
        static::__running('group_alpha');
        $run = static::__pending('group_beta');

        static::__assert_equals('queued', Task_Waiting::of($run)['reason'], 'the group\'s lock is free');
    }

    // -------------------------------------------------------------------------
    // Reason 3: no free worker - and the fallback
    // -------------------------------------------------------------------------

    public static function test_a_due_unlocked_run_with_every_worker_busy_waits_for_a_worker()
    {
        static::__clear_live();
        static::__with_caps(2, 1, function () {
            static::__running('plain_task', ['n' => 1], true);
            static::__running('plain_task', ['n' => 2], true);
            static::__running('plain_task', ['n' => 3], true);
            static::__running('plain_task', ['n' => 4]);   // an inline run occupies no worker
            $run = static::__pending('plain_task');

            static::__assert_equals(
                ['reason' => 'workers', 'text' => 'waiting for a free worker (3 of 3 busy)', 'lock' => null, 'held_by_task_id' => null],
                Task_Waiting::of($run),
                'busy = runs a pool worker is running; max = the on_demand cap plus the scheduled cap'
            );
        });
    }

    public static function test_a_due_unlocked_run_with_a_free_worker_is_queued()
    {
        static::__clear_live();
        static::__with_caps(2, 1, function () {
            static::__running('plain_task', ['n' => 1], true);
            static::__running('plain_task', ['n' => 2], true);
            $run = static::__pending('plain_task');

            static::__assert_equals(
                ['reason' => 'queued', 'text' => 'waiting for a worker to start', 'lock' => null, 'held_by_task_id' => null],
                Task_Waiting::of($run)
            );
        });
    }

    // -------------------------------------------------------------------------
    // Precedence
    // -------------------------------------------------------------------------

    public static function test_the_reasons_are_checked_in_order()
    {
        static::__clear_live();
        static::__with_caps(1, 1, function () {
            $holder_id = static::__running('group_alpha', [], true);
            static::__running('plain_task', [], true);   // with the holder: 2 of 2 busy

            $future = date('Y-m-d H:i:s', time() + 3600) . '.000';
            $not_due_and_locked = static::__pending('group_beta', [], ['scheduled_for' => $future]);
            $locked_and_no_worker = static::__pending('group_beta');
            $no_worker = static::__pending('plain_task', ['n' => 9]);

            $holder = Task_Lock_Holder::start(static::__group_lock(), 'hold');
            try {
                $waiting = new Task_Waiting();
                static::__assert_equals('scheduled', $waiting->describe($not_due_and_locked)['reason'], 'not due yet comes before a held lock');
                static::__assert_equals('lock', $waiting->describe($locked_and_no_worker)['reason'], 'a held lock comes before busy workers');
                static::__assert_equals($holder_id, $waiting->describe($locked_and_no_worker)['held_by_task_id']);
                static::__assert_equals('waiting for a free worker (2 of 2 busy)', $waiting->describe($no_worker)['text'], 'busy workers last');
            } finally {
                Task_Lock_Holder::release($holder);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Derived, never stored
    // -------------------------------------------------------------------------

    public static function test_a_changed_holder_is_a_different_answer_and_nothing_is_written()
    {
        static::__clear_live();
        $first = static::__running('group_alpha');
        $run = static::__pending('group_beta');
        $row_before = (array) DB::table('_tasks')->where('id', $run->id)->first();

        $holder = Task_Lock_Holder::start(static::__group_lock(), 'hold');
        try {
            static::__assert_equals($first, Task_Waiting::of($run)['held_by_task_id']);

            // The first holder settles and another member takes the group.
            DB::table('_tasks')->where('id', $first)->update(['status_id' => Task_Run_Model::STATUS_COMPLETED]);
            $second = static::__running('group_alpha');

            static::__assert_equals($second, Task_Waiting::of($run)['held_by_task_id'], 'the next read names the new holder');
            static::__assert_equals(
                'waiting for exclusive lock "fixture_group" (held by task #' . $second . ')',
                Task_Run_Model::find($run->id)->to_status_array()['waiting']['text']
            );
        } finally {
            Task_Lock_Holder::release($holder);
        }

        static::__assert_equals('queued', Task_Waiting::of($run)['reason'], 'and once the lock is free it is no longer a lock wait');
        static::__assert_equals($row_before, (array) DB::table('_tasks')->where('id', $run->id)->first(), 'the pending row was never written');
    }

    /** Only the holder's run id is exposed: nothing else about the holder is in the answer. */
    public static function test_only_the_holders_id_is_exposed()
    {
        static::__clear_live();
        $holder_id = static::__running('group_alpha', ['secret' => 'holder-param-value']);
        $run = static::__pending('group_beta');

        $holder = Task_Lock_Holder::start(static::__group_lock(), 'hold');
        try {
            $answer = Task_Waiting::of($run);
        } finally {
            Task_Lock_Holder::release($holder);
        }

        static::__assert_equals(['reason', 'text', 'lock', 'held_by_task_id'], array_keys($answer), 'four keys');
        static::__assert_equals($holder_id, $answer['held_by_task_id']);
        $encoded = json_encode($answer);
        static::__assert_false(str_contains($encoded, 'holder-param-value'), 'not the holder\'s params');
        static::__assert_false(str_contains($encoded, 'group_alpha'), 'not the holder\'s task name');
    }

    // -------------------------------------------------------------------------
    // Many runs, one read
    // -------------------------------------------------------------------------

    /**
     * One Task_Waiting describes a page of pending runs with ONE query - the running rows -
     * however many runs it is asked about; a fresh instance per run would repeat it per run.
     */
    public static function test_many_pending_runs_cost_two_queries()
    {
        static::__clear_live();
        $holder_id = static::__running('group_alpha', [], true);

        $runs = [];
        for ($n = 0; $n < 12; $n++) {
            $runs[] = static::__pending('group_beta', ['n' => $n]);
            $runs[] = static::__pending('plain_task', ['n' => $n]);
        }

        $holder = Task_Lock_Holder::start(static::__group_lock(), 'hold');
        try {
            static::__warm_schema($runs[0]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $waiting = new Task_Waiting();
                $answers = array_map(fn ($run) => $waiting->describe($run), $runs);
                $made = array_column(DB::getQueryLog(), 'query');
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }

            static::__assert_count(2, $made, '24 pending runs, two queries: ' . implode(' | ', $made));
            static::__assert_contains('_task_system_off', $made[0], 'the switch, once');
            static::__assert_contains('_tasks', $made[1], 'the running rows, once');

            foreach ($answers as $index => $answer) {
                if ($index % 2 === 0) {
                    static::__assert_equals($holder_id, $answer['held_by_task_id'], 'every grouped run names the one holder');
                } else {
                    static::__assert_equals('queued', $answer['reason']);
                }
            }

            // to_status_arrays() shares one resolver across the page it presents.
            $statuses = Task_Run_Model::to_status_arrays($runs);
            static::__assert_count(24, $statuses);
            static::__assert_equals($answers[0], $statuses[0]['waiting']);
            static::__assert_equals($answers[1], $statuses[1]['waiting']);
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    /**
     * While the task system is off that is what every pending run waits for, ahead of every
     * other reason - a run not yet due included - and the whole page costs one read of the
     * switch.
     */
    public static function test_the_task_system_being_off_comes_first()
    {
        static::__clear_live();
        $due = static::__pending('plain_task');
        $later = static::__pending('plain_task', ['n' => 2], ['scheduled_for' => date('Y-m-d H:i:s', time() + 3600) . '.000']);
        $running = Task_Run_Model::find(static::__running('plain_task', ['n' => 3]));

        \App\RSpade\Core\Task\Task::disable('test');

        static::__warm_schema($due);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $waiting = new Task_Waiting();
            $answers = [$waiting->describe($due), $waiting->describe($later), $waiting->describe($running)];
            $made = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        static::__assert_equals(
            ['reason' => 'disabled', 'text' => 'task system disabled - php artisan rsx:tasks:enable', 'lock' => null, 'held_by_task_id' => null],
            $answers[0]
        );
        static::__assert_equals('disabled', $answers[1]['reason'], 'ahead of "scheduled"');
        static::__assert_null($answers[2], 'a run that is not pending still waits for nothing');
        static::__assert_count(1, $made, 'one read of the switch for the page: ' . implode(' | ', $made));

        static::__assert_equals('disabled', $due->to_status_array()['waiting']['reason'], 'and it rides the status array');

        \App\RSpade\Core\Task\Task::enable();
        static::__assert_equals('queued', Task_Waiting::of($due)['reason'], 'back on: the ordinary reasons again');
    }

    // -------------------------------------------------------------------------
    // The helpers behind it
    // -------------------------------------------------------------------------

    public static function test_the_run_lock_identity_and_the_runs_waiting_on_it()
    {
        static::__clear_live();
        $hash = Task_Concurrency::params_hash([]);

        static::__assert_equals([], Task_Concurrency::run_lock_identities(self::SERVICE, 'plain_task', $hash), 'an unmanaged task has no run lock');
        static::__assert_equals(['group::' . self::GROUP], Task_Concurrency::run_lock_identities(self::SERVICE, 'group_alpha', $hash));
        static::__assert_equals(
            Task_Concurrency::run_lock_identities(self::SERVICE, 'group_alpha', $hash),
            Task_Concurrency::run_lock_identities(self::SERVICE, 'group_beta', $hash),
            'every member of a group shares one'
        );
        static::__assert_equals([self::SERVICE . '::exclusive_task'], Task_Concurrency::run_lock_identities(self::SERVICE, 'exclusive_task', $hash));

        $members = Task_Concurrency::group_members(self::GROUP);
        sort($members);
        static::__assert_equals([[self::SERVICE, 'group_alpha'], [self::SERVICE, 'group_beta']], $members, 'the group\'s members, from the manifest');
        static::__assert_equals([], Task_Concurrency::group_members('no_such_group'));

        $alpha = (int) static::__pending('group_alpha')->id;
        $beta = (int) static::__pending('group_beta')->id;
        $exclusive = (int) static::__pending('exclusive_task')->id;
        $debounce_one = (int) static::__pending('debounce_task', ['client' => 1])->id;
        static::__pending('debounce_task', ['client' => 2]);
        static::__pending('plain_task');

        static::__assert_equals([$alpha, $beta], Task_Concurrency::pending_ids_on_run_lock(self::SERVICE, 'group_alpha', $hash), 'a group: every member\'s pending run');
        static::__assert_equals([$exclusive], Task_Concurrency::pending_ids_on_run_lock(self::SERVICE, 'exclusive_task', $hash));
        static::__assert_equals([$debounce_one], Task_Concurrency::pending_ids_on_run_lock(self::SERVICE, 'debounce_task', Task_Concurrency::params_hash(['client' => 1])), 'a debounce: its own parameter set only');
        static::__assert_equals([], Task_Concurrency::pending_ids_on_run_lock(self::SERVICE, 'plain_task', $hash), 'an unmanaged task: none');
    }
}
