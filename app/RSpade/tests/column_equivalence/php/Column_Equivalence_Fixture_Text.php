<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * Test fixture: a text type whose sanitizer collapses every run of whitespace to one space and
 * trims the ends, so "a  b" and " a b " are the same stored content and "a b" / "a c" are not.
 * The vector file's text_type rows name it; the browser runner declares the same class at run
 * time (a browser test cannot see the test tree); Column_Equivalence_Fixture_Text.js is its
 * twin. Declared on Column_Equivalence_Fixture_Model's rate column.
 */
class Column_Equivalence_Fixture_Text extends Rsx_Text_Abstract
{
    public static function sanitize_encoded(string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', $raw));
    }

    public static function encode_plain_text(string $plain): string
    {
        return $plain;
    }
}
