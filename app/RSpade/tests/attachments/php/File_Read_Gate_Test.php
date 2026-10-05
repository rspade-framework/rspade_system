<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\Rsx_File_Gates;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The two file READ gates - file.thumbnail.authorize and file.download.authorize - are
 * mandatory, exactly as the upload gate is.
 *
 * Rsx::trigger_gate() is open when nothing is listening, so an application that registered the
 * upload gate but no read gate used to serve every stored file to anybody: get_preview_info
 * finds an attachment by sequential id and hands back its inline URL. Every surface now asks
 * through Rsx_File_Gates::authorize(), which throws (5xx, the application is misconfigured)
 * naming the missing handler before anything is answered.
 *
 * Handlers are driven through the Event_Registry test seam, never a live #[OnEvent] on the real
 * event names (a fixture handler would gate the running application's files).
 */
class File_Read_Gate_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        config(['rsx.search.enabled' => false]);
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();
        config(['rsx.search.enabled' => true]);
    }

    private static function __attachment(): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites WHERE id > 0 ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $bytes = "read gate fixture " . bin2hex(random_bytes(12)) . "\n";

        return File_Attachment_Model::create_from_string($bytes, 'read_gate.txt', ['site_id' => $site_id]);
    }

    // FILE-READ-GATE-PREVIEW-INFO: the audited path - metadata by sequential id - refuses loudly
    // when no thumbnail gate is registered, naming the gate.
    public static function test_preview_info_throws_when_no_thumbnail_gate_is_registered()
    {
        $attachment = static::__attachment();
        Event_Registry::_set_test_handlers(Rsx_File_Gates::THUMBNAIL, []);
        Event_Registry::_set_test_handlers(Rsx_File_Gates::DOWNLOAD, [fn ($data) => true]);

        $thrown = static::__assert_throws(
            \RuntimeException::class,
            fn () => File_Preview_Controller::get_preview_info(Request::create('/x', 'POST'), ['attachment_id' => $attachment->id]),
            'file.thumbnail.authorize'
        );

        static::__assert_contains('disabled', $thrown->getMessage(), 'the message states file reads are disabled');
        static::__assert_contains('/rsx/handlers/', $thrown->getMessage(), 'the message says where the handler goes');
    }

    // FILE-READ-GATE-DOWNLOAD: the bytes route refuses loudly when no download gate is
    // registered, even though the thumbnail gate allows.
    public static function test_download_throws_when_no_download_gate_is_registered()
    {
        $attachment = static::__attachment();
        Event_Registry::_set_test_handlers(Rsx_File_Gates::THUMBNAIL, [fn ($data) => true]);
        Event_Registry::_set_test_handlers(Rsx_File_Gates::DOWNLOAD, []);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => File_Attachment_Controller::download_file(Request::create('/_download/' . $attachment->key), ['key' => $attachment->key]),
            'file.download.authorize'
        );
    }

    // FILE-READ-GATE-REGISTERED: with handlers registered the gates behave as before - an allow
    // is true and a refusal is returned verbatim.
    public static function test_a_registered_gate_allows_and_denies_as_its_handler_says()
    {
        Event_Registry::_set_test_handlers(Rsx_File_Gates::THUMBNAIL, [fn ($data) => true]);
        static::__assert_true(Rsx_File_Gates::authorize(Rsx_File_Gates::THUMBNAIL, ['attachment' => null]) === true);

        Event_Registry::_set_test_handlers(Rsx_File_Gates::DOWNLOAD, [fn ($data) => 'refused']);
        static::__assert_equals('refused', Rsx_File_Gates::authorize(Rsx_File_Gates::DOWNLOAD, ['attachment' => null]));
    }

    // FILE-READ-GATE-NOT-A-FILE-GATE: the helper answers for the three file gates only.
    public static function test_an_unknown_event_is_not_a_file_gate()
    {
        static::__assert_throws(\Throwable::class, fn () => Rsx_File_Gates::require_handler('file.something.else'), 'not a file gate');
    }
}
