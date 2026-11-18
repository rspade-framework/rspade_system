<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ZipDownload\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Exceptions\Rsx_User_Facing_Exception;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_File_Gates;
use App\RSpade\Core\Files\Zip_Download_Request_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * /_download_zip refuses a request it will not serve with a USER-FACING sentence
 * (Rsx_User_Facing_Exception), thrown before a byte streams, and the sentence is
 * deliberately opaque:
 *
 *   - an unknown key and an expired request answer the SAME invalid-or-expired sentence;
 *   - a member whose key resolves to nothing, and a member either file gate refuses, answer
 *     the SAME denial sentence, which names no file and does not say whether it exists.
 *
 * The file gates are driven through the Event_Registry test seam, never a live #[OnEvent]
 * on the real event names (a fixture handler would gate the running application's files).
 */
class Zip_Download_Refusal_Test extends Rsx_Test_Abstract
{
    private const INVALID_LINK = 'This download link is invalid or has expired.';
    private const DENIED = 'Access to one or more of the requested files was denied. Please log in.';

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();
    }

    /**
     * Sign in as the baseline user on the baseline site and register file gates that
     * answer as given.
     */
    private static function __prepare(bool $thumbnail_allows = true, bool $download_allows = true): int
    {
        $site_id = (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
        Session::impersonate($site_id, 1, 1);

        Event_Registry::_set_test_handlers(Rsx_File_Gates::THUMBNAIL, [fn ($data) => $thumbnail_allows ? true : 'refused']);
        Event_Registry::_set_test_handlers(Rsx_File_Gates::DOWNLOAD, [fn ($data) => $download_allows ? true : 'refused']);

        return $site_id;
    }

    private static function __attachment(int $site_id, string $file_name): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'zip refusal fixture ' . bin2hex(random_bytes(8)),
            $file_name,
            ['site_id' => $site_id]
        );
    }

    /**
     * Call the endpoint for a key and return the refusal it throws, asserting its shape.
     */
    private static function __refusal_for(string $key, string $expected_sentence): Rsx_User_Facing_Exception
    {
        $request = Request::create('/_download_zip/' . $key, 'GET');

        $e = static::__assert_throws(
            Rsx_User_Facing_Exception::class,
            fn () => File_Attachment_Controller::download_multiple_zip($request, ['key' => $key])
        );

        static::__assert_equals($expected_sentence, $e->user_message(), 'the refusal is the user-facing sentence');

        return $e;
    }

    public static function test_unknown_key_is_refused_as_invalid_or_expired()
    {
        static::__prepare();

        static::__refusal_for(str_repeat('0', 64), self::INVALID_LINK);
    }

    public static function test_expired_request_is_refused_with_the_same_sentence()
    {
        $site_id = static::__prepare();
        $attachment = static::__attachment($site_id, 'expired.txt');
        $zip_request = Zip_Download_Request_Model::create_request([['key' => $attachment->key]], 'expired.zip');

        DB::table('_zip_download_requests')
            ->where('id', $zip_request->id)
            ->update(['created_at' => now()->subHours(25)]);

        static::__refusal_for($zip_request->download_key, self::INVALID_LINK);
    }

    public static function test_unknown_member_is_refused_without_naming_it()
    {
        $site_id = static::__prepare();
        $attachment = static::__attachment($site_id, 'present.txt');
        $missing_key = str_repeat('f', 32);
        $zip_request = Zip_Download_Request_Model::create_request(
            [['key' => $attachment->key], ['key' => $missing_key, 'name' => 'secret-plan.pdf']],
            'mixed.zip'
        );

        $e = static::__refusal_for($zip_request->download_key, self::DENIED);
        static::__assert_false(str_contains($e->getMessage(), $missing_key), 'the sentence does not name the key');
        static::__assert_false(str_contains($e->getMessage(), 'secret-plan'), 'the sentence does not name the file');
    }

    public static function test_thumbnail_gate_refusal_answers_the_same_sentence()
    {
        $site_id = static::__prepare(false, true);
        $attachment = static::__attachment($site_id, 'gated-thumb.txt');
        $zip_request = Zip_Download_Request_Model::create_request([['key' => $attachment->key]], 'gated.zip');

        $e = static::__refusal_for($zip_request->download_key, self::DENIED);
        static::__assert_false(str_contains($e->getMessage(), 'gated-thumb'), 'the sentence does not name the file');
    }

    public static function test_download_gate_refusal_answers_the_same_sentence()
    {
        $site_id = static::__prepare(true, false);
        $attachment = static::__attachment($site_id, 'gated-download.txt');
        $zip_request = Zip_Download_Request_Model::create_request([['key' => $attachment->key]], 'gated.zip');

        $e = static::__refusal_for($zip_request->download_key, self::DENIED);
        static::__assert_false(str_contains($e->getMessage(), 'gated-download'), 'the sentence does not name the file');
    }

    public static function test_an_allowed_set_is_served()
    {
        $site_id = static::__prepare();
        $attachment = static::__attachment($site_id, 'allowed.txt');
        $zip_request = Zip_Download_Request_Model::create_request([['key' => $attachment->key]], 'allowed.zip');

        $request = Request::create('/_download_zip/' . $zip_request->download_key, 'GET');
        $response = File_Attachment_Controller::download_multiple_zip($request, ['key' => $zip_request->download_key]);

        static::__assert_equals(200, $response->getStatusCode(), 'the same fixture with both gates allowing streams as 200');
    }
}
