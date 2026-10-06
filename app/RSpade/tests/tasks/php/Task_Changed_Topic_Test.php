<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Changed_Topic;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Task_Changed_Topic - the frame an operator screen follows a task by.
 *
 * notify() reads the relay's subscriber registry (the Redis set rsx_rt:subs, seeded here
 * the way the relay writes it) and publishes once per site holding a subscription to THAT
 * task id; it holds frames back while this process holds the task pool lock and sends
 * them on flush_deferred(). Publishes are observed through Realtime's capture seam, with
 * realtime switched on for the class so notify() does not return at its enablement gate.
 */
class Task_Changed_Topic_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FIX = 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Exec_Fixture_Service';

    private static $previous_enabled = null;

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
        DB::table('_tasks')->where('class', self::FIX)->where('method', 'marker_a')->where('queue', 'topic_test')->delete();
    }

    private static function __clean(): void
    {
        Realtime::_testing_redis()->del('rsx_rt:subs');
        Realtime::reset_registry_memo();
        Realtime::_testing_start_publish_capture();
    }

    /** Add one subscription to the registry the way the relay records it. */
    private static function __watch(int $task_id, int $site_id, string $topic = 'Task_Changed_Topic'): void
    {
        Realtime::_testing_redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => $site_id,
            'topic' => $topic,
            'filter' => ['id' => $task_id],
        ]));
    }

    /** The Task_Changed_Topic publishes captured so far, as [site_id => id]. */
    private static function __published(): array
    {
        $out = [];
        foreach (Realtime::_testing_published() as $publish) {
            if ($publish['topic'] === 'Task_Changed_Topic') {
                $out[] = ['site_id' => $publish['site_id'], 'id' => $publish['data']['id']];
            }
        }

        return $out;
    }

    /**
     * task-topic-01 - one frame per site that watches THIS task; a watcher of another task
     * or another topic gets nothing.
     */
    public static function test_notify_reaches_each_watching_site_once()
    {
        static::__clean();
        static::__watch(901, 1);
        static::__watch(901, 7);
        static::__watch(902, 3);
        static::__watch(901, 5, 'Some_Other_Topic');

        Task_Changed_Topic::notify(901);

        $published = static::__published();
        usort($published, fn ($a, $b) => $a['site_id'] <=> $b['site_id']);
        static::__assert_equals([['site_id' => 1, 'id' => 901], ['site_id' => 7, 'id' => 901]], $published);
    }

    /**
     * task-topic-02 - nobody watching: no frame at all.
     */
    public static function test_notify_with_no_watcher_publishes_nothing()
    {
        static::__clean();
        static::__watch(902, 1);

        Task_Changed_Topic::notify(901);

        static::__assert_equals([], static::__published());
    }

    /**
     * task-topic-03 - under the pool lock a frame is held back (no outbound call under the
     * lock), and flush_deferred() sends it once the lock is gone.
     */
    public static function test_notify_under_the_pool_lock_waits_for_flush()
    {
        static::__clean();
        static::__watch(901, 1);

        Task_Pool::lock();
        try {
            Task_Changed_Topic::notify(901);
            Task_Changed_Topic::notify(901);
            static::__assert_equals([], static::__published(), 'nothing is published under the lock');
        } finally {
            Task_Pool::unlock();
        }

        Task_Changed_Topic::flush_deferred();
        static::__assert_equals([['site_id' => 1, 'id' => 901]], static::__published(), 'one frame after the lock, however many notifies');

        Task_Changed_Topic::flush_deferred();
        static::__assert_equals(1, count(static::__published()), 'a second flush sends nothing');
    }

    /**
     * task-topic-04 - a task's own writes announce themselves: a log line, a heartbeat and a
     * result each publish for a watched row.
     */
    public static function test_task_writes_notify()
    {
        static::__clean();

        $id = (int) DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'topic_test',
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        static::__watch($id, 1);

        $task = Task_Instance::find($id);
        $task->info('a line');
        $task->heartbeat();
        $task->set_result(['ok' => true]);

        static::__assert_equals(3, count(static::__published()), 'log, heartbeat and result each publish');
    }

    /**
     * task-topic-05 - subscribing is a sysadmin capability.
     */
    public static function test_can_subscribe_is_sysadmin_only()
    {
        static::__acting_as_user(1);
        static::__assert_true(Session::is_developer(), 'fixture: user 1 is a developer');
        static::__assert_true(Task_Changed_Topic::can_subscribe(['id' => 1]), 'a developer may watch a task');

        Session::logout();
        static::__assert_false(Task_Changed_Topic::can_subscribe(['id' => 1]), 'a signed-out caller may not');
    }
}
