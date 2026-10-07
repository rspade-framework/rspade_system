<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TempFiles\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Files\Rsx_Temp_Files;
use App\RSpade\Core\Files\Temp_File_Cleanup_Service;
use App\RSpade\Core\Files\Temp_File_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Temp_Files - the temp file store: bytes under uploads/_temp/<2>/<random key>, a
 * _temp_files row each, an expiry, and a sweep that deletes only what this database holds.
 *
 * Temp files are written to disk, so this class commits: a clean baseline, no per-test
 * transactions. The store is the run's isolated test store (tests/CLAUDE.md, File-storage
 * isolation).
 */
class Rsx_Temp_Files_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /** Move a temp file's expiry into the past. */
    private static function __expire(Temp_File_Model $file): void
    {
        DB::table('_temp_files')->where('id', $file->id)->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
    }

    public static function test_store_bytes_writes_under_a_random_key_outside_the_blob_store()
    {
        $bytes = 'temp file ' . bin2hex(random_bytes(8)) . "\n";
        $file = Rsx_Temp_Files::store_bytes($bytes, 'export.csv');

        static::__assert_true((bool) preg_match('/^[0-9a-f]{32}$/', $file->temp_key), 'a 32-hex random key');
        static::__assert_not_equals(hash('sha256', $bytes), $file->temp_key, 'never the content hash');
        static::__assert_equals(Rsx_File_Paths::temp_root() . '/' . substr($file->temp_key, 0, 2) . '/' . $file->temp_key, $file->storage_path());
        static::__assert_equals($bytes, $file->read_bytes());
        static::__assert_equals('export.csv', $file->file_name);
        static::__assert_equals('text/plain', $file->mime_type, 'sniffed from the bytes');
        static::__assert_equals(strlen($bytes), (int) $file->size);
        static::__assert_equals(0, DB::table('_file_storage')->where('hash', hash('sha256', $bytes))->count(), 'nothing entered the blob store');

        $days = (strtotime($file->expires_at) - time()) / 86400;
        static::__assert_true($days > 6.9 && $days <= 7.0, 'expires after rsx.temp_files.retention_days (7)');

        $response = $file->download_response();
        static::__assert_contains('export.csv', (string) $response->headers->get('Content-Disposition'));

        $twin = Rsx_Temp_Files::store_bytes($bytes, 'export.csv');
        static::__assert_not_equals($file->temp_key, $twin->temp_key, 'identical bytes are two files');
    }

    public static function test_store_file_copies_with_its_own_lifetime()
    {
        $source = Rsx_Project_Paths::tmp_path('rsxtest_temp_source_' . uniqid() . '.csv');
        file_put_contents($source, "a,b\n");

        try {
            $file = Rsx_Temp_Files::store_file($source, 'data.csv', 'text/csv', 1);

            static::__assert_equals("a,b\n", $file->read_bytes());
            static::__assert_equals('text/csv', $file->mime_type, 'the type given');
            static::__assert_true(is_file($source), 'the source is left where it is');
            static::__assert_true((strtotime($file->expires_at) - time()) <= 86400, 'the lifetime the caller chose');
        } finally {
            @unlink($source);
        }

        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Temp_Files::store_bytes('x', 'x.txt', null, 0), 'at least 1 day');
    }

    public static function test_find_and_delete()
    {
        $file = Rsx_Temp_Files::store_bytes('find me', 'f.txt');
        $path = $file->storage_path();

        static::__assert_equals($file->id, Rsx_Temp_Files::find($file->temp_key)->id);
        static::__assert_null(Rsx_Temp_Files::find(str_repeat('0', 32)), 'no such key');

        static::__expire($file);
        static::__assert_null(Rsx_Temp_Files::find($file->temp_key), 'an expired file is gone to a reader');

        Rsx_Temp_Files::delete(Temp_File_Model::find($file->id));
        static::__assert_null(Temp_File_Model::find($file->id), 'the row is deleted');
        static::__assert_false(is_file($path), 'and the bytes');
    }

    /**
     * The sweep deletes expired files THIS database holds - and never a file on disk it has no
     * row for, which on a shared uploads mount is another environment's.
     */
    public static function test_the_sweep_deletes_only_expired_files_it_has_rows_for()
    {
        $expired = Rsx_Temp_Files::store_bytes('expired', 'old.txt');
        $live = Rsx_Temp_Files::store_bytes('live', 'new.txt');
        static::__expire($expired);
        $expired_path = $expired->storage_path();

        $foreign = Rsx_File_Paths::temp_root() . '/ab/' . str_repeat('ab', 16);
        ensure_directory(dirname($foreign));
        file_put_contents($foreign, 'another environment');
        touch($foreign, time() - 400 * 86400);

        try {
            $state = static::__run_task_method(Temp_File_Cleanup_Service::class, 'delete_expired');

            static::__assert_equals(1, $state['deleted']);
            static::__assert_false(is_file($expired_path), 'the expired file is deleted');
            static::__assert_null(Temp_File_Model::find($expired->id));
            static::__assert_not_null(Temp_File_Model::find($live->id), 'a live file is kept');
            static::__assert_true(is_file($foreign), 'a file with no row here is never touched');
        } finally {
            @unlink($foreign);
        }
    }

    public static function test_a_bad_retention_setting_throws()
    {
        $original = config('rsx.temp_files.retention_days');
        try {
            foreach ([0, -1, 'seven'] as $bad) {
                config(['rsx.temp_files.retention_days' => $bad]);
                static::__assert_throws(\RuntimeException::class, fn () => Rsx_Temp_Files::store_bytes('x', 'x.txt'), 'retention_days');
            }
        } finally {
            config(['rsx.temp_files.retention_days' => $original]);
        }
    }
}
