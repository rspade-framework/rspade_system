<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FileDisposal\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Blob_Audit;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Files\Rsx_Temp_Files;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\FileDisposal\Php\File_Disposal_Test_Listener;

/**
 * config('rsx.files.deleted_retention_days') as the disposal passes read it.
 *
 * 0 means KEEP FOREVER: the daily destroy pass never runs, so a long-deleted attachment
 * and its blob survive the daily pass AND the monthly sweep, and it stays restorable - and
 * NOTHING removes a blob for any reason (File_Disposal_Service::blob_release_enabled()): not
 * force_destroy(), not the daily release pass, not the monthly sweep's unreferenced rows or
 * stray disk files. `rsx:files:unreferenced_blobs` (File_Blob_Audit) lists what stays. Every
 * sweep of the blob tree passes over uploads/_temp. A positive value is honoured at its
 * boundary, and a negative value throws.
 *
 * Commits, for the reason File_Disposal_Test gives (blob unlinks are not transactional);
 * the runner relocates the file subsystem to test-storage.
 */
class File_Disposal_Retention_Config_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__reset();
    }

    public static function teardown()
    {
        static::__reset();
    }

    private static function __reset(): void
    {
        DB::statement('DELETE FROM _file_attachments');
        DB::statement('DELETE FROM _file_storage');
        File_Disposal_Test_Listener::reset();
    }

    private static function __make(string $content): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);
        return File_Attachment_Model::create_from_string($content, 'doc.txt', ['site_id' => $site_id]);
    }

    private static function __reload(int $id): ?File_Attachment_Model
    {
        return File_Attachment_Model::withTrashed()->find($id);
    }

    private static function __blob_exists(int $storage_id): bool
    {
        $storage = File_Storage_Model::find($storage_id);
        return $storage !== null && is_file($storage->get_full_path());
    }

    /** Soft-delete, then move deleted_at back by $days. */
    private static function __delete_days_ago(File_Attachment_Model $attachment, int $days): void
    {
        $attachment->delete();
        DB::table('_file_attachments')->where('id', $attachment->id)
            ->update(['deleted_at' => Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), $days * 86400))]);
    }

    /** Run $fn with deleted_retention_days set to $value, restoring the original after. */
    private static function __with_retention($value, callable $fn)
    {
        $original = config('rsx.files.deleted_retention_days');
        config(['rsx.files.deleted_retention_days' => $value]);
        try {
            return $fn();
        } finally {
            config(['rsx.files.deleted_retention_days' => $original]);
        }
    }

    private static function __run_daily(): array
    {
        return static::__run_task_method(File_Disposal_Service::class, 'run_daily_disposal');
    }

    private static function __run_monthly(): array
    {
        return static::__run_task_method(File_Disposal_Service::class, 'run_monthly_deep_sweep', ['force' => true]);
    }

    // -------------------------------------------------------------------------

    public static function test_zero_keeps_a_long_deleted_attachment_through_every_pass()
    {
        static::__reset();
        $att = static::__make('keep-forever-' . uniqid());
        $sid = (int) $att->file_storage_id;
        static::__delete_days_ago($att, 4000);

        static::__with_retention(0, function () {
            static::__run_daily();
            static::__run_monthly();
        });

        $reloaded = static::__reload($att->id);
        static::__assert_null($reloaded->destroyed_at, 'retention 0: the daily pass must not destroy');
        static::__assert_equals([], File_Disposal_Test_Listener::$destroyed_ids, 'no destroyed action fired');
        static::__assert_true(static::__blob_exists($sid), 'the blob survives the daily pass and the monthly sweep');
        static::__assert_true(
            File_Attachment_Model::get_deleted_files()->contains('id', $att->id),
            'still listed as recoverable'
        );

        $reloaded->undelete();
        static::__assert_not_null(File_Attachment_Model::find($att->id), 'undelete restores it after 4000 days');
    }

    public static function test_zero_removes_no_blob_for_any_reason()
    {
        static::__reset();

        // force_destroy() destroys the attachment, and keeps the bytes.
        $forced = static::__make('forced-' . uniqid());
        $forced_sid = (int) $forced->file_storage_id;
        static::__with_retention(0, fn () => $forced->force_destroy());
        static::__assert_not_null(static::__reload($forced->id)->destroyed_at, 'force_destroy stamps destroyed_at');
        static::__assert_true(static::__blob_exists($forced_sid), 'but releases nothing');

        // A destroyed attachment's blob: the daily release pass is off.
        $destroyed = static::__make('destroyed-' . uniqid());
        $sid = (int) $destroyed->file_storage_id;
        $now = Rsx_Time::to_database(Rsx_Time::now_iso());
        DB::table('_file_attachments')->where('id', $destroyed->id)->update(['deleted_at' => $now, 'destroyed_at' => $now]);

        // A stray file on disk with no row, older than the monthly sweep's age guard.
        $stray = Rsx_File_Paths::blob_root() . '/ff/ee/' . str_repeat('f', 60) . 'ee01';
        ensure_directory(dirname($stray));
        file_put_contents($stray, 'stray');
        touch($stray, time() - 400 * 86400);

        try {
            $result = static::__with_retention(0, fn () => static::__run_daily());
            static::__with_retention(0, fn () => static::__run_monthly());

            static::__assert_equals(0, $result['blobs_released'], 'the daily pass released nothing');
            static::__assert_true(static::__blob_exists($sid), 'the destroyed attachment\'s blob stays');
            static::__with_retention(0, fn () => static::__assert_false(File_Disposal_Service::blob_release_enabled(), 'blob release is off at 0'));
            static::__with_retention(0, fn () => static::__assert_false(File_Disposal_Service::release_blob_if_orphaned($sid), 'the release authority refuses'));
            static::__assert_true(static::__blob_exists($sid));
            static::__assert_true(is_file($stray), 'the monthly sweep removed no disk file');

            // The audit lists them: the unreferenced rows and the stray file.
            $listed = [];
            File_Blob_Audit::each_unreferenced(function (string $path, int $bytes) use (&$listed): void {
                $listed[] = $path;
            });
            static::__assert_contains(File_Storage_Model::relative_blob_path((string) File_Storage_Model::find($sid)->hash), implode("\n", $listed), 'a row nothing references');
            static::__assert_contains('ff/ee/' . basename($stray), implode("\n", $listed), 'a file no row describes');
        } finally {
            @unlink($stray);
        }
    }

    public static function test_no_sweep_of_the_blob_tree_enters_the_temp_store()
    {
        static::__reset();
        $temp = Rsx_Temp_Files::store_bytes('temp bytes ' . uniqid(), 'export.csv', 'text/csv');
        touch($temp->storage_path(), time() - 400 * 86400);

        static::__with_retention(30, fn () => static::__run_monthly());

        static::__assert_true(is_file($temp->storage_path()), 'the deep sweep never touches uploads/_temp');
        $listed = [];
        File_Blob_Audit::each_unreferenced(function (string $path, int $bytes) use (&$listed): void {
            $listed[] = $path;
        });
        static::__assert_false(str_contains(implode("\n", $listed), '_temp'), 'nor does the audit list it');

        Rsx_Temp_Files::delete($temp);
    }

    public static function test_positive_value_destroys_past_the_window_and_spares_inside_it()
    {
        static::__reset();
        $old = static::__make('past-' . uniqid());
        $old_sid = (int) $old->file_storage_id;
        static::__delete_days_ago($old, 6);
        $recent = static::__make('inside-' . uniqid());
        static::__delete_days_ago($recent, 4);

        static::__with_retention(5, fn () => static::__run_daily());

        static::__assert_not_null(static::__reload($old->id)->destroyed_at, 'deleted 6 days ago, window 5: destroyed');
        static::__assert_false(static::__blob_exists($old_sid), 'its blob released');
        static::__assert_null(static::__reload($recent->id)->destroyed_at, 'deleted 4 days ago, window 5: retained');
    }

    public static function test_negative_or_non_integer_value_throws_and_destroys_nothing()
    {
        static::__reset();
        $att = static::__make('negative-' . uniqid());
        static::__delete_days_ago($att, 400);

        foreach ([-1, 'thirty', 1.5] as $bad) {
            static::__with_retention($bad, function () {
                static::__assert_throws(\RuntimeException::class, fn () => static::__run_daily(), 'deleted_retention_days');
            });
        }

        static::__assert_null(static::__reload($att->id)->destroyed_at, 'a config error destroys nothing');
    }
}
