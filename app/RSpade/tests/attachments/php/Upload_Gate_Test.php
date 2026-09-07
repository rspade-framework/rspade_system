<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The MANDATORY file.upload.authorize gate on POST /_upload.
 *
 * Rsx::trigger_gate() returns true when nothing is listening - correct for an optional gate,
 * catastrophic for this one, since an application that never wrote a handler would be running an
 * anonymous upload endpoint. upload() therefore asks the registry directly FIRST and throws a
 * RuntimeException (5xx: the application is misconfigured, the client did nothing wrong) when no
 * handler is registered. The general trigger_gate() default is untouched.
 *
 * The gate also fires AFTER file presence/validity checks and carries the uploaded file itself
 * (plus its temp path) so a handler can inspect real bytes and reject on content.
 *
 * Handler registration is manifest-driven, so these tests drive it through the Event_Registry
 * test seam (_set_test_handlers / _clear_test_handlers) rather than declaring a live #[OnEvent]
 * on the real event name - a fixture handler on file.upload.authorize would be discovered by the
 * manifest and would silently gate the running application's uploads. Every test method clears
 * its overrides in a finally block, and teardown() is the class-level backstop.
 */
class Upload_Gate_Test extends Rsx_Test_Abstract
{
    /** Captured gate payload from the most recent capturing handler. */
    private static $captured_gate_data = null;

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();
        static::$captured_gate_data = null;
    }

    /**
     * Resolve a real seeded site id and set it as the session site, so the site-scoped save hook
     * and the site FK on _file_attachments are both satisfied in the CLI test harness.
     *
     * id > 0 deliberately: site 0 ("Default") exists, but upload() treats a falsy site id as
     * "unable to resolve a site" and returns 400 before any file is stored.
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites WHERE id > 0 ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /** Valid PNG bytes for a small solid-color image (parses cleanly). */
    private static function __valid_png_bytes(): string
    {
        $img = new \Imagick();
        $img->newImage(8, 8, new \ImagickPixel('blue'));
        $img->setImageFormat('png');
        $bytes = $img->getImageBlob();
        $img->destroy();

        return $bytes;
    }

    /**
     * Build a POST /_upload request carrying a test-mode UploadedFile over real bytes on disk.
     * test=true bypasses the is_uploaded_file() check; everything downstream reads the real path.
     */
    private static function __upload_request(string $original_name = 'gate.png'): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_gate_');
        file_put_contents($tmp, static::__valid_png_bytes());

        $file = new UploadedFile($tmp, $original_name, null, null, true);

        return Request::create('/_upload', 'POST', [], [], ['file' => $file]);
    }

    private static function __live_attachment_count(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM _file_attachments WHERE destroyed_at IS NULL')->c;
    }

    // --- mandatory gate --------------------------------------------------------------------------

    public static function test_no_registered_handler_fails_loud()
    {
        static::__site_id();
        Event_Registry::_set_test_handlers('file.upload.authorize', []);

        try {
            $count_before = static::__live_attachment_count();

            static::__assert_throws(
                \RuntimeException::class,
                function () {
                    File_Attachment_Controller::upload(static::__upload_request(), []);
                },
                'rsx:man file_upload'
            );

            static::__assert_equals(
                $count_before,
                static::__live_attachment_count(),
                'an ungated upload endpoint stores nothing'
            );
        } finally {
            Event_Registry::_clear_test_handlers();
        }
    }

    public static function test_missing_handler_message_names_the_gate()
    {
        static::__site_id();
        Event_Registry::_set_test_handlers('file.upload.authorize', []);

        try {
            $thrown = static::__assert_throws(
                \RuntimeException::class,
                function () {
                    File_Attachment_Controller::upload(static::__upload_request(), []);
                },
                'file.upload.authorize'
            );

            static::__assert_contains(
                'disabled',
                $thrown->getMessage(),
                'the message states uploads are disabled, not that the request was bad'
            );
        } finally {
            Event_Registry::_clear_test_handlers();
        }
    }

    // --- registered handler: allow -----------------------------------------------------------------

    public static function test_registered_handler_returning_true_allows_upload()
    {
        static::__site_id();
        Event_Registry::_set_test_handlers('file.upload.authorize', [
            function ($data) {
                return true;
            },
        ]);

        try {
            $response = File_Attachment_Controller::upload(static::__upload_request(), []);

            static::__assert_equals(200, $response->getStatusCode(), 'an authorized upload succeeds');

            $payload = json_decode($response->getContent(), true);
            static::__assert_true($payload['success'], 'response reports success');
            static::__assert_not_empty($payload['attachment']['key'], 'an access key is returned');
            static::__assert_equals('gate.png', $payload['attachment']['file_name'], 'the uploaded file is stored');
        } finally {
            Event_Registry::_clear_test_handlers();
        }
    }

    // --- gate payload ------------------------------------------------------------------------------

    public static function test_gate_payload_carries_the_uploaded_file()
    {
        static::__site_id();
        static::$captured_gate_data = null;

        Event_Registry::_set_test_handlers('file.upload.authorize', [
            function ($data) {
                static::$captured_gate_data = $data;
                return true;
            },
        ]);

        try {
            $expected_bytes = static::__valid_png_bytes();

            File_Attachment_Controller::upload(static::__upload_request('payload.png'), []);

            $data = static::$captured_gate_data;
            static::__assert_not_null($data, 'the gate handler ran');

            // Existing keys stay put - handlers written before this change keep working.
            static::__assert_array_has_key('request', $data, 'request key preserved');
            static::__assert_array_has_key('user', $data, 'user key preserved');
            static::__assert_array_has_key('params', $data, 'params key preserved');

            // New file keys.
            static::__assert_instance_of(UploadedFile::class, $data['file'], 'file key is the UploadedFile');
            static::__assert_equals('payload.png', $data['filename'], 'filename is the client original name');
            static::__assert_equals(strlen($expected_bytes), $data['size'], 'size is the byte count');
            static::__assert_equals('image/png', $data['mime_type'], 'mime_type is the sniffed type');
            static::__assert_equals('png', $data['extension'], 'extension is the lowercased client extension');

            // tmp_path must let a handler read the ACTUAL bytes before anything is persisted.
            static::__assert_not_empty($data['tmp_path'], 'tmp_path is populated');
            static::__assert_true(is_file($data['tmp_path']), 'tmp_path points at the received file');
            static::__assert_equals(
                $expected_bytes,
                file_get_contents($data['tmp_path']),
                'tmp_path yields the uploaded bytes, so a handler can reject on content'
            );
        } finally {
            Event_Registry::_clear_test_handlers();
            static::$captured_gate_data = null;
        }
    }

    // --- registered handler: deny ------------------------------------------------------------------

    public static function test_handler_response_halts_the_upload()
    {
        static::__site_id();
        Event_Registry::_set_test_handlers('file.upload.authorize', [
            function ($data) {
                return response()->json([
                    'success' => false,
                    'error' => 'Upload rejected by test gate',
                ], 403);
            },
        ]);

        try {
            $count_before = static::__live_attachment_count();

            $response = File_Attachment_Controller::upload(static::__upload_request(), []);

            static::__assert_equals(403, $response->getStatusCode(), 'the denial response is returned verbatim');

            $payload = json_decode($response->getContent(), true);
            static::__assert_false($payload['success'], 'the denial payload is the handler\'s own');
            static::__assert_equals('Upload rejected by test gate', $payload['error'], 'handler message preserved');

            static::__assert_equals(
                $count_before,
                static::__live_attachment_count(),
                'a denied upload stores nothing'
            );
        } finally {
            Event_Registry::_clear_test_handlers();
        }
    }

    // --- gate ordering -----------------------------------------------------------------------------

    public static function test_gate_runs_only_after_the_file_is_validated()
    {
        static::__site_id();
        static::$captured_gate_data = null;

        Event_Registry::_set_test_handlers('file.upload.authorize', [
            function ($data) {
                static::$captured_gate_data = $data;
                return true;
            },
        ]);

        try {
            $request = Request::create('/_upload', 'POST');
            $response = File_Attachment_Controller::upload($request, []);

            static::__assert_equals(400, $response->getStatusCode(), 'a fileless request is a 400');
            static::__assert_null(
                static::$captured_gate_data,
                'the gate never fires without a file - its payload is built around one'
            );
        } finally {
            Event_Registry::_clear_test_handlers();
            static::$captured_gate_data = null;
        }
    }
}
