<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\CodeQuality\Rules\PHP\TaskConcurrencyAttributes_CodeQualityRule;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Task\Task_Waiting;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Lock_Holder;

/**
 * #[Debounce] and #[Exclusive] declared TOGETHER on one task.
 *
 *   #[Debounce(n)] + #[Exclusive]           the bare #[Exclusive] adds nothing: the task is
 *                                           #[Debounce(n)] alone - pending row and run lock
 *                                           both keyed on class::method + params.
 *   #[Debounce(n)] + #[Exclusive('group')]  the two compose: ENQUEUE is the debounce's (one
 *                                           pending run per class::method + params, the delay
 *                                           from that identity's last completion, re-anchored
 *                                           when a run of it settles); RUNNING is the group's
 *                                           (one run across every member, plain or debounced).
 *
 * Fixtures: debounce_exclusive_task (the first shape), and the group 'fixture_combo_group' of
 * combo_debounced (#[Debounce(30)]) and combo_plain (a plain member).
 *
 * "Running elsewhere" is staged by Task_Lock_Holder - another process holding the run lock -
 * because RsxLocks is re-entrant within one process; "the run holding it" is a RUNNING row a
 * test writes. Rows roll back with the per-test transaction; the in-process worker runs inside
 * it too, after the test has removed every other live row so the worker sees only this test's.
 */
class Task_Debounce_Exclusive_Test extends Rsx_Test_Abstract
{
    private const SERVICE = Task_Concurrency_Fixture_Service::class;
    private const SERVICE_NAME = 'Task_Concurrency_Fixture_Service';
    private const GROUP = 'fixture_combo_group';
    private const DELAY = 30;

    private static function __group_lock_name(): string
    {
        return Task_Concurrency::run_lock_name('group::' . self::GROUP);
    }

    private static function __clear_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
    }

    private static function __row(int $id): object
    {
        return DB::table('_tasks')->where('id', $id)->first();
    }

    private static function __status(int $id): int
    {
        return (int) static::__row($id)->status_id;
    }

    private static function __pending(string $method, array $params = [], array $fields = []): int
    {
        return Task_Runner::insert_row(self::SERVICE, $method, $params, Task_Run_Model::ORIGIN_DISPATCHED, $fields);
    }

    private static function __running(string $method, array $params = []): int
    {
        return Task_Runner::insert_row(self::SERVICE, $method, $params, Task_Run_Model::ORIGIN_DISPATCHED, Task_Runner::running_fields());
    }

    private static function __pending_count(string $method): int
    {
        return DB::table('_tasks')->where('class', self::SERVICE)->where('method', $method)
            ->where('status_id', Task_Run_Model::STATUS_PENDING)->count();
    }

    private static function __seconds_ahead(int $id): float
    {
        return (float) Carbon::parse(static::__row($id)->scheduled_for)->format('U.v') - microtime(true);
    }

    private static function __moment(?string $datetime): float
    {
        return (float) Carbon::parse($datetime)->format('U.v');
    }

    private static function __due_schedule(string $method): int
    {
        return DB::table('_task_schedules')->insertGetId([
            'class' => self::SERVICE,
            'method' => $method,
            'cron_expression' => 'daily at 3am',
            'next_run_at' => date('Y-m-d H:i:s', time() - 60) . '.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function __attributes(string $method): array
    {
        $file = Manifest::php_class_metadata(self::SERVICE_NAME)['file'];

        return Manifest::get_file($file)['public_static_methods'][$method]['attributes'];
    }

    // -------------------------------------------------------------------------
    // #[Debounce] + bare #[Exclusive]: exactly #[Debounce] alone
    // -------------------------------------------------------------------------

    public static function test_a_bare_exclusive_beside_debounce_adds_nothing_to_the_policy()
    {
        static::__assert_equals(
            Task_Concurrency::get_policy(self::SERVICE, 'debounce_task'),
            Task_Concurrency::get_policy(self::SERVICE, 'debounce_exclusive_task'),
            'the policy of #[Debounce(30)] alone'
        );
        static::__assert_equals(['mode' => 'debounce', 'delay' => self::DELAY, 'group' => null], Task_Concurrency::get_policy(self::SERVICE, 'debounce_exclusive_task'));

        $hash = Task_Concurrency::params_hash(['p' => 'a']);
        static::__assert_equals(self::SERVICE . '::debounce_exclusive_task::' . $hash, Task_Concurrency::identity_key(self::SERVICE, 'debounce_exclusive_task', $hash));
        static::__assert_equals(
            Task_Concurrency::identity_key(self::SERVICE, 'debounce_exclusive_task', $hash),
            Task_Concurrency::run_lock_identity(self::SERVICE, 'debounce_exclusive_task', $hash),
            'the run lock is the debounce identity\'s, never the method\'s'
        );
    }

    public static function test_a_bare_exclusive_beside_debounce_coalesces_per_parameter_set()
    {
        $first = Task::dispatch(self::SERVICE_NAME, 'debounce_exclusive_task', ['x' => 1]);
        $same = Task::dispatch(self::SERVICE_NAME, 'debounce_exclusive_task', ['x' => 1]);
        $other = Task::dispatch(self::SERVICE_NAME, 'debounce_exclusive_task', ['x' => 2]);

        static::__assert_equals($first, $same, 'the same parameter set coalesces');
        static::__assert_not_equals($first, $other, 'another parameter set is another pending run');
        static::__assert_equals(2, static::__pending_count('debounce_exclusive_task'));
    }

    /**
     * One parameter set running elsewhere holds back only itself: a worker sets that set's
     * pending run aside and runs another set's in the same pass.
     */
    public static function test_a_bare_exclusive_beside_debounce_runs_different_params_concurrently()
    {
        static::__clear_queue();

        $hash_a = Task_Concurrency::params_hash(['p' => 'a']);
        $hash_b = Task_Concurrency::params_hash(['p' => 'b']);
        $lock_a = Task_Concurrency::run_lock_name(Task_Concurrency::identity_key(self::SERVICE, 'debounce_exclusive_task', $hash_a));

        $a = Task::dispatch(self::SERVICE_NAME, 'debounce_exclusive_task', ['p' => 'a']);
        $b = Task::dispatch(self::SERVICE_NAME, 'debounce_exclusive_task', ['p' => 'b']);

        $holder = Task_Lock_Holder::start($lock_a, 'hold');
        try {
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'debounce_exclusive_task', $hash_a), 'the held parameter set is running elsewhere');

            $other = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'debounce_exclusive_task', $hash_b);
            static::__assert_not_null($other, 'another parameter set is free while it runs');
            $other->release();

            Artisan::call('rsx:task:worker');

            static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($a), 'the running parameter set waits for itself');
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($b), 'the other parameter set ran beside it');
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    // -------------------------------------------------------------------------
    // #[Debounce] + #[Exclusive('group')]: the two identities
    // -------------------------------------------------------------------------

    public static function test_a_debounced_group_member_has_a_params_pending_identity_and_the_groups_run_lock()
    {
        static::__assert_equals(['mode' => 'debounce', 'delay' => self::DELAY, 'group' => self::GROUP], Task_Concurrency::get_policy(self::SERVICE, 'combo_debounced'));
        static::__assert_equals(['mode' => 'exclusive', 'delay' => 0, 'group' => self::GROUP], Task_Concurrency::get_policy(self::SERVICE, 'combo_plain'));
        static::__assert_equals(self::GROUP, Task_Concurrency::group_of(self::SERVICE, 'combo_debounced'));

        $hash_a = Task_Concurrency::params_hash(['p' => 'a']);
        $hash_b = Task_Concurrency::params_hash(['p' => 'b']);

        static::__assert_equals(self::SERVICE . '::combo_debounced::' . $hash_a, Task_Concurrency::identity_key(self::SERVICE, 'combo_debounced', $hash_a), 'pending: the method and its params');
        static::__assert_not_equals(
            Task_Concurrency::identity_key(self::SERVICE, 'combo_debounced', $hash_a),
            Task_Concurrency::identity_key(self::SERVICE, 'combo_debounced', $hash_b)
        );

        static::__assert_equals('group::' . self::GROUP, Task_Concurrency::run_lock_identity(self::SERVICE, 'combo_debounced', $hash_a), 'running: the group');
        static::__assert_equals(
            Task_Concurrency::run_lock_identity(self::SERVICE, 'combo_debounced', $hash_a),
            Task_Concurrency::run_lock_identity(self::SERVICE, 'combo_debounced', $hash_b),
            'whatever the params'
        );
        static::__assert_equals(
            Task_Concurrency::run_lock_identity(self::SERVICE, 'combo_plain', $hash_a),
            Task_Concurrency::run_lock_identity(self::SERVICE, 'combo_debounced', $hash_a),
            'and the plain member shares it'
        );

        $members = Task_Concurrency::group_members(self::GROUP);
        sort($members);
        static::__assert_equals([[self::SERVICE, 'combo_debounced'], [self::SERVICE, 'combo_plain']], $members, 'a debounced member is a member of the group');
    }

    /**
     * The group's lock held elsewhere stands for ANY member running: neither the debounced
     * member (any params) nor the plain one can take the run lock, and a task outside the
     * group can.
     */
    public static function test_the_groups_lock_excludes_plain_and_debounced_members_alike()
    {
        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            static::__assert_true(Task_Concurrency::is_group_running(self::GROUP));
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'combo_debounced', Task_Concurrency::params_hash(['p' => 'a'])), 'a debounced member cannot run');
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'combo_debounced', Task_Concurrency::params_hash(['p' => 'b'])), 'with any params');
            static::__assert_null(Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'combo_plain', Task_Concurrency::params_hash([])), 'nor the plain member');

            $outside = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'debounce_exclusive_task', Task_Concurrency::params_hash(['p' => 'a']));
            static::__assert_not_null($outside, 'a task outside the group is unaffected');
            $outside->release();
        } finally {
            Task_Lock_Holder::release($holder);
        }

        // A debounced member holding the lock takes the group's - and no lock of its own
        // identity, which another process can still take.
        $hash = Task_Concurrency::params_hash(['p' => 'a']);
        $lock = Task_Concurrency::try_acquire_run_lock(self::SERVICE, 'combo_debounced', $hash);
        static::__assert_not_null($lock);
        try {
            static::__assert_true(Task_Concurrency::is_group_running(self::GROUP), 'the debounced member holds the group');

            $identity_lock = Task_Concurrency::run_lock_name(Task_Concurrency::identity_key(self::SERVICE, 'combo_debounced', $hash));
            $holder = Task_Lock_Holder::start($identity_lock, 'hold');
            Task_Lock_Holder::release($holder);
        } finally {
            $lock->release();
        }

        static::__assert_false(Task_Concurrency::is_group_running(self::GROUP));
    }

    // -------------------------------------------------------------------------
    // Enqueue is the debounce's
    // -------------------------------------------------------------------------

    public static function test_one_pending_run_per_debounce_identity_within_a_group()
    {
        static::__clear_queue();

        $a = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        $a_again = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        $b = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'b']);
        $plain = Task::dispatch(self::SERVICE_NAME, 'combo_plain', ['p' => 'a']);
        $plain_again = Task::dispatch(self::SERVICE_NAME, 'combo_plain', ['p' => 'b']);

        static::__assert_equals($a, $a_again, 'a dispatch while one is pending returns that pending run');
        static::__assert_not_equals($a, $b, 'different params are a different debounce identity');
        static::__assert_equals(2, static::__pending_count('combo_debounced'));
        static::__assert_equals($plain, $plain_again, 'the plain member stays one pending run per method');
        static::__assert_equals(1, static::__pending_count('combo_plain'));

        $ids = [$a, $b, $plain];
        sort($ids);
        static::__assert_equals(
            $ids,
            Task_Concurrency::pending_ids_on_run_lock(self::SERVICE, 'combo_debounced', Task_Concurrency::params_hash(['p' => 'a'])),
            'every pending run of every member waits on the group\'s lock'
        );
        static::__assert_equals(
            $ids,
            Task_Concurrency::pending_ids_on_run_lock(self::SERVICE, 'combo_plain', Task_Concurrency::params_hash([])),
            'asked from the plain member too'
        );
    }

    /**
     * While a debounced member RUNS, another dispatch of the same identity queues exactly one
     * new pending run, a third returns that run, and other params queue their own.
     */
    public static function test_one_running_and_one_pending_per_debounce_identity_within_a_group()
    {
        static::__clear_queue();

        $first = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        DB::table('_tasks')->where('id', $first)->update(Task_Runner::running_fields());

        static::__assert_true(Task_Concurrency::is_identity_running(self::SERVICE, 'combo_debounced', Task_Concurrency::params_hash(['p' => 'a'])));
        static::__assert_false(Task_Concurrency::is_identity_running(self::SERVICE, 'combo_debounced', Task_Concurrency::params_hash(['p' => 'b'])), 'another parameter set is not the one running');

        $follow_up = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        static::__assert_not_equals($first, $follow_up, 'a running instance does not absorb the next dispatch');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($follow_up));
        static::__assert_equals($follow_up, Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']), 'a third dispatch returns the one pending run');

        $other = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'b']);
        static::__assert_not_equals($follow_up, $other, 'different params queue their own pending run');
        static::__assert_equals(2, static::__pending_count('combo_debounced'));
    }

    /**
     * The delay runs from the identity's own last completion, and the settle of a run
     * re-anchors that identity's pending run - and nobody else's in the group.
     */
    public static function test_the_debounce_delay_is_honoured_and_the_pending_run_is_re_anchored_on_settle()
    {
        static::__clear_queue();

        $past = now()->subMinutes(5)->format('Y-m-d H:i:s.v');
        $same = static::__pending('combo_debounced', ['p' => 'a'], ['scheduled_for' => $past]);
        $other_params = static::__pending('combo_debounced', ['p' => 'b'], ['scheduled_for' => $past]);
        $plain = static::__pending('combo_plain', [], ['scheduled_for' => $past]);

        $run = Task::internal(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_false(Task_Concurrency::is_group_running(self::GROUP), 'the settle released the group');

        $ahead = static::__seconds_ahead($same);
        static::__assert_true($ahead > self::DELAY - 2 && $ahead <= self::DELAY, "the same identity's pending run is re-anchored to now + delay (got {$ahead}s ahead)");
        static::__assert_true(static::__seconds_ahead($other_params) < 0, 'another parameter set keeps its place in the queue');
        static::__assert_true(static::__seconds_ahead($plain) < 0, 'and so does the plain member');

        // A fresh enqueue of an identity that has just completed waits out the delay; one
        // that has never completed is due now.
        DB::table('_tasks')->where('id', $same)->delete();
        $fresh = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        $ahead = static::__seconds_ahead($fresh);
        static::__assert_true($ahead > self::DELAY - 2 && $ahead <= self::DELAY, "a fresh enqueue is due at last completion + delay (got {$ahead}s ahead)");

        $never_run = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'c']);
        static::__assert_true(static::__seconds_ahead($never_run) <= 0, 'a parameter set with no completion of its own is due now');
    }

    // -------------------------------------------------------------------------
    // Running is the group's
    // -------------------------------------------------------------------------

    /**
     * Due debounced runs (two parameter sets) and a plain member's run all stay queued while
     * the group is held elsewhere - claimed by nobody, so holding no worker slot - and run
     * one after another, oldest due first, once it is free.
     */
    public static function test_a_worker_passes_over_a_debounced_member_while_the_group_is_held()
    {
        static::__clear_queue();

        $a = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'a']);
        $b = Task::dispatch(self::SERVICE_NAME, 'combo_debounced', ['p' => 'b']);
        $plain = Task::dispatch(self::SERVICE_NAME, 'combo_plain');
        $unrelated = Task::dispatch(self::SERVICE_NAME, 'plain_task');

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            Artisan::call('rsx:task:worker');

            foreach ([$a, $b, $plain] as $id) {
                $row = static::__row($id);
                static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id, 'a member waits for the group');
                static::__assert_null($row->worker_id, 'claimed by no worker while it waits');
                static::__assert_null($row->started_at);
            }
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($unrelated), 'the unrelated task ran in the same pass');
        } finally {
            Task_Lock_Holder::release($holder);
        }

        Artisan::call('rsx:task:worker');

        $previous_completed = null;
        foreach ([$a, $b, $plain] as $id) {
            $row = static::__row($id);
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $row->status_id, 'every member runs once the group is free');

            if ($previous_completed !== null) {
                static::__assert_true(
                    static::__moment($row->started_at) >= $previous_completed,
                    "run #{$id} started only after the run queued before it had finished"
                );
            }
            $previous_completed = static::__moment($row->completed_at);
        }

        static::__assert_equals(['ran' => 'combo_debounced'], Task_Run_Model::find($a)->state());
    }

    /**
     * An inline run of a debounced member WAITS for the group: Task::internal() parks on the
     * group's run lock and runs only after the holder - which releases the moment it sees a
     * writer queued behind it - lets go.
     */
    public static function test_an_inline_run_of_a_debounced_member_waits_for_the_group()
    {
        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'release_on_waiter');
        try {
            $run = Task::internal(self::SERVICE_NAME, 'combo_debounced', ['p' => 'inline']);

            $released_at = Task_Lock_Holder::released_at($holder);
            static::__assert_not_null($released_at, 'the holder saw the inline run queued behind the group lock, and released');
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the inline run then ran');
            static::__assert_true(
                (int) round(static::__moment($run->started_at) * 1000) >= (int) floor($released_at * 1000),
                "the run started ({$run->started_at}) after the holder released"
            );
        } finally {
            Task_Lock_Holder::stop($holder);
        }
    }

    // -------------------------------------------------------------------------
    // The wait line
    // -------------------------------------------------------------------------

    public static function test_a_blocked_debounced_member_names_the_group_and_the_holder()
    {
        static::__clear_queue();

        $holder_id = static::__running('combo_plain');
        $waiting = Task_Run_Model::find(static::__pending('combo_debounced', ['p' => 'a']));
        $not_due = Task_Run_Model::find(static::__pending('combo_debounced', ['p' => 'b'], ['scheduled_for' => now()->addMinutes(10)->format('Y-m-d H:i:s.v')]));

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            $resolver = new Task_Waiting();

            static::__assert_equals(
                ['reason' => 'lock', 'text' => 'waiting for exclusive lock "' . self::GROUP . '" (held by task #' . $holder_id . ')', 'lock' => self::GROUP, 'held_by_task_id' => $holder_id],
                $resolver->describe($waiting),
                'held by the plain member'
            );

            $answer = $resolver->describe($not_due);
            static::__assert_equals('scheduled', $answer['reason'], 'a run whose delay has not elapsed is scheduled, whatever holds the group');
            static::__assert_true(str_starts_with($answer['text'], 'scheduled for '));
            static::__assert_null($answer['lock']);
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    /** The other direction: a debounced member running holds back the plain one and its own other params. */
    public static function test_a_running_debounced_member_is_named_as_the_groups_holder()
    {
        static::__clear_queue();

        $holder_id = static::__running('combo_debounced', ['p' => 'a']);
        $plain = Task_Run_Model::find(static::__pending('combo_plain'));
        $other_params = Task_Run_Model::find(static::__pending('combo_debounced', ['p' => 'b']));

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            $resolver = new Task_Waiting();
            $expected = ['reason' => 'lock', 'text' => 'waiting for exclusive lock "' . self::GROUP . '" (held by task #' . $holder_id . ')', 'lock' => self::GROUP, 'held_by_task_id' => $holder_id];

            static::__assert_equals($expected, $resolver->describe($plain), 'the plain member waits on the debounced one');
            static::__assert_equals($expected, $resolver->describe($other_params), 'and so does another parameter set of the same method');
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    // -------------------------------------------------------------------------
    // Schedule ticks
    // -------------------------------------------------------------------------

    /**
     * A tick of a debounced member's schedule while the group is held by ANOTHER identity -
     * here a run of its own method with other params - is queued as its pending run.
     */
    public static function test_a_tick_blocked_by_another_identity_of_the_group_is_queued()
    {
        static::__clear_queue();

        $schedule_id = static::__due_schedule('combo_debounced');
        static::__running('combo_debounced', ['p' => 'other']);

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

            $queued = DB::table('_tasks')->where('schedule_id', $schedule_id)->get();
            static::__assert_count(1, $queued, 'the tick became one pending run');
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $queued[0]->status_id);
            static::__assert_equals(Task_Run_Model::ORIGIN_SCHEDULED, (int) $queued[0]->origin_id);
            static::__assert_equals(Task_Concurrency::params_hash([]), $queued[0]->params_hash);
            static::__assert_true(
                strtotime((string) DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at')) > time(),
                'and the cadence moved on'
            );
        } finally {
            Task_Lock_Holder::release($holder);
        }
    }

    /** A tick whose OWN identity is the one running is coalesced into that run: nothing is queued. */
    public static function test_a_tick_whose_own_identity_is_running_is_coalesced()
    {
        static::__clear_queue();

        $schedule_id = static::__due_schedule('combo_debounced');
        static::__running('combo_debounced', []);

        $holder = Task_Lock_Holder::start(static::__group_lock_name(), 'hold');
        try {
            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        } finally {
            Task_Lock_Holder::release($holder);
        }

        static::__assert_equals(0, DB::table('_tasks')->where('schedule_id', $schedule_id)->count(), 'no run was created');
        static::__assert_equals(0, static::__pending_count('combo_debounced'));
        static::__assert_true(
            strtotime((string) DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at')) > time(),
            'the tick was spent'
        );
    }

    // -------------------------------------------------------------------------
    // rsx:check (TASK-CONCURRENCY-01)
    // -------------------------------------------------------------------------

    public static function test_the_rule_accepts_the_combination_and_refuses_malformed_arguments()
    {
        foreach (['debounce_exclusive_task', 'combo_debounced', 'combo_plain', 'debounce_task', 'exclusive_task', 'plain_task'] as $method) {
            static::__assert_equals(
                [],
                TaskConcurrencyAttributes_CodeQualityRule::attribute_problems($method, static::__attributes($method)),
                "{$method} declares nothing the rule refuses"
            );
        }

        static::__assert_array_has_key('Debounce', static::__attributes('combo_debounced'), 'the fixture really declares both');
        static::__assert_array_has_key('Exclusive', static::__attributes('combo_debounced'));

        $refused = [
            'a negative delay' => ['Debounce' => [[-1]]],
            'a non-integer delay' => ['Debounce' => [['30']]],
            'no delay' => ['Debounce' => [[]]],
            'an empty group name' => ['Exclusive' => [['  ']]],
            'a non-string group' => ['Exclusive' => [[7]]],
            'two group names' => ['Exclusive' => [['a', 'b']]],
        ];
        foreach ($refused as $what => $attributes) {
            static::__assert_count(1, TaskConcurrencyAttributes_CodeQualityRule::attribute_problems('fixture', $attributes), $what);
        }

        static::__assert_count(
            2,
            TaskConcurrencyAttributes_CodeQualityRule::attribute_problems('fixture', ['Debounce' => [[-1]], 'Exclusive' => [['']]]),
            'each attribute is judged on its own'
        );
    }
}
