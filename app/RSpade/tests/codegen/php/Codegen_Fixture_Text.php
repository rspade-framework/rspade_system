<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * Test fixture: the text type Column_Kinds_Fixture_Model declares, so the generated docblock
 * can be asserted to type a text-type column as its value-object class. The storage form IS
 * the text. Its JavaScript twin (Codegen_Fixture_Text.js) exists because every text type is
 * both halves (TEXT-TYPE-01); nothing here is ever rendered.
 */
class Codegen_Fixture_Text extends Rsx_Text_Abstract
{
    /** Required of every type; a passthrough because the fixture has no encoding to enforce. */
    public static function sanitize_encoded(string $raw): string
    {
        return $raw;
    }

    /** Required of every type; a passthrough because the encoding IS plain text. */
    public static function encode_plain_text(string $plain): string
    {
        return $plain;
    }

    public function to_plain_text(): string
    {
        return $this->raw;
    }
}
