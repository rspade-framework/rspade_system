<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pipeline-type resolution (File_Attachment_Model::resolve_pipeline_mime) and the routing it drives.
 *
 * Policy: document extension wins unconditionally (correcting a flaky OOXML->application/zip sniff);
 * image sniff wins for the image family (correcting a misnamed extension); everything else keeps the
 * sniff. Then proves the four processing registries route on that pipeline mime, so a zip-sniffed
 * .docx resolves to the LibreOffice renderer / Pdf_Viewer / convertible / DOCUMENT bucket rather
 * than a generic icon.
 */
class Pipeline_Mime_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public static function setup()
    {
        // The routing assertions expect the LibreOffice renderer/extractor to be considered
        // registered (master switch on) regardless of host provisioning.
        config(['rsx.libreoffice.enabled' => true]);
    }

    // --- resolve_pipeline_mime unit matrix -------------------------------------------------------

    public static function test_document_extension_beats_zip_sniff()
    {
        static::__assert_equals(
            self::DOCX_MIME,
            File_Attachment_Model::resolve_pipeline_mime('application/zip', 'docx'),
            'a docx sniffed as application/zip resolves to the OOXML docx mime (extension wins)'
        );
    }

    public static function test_document_extension_case_insensitive()
    {
        static::__assert_equals(
            self::DOCX_MIME,
            File_Attachment_Model::resolve_pipeline_mime('application/zip', 'DOCX'),
            'the extension match is case-insensitive'
        );
    }

    public static function test_image_sniff_beats_extension()
    {
        static::__assert_equals(
            'image/webp',
            File_Attachment_Model::resolve_pipeline_mime('image/webp', 'png'),
            'a webp saved as .png resolves to image/webp (image sniff wins - png is not a document ext)'
        );
    }

    public static function test_pdf_extension_and_pdf_sniff()
    {
        static::__assert_equals(
            'application/pdf',
            File_Attachment_Model::resolve_pipeline_mime('application/pdf', 'pdf'),
            'a normal pdf resolves to application/pdf'
        );
    }

    public static function test_unknown_extension_zip_sniff_unchanged()
    {
        static::__assert_equals(
            'application/zip',
            File_Attachment_Model::resolve_pipeline_mime('application/zip', 'zip'),
            'a genuine .zip keeps application/zip (unchanged behavior)'
        );
    }

    public static function test_no_extension_image_sniff()
    {
        static::__assert_equals(
            'image/png',
            File_Attachment_Model::resolve_pipeline_mime('image/png', null),
            'no extension + image sniff -> the image sniff'
        );
    }

    public static function test_empty_and_null_inputs_are_safe()
    {
        static::__assert_equals('', File_Attachment_Model::resolve_pipeline_mime(null, null), 'both null -> empty string');
        static::__assert_equals('', File_Attachment_Model::resolve_pipeline_mime('', ''), 'both empty -> empty string');
        static::__assert_equals(
            self::DOCX_MIME,
            File_Attachment_Model::resolve_pipeline_mime(null, 'docx'),
            'null sniff + document extension still resolves the document mime'
        );
    }

    // --- routing on the pipeline mime ------------------------------------------------------------

    public static function test_convertible_routing_uses_pipeline_mime()
    {
        // Office documents have no thumbnail renderer at all now - they are CONVERTIBLE, and the
        // render worker turns them into a PDF rendition that the pipeline rasterizes. What must
        // still route on the pipeline mime is that convertible decision: a zip-sniffed docx is a
        // document, and reading the raw sniff was the original silent-icon bug.
        static::__assert_true(
            File_Attachment_Model::is_convertible_mime(
                File_Attachment_Model::resolve_pipeline_mime('application/zip', 'docx')
            ),
            'zip-sniffed docx is recognized as a convertible document'
        );

        static::__assert_false(
            File_Attachment_Model::is_convertible_mime('application/zip'),
            'the raw application/zip sniff is not convertible (this was the silent-icon bug)'
        );

        static::__assert_null(
            File_Attachment_Controller::renderer_class_for_mime(
                File_Attachment_Model::resolve_pipeline_mime('application/zip', 'docx')
            ),
            'a document has no direct thumbnail renderer - its pixels come from the rendition'
        );
    }

    public static function test_viewer_routes_on_pipeline_mime()
    {
        static::__assert_equals(
            'Pdf_Viewer',
            File_Preview_Controller::viewer_for_mime(
                File_Attachment_Model::resolve_pipeline_mime('application/zip', 'docx')
            ),
            'zip-sniffed docx routes to Pdf_Viewer'
        );

        static::__assert_equals(
            'Icon_Viewer',
            File_Preview_Controller::viewer_for_mime('application/zip'),
            'the raw application/zip sniff routes to Icon_Viewer (the bug)'
        );
    }

    public static function test_convertible_on_pipeline_mime()
    {
        // __is_convertible is protected; exercise it via reflection to prove the rendition endpoint
        // recognizes the pipeline mime as convertible.
        $method = new \ReflectionMethod(File_Preview_Controller::class, '__is_convertible');
        $method->setAccessible(true);

        $pipeline = File_Attachment_Model::resolve_pipeline_mime('application/zip', 'docx');
        static::__assert_true(
            $method->invoke(null, $pipeline),
            'zip-sniffed docx pipeline mime is convertible to PDF'
        );
        static::__assert_false(
            $method->invoke(null, 'application/zip'),
            'the raw application/zip sniff is not convertible (the bug)'
        );
    }

    public static function test_determine_file_type_on_pipeline_mime()
    {
        $pipeline = File_Attachment_Model::resolve_pipeline_mime('application/zip', 'docx');
        static::__assert_equals(
            6,
            File_Attachment_Model::determine_file_type($pipeline),
            'zip-sniffed docx buckets as DOCUMENT (6), not ARCHIVE (4)'
        );
        static::__assert_equals(
            4,
            File_Attachment_Model::determine_file_type('application/zip'),
            'the raw application/zip sniff buckets as ARCHIVE (4) (the bug)'
        );
    }
}
