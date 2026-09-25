<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * A type whose storage form WRAPS its content, so an empty document is a non-empty string.
 * This is the shape every WYSIWYG produces, and the reason is_empty() cannot be a check on
 * the raw form.
 */
class Text_Fixture_Wrapped_Text extends Rsx_Text_Abstract
{
    /** Required of every type; a passthrough here because the fixture has no encoding to enforce. */
    public static function sanitize_encoded(string $raw): string
    {
        return $raw;
    }

    /** A wrapping encoding escapes plain text into its wrapper. */
    public static function encode_plain_text(string $plain): string
    {
        return '<p>' . htmlspecialchars($plain) . '</p>';
    }

    /** A wrapping encoding answers emptiness by content, as the base requires. */
    public function is_empty(): bool
    {
        return trim($this->to_plain_text()) === '';
    }

    public function to_plain_text(): string
    {
        return trim(strip_tags(str_replace('<br>', '', $this->raw)));
    }
}
