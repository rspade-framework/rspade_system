<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;
use App\RSpade\Core\Task\Task_Pool;

/**
 * Task_Changed_Topic - "this _tasks row changed; go look". Filter: {id}.
 *
 * Published for every write a running task makes to its own row - a log line (the
 * task's own $task->info() family and its captured stdout), a heartbeat, a result,
 * a start, a completion or failure - and for a kill. It exists so an operator screen
 * (the /_sys task detail and its live console) can follow a task as it runs; the
 * frame carries the id only, and the screen refetches through its gated endpoint.
 *
 * Subscribing is a sysadmin capability: task params and logs are operator data.
 *
 * SITE ROUTING. A frame reaches only connections on the site it is published to, and
 * the worker writing the row knows nothing about who is watching or which site their
 * session is on. notify() therefore reads the relay's subscriber registry and publishes
 * once per site that holds a subscription to THIS row - and not at all when nobody is
 * watching, which is the common case: a running task's log line then costs one Redis
 * set read and no frame.
 *
 * NEVER UNDER THE POOL LOCK. A process holding the task pool lock may make no outbound
 * call (Core/Task/CLAUDE.md, THE RULE), and a worker settles a run - mark_failed() logs
 * its error line - under that lock. notify() therefore only RECORDS the id while this
 * process holds it, and the worker calls flush_deferred() each time it lets the lock go.
 */
class Task_Changed_Topic extends Realtime_Topic_Abstract
{
    /** @var array<int, true> task ids notify() held back under the pool lock */
    private static array $deferred = [];

    public static function can_subscribe(array $filter = []): bool
    {
        return Permission::is_sysadmin();
    }

    /**
     * Tell every watcher of task $task_id that its row changed.
     *
     * @param int $task_id _tasks.id
     */
    public static function notify(int $task_id): void
    {
        if (!Realtime::is_enabled()) {
            return;
        }

        if (Task_Pool::holds_lock()) {
            self::$deferred[$task_id] = true;

            return;
        }

        // A worker is a long-lived process and the registry memo is per process; a watcher
        // who opened the page after this worker's last read must still be found.
        Realtime::reset_registry_memo();

        $sites = [];
        foreach (Realtime::subscribed_registry_entries() as $entry) {
            if ($entry['topic'] === static::__class_topic() && (int) ($entry['filter']['id'] ?? 0) === $task_id) {
                $sites[$entry['site_id']] = true;
            }
        }

        foreach (array_keys($sites) as $site_id) {
            Realtime::publish(static::__class_topic(), ['id' => $task_id], $site_id);
        }
    }

    /**
     * Send what notify() held back while this process held the pool lock. Called by the
     * worker after every unlock; a no-op when nothing is waiting.
     */
    public static function flush_deferred(): void
    {
        $ids = array_keys(self::$deferred);
        self::$deferred = [];

        foreach ($ids as $task_id) {
            static::notify($task_id);
        }
    }

    /** The topic name as the browser and the registry spell it: the simple class name. */
    private static function __class_topic(): string
    {
        return class_basename(static::class);
    }
}
