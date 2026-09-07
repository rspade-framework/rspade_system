<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Preview\Php\Preview_Rendition_Fixture_Handler;

/**
 * The document.preview_rendition resolve chain consulted by File_Preview_Controller::pdf_rendition:
 * an app #[OnEvent] handler can intercept a rendition and report the ['unsupported' => true]
 * contract (-> 415), and declines for a non-marker attachment so the framework rendition pipeline
 * runs. Asserted at the chain level (the route path needs auth + a served response; the resolve
 * chain is the interceptable seam and is what the endpoint dispatches on).
 *
 * Uses the marker-guarded live fixture (Preview_Rendition_Fixture_Handler), which intercepts only
 * an attachment whose file_name contains 'rsx_test_rendition_intercept'.
 *
 * Pure logic: no DB (unsaved model instances carry just the file_name the fixture guards on).
 */
class Preview_Rendition_Chain_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_marker_attachment_reports_unsupported()
    {
        $attachment = new File_Attachment_Model();
        $attachment->file_name = Preview_Rendition_Fixture_Handler::MARKER . '.pdf';

        $result = Rsx::trigger_resolve('document.preview_rendition', [
            'attachment' => $attachment,
            'request' => null,
        ]);

        static::__assert_true(
            is_array($result) && ($result['unsupported'] ?? null) === true,
            'the fixture intercepts a marker attachment with the unsupported contract'
        );
    }

    public static function test_non_marker_attachment_declines()
    {
        $attachment = new File_Attachment_Model();
        $attachment->file_name = 'ordinary_document.docx';

        $result = Rsx::trigger_resolve('document.preview_rendition', [
            'attachment' => $attachment,
            'request' => null,
        ]);

        static::__assert_null($result, 'a non-marker attachment is declined so the framework rendition pipeline runs');
    }
}
