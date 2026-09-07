<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ZipDownload\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Zip_Download_Request_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Zip_Download_Request_Model - the database-backed multi-file ZIP download request.
 *
 * create_request() persists the file set with a 64-hex opaque download_key, validates
 * the file-set STRUCTURE fail-loud (server-side app code, not user input), and does NOT
 * authorize the files (authorization is per-file at serve time). find_by_download_key()
 * resolves a row; is_expired() enforces the config validity window; get_download_url()
 * threads the key through the type-safe route. Default transaction isolation - rows roll
 * back afterward.
 */
class Zip_Download_Request_Test extends Rsx_Test_Abstract
{
    // =====================================================================
    // create_request - happy path
    // =====================================================================

    public static function test_create_request_persists_row_with_hex_key_and_files()
    {
        $files = [
            ['key' => 'abc123', 'name' => 'reports/q1.pdf'],
            ['key' => 'def456'],
        ];

        $request = Zip_Download_Request_Model::create_request($files, 'client-files.zip');

        static::__assert_not_null($request->id, 'row persisted with an id');
        static::__assert_equals(64, strlen($request->download_key), 'download_key is 64 hex chars');
        static::__assert_true(ctype_xdigit($request->download_key), 'download_key is hexadecimal');
        static::__assert_equals('client-files.zip', $request->zip_name, 'zip_name stored');
        static::__assert_equals($files, $request->get_files(), 'file set round-trips through get_files()');
    }

    public static function test_create_request_allows_null_zip_name()
    {
        $request = Zip_Download_Request_Model::create_request([['key' => 'abc123']], null);

        static::__assert_null($request->zip_name, 'zip_name is null when not provided');
    }

    // =====================================================================
    // create_request - fail-loud structure validation
    // =====================================================================

    public static function test_create_request_rejects_empty_array()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Zip_Download_Request_Model::create_request([]);
        });
    }

    public static function test_create_request_rejects_non_array_entry()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Zip_Download_Request_Model::create_request(['not-an-array']);
        });
    }

    public static function test_create_request_rejects_missing_key()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Zip_Download_Request_Model::create_request([['name' => 'x.pdf']]);
        });
    }

    public static function test_create_request_rejects_non_string_key()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Zip_Download_Request_Model::create_request([['key' => 123]]);
        });
    }

    public static function test_create_request_rejects_non_string_name()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Zip_Download_Request_Model::create_request([['key' => 'abc', 'name' => 42]]);
        });
    }

    public static function test_create_request_rejects_unexpected_entry_key()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Zip_Download_Request_Model::create_request([['key' => 'abc', 'extra' => 'nope']]);
        });
    }

    // =====================================================================
    // find_by_download_key
    // =====================================================================

    public static function test_find_by_download_key_hit()
    {
        $request = Zip_Download_Request_Model::create_request([['key' => 'abc123']]);

        $found = Zip_Download_Request_Model::find_by_download_key($request->download_key);
        static::__assert_not_null($found, 'existing key resolves');
        static::__assert_equals($request->id, $found->id, 'resolves the same row');
    }

    public static function test_find_by_download_key_miss()
    {
        $found = Zip_Download_Request_Model::find_by_download_key(str_repeat('0', 64));
        static::__assert_null($found, 'unknown key resolves to null');
    }

    // =====================================================================
    // is_expired
    // =====================================================================

    public static function test_is_expired_false_when_fresh()
    {
        $request = Zip_Download_Request_Model::create_request([['key' => 'abc123']]);
        static::__assert_true(!$request->is_expired(), 'a just-created request is not expired');
    }

    public static function test_is_expired_true_when_backdated_beyond_window()
    {
        $request = Zip_Download_Request_Model::create_request([['key' => 'abc123']]);

        // Backdate created_at past the 24h default validity window directly in the DB.
        DB::table('_zip_download_requests')
            ->where('id', $request->id)
            ->update(['created_at' => now()->subHours(25)]);

        $fresh = Zip_Download_Request_Model::find_by_download_key($request->download_key);
        static::__assert_true($fresh->is_expired(), 'a request older than the window is expired');
    }

    // =====================================================================
    // get_download_url
    // =====================================================================

    public static function test_get_download_url_contains_key_and_prefix()
    {
        $request = Zip_Download_Request_Model::create_request([['key' => 'abc123']]);

        $url = $request->get_download_url();
        static::__assert_contains('/_download_zip', $url, 'url routes to the zip download endpoint');
        static::__assert_contains($request->download_key, $url, 'url carries the download key');
    }
}
