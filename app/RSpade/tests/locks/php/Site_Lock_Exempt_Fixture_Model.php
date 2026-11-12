<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * An exempt bookkeeping model for Site_Lock_Test: Email_Blocked_Address_Model declared
 * #[Site_Lock_Exempt]. Site_Lock reads the declaration from the manifest, which indexes this
 * file only under a test run; the tests instantiate anonymous subclasses, which inherit it.
 *
 * ABSTRACT so the model registry does not list a second model of _email_blocked_addresses.
 */
#[Site_Lock_Exempt('site lock test bookkeeping')]
abstract class Site_Lock_Exempt_Fixture_Model extends Email_Blocked_Address_Model
{
}
