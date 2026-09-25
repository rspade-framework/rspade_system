<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * A type whose write filter is loud and obvious, so a test can see exactly where it ran
 * and that it ran exactly once.
 */
class Text_Fixture_Loud_Text extends Rsx_Text_Abstract
{
    public static function sanitize_encoded(string $raw): string
    {
        return strtoupper(trim($raw));
    }

    /** Brackets mark the plain-text path, so a test can tell it from the encoded one. */
    public static function encode_plain_text(string $plain): string
    {
        return '[' . $plain . ']';
    }

    public function to_plain_text(): string
    {
        return strip_tags($this->raw);
    }
}
