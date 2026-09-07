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
 * File disposal & retention lifecycle (File_Disposal_Service + File_Attachment_Model
 * SoftDeletes). Proves: delete() enters the retention window (soft-delete, blob survives,
 * enumerable); undelete() restores and throws after destruction; the retention-aware
 * refcount keeps a blob a live-or-retained attachment still pins; the daily destroy pass
 * fires the hooks + stamps destroyed_at + releases the blob at refcount 0; the destroy.hold
 * gate holds; force_destroy() erases immediately.
 *
 * Commits (blob unlinks are filesystem, not transactional), so the class opts out of the
 * per-test transaction and runs on a fresh migrated DB; each test nukes the file tables +
 * the fixture listener first. The test harness relocates the whole file subsystem to
 * test-storage, so blob writes/unlinks never touch the developer store.
 */
class File_Disposal_Test extends Rsx_Test_Abstract
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

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);
        return $id;
    }

    /** Create a local attachment from unique-or-shared string content. */
    private static function __make(string $content, string $name = 'doc.txt'): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string($content, $name, ['site_id' => static::__site_id()]);
    }

    /** Reload an attachment INCLUDING soft-deleted rows. */
    private static function __reload(int $id): ?File_Attachment_Model
    {
        return File_Attachment_Model::withTrashed()->find($id);
    }

    private static function __blob_exists(int $storage_id): bool
    {
        $storage = File_Storage_Model::find($storage_id);
        return $storage !== null && is_file($storage->get_full_path());
    }

    private static function __run_daily(): void
    {
        File_Disposal_Service::run_daily_disposal(
            new Task_Instance(File_Disposal_Service::class, 'run_daily_disposal')
        );
    }

    // -------------------------------------------------------------------------

    public static function test_delete_enters_retention_not_destruction()
    {
        static::__reset();
        $att = static::__make('retention-' . uniqid());
        $sid = (int) $att->file_storage_id;

        $att->delete();

        // Excluded from normal queries, present via withTrashed, not yet destroyed.
        static::__assert_null(File_Attachment_Model::find($att->id), 'soft-deleted row hidden from normal scope');
        $reloaded = static::__reload($att->id);
        static::__assert_not_null($reloaded, 'withTrashed still finds it');
        static::__assert_not_null($reloaded->deleted_at, 'deleted_at stamped');
        static::__assert_null($reloaded->destroyed_at, 'not destroyed - still recoverable');
        static::__assert_true(static::__blob_exists($sid), 'blob survives retention');

        // Enumerable via the recovery API.
        $deleted = File_Attachment_Model::get_deleted_files();
        static::__assert_true($deleted->contains('id', $att->id), 'get_deleted_files lists the retained attachment');
    }

    public static function test_undelete_restores_then_throws_after_destroy()
    {
        static::__reset();
        $att = static::__make('undelete-' . uniqid());
        $att->delete();

        static::__reload($att->id)->undelete();
        static::__assert_not_null(File_Attachment_Model::find($att->id), 'undelete brings it back into normal scope');
        static::__assert_null(File_Attachment_Model::find($att->id)->deleted_at, 'deleted_at cleared');

        // After permanent destruction, undelete must throw.
        $att2 = static::__make('undelete2-' . uniqid());
        $att2->force_destroy();
        static::__assert_throws(\Exception::class, function () use ($att2) {
            static::__reload($att2->id)->undelete();
        }, 'destroyed');
    }

    public static function test_retention_aware_refcount_keeps_shared_blob()
    {
        static::__reset();
        $content = 'shared-' . uniqid();
        $a = static::__make($content, 'a.txt');
        $b = static::__make($content, 'b.txt'); // identical bytes -> deduplicated onto one blob
        $sid = (int) $a->file_storage_id;
        static::__assert_equals($sid, (int) $b->file_storage_id, 'identical content dedupes to one storage blob');

        // Soft-delete a: b (live) still pins the blob.
        $a->delete();
        static::__assert_false(File_Disposal_Service::release_blob_if_orphaned($sid), 'blob NOT released while b is live');
        static::__assert_true(static::__blob_exists($sid), 'blob survives');

        // Destroy a: b still pins it (retention-aware).
        static::__reload($a->id)->force_destroy();
        static::__assert_true(static::__blob_exists($sid), 'blob still pinned by live b after a destroyed');

        // Destroy b: now nothing pins the blob -> released.
        $b->force_destroy();
        static::__assert_false(static::__blob_exists($sid), 'blob released once every referrer is destroyed');
        static::__assert_null(File_Storage_Model::find($sid), 'storage row deleted');
    }

    public static function test_daily_pass_destroys_past_retention_and_releases_blob()
    {
        static::__reset();
        $att = static::__make('daily-' . uniqid());
        $sid = (int) $att->file_storage_id;
        $att->delete();

        // Backdate the retention clock well past the window. to_database() because a raw
        // DB::table update bypasses the model's ISO datetime cast.
        $old = Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), 40 * 86400));
        DB::table('_file_attachments')->where('id', $att->id)->update(['deleted_at' => $old]);

        static::__run_daily();

        $reloaded = static::__reload($att->id);
        static::__assert_not_null($reloaded->destroyed_at, 'destroy pass stamped destroyed_at');
        static::__assert_true(in_array($att->id, File_Disposal_Test_Listener::$destroyed_ids, true), 'destroyed action fired');
        static::__assert_false(static::__blob_exists($sid), 'blob released by the daily pass');
        static::__assert_null(File_Storage_Model::find($sid), 'storage row gone');
    }

    public static function test_hold_gate_defers_destruction()
    {
        static::__reset();
        $att = static::__make('held-' . uniqid());
        $att->delete();
        DB::table('_file_attachments')->where('id', $att->id)
            ->update(['deleted_at' => Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), 40 * 86400))]);

        File_Disposal_Test_Listener::$hold = true;
        static::__run_daily();
        static::__assert_null(static::__reload($att->id)->destroyed_at, 'held attachment is NOT destroyed');

        File_Disposal_Test_Listener::$hold = false;
        static::__run_daily();
        static::__assert_not_null(static::__reload($att->id)->destroyed_at, 'destroyed on the next run once the hold clears');
    }

    public static function test_force_destroy_is_immediate()
    {
        static::__reset();
        $att = static::__make('force-' . uniqid());
        $sid = (int) $att->file_storage_id;

        $att->force_destroy();

        $reloaded = static::__reload($att->id);
        static::__assert_not_null($reloaded->deleted_at, 'force_destroy stamps deleted_at');
        static::__assert_not_null($reloaded->destroyed_at, 'force_destroy stamps destroyed_at immediately');
        static::__assert_false(static::__blob_exists($sid), 'force_destroy releases the blob now');
    }

    /**
     * force_destroy() ANNOUNCES the destruction. The hook is not a permission, it is a
     * statement of fact - equally true down both paths - and an app tombstone keyed on it
     * would otherwise go on listing a file as restorable whose bytes are already gone.
     */
    public static function test_force_destroy_fires_the_destroyed_hook()
    {
        static::__reset();
        $att = static::__make('force-hook-' . uniqid());

        $att->force_destroy();

        static::__assert_true(
            in_array((int) $att->id, File_Disposal_Test_Listener::$destroyed_ids, true),
            'force_destroy must fire file.attachment.destroyed; saw: '
                . implode(',', File_Disposal_Test_Listener::$destroyed_ids)
        );
    }

    /**
     * The GATE stays bypassed - vetoing a forced erasure is precisely what "force" refuses
     * to allow. Only the announcement was added.
     */
    public static function test_force_destroy_ignores_a_hold()
    {
        static::__reset();
        $att = static::__make('force-held-' . uniqid());

        File_Disposal_Test_Listener::$hold = true;
        $att->force_destroy();
        File_Disposal_Test_Listener::$hold = false;

        static::__assert_not_null(
            static::__reload($att->id)->destroyed_at,
            'a hold must not be able to veto a forced destroy'
        );
    }

    /**
     * Listener failure policy DIFFERS from the scheduled path, deliberately. There a
     * throwing listener aborts and the attachment retries next run; a forced destroy has no
     * next run, and its most important caller is create_from_upload()'s rejected-upload
     * rollback, which must not be derailed by an app listener.
     */
    public static function test_a_throwing_listener_does_not_stop_a_forced_destroy()
    {
        static::__reset();
        $att = static::__make('force-throw-' . uniqid());
        $sid = (int) $att->file_storage_id;

        File_Disposal_Test_Listener::$throw_on_destroyed = true;
        $att->force_destroy();
        File_Disposal_Test_Listener::$throw_on_destroyed = false;

        $reloaded = static::__reload($att->id);
        static::__assert_not_null($reloaded->destroyed_at, 'the destruction must complete anyway');
        static::__assert_false($sid && static::__blob_exists($sid), 'and the blob is still released');
    }
}
