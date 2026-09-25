<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use Illuminate\Routing\RouteCollection;
use App\RSpade\Core\Health\Security_Health_Checks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The rsx:health "Laravel Route Table" row: Laravel's router holds no route.
 *
 * RSX dispatch never consults Laravel's router, and the framework provider empties the
 * route table once every provider has booted - so a vendor package's routes (Ignition's
 * /_ignition/* endpoints) are gone from the booted application this test runs in. A route
 * registered after boot is exactly the regression the row exists to report, so the test
 * registers one and expects a FAIL naming it.
 *
 * Behavior of record: php artisan rsx:man dispatch.
 */
class Laravel_Route_Table_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_the_booted_route_table_is_empty_and_the_row_is_ok()
    {
        static::__assert_equals(0, count(app('router')->getRoutes()->getRoutes()), 'the booted route table is empty');

        $row = Security_Health_Checks::laravel_route_table();
        static::__assert_equals('OK', $row['status']);
    }

    public static function test_a_registered_route_is_a_fail_naming_it()
    {
        $router = app('router');
        $original = $router->getRoutes();

        try {
            $router->setRoutes(new RouteCollection());
            $router->get('health-route-probe', fn () => 'probe');

            $row = Security_Health_Checks::laravel_route_table();

            static::__assert_equals('FAIL', $row['status']);
            static::__assert_contains('/health-route-probe', $row['detail']);
        } finally {
            $router->setRoutes($original);
        }
    }
}
