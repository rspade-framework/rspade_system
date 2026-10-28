<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Service\Rsx_Service_Abstract;

/**
 * Test-only ABSTRACT intermediate between Rsx_Service_Abstract and a service: the sanctioned
 * way to share code between services - plain methods, no registry attribute. Its subclass
 * Task_List_Fixture_Service must still be listed by rsx:task:list.
 */
abstract class Task_List_Fixture_Base_Abstract extends Rsx_Service_Abstract
{
    public static function shared_greeting(): string
    {
        return 'shared';
    }
}
