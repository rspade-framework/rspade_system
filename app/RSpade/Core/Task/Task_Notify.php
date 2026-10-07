<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Task\Task_Pool;

/**
 * Task_Notify - the one place the task system publishes its realtime frames.
 *
 * Three topics, each a "something changed, go look" with no data beyond the filter
 * (frames are notification-only; the watcher refetches through a gated endpoint):
 *
 *   Task_Changed_Topic {id}       the run's lifecycle, reports, messages or attachments
 *   Task_Output_Topic {id}        the run's output (stdout / stderr / operator lines) -
 *                                 separate because output is the high-volume, niche feed
 *   Task_List_Changed_Topic {}    a run entered, left or moved between lifecycle states:
 *                                 what a list of runs (the active grid) watches
 *
 * SITE ROUTING. A frame reaches only connections on the site it is published to, and the
 * process writing a run knows nothing about who is watching or which site their session is
 * on. Each frame is therefore published once per site that holds a matching subscription in
 * the relay's registry - and not at all when nobody is watching, which is the common case: a
 * running task's report then costs one Redis set read and no frame.
 *
 * NEVER UNDER A POOL LOCK. A process holding a task pool lock may make no outbound call
 * (Core/Task/CLAUDE.md, THE RULE), and a worker settles a run under its lock. While this
 * process holds one, frames are only RECORDED; whoever lets the lock go calls flush_deferred().
 */
class Task_Notify
{
    /** @var array<string, true> frames held back under a pool lock, keyed "topic|id" */
    private static array $deferred = [];

    /**
     * A run changed. $changed: its row, reports, messages or attachments; $output: its output.
     */
    public static function changed(int $task_id, bool $changed = true, bool $output = false): void
    {
        if ($changed) {
            static::__send('Task_Changed_Topic', $task_id);
        }
        if ($output) {
            static::__send('Task_Output_Topic', $task_id);
        }
    }

    /**
     * A run entered, left or moved between lifecycle states: its own watchers and every list
     * watcher are told.
     */
    public static function lifecycle(int $task_id): void
    {
        static::__send('Task_Changed_Topic', $task_id);
        static::__send('Task_List_Changed_Topic', null);
    }

    /**
     * Send what was held back while this process held a pool lock. Called by whoever releases
     * the lock; a no-op when nothing is waiting.
     */
    public static function flush_deferred(): void
    {
        $held = array_keys(self::$deferred);
        self::$deferred = [];

        foreach ($held as $key) {
            [$topic, $id] = explode('|', $key, 2);
            static::__send($topic, $id === '' ? null : (int) $id);
        }
    }

    private static function __send(string $topic, ?int $task_id): void
    {
        if (!Realtime::is_enabled()) {
            return;
        }

        if (Task_Pool::holds_lock()) {
            self::$deferred[$topic . '|' . ($task_id ?? '')] = true;

            return;
        }

        // A worker is a long-lived process and the registry memo is per process; a watcher
        // who opened the page after this process's last read must still be found.
        Realtime::reset_registry_memo();

        $sites = [];
        foreach (Realtime::subscribed_registry_entries() as $entry) {
            if ($entry['topic'] !== $topic) {
                continue;
            }
            if ($task_id !== null && (int) ($entry['filter']['id'] ?? 0) !== $task_id) {
                continue;
            }
            $sites[$entry['site_id']] = true;
        }

        $data = $task_id === null ? [] : ['id' => $task_id];
        foreach (array_keys($sites) as $site_id) {
            Realtime::publish($topic, $data, $site_id);
        }
    }
}
