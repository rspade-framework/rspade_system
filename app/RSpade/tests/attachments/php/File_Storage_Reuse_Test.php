<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * BLOB INGEST WHEN THE ROW SURVIVES BUT THE FILE DOES NOT.
 *
 * find_or_create() is content-addressed: same bytes, same hash, same row. If something
 * outside the framework removes a blob from disk, the row and every attachment pointing
 * at it are still valid - so re-storing those bytes must put them back under the SAME
 * record, not insert a second row carrying a hash that is uniquely indexed.
 *
 * Proves the repair happens, the identity is preserved, and the uniqueness of the hash
 * is never challenged.
 */
class File_Storage_Reuse_Test extends Rsx_Test_Abstract
{
    /** Temp files written by the tests, removed in teardown. */
    private static array $temp_files = [];

    public static function teardown()
    {
        foreach (static::$temp_files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        static::$temp_files = [];
    }

    /**
     * A temp file carrying bytes no other blob in the store can already have.
     */
    private static function __temp_file_with(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rsx_reuse_');
        file_put_contents($path, $contents);
        static::$temp_files[] = $path;

        return $path;
    }

    public static function test_missing_file_is_rewritten_under_the_same_record()
    {
        $bytes = 'storage-reuse-' . uniqid('', true);
        $storage = File_Storage_Model::store_blob(static::__temp_file_with($bytes));

        $original_id = (int) $storage->id;
        $hash = (string) $storage->hash;
        $path = $storage->get_full_path();

        static::__assert_true(is_file($path), 'The blob should exist on disk after the first store.');

        // Something outside the framework takes the bytes away.
        unlink($path);
        static::__assert_false(is_file($path), 'The blob file should be gone.');

        $again = File_Storage_Model::store_blob(static::__temp_file_with($bytes));

        static::__assert_equals($original_id, (int) $again->id, 'The same storage record must be returned.');
        static::__assert_equals($hash, (string) $again->hash, 'The hash must be unchanged.');
        static::__assert_true(is_file($path), 'The bytes must be back on disk at the record own path.');
        static::__assert_equals($bytes, file_get_contents($path), 'The restored bytes must be the originals.');

        $rows = (int) DB::table('_file_storage')->where('hash', $hash)->count();
        static::__assert_equals(1, $rows, 'Exactly one storage row may carry the hash.');
    }

    public static function test_size_is_corrected_when_the_row_disagrees_with_the_bytes()
    {
        $bytes = 'storage-reuse-size-' . uniqid('', true);
        $storage = File_Storage_Model::store_blob(static::__temp_file_with($bytes));

        $path = $storage->get_full_path();
        unlink($path);

        // A row whose recorded size does not describe the bytes it is about to receive.
        DB::table('_file_storage')->where('id', $storage->id)->update(['size' => 1]);

        $again = File_Storage_Model::store_blob(static::__temp_file_with($bytes));

        static::__assert_equals((int) $storage->id, (int) $again->id, 'The same record must be returned.');
        static::__assert_equals(strlen($bytes), (int) $again->size, 'The size must describe the restored bytes.');
    }

    public static function test_an_intact_blob_still_dedups_without_touching_the_disk()
    {
        $bytes = 'storage-reuse-intact-' . uniqid('', true);
        $first = File_Storage_Model::store_blob(static::__temp_file_with($bytes));
        $path = $first->get_full_path();
        $mtime = filemtime($path);

        $second = File_Storage_Model::store_blob(static::__temp_file_with($bytes));

        static::__assert_equals((int) $first->id, (int) $second->id, 'Identical bytes dedup onto one record.');
        static::__assert_equals($mtime, filemtime($path), 'A dedup hit must not rewrite the blob.');
    }
}
