<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The document.extract_text filter chain: an app #[OnEvent] handler can intercept extraction and
 * return text / unsupported / failed, and the framework enforces that contract. Proven with a
 * marker-guarded fixture (Search_Extract_Text_Fixture_Handler) that intercepts only test paths.
 *
 * The last test is the SAFETY property that makes marker-guarded live fixtures acceptable: a real
 * blob (whose path is a content hash, never carrying the marker) must fall through to the
 * framework extractor - the live fixture must NOT hijack it.
 */
class Search_Filter_Chain_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        // Extract by direct call; never let a create-kick spawn a real worker.
        config(['rsx.search.enabled' => false]);
    }

    public static function teardown()
    {
        config(['rsx.search.enabled' => true]);
    }

    public static function test_marker_path_intercepts_as_text()
    {
        $result = Rsx::trigger_resolve('document.extract_text', [
            'path' => '/tmp/rsx_test_intercept_hello.dat',
            'mime' => 'application/x-test',
            'storage' => null,
        ]);

        static::__assert_true(is_string($result), 'a text interception returns a string');
        static::__assert_contains('FIXTURE_EXTRACTED_TEXT', $result, 'fixture text is returned');
    }

    public static function test_marker_path_reports_unsupported()
    {
        $result = Rsx::trigger_resolve('document.extract_text', [
            'path' => '/tmp/rsx_test_intercept_unsupported.dat',
            'mime' => 'application/x-test',
            'storage' => null,
        ]);

        static::__assert_true(
            is_array($result) && ($result['status'] ?? null) === 'unsupported',
            'unsupported contract shape returned'
        );
    }

    public static function test_marker_path_reports_failed()
    {
        $result = Rsx::trigger_resolve('document.extract_text', [
            'path' => '/tmp/rsx_test_intercept_failed.dat',
            'mime' => 'application/x-test',
            'storage' => null,
        ]);

        static::__assert_true(
            is_array($result) && ($result['status'] ?? null) === 'failed',
            'failed contract shape returned'
        );
    }

    public static function test_non_marker_path_declines()
    {
        $result = Rsx::trigger_resolve('document.extract_text', [
            'path' => '/var/www/html/system/storage/uploads/ab/cd/abcd0123456789',
            'mime' => 'application/pdf',
            'storage' => null,
        ]);

        static::__assert_null($result, 'a non-marker (real-shaped) path is declined so the framework pipeline runs');
    }

    public static function test_real_file_uses_framework_extractor_not_fixture()
    {
        // A real blob path is a content hash (never contains the marker), so the LIVE fixture
        // must decline and the framework Plain extractor must run - proven by the recorded method.
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $unique = 'chain_' . substr(md5((string) microtime(true)), 0, 8);
        $attachment = File_Attachment_Model::create_from_string("plain body {$unique} more text", "chain_{$unique}.txt", ['site_id' => $site_id]);

        try {
            $storage = File_Storage_Model::find($attachment->file_storage_id);
            $index = Search_Index_Service::extract_storage($storage);

            static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $index->status_id, 'real file was extracted');
            static::__assert_equals('Plain_Text_Extractor', $index->extraction_method, 'framework extractor ran; the marker-guarded fixture declined');
        } finally {
            $attachment->delete();
        }
    }
}
