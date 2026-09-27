<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Icons;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The outline file-type icon: one extension map with two renditions, the outline one a
 * framework-owned currentColor SVG served verbatim (never through ImageMagick), reachable
 * from both realms, with the colour PNG path unchanged.
 *
 * Behavior of record: php artisan rsx:man file_upload (FILE TYPE ICONS).
 */
class File_Outline_Icon_Test extends Rsx_Test_Abstract
{
    private static function __project_path(string $relative): string
    {
        return dirname(base_path()) . '/' . $relative;
    }

    private static function __icon_request(string $extension, array $query)
    {
        $request = Request::create("/_icon_by_extension/{$extension}", 'GET', $query);

        return File_Attachment_Controller::icon_by_extension($request, ['extension' => $extension]);
    }

    public static function test_every_mapped_extension_resolves_to_an_outline_file_on_disk()
    {
        $payload = File_Attachment_Icons::get_outline_icon_payload();

        static::__assert_true(count($payload['extensions']) > 90, 'the payload carries the whole extension map');

        foreach ($payload['extensions'] as $extension => $name) {
            $path = File_Attachment_Icons::get_icon_resource_by_file_extension($extension, File_Attachment_Icons::STYLE_OUTLINE);

            static::__assert_equals("outline/{$name}.svg", basename(dirname($path)) . '/' . basename($path), "{$extension} resolves to its mapped outline icon");
            static::__assert_true(is_file(static::__project_path($path)), "{$path} (for {$extension}) is missing");
            static::__assert_true(isset($payload['icons'][$name]), "the payload carries the artwork for {$name}");
        }

        static::__assert_equals(
            'file-type-pdf.svg',
            basename(File_Attachment_Icons::get_icon_resource_by_file_extension('PDF', File_Attachment_Icons::STYLE_OUTLINE)),
            'the lookup is case-insensitive'
        );
    }

    public static function test_an_unrecognised_extension_gets_the_generic_icon()
    {
        $generic = file_get_contents(static::__project_path('system/app/RSpade/Core/Files/resource/icons/outline/file.svg'));

        foreach (['unknownext', '', null] as $extension) {
            static::__assert_equals($generic, File_Attachment_Icons::get_outline_icon_svg($extension), 'an unrecognised extension is the generic file mark');
        }

        static::__assert_equals('file', File_Attachment_Icons::get_outline_icon_payload()['generic']);
    }

    public static function test_every_outline_svg_is_a_square_currentcolor_stroke_with_no_fill()
    {
        foreach (File_Attachment_Icons::get_outline_icon_payload()['icons'] as $name => $svg) {
            static::__assert_contains('viewBox="0 0 24 24"', $svg, "{$name} is on the 24x24 grid");
            static::__assert_contains('stroke="currentColor"', $svg, "{$name} strokes in currentColor");

            preg_match_all('/\bfill="([^"]*)"/', $svg, $fills);
            static::__assert_equals(['none'], array_values(array_unique($fills[1])), "{$name} declares no fill but none");

            preg_match_all('/\bstroke="([^"]*)"/', $svg, $strokes);
            static::__assert_equals(['currentColor'], array_values(array_unique($strokes[1])), "{$name} strokes only in currentColor");

            static::__assert_false(preg_match('/<script|\bon[a-z]+=|href=|<style/i', $svg) === 1, "{$name} carries no script, handler, link or style");
        }
    }

    public static function test_the_outline_route_serves_the_svg_hardened()
    {
        $response = static::__icon_request('pdf', ['style' => 'outline']);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_true(str_starts_with((string) $response->headers->get('Content-Type'), 'image/svg+xml'), 'served as image/svg+xml');
        static::__assert_equals('nosniff', $response->headers->get('X-Content-Type-Options'));
        static::__assert_equals(File_Attachment_Controller::FILE_RESPONSE_CSP, $response->headers->get('Content-Security-Policy'));
        static::__assert_contains('max-age=604800', (string) $response->headers->get('Cache-Control'));
        static::__assert_contains('public', (string) $response->headers->get('Cache-Control'));
        static::__assert_equals(File_Attachment_Icons::get_outline_icon_svg('pdf'), $response->getContent(), 'the bytes are the artwork, verbatim');
    }

    public static function test_an_unknown_style_is_refused()
    {
        static::__assert_throws(HttpException::class, function () {
            static::__icon_request('pdf', ['style' => 'bogus']);
        });

        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            File_Attachment_Icons::get_icon_resource_by_file_extension('pdf', 'bogus');
        });
    }

    public static function test_the_colour_png_path_is_unchanged()
    {
        static::__assert_equals(
            'system/app/RSpade/Core/Files/resource/icons/pdf.png',
            File_Attachment_Icons::get_icon_resource_by_file_extension('pdf')
        );
        static::__assert_equals(
            'system/app/RSpade/Core/Files/resource/icons/document.png',
            File_Attachment_Icons::get_icon_resource_by_file_extension('docx', File_Attachment_Icons::STYLE_COLOR)
        );
        static::__assert_equals(
            'system/app/RSpade/Core/Files/resource/icons/file.png',
            File_Attachment_Icons::get_icon_resource_by_file_extension('unknownext')
        );

        $response = static::__icon_request('pdf', ['width' => 64, 'height' => 64]);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals('image/png', $response->headers->get('Content-Type'));
        static::__assert_contains('max-age=86400', (string) $response->headers->get('Cache-Control'));
        static::__assert_equals(File_Attachment_Icons::get_icon_as_png('pdf', 64, 64), $response->getContent());
    }

    public static function test_the_route_is_declared_in_both_realms()
    {
        $data = Manifest::get_full_manifest()['data'];

        foreach (['routes', 'portal_routes'] as $table) {
            $row = $data[$table]['/_icon_by_extension/:extension'] ?? null;

            static::__assert_true($row !== null, "/_icon_by_extension/:extension is in the {$table} table");
            static::__assert_equals('icon_by_extension', $row['method'], "the {$table} row dispatches the one handler");
            static::__assert_equals(['GET'], $row['methods'], "the {$table} row is GET only");
        }
    }

    public static function test_the_attachment_accessors()
    {
        $attachment = new File_Attachment_Model();
        $attachment->file_extension = 'docx';

        static::__assert_equals(File_Attachment_Icons::get_outline_icon_svg('docx'), $attachment->get_outline_icon_svg());
        static::__assert_equals(
            File_Attachment_Icons::get_icon_resource_by_file_extension('docx', File_Attachment_Icons::STYLE_OUTLINE),
            $attachment->get_icon_resource(File_Attachment_Icons::STYLE_OUTLINE)
        );
        static::__assert_equals(
            File_Attachment_Icons::get_icon_resource_by_file_extension('docx'),
            $attachment->get_icon_resource()
        );
    }
}
