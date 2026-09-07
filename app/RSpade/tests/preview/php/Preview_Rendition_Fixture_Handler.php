<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use App\RSpade\Core\Files\File_Attachment_Model;

/**
 * Fixture handler proving an app #[OnEvent] can intercept the document.preview_rendition resolve
 * chain consulted by File_Preview_Controller::pdf_rendition.
 *
 * CRITICAL - MARKER GUARD: this handler is manifest-discovered and therefore LIVE in dev. It runs
 * for EVERY rendition request. The chain payload carries no path, so it guards on the attachment's
 * file_name: it INTERCEPTS ONLY an attachment whose file_name contains the marker
 * 'rsx_test_rendition_intercept' and DECLINES (returns null) for everything else. Real files are
 * not named with the marker, so live rendition serving is unaffected (a real file literally named
 * with the marker is an accepted, documented test seam - the same marker philosophy as the search
 * fixtures). On interception it returns the ['unsupported' => true] contract branch (-> 415).
 */
class Preview_Rendition_Fixture_Handler
{
    /** file_name substring that triggers interception. */
    public const MARKER = 'rsx_test_rendition_intercept';

    #[OnEvent('document.preview_rendition', priority: 5)]
    public static function intercept($data)
    {
        $attachment = $data['attachment'] ?? null;

        if (!($attachment instanceof File_Attachment_Model)) {
            return null;
        }

        if (strpos((string) $attachment->file_name, self::MARKER) === false) {
            return null; // decline - let the framework rendition pipeline run
        }

        return ['unsupported' => true];
    }
}
