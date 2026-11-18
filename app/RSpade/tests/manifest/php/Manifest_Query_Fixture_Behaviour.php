<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

/**
 * Manifest_Query_Test fixture: a trait the leaf uses.
 */
trait Manifest_Query_Fixture_Behaviour
{
    public static function fixture_behaviour(): string
    {
        return 'behaviour';
    }
}
