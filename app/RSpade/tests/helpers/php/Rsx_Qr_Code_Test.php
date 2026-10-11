<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Helpers\Php;

use InvalidArgumentException;
use App\RSpade\Core\Qr\Rsx_Qr_Code;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Qr_Code::generate() - the one QR code generator.
 *
 * The drawn modules are read back out of the SVG path and checked against what a QR code
 * must contain (the three finder patterns, an empty quiet zone), and the symbol size is
 * checked at a text length where error correction level Q and the weaker levels differ.
 */
class Rsx_Qr_Code_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The dark modules the SVG draws, as a [y][x] => true grid in viewBox coordinates.
     */
    private static function __drawn_modules(string $svg): array
    {
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\d+z/', $svg, $runs, PREG_SET_ORDER);

        $grid = [];

        foreach ($runs as $run) {
            for ($x = (int) $run[1]; $x < (int) $run[1] + (int) $run[3]; $x++) {
                $grid[(int) $run[2]][$x] = true;
            }
        }

        return $grid;
    }

    public static function test_the_result_carries_the_svg_and_both_recommended_sizes()
    {
        $qr = Rsx_Qr_Code::generate('https://example.com/p/abc123XYZ');

        static::__assert_equals(['svg', 'size_px', 'size_in', 'modules'], array_keys($qr));
        static::__assert_equals(37, $qr['modules'], 'a 29-module symbol plus a 4-module quiet zone each side');
        static::__assert_equals(148, $qr['size_px'], '4 CSS pixels per module');
        static::__assert_equals(1.0, $qr['size_in'], 'a short URL prints at the one-inch minimum');
    }

    public static function test_the_svg_is_inline_embeddable_and_states_its_own_screen_size()
    {
        $qr = Rsx_Qr_Code::generate('https://example.com/p/abc123XYZ');
        $svg = $qr['svg'];

        static::__assert_true(str_starts_with($svg, '<svg'), 'starts at the svg element');
        static::__assert_true(str_ends_with($svg, '</svg>'), 'and is a complete element');
        static::__assert_false(str_contains($svg, '<?xml'), 'no XML declaration');
        static::__assert_contains('viewBox="0 0 37 37"', $svg);
        static::__assert_contains('width="148" height="148"', $svg);
    }

    public static function test_the_code_is_black_on_an_opaque_white_ground()
    {
        $qr = Rsx_Qr_Code::generate('https://example.com/');
        $svg = $qr['svg'];
        $side = $qr['modules'];

        static::__assert_contains(
            '<rect width="' . $side . '" height="' . $side . '" fill="#fff"/>',
            $svg,
            'white backing over the whole image'
        );
        static::__assert_contains('<path fill="#000"', $svg);
        static::__assert_false(str_contains($svg, 'currentColor'), 'never inherits the page colour');
    }

    public static function test_the_quiet_zone_is_empty_and_the_finder_patterns_are_drawn()
    {
        $qr = Rsx_Qr_Code::generate('https://example.com/p/abc123XYZ');
        $grid = static::__drawn_modules($qr['svg']);
        $last = $qr['modules'] - 1;

        foreach ($grid as $y => $row) {
            foreach (array_keys($row) as $x) {
                static::__assert_true(
                    $x >= 4 && $x <= $last - 4 && $y >= 4 && $y <= $last - 4,
                    "module {$x},{$y} is outside the quiet zone"
                );
            }
        }

        // A finder pattern is a 7x7 dark ring around a light ring around a 3x3 dark core.
        foreach ([[4, 4], [$last - 10, 4], [4, $last - 10]] as [$left, $top]) {
            for ($i = 0; $i < 7; $i++) {
                static::__assert_true(isset($grid[$top][$left + $i]), 'finder top edge');
                static::__assert_true(isset($grid[$top + 6][$left + $i]), 'finder bottom edge');
                static::__assert_true(isset($grid[$top + $i][$left]), 'finder left edge');
                static::__assert_true(isset($grid[$top + $i][$left + 6]), 'finder right edge');
            }

            static::__assert_false(isset($grid[$top + 1][$left + 1]), 'finder light ring');
            static::__assert_true(isset($grid[$top + 3][$left + 3]), 'finder core');
        }
    }

    public static function test_error_correction_is_level_q()
    {
        // In byte mode a 29-module symbol holds 32 characters at level Q and 42 at level M,
        // so 33 characters need the next size up at Q and would not at anything weaker.
        static::__assert_equals(29 + 8, Rsx_Qr_Code::generate(str_repeat('a', 32))['modules']);
        static::__assert_equals(33 + 8, Rsx_Qr_Code::generate(str_repeat('a', 33))['modules']);
    }

    public static function test_recommended_sizes_grow_with_the_text()
    {
        $long = Rsx_Qr_Code::generate(str_repeat('https://example.com/a/long/path/', 6));

        static::__assert_equals($long['modules'] * 4, $long['size_px']);
        static::__assert_equals(round($long['modules'] * 0.02, 2), $long['size_in']);
        static::__assert_greater_than(1.0, $long['size_in'], 'a dense code is told to print larger than the minimum');
    }

    public static function test_text_outside_ascii_is_encoded()
    {
        $qr = Rsx_Qr_Code::generate('Grüße – 日本語');

        static::__assert_greater_than(0, count(static::__drawn_modules($qr['svg'])));
    }

    public static function test_empty_text_is_refused()
    {
        $threw = false;

        try {
            Rsx_Qr_Code::generate('');
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }

        static::__assert_true($threw, 'there is nothing to encode');
    }
}
