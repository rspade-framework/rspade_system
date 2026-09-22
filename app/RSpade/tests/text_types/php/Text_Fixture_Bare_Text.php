<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * The MINIMUM a text type is: a filter, and nothing else.
 *
 * Exists to pin that the optional conventions really are optional - a type that declares
 * only filter_set() is a complete, storable, validatable type, and asking it for a
 * rendition it never defined throws rather than guessing.
 */
class Text_Fixture_Bare_Text extends Rsx_Text_Abstract
{
    public static function filter_set(string $raw): string
    {
        return $raw;
    }

    public static function escape_string(string $plain): string
    {
        return $plain;
    }
}
