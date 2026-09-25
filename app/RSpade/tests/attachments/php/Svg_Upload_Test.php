<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Icons;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Svg_Upload_Sanitizer;
use App\RSpade\Core\Files\Unparseable_Svg_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Uploaded SVG: sanitized before storage, refused when unparseable, never handed to
 * ImageMagick (thumbnail = extension icon), and every file response is served with the
 * sandboxing CSP and nosniff.
 *
 * Behavior of record: php artisan rsx:man file_upload (SVG).
 */
class Svg_Upload_Test extends Rsx_Test_Abstract
{
    /** A hostile SVG: inline script, an event handler, and a local-file image reference. */
    private const HOSTILE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
        . 'width="10" height="10" onload="alert(1)"><script>alert(2)</script>'
        . '<image xlink:href="text:/etc/hostname" width="10" height="10"/>'
        . '<rect width="10" height="10" fill="red"/></svg>';

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        \App\RSpade\Core\Session\Session::set_site_id($id);

        return $id;
    }

    private static function __uploaded_file(string $bytes, string $original_name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_svg_');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $original_name, null, null, true);
    }

    public static function test_an_uploaded_svg_is_stored_sanitized()
    {
        $attachment = File_Attachment_Model::create_from_upload(
            static::__uploaded_file(self::HOSTILE_SVG, 'hostile.svg'),
            ['site_id' => static::__site_id()]
        );

        $stored = file_get_contents($attachment->resolve_storage()->get_full_path());

        static::__assert_true($attachment->is_svg(), 'the attachment reads as an SVG');
        static::__assert_contains('<rect', $stored);
        static::__assert_false(stripos($stored, '<script') !== false, 'the script element was stored');
        static::__assert_false(stripos($stored, 'onload') !== false, 'the event handler was stored');
    }

    public static function test_an_svg_never_reaches_imagemagick()
    {
        $attachment = File_Attachment_Model::create_from_upload(
            static::__uploaded_file(self::HOSTILE_SVG, 'probe.svg'),
            ['site_id' => static::__site_id()]
        );

        static::__assert_null($attachment->width, 'an SVG keeps no ImageMagick-derived dimensions');

        $thumbnail = File_Attachment_Controller::_render_thumbnail_data(
            $attachment,
            $attachment->resolve_storage()->get_full_path(),
            'fit',
            64,
            64
        );

        static::__assert_equals(
            File_Attachment_Icons::render_icon_as_thumbnail('svg', 64, 64),
            $thumbnail,
            'an SVG thumbnail is the extension icon'
        );
    }

    public static function test_an_unparseable_svg_is_refused_and_nothing_is_stored()
    {
        $site_id = static::__site_id();
        $before = (int) DB::selectOne('SELECT COUNT(*) AS n FROM _file_attachments')->n;

        static::__assert_throws(Unparseable_Svg_Exception::class, function () use ($site_id) {
            File_Attachment_Model::create_from_upload(
                static::__uploaded_file('<svg xmlns="http://www.w3.org/2000/svg"><rect', 'broken.svg'),
                ['site_id' => $site_id]
            );
        });

        $after = (int) DB::selectOne('SELECT COUNT(*) AS n FROM _file_attachments')->n;
        static::__assert_equals($before, $after, 'a refused SVG leaves no attachment row');
    }

    public static function test_svg_detection_by_type_or_extension()
    {
        static::__assert_true(Svg_Upload_Sanitizer::is_svg('image/svg+xml', 'png'));
        static::__assert_true(Svg_Upload_Sanitizer::is_svg('text/plain', 'svg'));
        static::__assert_true(Svg_Upload_Sanitizer::is_svg('application/gzip', 'svgz'));
        static::__assert_false(Svg_Upload_Sanitizer::is_svg('image/png', 'png'));
    }

    public static function test_file_responses_are_sandboxed_and_nosniff()
    {
        $plain = File_Attachment_Controller::harden_file_response(response('bytes', 200, ['Content-Type' => 'image/svg+xml']));
        static::__assert_equals(File_Attachment_Controller::FILE_RESPONSE_CSP, $plain->headers->get('Content-Security-Policy'));
        static::__assert_equals('nosniff', $plain->headers->get('X-Content-Type-Options'));
        static::__assert_contains('sandbox', File_Attachment_Controller::FILE_RESPONSE_CSP);

        $own = response('x', 200, ['Content-Security-Policy' => "default-src 'none'; img-src data:"]);
        File_Attachment_Controller::harden_file_response($own);
        static::__assert_equals("default-src 'none'; img-src data:", $own->headers->get('Content-Security-Policy'), 'a response with its own policy keeps it');
        static::__assert_equals('nosniff', $own->headers->get('X-Content-Type-Options'));
    }

    public static function test_every_mapped_icon_is_a_png_on_disk()
    {
        foreach (['svg', 'pdf', 'docx', 'zip', 'mp4', 'unknownext'] as $extension) {
            $path = File_Attachment_Icons::get_icon_resource_by_file_extension($extension);
            static::__assert_true(str_ends_with($path, '.png'), "{$extension} maps to {$path}, not a PNG");
            static::__assert_true(is_file(dirname(base_path()) . '/' . $path), "{$path} is missing");
        }
    }
}
