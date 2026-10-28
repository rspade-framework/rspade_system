<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:task:list lists every concrete service, at any depth below Rsx_Service_Abstract - a
 * service under an abstract intermediate included (it used to require the service's DIRECT
 * parent to be Rsx_Service_Abstract), and never the abstract itself.
 */
class Task_List_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_service_under_an_abstract_base_is_listed()
    {
        Artisan::call('rsx:task:list');
        $output = Artisan::output();

        static::__assert_contains('Task_List_Fixture_Service', $output, 'the service under an abstract base is listed');
        static::__assert_contains('listed_under_a_base', $output, 'with its task');
        static::__assert_true(!str_contains($output, 'Task_List_Fixture_Base_Abstract'), 'the abstract base itself is not');
    }
}
