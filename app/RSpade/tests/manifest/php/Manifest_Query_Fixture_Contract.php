<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

/**
 * Manifest_Query_Test fixture: an interface declared in a scanned file, implemented by the
 * leaf. It extends a built-in interface, which is not a parent class either.
 */
interface Manifest_Query_Fixture_Contract extends \JsonSerializable
{
    public static function fixture_contract_name(): string;
}
