<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ZipDownload\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Zip_Download_Request_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * /_download_zip is RESTARTABLE, NOT RESUMABLE, and its headers say so.
 *
 * The archive is generated as it streams and two generations of one key are not
 * byte-identical (Zip_Stream stamps the wall-clock DOS mod-time at stream-open), so a
 * byte-range resume would corrupt the archive. The response therefore declares
 * Accept-Ranges: none, ignores a Range request (200 with the whole archive, never 416)
 * and carries no validator (ETag / Last-Modified) that would invite If-Range. This test
 * pins those four facts on the response object the endpoint builds; the body is never
 * streamed, so no bytes are sent.
 *
 * Runs as the baseline user on the baseline site: the app's file.download.authorize
 * handler requires a logged-in staff user, and the per-member gates run at serve time.
 */
class Zip_Download_Range_Headers_Test extends Rsx_Test_Abstract
{
    private static function __response_for(array $server_headers)
    {
        $site_id = (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
        Session::impersonate($site_id, 1, 1);

        $attachment = File_Attachment_Model::create_from_string(
            'range-headers-' . uniqid(),
            'doc.txt',
            ['site_id' => $site_id]
        );

        $zip_request = Zip_Download_Request_Model::create_request(
            [['key' => $attachment->key]],
            'range-headers.zip'
        );

        $request = Request::create(
            '/_download_zip/' . $zip_request->download_key,
            'GET',
            [],
            [],
            [],
            $server_headers
        );

        return File_Attachment_Controller::download_multiple_zip($request, ['key' => $zip_request->download_key]);
    }

    public static function test_response_declares_no_range_support()
    {
        $response = static::__response_for([]);

        static::__assert_equals(200, $response->getStatusCode(), 'a plain GET streams as 200');
        static::__assert_equals('none', $response->headers->get('Accept-Ranges'), 'Accept-Ranges: none is declared');
    }

    public static function test_range_request_is_ignored_not_refused()
    {
        $response = static::__response_for(['HTTP_RANGE' => 'bytes=0-99']);

        static::__assert_equals(200, $response->getStatusCode(), 'a Range request is answered 200, never 416 or 206');
        static::__assert_equals('none', $response->headers->get('Accept-Ranges'), 'the declaration stands on a Range request too');
        static::__assert_false($response->headers->has('Content-Range'), 'no Content-Range: the range was ignored, not honoured');
    }

    public static function test_no_validator_invites_conditional_resume()
    {
        $response = static::__response_for([]);

        static::__assert_false($response->headers->has('ETag'), 'no ETag - two generations are not byte-identical');
        static::__assert_false($response->headers->has('Last-Modified'), 'no Last-Modified - nothing to If-Range against');
        static::__assert_false($response->headers->has('Content-Length'), 'no Content-Length - the archive is generated as it streams');
    }
}
