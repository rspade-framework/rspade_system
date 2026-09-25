<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FileDisposal\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\FileDisposal\Php\File_Disposal_Test_Listener;

/**
 * config('rsx.files.deleted_retention_days') as the disposal passes read it.
 *
 * 0 means KEEP FOREVER: the daily destroy pass never runs, so a long-deleted attachment
 * and its blob survive the daily pass AND the monthly sweep, and it stays restorable. The
 * daily blob-release pass still runs, freeing the bytes of explicitly destroyed
 * attachments. A positive value is honoured at its boundary, and a negative value throws.
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
        return File_Disposal_Service::run_daily_disposal(
            new Task_Instance(File_Disposal_Service::class, 'run_daily_disposal')
        );
    }

    private static function __run_monthly(): array
    {
        return File_Disposal_Service::run_monthly_deep_sweep(
            new Task_Instance(File_Disposal_Service::class, 'run_monthly_deep_sweep'),
            ['force' => true]
        );
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

    public static function test_zero_still_releases_the_blob_of_an_explicitly_destroyed_attachment()
    {
        static::__reset();

        // force_destroy() is immediate under keep-forever too.
        $forced = static::__make('forced-' . uniqid());
        $forced_sid = (int) $forced->file_storage_id;
        static::__with_retention(0, fn () => $forced->force_destroy());
        static::__assert_not_null(static::__reload($forced->id)->destroyed_at, 'force_destroy stamps destroyed_at');
        static::__assert_false(static::__blob_exists($forced_sid), 'force_destroy releases the blob');

        // A destroyed attachment whose blob is still present: the daily blob-release pass
        // frees it under keep-forever as under any other setting.
        $destroyed = static::__make('destroyed-' . uniqid());
        $sid = (int) $destroyed->file_storage_id;
        $now = Rsx_Time::to_database(Rsx_Time::now_iso());
        DB::table('_file_attachments')->where('id', $destroyed->id)
            ->update(['deleted_at' => $now, 'destroyed_at' => $now]);
        static::__assert_true(static::__blob_exists($sid), 'precondition: blob still on disk');

        $result = static::__with_retention(0, fn () => static::__run_daily());

        static::__assert_false(static::__blob_exists($sid), 'the blob-release pass runs under retention 0');
        static::__assert_equals(1, $result['blobs_released'], 'one blob released');
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
