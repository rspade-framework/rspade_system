<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Helpers\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * nl2brhtml() - multi-line plain text into markup: escaped, then a <br /> at each break.
 *
 * The output matches the JS twin character for character on the cases the browser test
 * pins (tests/helpers/playwright/html_text_helpers.js), apostrophes aside: PHP's
 * escape_html() writes &#039; where lodash writes &#39;.
 */
class Nl2brhtml_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_escapes_then_breaks_lines()
    {
        static::__assert_equals("a&lt;b&gt;<br />\nc &amp; d", nl2brhtml("a<b>\nc & d"));
        static::__assert_equals("x<br />\r\ny", nl2brhtml("x\r\ny"));
    }

    public static function test_null_is_empty()
    {
        static::__assert_equals('', nl2brhtml(null));
    }

    public static function test_markup_is_never_markup()
    {
        $html = nl2brhtml('<img src=x onerror=alert(1)>');

        static::__assert_false(str_contains($html, '<img'));
    }
}
