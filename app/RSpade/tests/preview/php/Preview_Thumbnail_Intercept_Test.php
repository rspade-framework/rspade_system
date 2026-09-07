<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Preview\Php\Preview_Thumbnail_Render_Fixture_Handler;

/**
 * The document.thumbnail_render filter chain inserted at the top of
 * File_Attachment_Controller::_render_thumbnail_data: an app #[OnEvent] handler can return WebP
 * bytes that are served verbatim, and a normal (non-marker) source still renders through the
 * framework renderer registry / icon path. Proven with the marker-guarded live fixture
 * (Preview_Thumbnail_Render_Fixture_Handler), which intercepts only paths containing
 * 'rsx_test_thumb_intercept' - the SAFETY property that makes the live fixture acceptable.
 *
 * Pure logic: no DB (uses unsaved model instances, exercises the byte pipeline directly).
 */
class Preview_Thumbnail_Intercept_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_marker_path_intercepts_with_fixture_bytes()
    {
        $attachment = new File_Attachment_Model();
        $attachment->mime_type = 'application/pdf';
        $attachment->file_extension = 'pdf';
        $attachment->key = 'rsx_test_thumb_marker';

        $bytes = File_Attachment_Controller::_render_thumbnail_data(
            $attachment,
            '/tmp/' . Preview_Thumbnail_Render_Fixture_Handler::MARKER . '_source.pdf',
            'fit',
            64,
            64
        );

        static::__assert_equals(
            Preview_Thumbnail_Render_Fixture_Handler::webp_bytes(),
            $bytes,
            'the fixture WebP bytes are returned verbatim (chain intercepted before the registry)'
        );
    }

    public static function test_non_marker_source_renders_via_registry()
    {
        // A text/plain attachment has no registered renderer, so the framework pipeline renders the
        // generic extension icon as WebP. The source path is a real-shaped content hash (no marker),
        // so the live fixture MUST decline - the returned bytes must NOT be the fixture's bytes.
        $attachment = new File_Attachment_Model();
        $attachment->mime_type = 'text/plain';
        $attachment->file_extension = 'txt';
        $attachment->key = 'rsx_test_thumb_normal';

        $bytes = File_Attachment_Controller::_render_thumbnail_data(
            $attachment,
            '/var/www/html/system/storage/uploads/ab/cd/abcd0123456789',
            'fit',
            64,
            64
        );

        static::__assert_not_empty($bytes, 'the registry/icon path produced thumbnail bytes');
        static::__assert_not_equals(
            Preview_Thumbnail_Render_Fixture_Handler::webp_bytes(),
            $bytes,
            'the live marker-guarded fixture did NOT hijack a real (non-marker) thumbnail'
        );
        static::__assert_true(
            strncmp($bytes, 'RIFF', 4) === 0,
            'the framework path produced a WebP (RIFF container)'
        );
    }
}
