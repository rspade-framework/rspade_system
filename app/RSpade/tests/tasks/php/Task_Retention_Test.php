<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Retention_Service;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Task_Retention_Service - task history kept to the size rsx.tasks.retention allows, over
 * FINISHED runs only:
 *
 *   TRUNCATE  a run finished longer ago than the truncate window keeps its last N output lines,
 *             its attachments are unlinked (each blob released when nothing else references
 *             it), and it is stamped output_truncated_at so it is never visited again;
 *   PURGE     a run finished longer ago than the purge window is deleted with its reports,
 *             output and messages;
 *   TEMP      a temp directory whose run is finished or gone is removed.
 *
 * Runs are planted with completed_at in the past; the passes are called with explicit windows.
 * Every test removes all runs first, so the counts are this test's alone. Attachments release
 * blobs, which commit, so this class provisions a clean baseline and opts out of transactions.
 */
class Task_Retention_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private static function __clear(): void
    {
        DB::table('_tasks')->delete();
    }

    /** A run with $lines output lines, a report, a message and an attachment. */
    private static function __run(int $status_id, ?int $finished_minutes_ago, int $lines = 10): Task_Instance
    {
        $fields = ['status_id' => $status_id];
        if ($finished_minutes_ago !== null) {
            $fields['completed_at'] = date('Y-m-d H:i:s', time() - $finished_minutes_ago * 60) . '.000';
        }
        $task = Task_Instance::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, $fields));

        for ($i = 1; $i <= $lines; $i++) {
            $task->stdout("line {$i}");
        }
        $task->state(['kept' => true]);
        $task->message('hello');
        $task->attach_bytes('result', 'retention ' . bin2hex(random_bytes(8)), 'result.txt');
        $task->flush();

        return $task;
    }

    private static function __lines(Task_Instance $task): array
    {
        return array_column(Task_Run_Model::find($task->get_id())->output_after(), 'line');
    }

    private static function __storage_id(Task_Instance $task): int
    {
        return (int) DB::table('_task_attachments')->where('task_id', $task->get_id())->value('file_storage_id');
    }

    // -------------------------------------------------------------------------
    // Truncate
    // -------------------------------------------------------------------------

    public static function test_truncation_keeps_the_last_lines_and_unlinks_attachments()
    {
        static::__clear();
        $old = static::__run(Task_Run_Model::STATUS_COMPLETED, 120);
        $storage_id = static::__storage_id($old);

        static::__assert_equals(1, Task_Retention_Service::truncate_finished_runs(60, 3));

        static::__assert_equals(['line 8', 'line 9', 'line 10'], static::__lines($old), 'the last 3 lines are kept');
        $run = Task_Run_Model::find($old->get_id());
        static::__assert_not_null($run->output_truncated_at, 'stamped');
        static::__assert_equals([], $run->attachments(), 'attachments are unlinked');
        static::__assert_null(File_Storage_Model::find($storage_id), 'and the orphaned blob released');
        static::__assert_equals(['kept' => true], $run->state(), 'reports are kept');
        static::__assert_equals(['hello'], array_column($run->messages_after(), 'body'), 'and messages');

        static::__assert_equals(0, Task_Retention_Service::truncate_finished_runs(60, 3), 'a truncated run is never visited again');
    }

    public static function test_truncation_leaves_recent_and_live_runs_alone()
    {
        static::__clear();
        $recent = static::__run(Task_Run_Model::STATUS_FAILED, 10);
        $running = static::__run(Task_Run_Model::STATUS_RUNNING, null);

        static::__assert_equals(0, Task_Retention_Service::truncate_finished_runs(60, 3));

        static::__assert_equals(10, count(static::__lines($recent)), 'a recent run keeps its output');
        static::__assert_equals(10, count(static::__lines($running)), 'a live run keeps its output');
        static::__assert_equals(1, count(Task_Run_Model::find($running->get_id())->attachments()));
        static::__assert_null(Task_Run_Model::find($recent->get_id())->output_truncated_at);
    }

    public static function test_keep_lines_zero_deletes_all_output()
    {
        static::__clear();
        $old = static::__run(Task_Run_Model::STATUS_KILLED, 120);

        static::__assert_equals(1, Task_Retention_Service::truncate_finished_runs(60, 0));
        static::__assert_equals([], static::__lines($old));
    }

    // -------------------------------------------------------------------------
    // Purge
    // -------------------------------------------------------------------------

    public static function test_purge_deletes_old_finished_runs_and_everything_beside_them()
    {
        static::__clear();
        $old = static::__run(Task_Run_Model::STATUS_CANCELLED, 600);
        $storage_id = static::__storage_id($old);
        $recent = static::__run(Task_Run_Model::STATUS_COMPLETED, 10);
        $live = static::__run(Task_Run_Model::STATUS_PENDING, null);

        static::__assert_equals(1, Task_Retention_Service::purge_finished_runs(300));

        static::__assert_null(Task_Run_Model::find($old->get_id()), 'the old run is gone');
        foreach (['_task_output', '_task_reports', '_task_messages', '_task_attachments'] as $table) {
            static::__assert_equals(0, DB::table($table)->where('task_id', $old->get_id())->count(), "{$table} rows went with it");
        }
        static::__assert_null(File_Storage_Model::find($storage_id), 'its orphaned blob was released');

        static::__assert_not_null(Task_Run_Model::find($recent->get_id()), 'a recent run is kept');
        static::__assert_not_null(Task_Run_Model::find($live->get_id()), 'a live run is kept');
    }

    // -------------------------------------------------------------------------
    // Temp directories
    // -------------------------------------------------------------------------

    public static function test_temp_directories_of_finished_or_missing_runs_are_removed()
    {
        static::__clear();
        $finished = static::__run(Task_Run_Model::STATUS_COMPLETED, 1, 0);
        $running = static::__run(Task_Run_Model::STATUS_RUNNING, null, 0);

        $finished_dir = $finished->get_temp_dir();
        $running_dir = $running->get_temp_dir();
        $missing_dir = Rsx_Project_Paths::tasks_dir() . '/task_2147480000';
        $foreign_dir = Rsx_Project_Paths::tasks_dir() . '/not_a_task_dir_' . uniqid();
        mkdir($missing_dir, 0755, true);
        mkdir($foreign_dir, 0755, true);

        try {
            static::__assert_equals(2, Task_Retention_Service::remove_orphaned_temp_directories());

            static::__assert_false(is_dir($finished_dir), 'a finished run\'s directory is removed');
            static::__assert_false(is_dir($missing_dir), 'so is one whose run is gone');
            static::__assert_true(is_dir($running_dir), 'a live run keeps its directory');
            static::__assert_true(is_dir($foreign_dir), 'a directory that names no run is not touched');
        } finally {
            $running->cleanup_temp_dir();
            @rmdir($foreign_dir);
            @rmdir($missing_dir);
        }
    }

    // -------------------------------------------------------------------------
    // The scheduled sweep
    // -------------------------------------------------------------------------

    public static function test_the_sweep_runs_every_pass_and_summarizes()
    {
        static::__clear();
        // Past both configured windows (7 and 21 days): truncated, then purged.
        $old = static::__run(Task_Run_Model::STATUS_COMPLETED, 40000);

        $run = Task::internal('Task_Retention_Service', 'sweep');

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_contains('Truncated 1 run(s), purged 1 run(s)', (string) $run->summary());
        static::__assert_null(Task_Run_Model::find($old->get_id()));
    }

    public static function test_the_sweep_refuses_an_invalid_configuration()
    {
        $original = config('rsx.tasks.retention');
        try {
            config(['rsx.tasks.retention.output_keep_lines' => -1]);
            static::__assert_throws(\RuntimeException::class, fn () => Task::internal('Task_Retention_Service', 'sweep'), 'rsx.tasks.retention.output_keep_lines must be an integer of at least 0');

            config(['rsx.tasks.retention' => $original]);
            config(['rsx.tasks.retention.purge_after_minutes' => '30240']);
            static::__assert_throws(\RuntimeException::class, fn () => Task::internal('Task_Retention_Service', 'sweep'), 'rsx.tasks.retention.purge_after_minutes must be an integer');
        } finally {
            config(['rsx.tasks.retention' => $original]);
        }
    }
}
