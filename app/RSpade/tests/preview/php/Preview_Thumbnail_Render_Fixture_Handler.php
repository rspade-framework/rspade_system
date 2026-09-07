<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

/**
 * Fixture handler proving an app #[OnEvent] can intercept the document.thumbnail_render filter
 * chain (inserted at the top of File_Attachment_Controller::_render_thumbnail_data).
 *
 * CRITICAL - MARKER GUARD: this handler is manifest-discovered and therefore LIVE in dev. It is
 * registered on the REAL thumbnail chain that runs for every thumbnail. To avoid hijacking real
 * thumbnails it INTERCEPTS ONLY source paths containing the marker 'rsx_test_thumb_intercept' and
 * DECLINES (returns null) for everything else - and real blob paths are content hashes that can
 * never contain the marker. When it intercepts, it returns a tiny valid WebP (the chain returns a
 * string as WebP bytes verbatim).
 */
class Preview_Thumbnail_Render_Fixture_Handler
{
    /** Path substring that triggers interception (never present in a real content-hash blob path). */
    public const MARKER = 'rsx_test_thumb_intercept';

    /** A 1x1 red WebP, base64-encoded (RIFF/WEBP container). */
    private const WEBP_BASE64 = 'UklGRjwAAABXRUJQVlA4IDAAAADQAQCdASoBAAEAAgA0JaACdLoB+AADsAD+8MQL/yC5YXXI1/8gP+QH/ID/+PIAAAA=';

    /**
     * The WebP bytes this fixture returns on interception (so tests can compare exactly).
     *
     * @return string
     */
    public static function webp_bytes(): string
    {
        return base64_decode(self::WEBP_BASE64);
    }

    #[OnEvent('document.thumbnail_render', priority: 5)]
    public static function intercept($data)
    {
        $path = $data['path'] ?? '';

        if (strpos($path, self::MARKER) === false) {
            return null; // decline - let the framework renderer registry handle it
        }

        return static::webp_bytes();
    }
}
