<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\RSpade\Core\Files\Rsx_Temp_Files;
use App\RSpade\Core\Files\Temp_File_Model;
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
 *      cut to its last output_keep_lines lines and its attachments deleted - each one's temp
 *      file (Rsx_Temp_Files), which takes the attachment row with it. The run is stamped
 *      output_truncated_at so it is never visited again.
 *   2. PURGE: a run that finished more than purge_after_minutes ago is deleted, its reports,
 *      output and messages with it (foreign keys cascade); its attachments are deleted first.
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
        $truncated = 0;
        $purged = 0;
        $temp_dirs = 0;
        $stopped = function () use (&$truncated, &$purged, &$temp_dirs) {
            return "Stopped after truncating {$truncated} run(s), purging {$purged} run(s) and removing {$temp_dirs} temp director(ies).";
        };

        $task->status('Truncating finished runs');
        $truncated = static::truncate_finished_runs($truncate_minutes, $keep_lines, $task);
        if ($task->is_stop_requested()) {
            $task->stdout('Stop requested - ' . lcfirst($stopped()));
            $task->summary($stopped());

            return null;
        }

        if ($truncated > 0) {
            $task->stdout(
                "Truncated the output of {$truncated} finished run(s) to its last {$keep_lines} line(s) and deleted their attached files "
                . '(finished more than ' . duration_to_human($truncate_minutes * 60) . ' ago).'
            );
        }

        $task->status('Purging finished runs');
        $purged = static::purge_finished_runs($purge_minutes, $task);
        if ($task->is_stop_requested()) {
            $task->stdout('Stop requested - ' . lcfirst($stopped()));
            $task->summary($stopped());

            return null;
        }

        if ($purged > 0) {
            $task->stdout("Purged {$purged} finished run(s) and everything they recorded (finished more than " . duration_to_human($purge_minutes * 60) . ' ago).');
        }

        $task->status('Removing orphaned temp directories');
        $temp_dirs = static::remove_orphaned_temp_directories($task);
        if ($task->is_stop_requested()) {
            $task->stdout('Stop requested - ' . lcfirst($stopped()));
            $task->summary($stopped());

            return null;
        }

        if ($temp_dirs > 0) {
            $task->stdout("Removed {$temp_dirs} task temp director(ies) whose run had finished or no longer exists.");
        }
        if ($truncated === 0 && $purged === 0 && $temp_dirs === 0) {
            $task->stdout('No finished run is old enough to truncate or purge, and no temp directory is orphaned.');
        }

        $task->summary("Truncated {$truncated} run(s), purged {$purged} run(s), removed {$temp_dirs} temp director(ies).");

        return null;
    }

    /**
     * Truncate every finished run older than $minutes that has not been truncated yet.
     * Returns how many runs were truncated; a stop requested on $task ends the pass early.
     */
    public static function truncate_finished_runs(int $minutes, int $keep_lines, ?Task_Instance $task = null): int
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
                if ($task?->is_stop_requested()) {
                    return $count;
                }
                $task?->heartbeat();

                $last_id = (int) $id;
                static::__truncate_output((int) $id, $keep_lines);
                static::__unlink_attachments((int) $id);
                DB::table('_tasks')->where('id', $id)->update(['output_truncated_at' => now()->format('Y-m-d H:i:s.v')]);
                $count++;
            }
        }
    }

    /**
     * Delete every finished run older than $minutes. Returns how many were deleted; a stop
     * requested on $task ends the pass early.
     */
    public static function purge_finished_runs(int $minutes, ?Task_Instance $task = null): int
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
                if ($task?->is_stop_requested()) {
                    return $count;
                }
                $task?->heartbeat();

                static::__unlink_attachments((int) $id);
                DB::table('_tasks')->where('id', $id)->delete();
                $count++;
            }
        }
    }

    /**
     * Remove every task temp directory whose run is gone or finished. Returns how many; a stop
     * requested on $task ends the pass early.
     */
    public static function remove_orphaned_temp_directories(?Task_Instance $task = null): int
    {
        $base = Rsx_Project_Paths::tasks_dir();
        if (!is_dir($base)) {
            return 0;
        }

        $removed = 0;
        foreach (File::directories($base) as $dir) {
            if ($task?->is_stop_requested()) {
                return $removed;
            }
            $task?->heartbeat();

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

    /**
     * Delete a run's attachments: each one's temp file, which deletes the attachment row with
     * it (the foreign key cascades).
     */
    private static function __unlink_attachments(int $task_id): void
    {
        $temp_file_ids = DB::table('_task_attachments')->where('task_id', $task_id)->pluck('temp_file_id')->map(fn ($id) => (int) $id)->all();

        foreach (Temp_File_Model::whereIn('id', $temp_file_ids)->result_set() as $temp_file) {
            Rsx_Temp_Files::delete($temp_file);
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
