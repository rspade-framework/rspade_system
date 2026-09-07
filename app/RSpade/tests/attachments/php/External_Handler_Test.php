<?php

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Attachments\Php\Attachment_Fixture_Fresh_Handler;
use App\RSpade\Tests\Attachments\Php\Attachment_Fixture_Handler;

/**
 * Attachment External Handlers (WP-A) - acceptance coverage (spec section 11, criteria 1-9).
 *
 * Proves: plain attachments are byte-unchanged; external attachments (null storage) materialize on
 * demand at every byte path; evict -> orphan sweep -> re-materialize round-trips; relink re-extracts
 * metadata and self-invalidates the hash-keyed thumbnail cache; unregistered handlers fail loud;
 * evict on a handler-less attachment throws; create_external + add_to lands in fileable listings;
 * mime_type + file_size persist and work without a resident blob.
 */
class External_Handler_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        // Register fixture handlers in the security allowlist for the whole class run.
        config(['rsx.attachments.handlers' => [
            'Attachment_Fixture_Handler',
            'Attachment_Fixture_Fresh_Handler',
        ]]);
    }

    /**
     * Resolve a real seeded site id and set it as the session site, so the site-scoped save hook
     * and the site FK on _file_attachments are both satisfied in the CLI test harness.
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        \App\RSpade\Core\Session\Session::set_site_id($id);
        return $id;
    }

    private static function __make_external(string $handler = 'Attachment_Fixture_Handler', string $variant = 'a'): File_Attachment_Model
    {
        Attachment_Fixture_Handler::reset();
        Attachment_Fixture_Fresh_Handler::reset();

        $bytes = Attachment_Fixture_Handler::png_bytes($variant);

        return File_Attachment_Model::create_external($handler, ['ref' => 'fixture', 'variant' => $variant], [
            'site_id' => static::__site_id(),
            'file_name' => 'external.png',
            'file_extension' => 'png',
            'mime_type' => 'image/png',
            'file_size' => strlen($bytes),
        ]);
    }

    private static function __make_plain(string $variant = 'a'): File_Attachment_Model
    {
        // Mirror the ingest path (metadata + process_file) without create_from_upload's HTTP
        // session dependency, which is unavailable in the CLI test harness.
        $bytes = Attachment_Fixture_Handler::png_bytes($variant);
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_plain_');
        file_put_contents($tmp, $bytes);
        $storage = File_Storage_Model::store_blob($tmp);
        @unlink($tmp);

        $a = new File_Attachment_Model();
        $a->key = File_Attachment_Model::generate_key();
        $a->file_storage_id = $storage->id;
        $a->file_name = 'plain.png';
        $a->file_extension = 'png';
        $a->mime_type = 'image/png';
        $a->file_size = $storage->size;
        $a->file_type_id = File_Attachment_Model::determine_file_type('image/png');
        $a->site_id = static::__site_id();
        $a->save();
        $a->process_file();

        return $a;
    }

    // Criterion 1/8/9: plain attachment - metadata populated, thumbnail renders, delete sweeps storage.
    public static function test_plain_attachment_unchanged()
    {
        $bytes = Attachment_Fixture_Handler::png_bytes('a');
        $a = static::__make_plain('a');

        static::__assert_true($a->has_blob(), 'plain attachment is resident');
        static::__assert_null($a->handler_class, 'plain attachment has no handler');
        static::__assert_equals(strlen($bytes), $a->get_size(), 'get_size reads file_size column');
        static::__assert_equals('image/png', $a->mime_type, 'mime_type persisted at ingest (mime fix)');
        static::__assert_true($a->has_thumbnail(), 'image has a thumbnail renderer');

        $storage = $a->file_storage;
        static::__assert_equals($storage->size, $a->get_size(), 'file_size matches storage size');

        // Thumbnail renders to WebP bytes.
        $data = File_Attachment_Controller::_render_thumbnail_data($a, $storage->get_full_path(), 'fit', 32, 32);
        static::__assert_true(strlen($data) > 0, 'thumbnail produced bytes');
        static::__assert_contains('WEBP', substr($data, 0, 16), 'thumbnail is a WebP');

        // Retention semantics: delete() SOFT-deletes into the retention window - the blob stays
        // PINNED by the retained attachment (retention-aware refcount), so the storage row
        // survives. Only permanent destruction (force_destroy / the disposal task) releases it.
        $storage_id = $storage->id;
        $a->delete();
        static::__assert_not_null(File_Storage_Model::find($storage_id), 'storage survives a soft delete (pinned by the retained attachment)');

        $a->force_destroy();
        static::__assert_null(File_Storage_Model::find($storage_id), 'storage released once the attachment is permanently destroyed');
    }

    // Criterion 9: external attachment metadata works with NO resident blob.
    public static function test_external_metadata_without_blob()
    {
        $bytes = Attachment_Fixture_Handler::png_bytes('a');
        $a = static::__make_external();

        static::__assert_null($a->file_storage_id, 'external attachment has no blob initially');
        static::__assert_true($a->has_handler(), 'external attachment has a handler');
        static::__assert_equals(strlen($bytes), $a->get_size(), 'get_size works without a blob');
        static::__assert_equals('image/png', $a->mime_type, 'mime_type set by create_external');
        static::__assert_equals(0, Attachment_Fixture_Handler::$fetch_count, 'no fetch until bytes needed');
    }

    // Criterion 2: first byte access materializes; key + URLs stable; no re-materialize.
    public static function test_resolve_storage_materializes_once()
    {
        $a = static::__make_external();
        $key_before = $a->key;
        $url_before = $a->get_download_url();

        $storage = $a->resolve_storage();
        static::__assert_equals(1, Attachment_Fixture_Handler::$fetch_count, 'first resolve materializes');
        static::__assert_not_null($a->file_storage_id, 'blob linked after materialize');
        static::__assert_equals($key_before, $a->key, 'key stable across materialize');
        static::__assert_equals($url_before, $a->get_download_url(), 'download URL stable');
        static::__assert_true(file_exists($storage->get_full_path()), 'blob written to disk');

        // Second access does not re-fetch.
        $a->resolve_storage();
        static::__assert_equals(1, Attachment_Fixture_Handler::$fetch_count, 'resident blob not re-materialized');
    }

    // Criterion 2: first thumbnail request materializes + renders.
    public static function test_thumbnail_materializes_external()
    {
        $a = static::__make_external();
        static::__assert_null($a->file_storage_id, 'starts non-resident');

        $storage = $a->resolve_storage();
        static::__assert_equals(1, Attachment_Fixture_Handler::$fetch_count, 'thumbnail path materialized bytes');

        $data = File_Attachment_Controller::_render_thumbnail_data($a, $storage->get_full_path(), 'cover', 24, 24);
        static::__assert_contains('WEBP', substr($data, 0, 16), 'external attachment renders a real thumbnail');
    }

    // Criterion 3: evict -> orphan sweep removes file -> next access re-materializes.
    public static function test_evict_cleanup_rematerialize_roundtrip()
    {
        $a = static::__make_external();
        $storage = $a->resolve_storage();
        $storage_id = $storage->id;
        $path = $storage->get_full_path();
        static::__assert_true(file_exists($path), 'blob resident');

        $a->evict_blob();
        static::__assert_null($a->file_storage_id, 'evict nulled the blob link');

        // The blob is now orphaned; the cleanup command must sweep it (spec section 6, item 8).
        $exit = Artisan::call('rsx:storage:cleanup', ['--force' => true]);
        static::__assert_equals(0, $exit, 'cleanup command succeeded');
        static::__assert_null(File_Storage_Model::find($storage_id), 'evicted-orphan storage row swept');
        static::__assert_false(file_exists($path), 'evicted-orphan blob file removed');

        // Bytes re-materialize on next demand.
        $a->resolve_storage();
        static::__assert_equals(2, Attachment_Fixture_Handler::$fetch_count, 're-materialized after eviction');
        static::__assert_not_null($a->file_storage_id, 'blob linked again');
    }

    // Criterion 4: relink to new content re-extracts dims/mime and changes the hash (cache self-invalidates).
    public static function test_relink_reextracts_metadata()
    {
        $a = static::__make_plain('a');   // 8x8 red
        static::__assert_equals(8, $a->width, 'initial width from variant a');
        $old_hash = $a->file_storage->hash;

        // Build storage for variant b (16x16 blue) and relink.
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_relink_');
        file_put_contents($tmp, Attachment_Fixture_Handler::png_bytes('b'));
        $new_storage = File_Storage_Model::store_blob($tmp);
        @unlink($tmp);

        $a->relink_storage($new_storage);

        static::__assert_equals($new_storage->id, $a->file_storage_id, 'repointed to new storage');
        static::__assert_equals(16, $a->width, 'dimensions re-extracted after relink');
        static::__assert_equals($new_storage->size, $a->file_size, 'file_size updated on relink');
        static::__assert_not_equals($old_hash, $new_storage->hash, 'content hash changed - thumbnail cache self-invalidates');
    }

    // Criterion 5: unregistered handler_class on a byte path fails loud; nothing served.
    public static function test_unregistered_handler_fails_loud()
    {
        $a = static::__make_external();
        // Force an unregistered handler directly in the row (bypassing create_external's guard).
        DB::table('_file_attachments')->where('id', $a->id)->update([
            'handler_class' => 'Bogus_Unregistered_Handler',
            'file_storage_id' => null,
        ]);
        $reloaded = File_Attachment_Model::find($a->id);

        static::__assert_throws(\RuntimeException::class, function () use ($reloaded) {
            $reloaded->resolve_storage();
        }, 'not registered');
    }

    // Criterion 6: evict on a handler-less attachment throws.
    public static function test_evict_without_handler_throws()
    {
        $p = static::__make_plain('a');
        static::__assert_throws(\App\RSpade\Core\Debug\Rsx_Caller_Exception::class, function () use ($p) {
            $p->evict_blob();
        }, 'handler');
    }

    // Criterion 7: create_external + add_to appears in fileable listings.
    public static function test_create_external_add_to_listing()
    {
        $a = static::__make_external();

        // Use a persisted storage row as an arbitrary fileable parent.
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_parent_');
        file_put_contents($tmp, Attachment_Fixture_Handler::png_bytes('a'));
        $parent = File_Storage_Model::store_blob($tmp);
        @unlink($tmp);

        $a->add_to($parent, 'ext_docs');

        static::__assert_equals('File_Storage_Model', $a->fileable_type, 'fileable_type set');
        static::__assert_equals($parent->id, $a->fileable_id, 'fileable_id set');

        $found = File_Attachment_Model::forModel('File_Storage_Model', $parent->id)->get();
        static::__assert_count(1, $found, 'external attachment appears in fileable listing');
        static::__assert_equals($a->key, $found->first()->key, 'listing returns the external attachment');
    }

    // apply_serve_freshness(): a stale handler evicts so the next serve re-materializes.
    public static function test_serve_freshness_evicts_stale()
    {
        $a = static::__make_external('Attachment_Fixture_Fresh_Handler');
        $a->resolve_storage();
        static::__assert_not_null($a->file_storage_id, 'resident before freshness check');

        Attachment_Fixture_Fresh_Handler::$stale = true;
        $a->apply_serve_freshness();
        static::__assert_null($a->file_storage_id, 'stale content evicted at serve time');

        Attachment_Fixture_Fresh_Handler::$stale = false;
        $a->resolve_storage();
        static::__assert_not_null($a->file_storage_id, 're-materialized fresh bytes');
    }
}
