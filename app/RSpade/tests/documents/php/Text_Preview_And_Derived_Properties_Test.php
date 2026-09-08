<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A text file previews AS ITSELF, and the attachment carries its own presentation answers.
 *
 * Four properties, each independent of the others and each with a test here:
 *
 *   1. text/* resolves to Text_Viewer, not to the terminal '*' Icon_Viewer.
 *   2. text/* CLASSIFIES as FILE_TYPE_DOCUMENT - previewable in exactly the way a PDF is.
 *      FILE_TYPE_TEXT stays a valid enum value that nothing assigns.
 *   3. should_show_text_preview() answers the presentation question once, so a host with a
 *      text pane stops keying off the extraction status: a .txt would show the same
 *      characters twice and a spreadsheet's extraction is search fodder, not prose.
 *   4. is_image / is_video / is_document / can_open_inline are DERIVED PROPERTIES - declared
 *      in $appends, serialized by toArray(), and therefore present on the JS record. And
 *      can_open_inline is the one that cannot be derived from file_type_id at all.
 */
class Text_Preview_And_Derived_Properties_Test extends Rsx_Test_Abstract
{
    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function setup()
    {
        // Creating an attachment queues its blob; with the kick switch off no detached worker is
        // spawned and the index rows these tests write by hand stay the truth.
        config(['rsx.search.enabled' => false]);

        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
        Event_Registry::_set_test_handlers('file.download.authorize', [fn($data) => true]);
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                Search_Index_Model::forModel('File_Storage_Model', $attachment->file_storage_id)->delete();
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        Event_Registry::_clear_test_handlers();

        config(['rsx.search.enabled' => true]);
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    private static function __make_attachment(string $marker, string $filename): File_Attachment_Model
    {
        $site_id = static::__site_id();
        $bytes = "RSpade text preview test {$marker} " . bin2hex(random_bytes(12));
        $attachment = File_Attachment_Model::create_from_string($bytes, $filename, ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();

        return $attachment;
    }

    /**
     * A REAL 1x1 PNG. The sniffed mime is what drives the pipeline for anything that is not a
     * known document extension, so bytes that merely carry a .png name would sniff text/plain
     * and answer every question here as a text file would.
     */
    private static function __make_png_attachment(string $marker): File_Attachment_Model
    {
        $site_id = static::__site_id();
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $attachment = File_Attachment_Model::create_from_string($png, "{$marker}.png", ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();

        return $attachment;
    }

    /**
     * Stamp an extraction outcome onto this attachment's blob without running the pipeline.
     * should_show_text_preview() reads the index row's status_id and nothing else about it.
     */
    private static function __set_extraction_status(File_Attachment_Model $attachment, int $status_id): void
    {
        $index = Search_Index_Model::find_or_create_for_model('File_Storage_Model', $attachment->file_storage_id);
        $index->status_id = $status_id;
        $index->content = 'extracted text stand-in';
        $index->save();
    }

    // ============================================================================================
    // VIEWER RESOLUTION
    // ============================================================================================

    // DOCUMENTS-TEXTVIEW-RESOLVE: text/* resolves to Text_Viewer. The registry is first-match, so
    // this also proves text/* sits ABOVE the terminal '*' and BELOW the specific document mimes.
    public static function test_text_mimes_resolve_to_the_text_viewer()
    {
        static::__assert_equals(
            'Text_Viewer',
            File_Preview_Controller::viewer_for_mime('text/plain'),
            'a .txt renders as itself'
        );
        static::__assert_equals(
            'Text_Viewer',
            File_Preview_Controller::viewer_for_mime('text/csv'),
            'so does a .csv - it needs no conversion and gains nothing from one'
        );

        static::__assert_equals(
            'Pdf_Viewer',
            File_Preview_Controller::viewer_for_mime('application/pdf'),
            'the text/* entry does not shadow the document patterns above it'
        );
        static::__assert_equals(
            'Image_Viewer',
            File_Preview_Controller::viewer_for_mime('image/png'),
            'nor the image entry'
        );
        static::__assert_equals(
            'Icon_Viewer',
            File_Preview_Controller::viewer_for_mime('application/octet-stream'),
            'and the terminal * still answers for everything else'
        );
    }

    // ============================================================================================
    // CLASSIFICATION
    // ============================================================================================

    // DOCUMENTS-TEXTVIEW-CLASSIFY: text/* buckets as FILE_TYPE_DOCUMENT. FILE_TYPE_TEXT survives
    // as a declared enum value that nothing assigns - the migration moved the rows, not the value.
    public static function test_text_classifies_as_a_document()
    {
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_DOCUMENT,
            File_Attachment_Model::determine_file_type('text/plain'),
            'a text file is a document, previewable in exactly the way a PDF is'
        );
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_DOCUMENT,
            File_Attachment_Model::determine_file_type('text/csv'),
            'every text/* mime, not just text/plain'
        );
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_DOCUMENT,
            File_Attachment_Model::determine_file_type('application/pdf'),
            'alongside the mimes that already classified this way'
        );
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_IMAGE,
            File_Attachment_Model::determine_file_type('image/png'),
            'and nothing else moved'
        );

        static::__assert_equals(
            5,
            File_Attachment_Model::FILE_TYPE_TEXT,
            'FILE_TYPE_TEXT remains a declared constant'
        );
        static::__assert_array_has_key(
            File_Attachment_Model::FILE_TYPE_TEXT,
            File_Attachment_Model::$enums['file_type_id'],
            'and a valid enum row - an enum value is not removed by moving rows off it'
        );
    }

    // ============================================================================================
    // should_show_text_preview()
    // ============================================================================================

    // DOCUMENTS-TEXTVIEW-SUPPRESS: the presentation flag. True for a document whose picture is the
    // preview; false for a file whose preview already IS its text, and for a spreadsheet.
    public static function test_should_show_text_preview_answers_per_mime()
    {
        $docx = static::__make_attachment('docx', 'suppress_word.docx');
        $pdf = static::__make_attachment('pdf', 'suppress_doc.pdf');
        $xlsx = static::__make_attachment('xlsx', 'suppress_sheet.xlsx');
        $txt = static::__make_attachment('txt', 'suppress_notes.txt');

        foreach ([$docx, $pdf, $xlsx, $txt] as $attachment) {
            static::__set_extraction_status($attachment, Search_Index_Model::STATUS_EXTRACTED);
        }

        static::__assert_true(
            $docx->should_show_text_preview(),
            'a Word document: the preview is a picture of it, so its text is worth a pane'
        );
        static::__assert_true(
            $pdf->should_show_text_preview(),
            'and a PDF, for the same reason'
        );
        static::__assert_false(
            $xlsx->should_show_text_preview(),
            'a spreadsheet extracts to an undelimited run of cell values - search fodder, not prose'
        );
        static::__assert_false(
            $txt->should_show_text_preview(),
            'a text file: the preview IS the text, so a pane beside it repeats every character'
        );

        // The flag is about DISPLAY, and it needs text to be displaying: no extraction, no pane.
        static::__set_extraction_status($docx, Search_Index_Model::STATUS_FAILED);
        static::__assert_false(
            $docx->fresh()->should_show_text_preview(),
            'a failed extraction has nothing to show, whatever the mime says'
        );
    }

    // DOCUMENTS-TEXTVIEW-PAYLOAD: both payloads that carry text carry the flag, so a caller
    // already holding the text does not need a second round trip to learn whether to display it.
    public static function test_both_preview_payloads_carry_the_flag()
    {
        $txt = static::__make_attachment('payload', 'payload_notes.txt');
        static::__set_extraction_status($txt, Search_Index_Model::STATUS_EXTRACTED);

        $request = Request::create('/');

        $info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $txt->id]);
        static::__assert_array_has_key('should_show_text_preview', $info, 'get_preview_info carries it');
        static::__assert_false($info['should_show_text_preview'], 'and answers false for a text file');
        static::__assert_equals('Text_Viewer', $info['viewer'], 'the same payload names the viewer');

        $text = File_Preview_Controller::get_extracted_text($request, ['attachment_id' => $txt->id]);
        static::__assert_array_has_key('should_show_text_preview', $text, 'get_extracted_text carries it too');
        static::__assert_false($text['should_show_text_preview'], 'with the same answer');
        static::__assert_equals('available', $text['status'], 'and the text is returned regardless');
        static::__assert_not_empty($text['text'], 'the flag is advice about display, never authorization');
    }

    // ============================================================================================
    // DERIVED PROPERTIES
    // ============================================================================================

    // DOCUMENTS-TEXTVIEW-INLINE: can_open_inline consults the browser-native mime list, NOT
    // file_type_id - .txt, .docx and .xlsx all classify as DOCUMENT and disagree about this.
    public static function test_can_open_inline_is_answered_from_the_mime()
    {
        $txt = static::__make_attachment('inline_txt', 'inline_notes.txt');
        $pdf = static::__make_attachment('inline_pdf', 'inline_doc.pdf');
        $png = static::__make_png_attachment('inline_picture');
        $docx = static::__make_attachment('inline_docx', 'inline_word.docx');
        $xlsx = static::__make_attachment('inline_xlsx', 'inline_sheet.xlsx');

        static::__assert_true($txt->can_open_inline(), 'a browser renders text bytes in a tab');
        static::__assert_true($pdf->can_open_inline(), 'and a PDF');
        static::__assert_true($png->can_open_inline(), 'and an image');
        static::__assert_false($docx->can_open_inline(), 'a .docx has a preview here but downloads in a tab');
        static::__assert_false($xlsx->can_open_inline(), 'as does a spreadsheet');

        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_DOCUMENT,
            (int) $txt->file_type_id,
            'the .txt and the .docx are the SAME file_type_id ...'
        );
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_DOCUMENT,
            (int) $docx->file_type_id,
            '... which is exactly why this question cannot be answered from it'
        );
    }

    // DOCUMENTS-TEXTVIEW-APPENDS: the four derived properties ride toArray(), so they reach the
    // JS record on every payload the model produces - not just on the one fetch() builds.
    public static function test_to_array_carries_the_derived_properties()
    {
        $png = static::__make_png_attachment('appends_picture');
        $docx = static::__make_attachment('appends_docx', 'appends_word.docx');

        $data = $png->toArray();

        foreach (['is_image', 'is_video', 'is_document', 'can_open_inline'] as $key) {
            static::__assert_array_has_key($key, $data, "toArray() carries {$key}");
        }

        static::__assert_true($data['is_image'], 'an image reports itself as one');
        static::__assert_false($data['is_video'], 'and not as a video');
        static::__assert_false($data['is_document'], 'and not as a document');
        static::__assert_true($data['can_open_inline'], 'and a browser can show it inline');

        // Each accessor DELEGATES to the public predicate; the method is the definition and the
        // property is only its serialization, so they can never disagree.
        $word = $docx->toArray();
        static::__assert_equals($docx->is_image(), $word['is_image'], 'is_image matches its method');
        static::__assert_equals($docx->is_video(), $word['is_video'], 'is_video matches its method');
        static::__assert_equals($docx->is_document(), $word['is_document'], 'is_document matches its method');
        static::__assert_equals($docx->can_open_inline(), $word['can_open_inline'], 'can_open_inline matches its method');

        // And they arrive on the ORM payload the browser actually receives.
        $fetched = File_Attachment_Model::fetch($png->id);
        static::__assert_true($fetched['is_image'], 'fetch() carries them with no hand-added key');
        static::__assert_true($fetched['can_open_inline'], 'all four of them');
    }
}
