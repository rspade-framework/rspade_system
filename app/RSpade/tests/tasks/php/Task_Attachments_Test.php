<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Blob_References;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Files\Temp_File_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Named files a task attaches to its run (attach_bytes() / attach_file()), read back through
 * Task_Run_Model::attachments() / attachment($name).
 *
 * The bytes become a temp file of their own (Rsx_Temp_Files - uploads/_temp/, a random key)
 * and the _task_attachments row names it. Nothing is shared and nothing touches the
 * content-addressed blob store: identical bytes attached twice are two temp files, attaching a
 * name again deletes the earlier file at once, and deleting the temp file deletes the row.
 *
 * Temp files are written to disk, so this class commits: it provisions a clean baseline and
 * opts out of per-test transactions. The store itself is the run's isolated test store
 * (tests/CLAUDE.md, File-storage isolation).
 */
class Task_Attachments_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private static function __instance(): Task_Instance
    {
        return Task_Instance::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields()));
    }

    private static function __run(Task_Instance $task): Task_Run_Model
    {
        return Task_Run_Model::find($task->get_id());
    }

    /** Bytes no other test (or earlier run) stored, so the blob is this test's alone. */
    private static function __unique_bytes(string $marker): string
    {
        return "task attachment {$marker} " . bin2hex(random_bytes(8)) . "\n";
    }

    // -------------------------------------------------------------------------
    // Attaching
    // -------------------------------------------------------------------------

    public static function test_attach_bytes_records_the_file_as_a_temp_file()
    {
        $task = static::__instance();
        $bytes = static::__unique_bytes('report');

        $task->attach_bytes('report', $bytes, 'report.txt');

        $attachment = static::__run($task)->attachment('report');
        static::__assert_not_null($attachment);
        static::__assert_equals('report.txt', $attachment->file_name);
        static::__assert_equals('text/plain', $attachment->mime_type, 'sniffed from the bytes');
        static::__assert_equals(strlen($bytes), (int) $attachment->size);
        static::__assert_equals($bytes, $attachment->read_bytes());
        static::__assert_equals(['name' => 'report', 'file_name' => 'report.txt', 'mime_type' => 'text/plain', 'size' => strlen($bytes)], $attachment->to_listing_array());

        $temp_file = Temp_File_Model::find($attachment->temp_file_id);
        static::__assert_not_null($temp_file, 'the bytes are a temp file');
        static::__assert_true(str_starts_with($temp_file->storage_path(), Rsx_File_Paths::temp_root() . '/'), 'under uploads/_temp');
        static::__assert_not_equals(hash('sha256', $bytes), $temp_file->temp_key, 'keyed at random, never by content');
        static::__assert_equals(0, DB::table('_file_storage')->where('hash', hash('sha256', $bytes))->count(), 'nothing entered the blob store');

        $stream = $attachment->read_stream();
        static::__assert_equals($bytes, stream_get_contents($stream));
        fclose($stream);

        $response = $attachment->download_response();
        static::__assert_contains('attachment', (string) $response->headers->get('Content-Disposition'));
        static::__assert_contains('report.txt', (string) $response->headers->get('Content-Disposition'));
    }

    public static function test_attach_file_copies_a_file_and_leaves_the_source()
    {
        $task = static::__instance();
        $bytes = static::__unique_bytes('csv');
        $path = Rsx_Project_Paths::tmp_path('rsxtest_task_attachment_' . uniqid() . '.csv');
        file_put_contents($path, $bytes);

        try {
            $task->attach_file('export', $path, null, 'text/csv');

            $attachment = static::__run($task)->attachment('export');
            static::__assert_equals(basename($path), $attachment->file_name, 'the source name by default');
            static::__assert_equals('text/csv', $attachment->mime_type, 'the mime type given');
            static::__assert_equals($bytes, $attachment->read_bytes());
            static::__assert_true(is_file($path), 'the source file is left where it is');
        } finally {
            @unlink($path);
        }
    }

    public static function test_attachments_are_listed_by_name()
    {
        $task = static::__instance();
        $task->attach_bytes('zeta', static::__unique_bytes('z'), 'z.txt');
        $task->attach_bytes('alpha', static::__unique_bytes('a'), 'a.txt');

        $run = static::__run($task);
        static::__assert_equals(['alpha', 'zeta'], array_map(fn ($a) => $a->name, $run->attachments()));
        static::__assert_null($run->attachment('missing'));
    }

    public static function test_an_attachment_refuses_a_bad_name_or_an_unreadable_file()
    {
        $task = static::__instance();

        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->attach_bytes('', 'x', 'x.txt'), '1 to 255 characters');
        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->attach_bytes(str_repeat('n', 256), 'x', 'x.txt'), '1 to 255 characters');
        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->attach_file('f', '/nonexistent/file.txt'), 'is not a readable file');
        static::__assert_equals([], static::__run($task)->attachments(), 'nothing was recorded');
    }

    // -------------------------------------------------------------------------
    // Replacing, and deleting
    // -------------------------------------------------------------------------

    public static function test_replacing_a_name_deletes_the_earlier_temp_file()
    {
        $task = static::__instance();
        $task->attach_bytes('result', static::__unique_bytes('first'), 'result.txt');
        $first = Temp_File_Model::find(static::__run($task)->attachment('result')->temp_file_id);
        $first_path = $first->storage_path();

        $second = static::__unique_bytes('second');
        $task->attach_bytes('result', $second, 'result.txt');

        $attachment = static::__run($task)->attachment('result');
        static::__assert_equals(1, count(static::__run($task)->attachments()), 'one row per name');
        static::__assert_equals($second, $attachment->read_bytes(), 'the name now holds the new file');
        static::__assert_null(Temp_File_Model::find($first->id), 'the earlier temp file is gone');
        static::__assert_false(is_file($first_path), 'and its bytes with it');
    }

    public static function test_identical_bytes_are_two_files_never_shared()
    {
        $shared = static::__unique_bytes('shared');

        $other = static::__instance();
        $other->attach_bytes('copy', $shared, 'copy.txt');
        $task = static::__instance();
        $task->attach_bytes('result', $shared, 'result.txt');

        static::__assert_not_equals((int) static::__run($other)->attachment('copy')->temp_file_id, (int) static::__run($task)->attachment('result')->temp_file_id);

        $task->attach_bytes('result', static::__unique_bytes('replacement'), 'result.txt');
        static::__assert_equals($shared, static::__run($other)->attachment('copy')->read_bytes(), 'replacing one never touches the other');
    }

    public static function test_deleting_the_temp_file_deletes_the_attachment_and_nothing_is_a_blob_reference()
    {
        $declared = array_filter(File_Blob_References::declarations(), fn ($d) => $d['table'] === '_task_attachments');
        static::__assert_equals(0, count($declared), '_task_attachments pins no blob');

        $task = static::__instance();
        $task->attach_bytes('gone', static::__unique_bytes('gone'), 'gone.txt');
        $temp_file = Temp_File_Model::find(static::__run($task)->attachment('gone')->temp_file_id);

        \App\RSpade\Core\Files\Rsx_Temp_Files::delete($temp_file);

        static::__assert_null(static::__run($task)->attachment('gone'), 'the row went with its temp file');
    }
}
