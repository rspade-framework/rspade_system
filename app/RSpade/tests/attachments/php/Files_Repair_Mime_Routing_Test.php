<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:files:repair_mime_routing: a docx mis-sniffed as application/zip (file_type_id=ARCHIVE, blob
 * already marked indexed, stale icon rendition + thumbnail cached) is re-bucketed to DOCUMENT, its
 * blob re-queued for extraction, and its stale caches invalidated. --dry-run changes nothing; a
 * second run is idempotent.
 */
class Files_Repair_Mime_Routing_Test extends Rsx_Test_Abstract
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public static function setup()
    {
        // The command dispatches Document_Render_Service::render_pending; disabling the master switch
        // makes any spawned worker a no-op (no real extraction during the test).
        config(['rsx.search.enabled' => false]);
    }

    public static function teardown()
    {
        config(['rsx.search.enabled' => true]);
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);
        return $id;
    }

    public static function test_repair_rebuckets_requeues_and_invalidates()
    {
        // Seed a resident blob backing a docx whose sniff came out as application/zip (the bug).
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_repair_');
        file_put_contents($tmp, 'PK' . "\x03\x04" . random_bytes(64));  // zip-ish bytes; content irrelevant here
        $storage = File_Storage_Model::store_blob($tmp);
        @unlink($tmp);

        $storage->is_indexed = 1;  // simulate a prior (mis-routed) extraction attempt
        $storage->save();

        $attachment = new File_Attachment_Model();
        $attachment->key = File_Attachment_Model::generate_key();
        $attachment->file_storage_id = $storage->id;
        $attachment->file_name = 'backstreet.docx';
        $attachment->file_extension = 'docx';
        $attachment->mime_type = 'application/zip';                 // the flaky sniff
        $attachment->file_size = $storage->size;
        $attachment->file_type_id = 4;                             // ARCHIVE (mis-bucketed)
        $attachment->site_id = static::__site_id();
        $attachment->save();

        // Fake the stale icon artifacts the mis-routing would have produced.
        $rendition = File_Preview_Controller::rendition_cache_path($storage);
        static::__ensure_dir(dirname($rendition));
        file_put_contents($rendition, '%PDF-1.4 stale');

        $thumb_dir = Rsx_File_Paths::thumbnails_root() . '/preset';
        static::__ensure_dir($thumb_dir);
        $thumb = $thumb_dir . '/cover_100x100_' . $storage->hash . '_docx.webp';
        file_put_contents($thumb, 'RIFFxxxxWEBP');

        // --- 1) dry-run: reports but changes nothing ---
        Artisan::call('rsx:files:repair_mime_routing', ['--dry-run' => true]);

        $after_dry = File_Attachment_Model::find($attachment->id);
        static::__assert_equals(4, (int) $after_dry->file_type_id, 'dry-run leaves file_type_id unchanged');
        static::__assert_equals(1, (int) File_Storage_Model::find($storage->id)->is_indexed, 'dry-run leaves is_indexed unchanged');
        static::__assert_true(is_file($rendition), 'dry-run leaves the rendition cache in place');
        static::__assert_true(is_file($thumb), 'dry-run leaves the thumbnail cache in place');

        // --- 2) real run: re-buckets, re-queues, invalidates ---
        Artisan::call('rsx:files:repair_mime_routing');

        $repaired = File_Attachment_Model::find($attachment->id);
        static::__assert_equals(6, (int) $repaired->file_type_id, 'file_type_id re-bucketed to DOCUMENT (6)');
        static::__assert_equals('application/zip', $repaired->mime_type, 'mime_type (raw sniff) is intentionally left unchanged');
        static::__assert_equals(0, (int) File_Storage_Model::find($storage->id)->is_indexed, 'blob re-queued (is_indexed reset to 0)');
        static::__assert_false(is_file($rendition), 'stale rendition cache deleted');
        static::__assert_false(is_file($thumb), 'stale thumbnail cache deleted');

        // --- 3) idempotent: a second run finds nothing to change ---
        Artisan::call('rsx:files:repair_mime_routing');
        $again = File_Attachment_Model::find($attachment->id);
        static::__assert_equals(6, (int) $again->file_type_id, 'second run leaves the corrected file_type_id in place');
        static::__assert_equals(0, (int) File_Storage_Model::find($storage->id)->is_indexed, 'second run does not spuriously re-touch the blob');
    }

    private static function __ensure_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
