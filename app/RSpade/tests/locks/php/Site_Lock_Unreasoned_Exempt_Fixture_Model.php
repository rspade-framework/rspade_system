<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * A #[Site_Lock_Exempt] declaration with no reason, for Site_Lock_Test: Site_Lock refuses
 * it ("gives no reason") the first time it classifies the class.
 *
 * ABSTRACT so the model registry does not list a second model of _email_blocked_addresses.
 */
#[Site_Lock_Exempt]
abstract class Site_Lock_Unreasoned_Exempt_Fixture_Model extends Email_Blocked_Address_Model
{
}
