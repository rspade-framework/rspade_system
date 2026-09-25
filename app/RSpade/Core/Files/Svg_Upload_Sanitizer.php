<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use enshrined\svgSanitize\Sanitizer;
use App\RSpade\Core\Files\Unparseable_Svg_Exception;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Svg_Upload_Sanitizer - every SVG is sanitized before its bytes are stored.
 *
 * An SVG is a document, not a picture: it can carry <script>, event-handler attributes,
 * javascript: links and <foreignObject> HTML, all of which run when the file is opened
 * from the application's own origin. File_Attachment_Model::create_from_upload() - the
 * one door every attachment enters by, whatever transport carried it - hands any SVG
 * here first, and what is stored is the sanitized document (enshrined/svg-sanitize: an
 * element and attribute allow-list, remote references removed, DOCTYPEs stripped).
 *
 * Three layers, of which this is one: the stored bytes are clean; every file response is
 * served with a sandboxing CSP and nosniff (File_Attachment_Controller::harden_file_response);
 * and an SVG never reaches ImageMagick (its thumbnail is the extension icon).
 *
 * AN SVG THAT CANNOT BE PARSED IS REFUSED, loudly (Unparseable_Svg_Exception, a 422 at the
 * upload endpoints). Storing the original bytes of a document the sanitizer could not read
 * would store exactly the file it exists to stop.
 *
 * .svgz (gzip-compressed SVG) is decompressed, sanitized and recompressed.
 */
class Svg_Upload_Sanitizer
{
    /** The SVG media type. */
    public const MIME = 'image/svg+xml';

    /**
     * Is this upload an SVG? By its sniffed media type, or by an .svg / .svgz extension
     * (a sniff can miss an SVG with an unusual prolog, and the extension is what a browser
     * and the preview pipeline will go by).
     *
     * @param string|null $mime_type The sniffed media type
     * @param string|null $extension The lowercase extension, no dot
     * @return bool
     */
    public static function is_svg(?string $mime_type, ?string $extension): bool
    {
        $mime_type = strtolower(trim((string) $mime_type));
        $extension = strtolower(trim((string) $extension));

        return $mime_type === self::MIME || $extension === 'svg' || $extension === 'svgz';
    }

    /**
     * Sanitize the SVG at $source_path into a new temporary file and return its path.
     *
     * The caller stores from the returned path and unlinks it afterwards.
     *
     * @param string $source_path The received file
     * @param string $extension The lowercase extension, no dot ('svgz' selects gzip handling)
     * @param string $file_name The name the user gave the file, for the refusal message
     * @return string Absolute path of the sanitized copy
     * @throws Unparseable_Svg_Exception
     */
    public static function sanitize_to_temp_file(string $source_path, string $extension, string $file_name): string
    {
        $bytes = file_get_contents($source_path);
        if ($bytes === false) {
            shouldnt_happen("Svg_Upload_Sanitizer could not read the received file {$source_path}");
        }

        $is_compressed = strtolower($extension) === 'svgz' || str_starts_with($bytes, "\x1f\x8b");

        if ($is_compressed) {
            $bytes = @gzdecode($bytes);
            if ($bytes === false) {
                throw new Unparseable_Svg_Exception("{$file_name} is not a readable compressed SVG.");
            }
        }

        $clean = static::sanitize($bytes);
        if ($clean === null) {
            throw new Unparseable_Svg_Exception("{$file_name} is not a readable SVG image.");
        }

        if ($is_compressed) {
            $clean = gzencode($clean, 9);
        }

        $path = Rsx_Project_Paths::tmp_path('svg_sanitize_' . bin2hex(random_bytes(8)) . '.svg');
        if (file_put_contents($path, $clean) === false) {
            shouldnt_happen("Svg_Upload_Sanitizer could not write the sanitized copy to {$path}");
        }

        return $path;
    }

    /**
     * Sanitize an SVG document, or null when it is not one the sanitizer can read.
     *
     * @param string $svg
     * @return string|null
     */
    public static function sanitize(string $svg): ?string
    {
        if (trim($svg) === '') {
            return null;
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize($svg);

        if (!is_string($clean) || !preg_match('/<svg[\s>\/]/i', $clean)) {
            return null;
        }

        return $clean;
    }
}
