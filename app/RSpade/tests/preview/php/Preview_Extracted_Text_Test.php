<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * File_Preview_Controller::get_extracted_text - the payload <Document_Text_Preview> reads.
 *
 * Two things are under test. The STATUS VOCABULARY (available | pending | error | unsupported)
 * must describe every extraction state exactly once, because the component paints a different
 * thing for each and 'pending' is the only one it will wait on. And the GATE CASCADE: extracted
 * text is the document's CONTENT, so it clears file.thumbnail.authorize AND file.download.authorize
 * - the pair /_download and /_preview/pdf run - where get_preview_info (metadata) clears the
 * thumbnail gate alone. The last test proves the difference is real rather than documented.
 */
class Preview_Extracted_Text_Test extends Rsx_Test_Abstract
{
    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    public static function setup()
    {
        // Creating an attachment must never spawn a real extraction worker - every test here
        // drives the extraction itself, synchronously, so it knows the state it is asserting on.
        config(['rsx.search.enabled' => false]);

        // The subject is the status vocabulary, not authorization; stand in for the app's handlers
        // with the documented test seam. The denial test overrides them again for its own case.
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
        Event_Registry::_set_test_handlers('file.download.authorize', [fn($data) => true]);
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        Event_Registry::_clear_test_handlers();

        config(['rsx.search.enabled' => true]);
    }

    /**
     * A real seeded site id, set as the session site (satisfies the site-scoped save hook and the
     * site FK on _file_attachments in the CLI harness).
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /**
     * A tracked attachment over bytes nothing else in the install shares, so it owns its own
     * deduplicated blob (and therefore its own index row).
     */
    private static function __make_attachment(string $filename, string $body): File_Attachment_Model
    {
        $site_id = static::__site_id();
        $bytes = $body . "\nrsx text preview fixture " . bin2hex(random_bytes(12)) . "\n";
        $attachment = File_Attachment_Model::create_from_string($bytes, $filename, ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    private static function __text_for(File_Attachment_Model $attachment)
    {
        return File_Preview_Controller::get_extracted_text(
            Request::create('/x', 'POST'),
            ['attachment_id' => $attachment->id]
        );
    }

    // PREVIEW-TEXT-PENDING: no index row yet IS the queue, and it reads as 'pending' with no text.
    public static function test_unindexed_attachment_reads_as_pending()
    {
        $attachment = static::__make_attachment('pending_text.txt', 'queued but never extracted');

        static::__assert_equals(0, (int) File_Storage_Model::find($attachment->file_storage_id)->is_indexed, 'the blob starts un-indexed');

        $result = static::__text_for($attachment);

        static::__assert_equals('pending', $result['status'], 'an un-indexed blob is pending');
        static::__assert_null($result['text'], 'pending carries no text - "" would be indistinguishable from an empty document');
    }

    // PREVIEW-TEXT-AVAILABLE: once the extraction pass has run, the text itself is returned.
    public static function test_extracted_attachment_returns_its_text()
    {
        $marker = 'RSPADE_TEXT_PREVIEW_MARKER_' . bin2hex(random_bytes(6));
        $attachment = static::__make_attachment('extracted_text.txt', $marker);

        $index = Search_Index_Service::extract_storage(File_Storage_Model::find($attachment->file_storage_id));
        static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, (int) $index->status_id, 'a plain-text blob extracts');

        $result = static::__text_for($attachment);

        static::__assert_equals('available', $result['status'], 'an extracted blob is available');
        static::__assert_contains($marker, (string) $result['text'], 'the extracted text is returned verbatim');
    }

    // PREVIEW-TEXT-UNSUPPORTED: a mime no extractor claims is UNSUPPORTED, not a failure - and the
    // vocabulary keeps them apart, because only one of them is worth an operator's attention.
    public static function test_unsupported_mime_reads_as_unsupported()
    {
        $attachment = static::__make_attachment('unsupported_text.bin', "\x00\x01\x02binary payload\x03");

        $index = Search_Index_Service::extract_storage(File_Storage_Model::find($attachment->file_storage_id));
        static::__assert_equals(
            Search_Index_Model::STATUS_UNSUPPORTED,
            (int) $index->status_id,
            'nothing in the extractor registry claims this mime'
        );

        $result = static::__text_for($attachment);

        static::__assert_equals('unsupported', $result['status'], 'unsupported is its own answer');
        static::__assert_null($result['text'], 'no text accompanies unsupported');
    }

    // PREVIEW-TEXT-FAILED: a recorded FAILED extraction reads as 'error'. Terminal - nothing but
    // rsx:search:reindex retries it, so the component must not sit in a waiting state over it.
    public static function test_failed_extraction_reads_as_error()
    {
        $attachment = static::__make_attachment('failed_text.txt', 'this blob will lose its bytes');
        $storage = File_Storage_Model::find($attachment->file_storage_id);

        // The genuine FAILED path: the content-addressed blob is gone from disk.
        @unlink($storage->get_full_path());

        $index = Search_Index_Service::extract_storage($storage);
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id, 'a missing blob is a recorded failure');

        $result = static::__text_for($attachment);

        static::__assert_equals('error', $result['status'], 'a failed extraction is an error');
        static::__assert_null($result['text'], 'no text accompanies an error');
    }

    // PREVIEW-TEXT-DEGRADED: a degraded upload (preview_unavailable) is answered 'error' WITHOUT
    // consulting the index, which for unparseable bytes would report a text-free extraction as
    // 'available' and paint an empty document over a broken one.
    public static function test_preview_unavailable_reads_as_error()
    {
        $attachment = static::__make_attachment('degraded_text.txt', 'degraded upload');

        Search_Index_Service::extract_storage(File_Storage_Model::find($attachment->file_storage_id));
        static::__assert_equals('available', static::__text_for($attachment)['status'], 'it extracts fine before being marked degraded');

        $attachment->preview_unavailable = true;
        $attachment->save();

        $result = static::__text_for(File_Attachment_Model::find($attachment->id));

        static::__assert_equals('error', $result['status'], 'a degraded upload has no readable content');
        static::__assert_null($result['text'], 'no text accompanies a degraded upload');
    }

    // PREVIEW-TEXT-CONTENT-GATE: the cascade is real. With the DOWNLOAD gate refusing and the
    // thumbnail gate permitting, get_preview_info (metadata) still answers and get_extracted_text
    // (content) refuses - which is the entire reason the two endpoints run different gates.
    public static function test_download_gate_denies_text_while_preview_info_still_answers()
    {
        $attachment = static::__make_attachment('gated_text.txt', 'content behind the download gate');
        Search_Index_Service::extract_storage(File_Storage_Model::find($attachment->file_storage_id));

        Event_Registry::_set_test_handlers('file.download.authorize', [fn($data) => false]);

        try {
            $denied = static::__text_for($attachment);

            static::__assert_instance_of(Error_Response::class, $denied, 'the content endpoint refuses');
            static::__assert_equals(
                \App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED,
                $denied->get_error_code(),
                'the refusal is an unauthorized error'
            );

            $info = File_Preview_Controller::get_preview_info(
                Request::create('/x', 'POST'),
                ['attachment_id' => $attachment->id]
            );
            static::__assert_array_has_key('viewer', $info, 'metadata still answers - it runs the thumbnail gate alone');
        } finally {
            Event_Registry::_set_test_handlers('file.download.authorize', [fn($data) => true]);
        }
    }
}
