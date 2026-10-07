<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Retention_Service - keeps task history to the size rsx.tasks.retention allows.
 *
 * Every 30 minutes, over FINISHED runs only (completed, failed, stopped, killed, cancelled):
 *
 *   1. TRUNCATE: a run that finished more than output_truncate_after_minutes ago has its output
 *      cut to its last output_keep_lines lines and its attachments unlinked - each blob is then
 *      released if nothing else references it (File_Disposal_Service). The run is stamped
 *      output_truncated_at so it is never visited again.
 *   2. PURGE: a run that finished more than purge_after_minutes ago is deleted, its reports,
 *      output and messages with it (foreign keys cascade); its attachments are unlinked first.
 *   3. TEMP DIRECTORIES: a run's temp directory that outlived its run is removed.
 *
 * Every pass walks its set by keyset (id), a page at a time, so the whole backlog is handled
 * however large it is.
 */
class Task_Retention_Service extends Rsx_Service_Abstract
{
    /** Runs examined per page of a pass. */
    const PAGE = 500;

    #[Task('Truncate old task output, unlink old task attachments, purge old task runs')]
    #[Exclusive]
    #[Schedule('*/30 * * * *')]
    public static function sweep(Task_Instance $task, array $params = [])
    {
        [$truncate_minutes, $keep_lines, $purge_minutes] = static::__config();

        $truncated = static::truncate_finished_runs($truncate_minutes, $keep_lines);
        $purged = static::purge_finished_runs($purge_minutes);
        $temp_dirs = static::remove_orphaned_temp_directories();

        if ($truncated || $purged || $temp_dirs) {
            $task->summary("Truncated {$truncated} run(s), purged {$purged} run(s), removed {$temp_dirs} temp director(ies).");
        }

        return null;
    }

    /**
     * Truncate every finished run older than $minutes that has not been truncated yet.
     * Returns how many runs were truncated.
     */
    public static function truncate_finished_runs(int $minutes, int $keep_lines): int
    {
        $cutoff = static::__cutoff($minutes);
        $count = 0;
        $last_id = 0;

        while (true) {
            $ids = DB::table('_tasks')
                ->whereNull('output_truncated_at')
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff)
                ->where('id', '>', $last_id)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return $count;
            }

            foreach ($ids as $id) {
                $last_id = (int) $id;
                static::__truncate_output((int) $id, $keep_lines);
                static::__unlink_attachments((int) $id);
                DB::table('_tasks')->where('id', $id)->update(['output_truncated_at' => now()->format('Y-m-d H:i:s.v')]);
                $count++;
            }
        }
    }

    /**
     * Delete every finished run older than $minutes. Returns how many were deleted.
     */
    public static function purge_finished_runs(int $minutes): int
    {
        $cutoff = static::__cutoff($minutes);
        $count = 0;

        while (true) {
            $ids = DB::table('_tasks')
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff)
                ->whereNotIn('status_id', Task_Run_Model::LIVE_STATUSES)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return $count;
            }

            foreach ($ids as $id) {
                static::__unlink_attachments((int) $id);
                DB::table('_tasks')->where('id', $id)->delete();
                $count++;
            }
        }
    }

    /**
     * Remove every task temp directory whose run is gone or finished. Returns how many.
     */
    public static function remove_orphaned_temp_directories(): int
    {
        $base = Rsx_Project_Paths::tasks_dir();
        if (!is_dir($base)) {
            return 0;
        }

        $removed = 0;
        foreach (File::directories($base) as $dir) {
            if (!preg_match('/^task_(\d+)$/', basename($dir), $matches)) {
                continue;
            }

            $status_id = DB::table('_tasks')->where('id', (int) $matches[1])->value('status_id');
            if ($status_id === null || !in_array((int) $status_id, Task_Run_Model::LIVE_STATUSES, true)) {
                File::deleteDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /** Keep the last $keep_lines output rows of one run: everything below the cutoff id goes. */
    private static function __truncate_output(int $task_id, int $keep_lines): void
    {
        if ($keep_lines === 0) {
            DB::table('_task_output')->where('task_id', $task_id)->delete();

            return;
        }

        $boundary = DB::table('_task_output')
            ->where('task_id', $task_id)
            ->orderByDesc('id')
            ->offset($keep_lines - 1)
            ->limit(1)
            ->value('id');

        if ($boundary === null) {
            return;
        }

        DB::table('_task_output')->where('task_id', $task_id)->where('id', '<', $boundary)->delete();
    }

    /** Delete a run's attachment rows, then release each blob nothing else references. */
    private static function __unlink_attachments(int $task_id): void
    {
        $storage_ids = DB::table('_task_attachments')->where('task_id', $task_id)->pluck('file_storage_id')->map(fn ($id) => (int) $id)->all();
        if ($storage_ids === []) {
            return;
        }

        DB::table('_task_attachments')->where('task_id', $task_id)->delete();

        foreach (array_unique($storage_ids) as $storage_id) {
            File_Disposal_Service::release_blob_if_orphaned($storage_id);
        }
    }

    private static function __cutoff(int $minutes): string
    {
        return date('Y-m-d H:i:s', time() - $minutes * 60) . '.000';
    }

    /**
     * rsx.tasks.retention, validated: [output_truncate_after_minutes, output_keep_lines,
     * purge_after_minutes].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function __config(): array
    {
        $values = [];
        foreach (['output_truncate_after_minutes' => 1, 'output_keep_lines' => 0, 'purge_after_minutes' => 1] as $key => $minimum) {
            $value = config("rsx.tasks.retention.{$key}");
            if (!is_int($value) || $value < $minimum) {
                throw new \RuntimeException("rsx.tasks.retention.{$key} must be an integer of at least {$minimum}, got " . var_export($value, true));
            }
            $values[] = $value;
        }

        return $values;
    }
}
