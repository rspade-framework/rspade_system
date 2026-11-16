<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * Test fixture: a text type whose stored form is HTML (STORES_HTML true), declared on
 * Column_Kinds_Fixture_Model's body_html, so the generated docblock can be asserted to name
 * the HTML form. Its JavaScript twin (Codegen_Fixture_Html_Text.js) exists because every text
 * type is both halves (TEXT-TYPE-01); nothing here is ever rendered.
 */
class Codegen_Fixture_Html_Text extends Rsx_Text_Abstract
{
    public const STORES_HTML = true;

    /** Required of every type; a passthrough because the fixture is never stored. */
    public static function sanitize_encoded(string $raw): string
    {
        return $raw;
    }

    /** Required of every type; HTML-escapes plain text into a paragraph. */
    public static function encode_plain_text(string $plain): string
    {
        return '<p>' . htmlspecialchars($plain) . '</p>';
    }
}
