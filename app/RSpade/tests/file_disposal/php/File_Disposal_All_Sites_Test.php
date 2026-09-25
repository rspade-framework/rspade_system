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
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * File_Disposal_All_Sites_Test - retention and blob release span EVERY site.
 *
 * The blob store is deduplicated across the whole install and retention is install
 * policy, so a disposal worker's declared site must not narrow what it sees. Each test
 * builds attachments under a SECOND site and runs the pass from a process declaring
 * site 1.
 *
 * Runs in the default per-test transaction; the second site is created inside it. The
 * test run relocates the file subsystem, so a blob unlink never reaches the developer
 * store.
 */
class File_Disposal_All_Sites_Test extends Rsx_Test_Abstract
{
    private const WORKER_SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::WORKER_SITE_ID);
    }

    private static function __second_site_id(): int
    {
        $site = new Site_Model();
        $site->slug = 'disposal-' . uniqid();
        $site->name = 'Disposal Second Site';
        $site->save();

        return (int) $site->id;
    }

    private static function __make_as_site(int $site_id, string $content): File_Attachment_Model
    {
        static::__acting_as_site($site_id);

        try {
            return File_Attachment_Model::create_from_string($content, 'doc.txt', ['site_id' => $site_id]);
        } finally {
            static::__acting_as_site(self::WORKER_SITE_ID);
        }
    }

    private static function __stored(int $id): ?File_Attachment_Model
    {
        return File_Attachment_Model::without_site_scope(fn () => File_Attachment_Model::withTrashed()->find($id));
    }

    private static function __backdate(int $id, string $column, int $days): void
    {
        // to_database(): a raw DB::table update bypasses the model's ISO datetime cast.
        DB::table('_file_attachments')->where('id', $id)->update([
            $column => Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), $days * 86400)),
        ]);
    }

    public static function test_the_daily_pass_destroys_another_sites_attachment_past_retention()
    {
        $other_site_id = static::__second_site_id();
        $attachment = static::__make_as_site($other_site_id, 'other-site-retention-' . uniqid());

        static::__acting_as_site($other_site_id);
        $attachment->delete();
        static::__acting_as_site(self::WORKER_SITE_ID);

        static::__backdate((int) $attachment->id, 'deleted_at', 400);

        File_Disposal_Service::run_daily_disposal(
            new Task_Instance(File_Disposal_Service::class, 'run_daily_disposal')
        );

        $stored = static::__stored((int) $attachment->id);
        static::__assert_not_null($stored->destroyed_at, 'a worker declaring site 1 destroyed the second site\'s attachment');
        static::__assert_equals($other_site_id, (int) $stored->site_id, 'which still belongs to its own site');
    }

    public static function test_a_blob_another_site_still_holds_is_never_released()
    {
        $other_site_id = static::__second_site_id();
        $content = 'shared-across-sites-' . uniqid();

        $mine = static::__make_as_site(self::WORKER_SITE_ID, $content);
        $theirs = static::__make_as_site($other_site_id, $content);
        $storage_id = (int) $mine->file_storage_id;

        static::__assert_equals($storage_id, (int) $theirs->file_storage_id, 'identical bytes share one blob across sites');

        $mine->force_destroy();

        static::__assert_false(
            File_Disposal_Service::release_blob_if_orphaned($storage_id),
            'the other site\'s live attachment still pins the blob'
        );
        static::__assert_not_null(File_Storage_Model::find($storage_id), 'the storage row survives');
    }

    public static function test_the_claim_window_sweep_reaches_another_sites_upload()
    {
        $other_site_id = static::__second_site_id();
        $upload = static::__make_as_site($other_site_id, 'unclaimed-' . uniqid());

        static::__backdate((int) $upload->id, 'created_at', 30);

        File_Disposal_Service::sweep_unclaimed_uploads(
            new Task_Instance(File_Disposal_Service::class, 'sweep_unclaimed_uploads')
        );

        static::__assert_not_null(
            static::__stored((int) $upload->id)->deleted_at,
            'a stale unclaimed upload of another site entered retention'
        );
    }
}
