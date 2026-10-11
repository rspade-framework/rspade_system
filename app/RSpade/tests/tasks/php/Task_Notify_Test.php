<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Task\Task_Waiting;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Task_Notify - the frames a watcher follows runs by.
 *
 *   Task_Changed_Topic {id}       a run's lifecycle, reports, messages or attachments -
 *                                 and, for a PENDING run, a lifecycle move of a run on the
 *                                 same run lock (what it is waiting for is derived at read
 *                                 time, and names the run holding the lock)
 *   Task_Output_Topic {id}        a run's output lines
 *   Task_List_Changed_Topic {class, method}
 *                                 a run entered, left or moved between lifecycle states;
 *                                 a subscription filtered on class / method hears only
 *                                 that task's runs
 *
 * Each frame is published once per site holding a matching subscription in the relay's
 * registry (the Redis set rsx_rt:subs, seeded here the way the relay writes it) and not at all
 * when nobody watches; while this process holds a task pool lock frames are held back until
 * flush_deferred(). Publishes are observed through Realtime's capture seam, with realtime
 * switched on for the class. Subscribing is the view gate's (Task_Gates_Test).
 */
class Task_Notify_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static $previous_enabled = null;

    /** @var int[] runs this class wrote */
    private static array $runs = [];

    public static function setup()
    {
        self::$previous_enabled = config('rsx.realtime.enabled');
        config(['rsx.realtime.enabled' => true]);
        static::__clean();
    }

    public static function teardown()
    {
        static::__clean();
        Realtime::_testing_reset();
        config(['rsx.realtime.enabled' => self::$previous_enabled]);
        DB::table('_tasks')->whereIn('id', self::$runs)->delete();
    }

    private static function __clean(): void
    {
        Realtime::_testing_redis()->del('rsx_rt:subs');
        Realtime::reset_registry_memo();
        Realtime::_testing_start_publish_capture();
    }

    /** Add one subscription to the registry the way the relay records it. */
    private static function __watch(string $topic, ?int $task_id, int $site_id): void
    {
        Realtime::_testing_redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => $site_id,
            'topic' => $topic,
            'filter' => $task_id === null ? [] : ['id' => $task_id],
        ]));
    }

    /** Add one subscription with an arbitrary filter, the way the relay records it. */
    private static function __watch_filter(string $topic, array $filter, int $site_id): void
    {
        Realtime::_testing_redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => $site_id,
            'topic' => $topic,
            'filter' => $filter,
        ]));
    }

    /** The publishes captured so far: [topic, site_id, id-or-null], in order. */
    private static function __published(): array
    {
        $out = [];
        foreach (Realtime::_testing_published() as $publish) {
            if (str_starts_with($publish['topic'], 'Task_')) {
                $out[] = [$publish['topic'], $publish['site_id'], $publish['data']['id'] ?? null];
            }
        }

        return $out;
    }

    private static function __sorted(array $published): array
    {
        sort($published);

        return $published;
    }

    private static function __run(): Task_Instance
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());
        self::$runs[] = $id;

        return Task_Instance::find($id);
    }

    public static function test_a_change_reaches_each_site_watching_that_run_once()
    {
        static::__clean();
        static::__watch('Task_Changed_Topic', 901, 1);
        static::__watch('Task_Changed_Topic', 901, 7);
        static::__watch('Task_Changed_Topic', 902, 3);
        static::__watch('Task_Output_Topic', 901, 5);
        static::__watch('Some_Other_Topic', 901, 6);

        Task_Notify::changed(901);

        static::__assert_equals(
            [['Task_Changed_Topic', 1, 901], ['Task_Changed_Topic', 7, 901]],
            static::__sorted(static::__published())
        );
    }

    public static function test_output_has_its_own_topic()
    {
        static::__clean();
        static::__watch('Task_Changed_Topic', 901, 1);
        static::__watch('Task_Output_Topic', 901, 5);

        Task_Notify::changed(901, false, true);
        static::__assert_equals([['Task_Output_Topic', 5, 901]], static::__published(), 'output only');

        static::__clean();
        static::__watch('Task_Changed_Topic', 901, 1);
        static::__watch('Task_Output_Topic', 901, 5);
        Task_Notify::changed(901, true, true);
        static::__assert_equals([['Task_Changed_Topic', 1, 901], ['Task_Output_Topic', 5, 901]], static::__sorted(static::__published()), 'both');
    }

    public static function test_a_lifecycle_move_reaches_the_run_and_every_matching_list_watcher()
    {
        static::__clean();
        $task = static::__run();
        $id = $task->get_id();
        static::__clean();

        static::__watch('Task_Changed_Topic', $id, 1);
        static::__watch('Task_List_Changed_Topic', null, 1);
        static::__watch_filter('Task_List_Changed_Topic', ['class' => 'Task_Exec_Fixture_Service'], 4);
        static::__watch_filter('Task_List_Changed_Topic', ['class' => 'Task_Exec_Fixture_Service', 'method' => 'marker_a'], 6);
        static::__watch_filter('Task_List_Changed_Topic', ['class' => 'Some_Other_Service'], 5);
        static::__watch_filter('Task_List_Changed_Topic', ['class' => 'Task_Exec_Fixture_Service', 'method' => 'marker_b'], 8);

        Task_Notify::lifecycle($id);

        static::__assert_equals(
            [['Task_Changed_Topic', 1, $id], ['Task_List_Changed_Topic', 1, null], ['Task_List_Changed_Topic', 4, null], ['Task_List_Changed_Topic', 6, null]],
            static::__sorted(static::__published()),
            'unfiltered, by service and by task all hear it; another service or another method does not'
        );

        $list_frames = array_values(array_filter(Realtime::_testing_published(), fn ($p) => $p['topic'] === 'Task_List_Changed_Topic'));
        static::__assert_equals(['class' => 'Task_Exec_Fixture_Service', 'method' => 'marker_a'], $list_frames[0]['data'], 'the frame names the task by its simple service name');
    }

    // -------------------------------------------------------------------------
    // The waiters: pending runs on the same run lock
    // -------------------------------------------------------------------------

    private static function __fixture_row(string $method, array $fields = [], array $params = []): int
    {
        $id = Task_Runner::insert_row(Task_Concurrency_Fixture_Service::class, $method, $params, Task_Run_Model::ORIGIN_DISPATCHED, $fields);
        self::$runs[] = $id;

        return $id;
    }

    /** The ids Task_Changed_Topic was published for, sorted. */
    private static function __changed_ids(): array
    {
        $ids = [];
        foreach (static::__published() as [$topic, $site_id, $id]) {
            if ($topic === 'Task_Changed_Topic') {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * What a pending run is waiting for names the run holding its lock, and is derived at read
     * time - so when a run starts or settles, every PENDING run on the same run lock gets its
     * own changed frame and a view of that one run re-reads. For an exclusive group that is
     * the pending run of every member; runs on other locks, and unmanaged runs, hear nothing.
     */
    public static function test_a_lifecycle_move_tells_the_pending_runs_on_the_same_group_lock()
    {
        static::__clean();
        DB::table('_tasks')->where('class', Task_Concurrency_Fixture_Service::class)->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();

        $holder = static::__fixture_row('group_alpha', Task_Runner::running_fields());
        $waiting_beta = static::__fixture_row('group_beta');
        $waiting_alpha = static::__fixture_row('group_alpha');
        $other_lock = static::__fixture_row('exclusive_task');
        $unmanaged = static::__fixture_row('plain_task');
        $finished_beta = static::__fixture_row('group_beta', ['status_id' => Task_Run_Model::STATUS_COMPLETED]);
        static::__clean();

        foreach ([$holder, $waiting_beta, $waiting_alpha, $other_lock, $unmanaged, $finished_beta] as $id) {
            static::__watch('Task_Changed_Topic', $id, 1);
        }

        Task_Notify::lifecycle($holder);

        static::__assert_equals(
            [$holder, $waiting_beta, $waiting_alpha],
            static::__changed_ids(),
            'the run itself and the two pending runs of its group; not another lock\'s, not an unmanaged run, not a finished one'
        );
    }

    public static function test_the_waiters_of_an_ungrouped_lock_are_its_own_identity_only()
    {
        static::__clean();
        DB::table('_tasks')->where('class', Task_Concurrency_Fixture_Service::class)->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();

        $exclusive_holder = static::__fixture_row('exclusive_task', Task_Runner::running_fields());
        $exclusive_waiter = static::__fixture_row('exclusive_task');
        $debounce_holder = static::__fixture_row('debounce_task', Task_Runner::running_fields(), ['client' => 1]);
        $debounce_same = static::__fixture_row('debounce_task', [], ['client' => 1]);
        $debounce_other = static::__fixture_row('debounce_task', [], ['client' => 2]);
        $grouped = static::__fixture_row('group_beta');
        static::__clean();

        foreach ([$exclusive_holder, $exclusive_waiter, $debounce_holder, $debounce_same, $debounce_other, $grouped] as $id) {
            static::__watch('Task_Changed_Topic', $id, 1);
        }

        Task_Notify::lifecycle($exclusive_holder);
        static::__assert_equals([$exclusive_holder, $exclusive_waiter], static::__changed_ids(), '#[Exclusive]: the method\'s pending run');

        static::__clean();
        foreach ([$exclusive_waiter, $debounce_holder, $debounce_same, $debounce_other, $grouped] as $id) {
            static::__watch('Task_Changed_Topic', $id, 1);
        }

        Task_Notify::lifecycle($debounce_holder);
        static::__assert_equals([$debounce_holder, $debounce_same], static::__changed_ids(), '#[Debounce]: the pending run of the same parameter set only');
    }

    /** An unmanaged run has no lock, so nothing waits on it: its move tells only itself. */
    public static function test_an_unmanaged_runs_move_tells_no_other_run()
    {
        static::__clean();
        $moved = static::__fixture_row('plain_task', Task_Runner::running_fields());
        $pending = static::__fixture_row('plain_task');
        static::__clean();
        static::__watch('Task_Changed_Topic', $moved, 1);
        static::__watch('Task_Changed_Topic', $pending, 1);

        Task_Notify::lifecycle($moved);

        static::__assert_equals([$moved], static::__changed_ids());
    }

    /** Only watched waiters are published to, each on the sites watching it. */
    public static function test_a_waiter_frame_goes_only_where_that_waiter_is_watched()
    {
        static::__clean();
        DB::table('_tasks')->where('class', Task_Concurrency_Fixture_Service::class)->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        $holder = static::__fixture_row('group_alpha', Task_Runner::running_fields());
        $watched = static::__fixture_row('group_beta');
        $unwatched = static::__fixture_row('group_alpha');
        static::__clean();
        static::__watch('Task_Changed_Topic', $watched, 3);
        static::__watch('Task_Changed_Topic', $watched, 8);

        Task_Notify::lifecycle($holder);

        static::__assert_equals(
            [['Task_Changed_Topic', 3, $watched], ['Task_Changed_Topic', 8, $watched]],
            static::__sorted(static::__published()),
            'the watched waiter, once per site; nothing for the run nobody watches'
        );
    }

    /** Under a pool lock the waiters' frames are held back with the rest (a worker's settle). */
    public static function test_waiter_frames_under_a_pool_lock_wait_for_flush_deferred()
    {
        static::__clean();
        DB::table('_tasks')->where('class', Task_Concurrency_Fixture_Service::class)->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        $holder = static::__fixture_row('group_alpha', Task_Runner::running_fields());
        $waiter = static::__fixture_row('group_beta');
        static::__clean();
        static::__watch('Task_Changed_Topic', $waiter, 1);

        Task_Pool::lock(Task_Pool::ON_DEMAND);
        try {
            Task_Notify::lifecycle($holder);
            static::__assert_equals([], static::__published(), 'nothing is published under the lock');
        } finally {
            Task_Pool::unlock(Task_Pool::ON_DEMAND);
        }

        Task_Notify::flush_deferred();
        static::__assert_equals([['Task_Changed_Topic', 1, $waiter]], static::__published());
    }

    /**
     * End to end, every way a run starts or settles goes through lifecycle(): an inline run of
     * one group member tells the pending run of another when it starts and when it settles -
     * and the settle's frame comes after the lock is released.
     */
    public static function test_a_real_run_tells_its_groups_waiters_when_it_starts_and_settles()
    {
        static::__clean();
        DB::table('_tasks')->where('class', Task_Concurrency_Fixture_Service::class)->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        $waiter = static::__fixture_row('group_beta');
        static::__clean();
        static::__watch('Task_Changed_Topic', $waiter, 1);

        $run = Task::internal('Task_Concurrency_Fixture_Service', 'group_alpha');
        self::$runs[] = (int) $run->id;

        static::__assert_equals([$waiter, $waiter], static::__changed_ids(), 'one frame when the holder started, one when it settled');
        static::__assert_false(Task_Concurrency::is_group_running('fixture_group'), 'the group lock is free by the time the waiter re-reads');
        static::__assert_equals('queued', Task_Waiting::of(Task_Run_Model::find($waiter))['reason'], 'so its next read no longer names a holder');
    }

    /** A cancel, a kill and an abandonment settle through lifecycle() too. */
    public static function test_a_cancelled_run_tells_the_waiters_on_its_lock()
    {
        static::__clean();
        DB::table('_tasks')->where('class', Task_Concurrency_Fixture_Service::class)->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        $cancelled = static::__fixture_row('group_alpha');
        $waiter = static::__fixture_row('group_beta');
        static::__clean();
        static::__watch('Task_Changed_Topic', $waiter, 1);

        static::__assert_true(Task_Run_Model::find($cancelled)->cancel('not needed'));

        static::__assert_equals([$waiter], static::__changed_ids());
    }

    public static function test_nobody_watching_publishes_nothing()
    {
        static::__clean();
        static::__watch('Task_Changed_Topic', 902, 1);

        Task_Notify::changed(901, true, true);
        Task_Notify::lifecycle(901);

        static::__assert_equals([], static::__published());
    }

    /**
     * Under a pool lock a frame is held back (no outbound call under the lock), and
     * flush_deferred() sends each held frame once.
     */
    public static function test_frames_under_a_pool_lock_wait_for_flush_deferred()
    {
        static::__clean();
        static::__watch('Task_Changed_Topic', 901, 1);

        Task_Pool::lock(Task_Pool::ON_DEMAND);
        try {
            Task_Notify::changed(901);
            Task_Notify::changed(901);
            static::__assert_equals([], static::__published(), 'nothing is published under the lock');
        } finally {
            Task_Pool::unlock(Task_Pool::ON_DEMAND);
        }

        Task_Notify::flush_deferred();
        static::__assert_equals([['Task_Changed_Topic', 1, 901]], static::__published(), 'one frame after the lock, however many changes');

        Task_Notify::flush_deferred();
        static::__assert_equals(1, count(static::__published()), 'a second flush sends nothing');
    }

    /**
     * A run's own writes announce themselves: a report is a change, an output line is output,
     * an operator line is output.
     */
    public static function test_a_runs_writes_announce_themselves()
    {
        static::__clean();
        $task = static::__run();
        static::__watch('Task_Changed_Topic', $task->get_id(), 1);
        static::__watch('Task_Output_Topic', $task->get_id(), 1);

        $task->heartbeat();
        $task->flush();
        static::__assert_equals([['Task_Changed_Topic', 1, $task->get_id()]], static::__published(), 'a report');

        static::__clean();
        static::__watch('Task_Changed_Topic', $task->get_id(), 1);
        static::__watch('Task_Output_Topic', $task->get_id(), 1);
        $task->stdout('a line');
        $task->flush();
        static::__assert_equals([['Task_Output_Topic', 1, $task->get_id()]], static::__published(), 'an output line');

        static::__clean();
        static::__watch('Task_Changed_Topic', $task->get_id(), 1);
        static::__watch('Task_Output_Topic', $task->get_id(), 1);
        Task_Instance::record_operator_line($task->get_id(), 'operator');
        static::__assert_equals([['Task_Output_Topic', 1, $task->get_id()]], static::__published(), 'an operator line');
    }
}
