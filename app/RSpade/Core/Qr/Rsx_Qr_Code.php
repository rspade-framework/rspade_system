<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;

/**
 * Rsx_Qr_Code - the one QR code generator.
 *
 * generate($text) answers the code as an inline SVG together with the size it should be
 * shown at on a screen and printed at on paper. Every decision a caller would otherwise
 * have to reason about is made here and is the same for every code:
 *
 *   - error correction is level Q (a code still reads with about a quarter of it damaged),
 *     which suits a sheet of paper that gets folded, smudged or photocopied;
 *   - the code is black on white, whatever the page around it looks like, and carries the
 *     full four-module quiet zone INSIDE the image, so a dark theme or a tight layout
 *     cannot make it unreadable;
 *   - the output is vector, so one SVG is sharp on any screen and at any printer
 *     resolution. There is no raster output.
 *
 * The recommended sizes are derived from the code's module count, because a code that
 * holds more text has more modules and needs more room: a short URL fits in an inch, a
 * long string is told to be larger rather than left to print too finely to scan.
 *
 * See: php artisan rsx:man qr_codes
 */
class Rsx_Qr_Code
{
    /** Modules of white margin on every side - the quiet zone the QR specification requires. */
    public const QUIET_ZONE_MODULES = 4;

    /** CSS pixels per module at the recommended screen size. */
    public const SCREEN_PIXELS_PER_MODULE = 4;

    /** Inches per module at the recommended print size (about half a millimetre). */
    public const PRINT_INCHES_PER_MODULE = 0.02;

    /** The smallest recommended print size, in inches, however little the code holds. */
    public const PRINT_MINIMUM_INCHES = 1.0;

    /**
     * Encode text as a QR code.
     *
     * The SVG carries size_px as its own width and height, so placed on a page untouched it
     * is already the recommended screen size; CSS on the element overrides it (for paper,
     * `width: <size_in>in; height: <size_in>in`). It starts at the <svg element and is safe
     * to embed raw - every character in it is generated here, none comes from $text.
     *
     * @param string $text What the code encodes - normally a URL. Must not be empty.
     * @return array {svg: string, size_px: int, size_in: float, modules: int} - the inline
     *               SVG, the recommended side length on screen in CSS pixels, the
     *               recommended side length on paper in inches, and the modules per side
     *               including the quiet zone (the SVG's viewBox).
     * @throws InvalidArgumentException When $text is empty.
     * @throws \BaconQrCode\Exception\WriterException When $text is longer than a QR code
     *         can hold.
     */
    public static function generate(string $text): array
    {
        if ($text === '') {
            throw new InvalidArgumentException('Rsx_Qr_Code::generate() requires text to encode.');
        }

        // Plain ASCII (every URL) is encoded as is. Anything else is encoded as UTF-8,
        // which the encoder announces to the scanner with an ECI header.
        $encoding = mb_check_encoding($text, 'ASCII') ? Encoder::DEFAULT_BYTE_MODE_ENCODING : 'UTF-8';

        $matrix = Encoder::encode($text, ErrorCorrectionLevel::Q(), $encoding)->getMatrix();

        $width = $matrix->getWidth();
        $modules = $width + 2 * self::QUIET_ZONE_MODULES;
        $size_px = $modules * self::SCREEN_PIXELS_PER_MODULE;
        $size_in = max(self::PRINT_MINIMUM_INCHES, round($modules * self::PRINT_INCHES_PER_MODULE, 2));

        // One path, one rectangle per horizontal run of dark modules.
        $path = '';

        for ($y = 0; $y < $width; $y++) {
            $run_start = null;

            for ($x = 0; $x <= $width; $x++) {
                $dark = $x < $width && $matrix->get($x, $y) === 1;

                if ($dark && $run_start === null) {
                    $run_start = $x;
                } elseif (!$dark && $run_start !== null) {
                    $length = $x - $run_start;
                    $path .= 'M' . ($run_start + self::QUIET_ZONE_MODULES) . ' ' . ($y + self::QUIET_ZONE_MODULES)
                        . 'h' . $length . 'v1h-' . $length . 'z';
                    $run_start = null;
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $modules . ' ' . $modules . '"'
            . ' width="' . $size_px . '" height="' . $size_px . '"'
            . ' shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="' . $modules . '" height="' . $modules . '" fill="#fff"/>'
            . '<path fill="#000" d="' . $path . '"/>'
            . '</svg>';

        return [
            'svg' => $svg,
            'size_px' => $size_px,
            'size_in' => $size_in,
            'modules' => $modules,
        ];
    }
}
