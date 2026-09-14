<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * The simplest possible text type: the storage form IS the text.
 *
 * The shipped Rich_Text and Raw_Text live in the reference application and exist to be
 * adapted by whoever installs it, so a framework test must not assert on their behaviour -
 * it would fail the first time a downstream developer changed an allow-list, which is the
 * thing those classes are FOR. These fixtures pin the ABSTRACT's contract instead.
 */
class Text_Fixture_Plain_Text extends Rsx_Text_Abstract
{
    /** Required of every type; a passthrough here because the fixture has no encoding to enforce. */
    public static function filter_set(string $raw): string
    {
        return $raw;
    }

    public function to_text(): string
    {
        return $this->raw;
    }
}
