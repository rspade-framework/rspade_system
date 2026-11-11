<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Tests\Locks\Php\Lockd_Outage_Fixture_Controller;

/**
 * External API surfaces for Lockd_Outage_Test: a reader GET and a writer POST that record that
 * they ran. Present only while the suite runs.
 */
#[Auth('is_logged_in')]
class Lockd_Outage_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    #[Api_Endpoint('/api/v1/test-probe/lockd-outage/read', methods: ['GET'])]
    public static function read(Request $request, array $params = [])
    {
        Lockd_Outage_Fixture_Controller::$ran[] = 'api_read';

        return ['ran' => 'api_read'];
    }

    #[Writer]
    #[Api_Endpoint('/api/v1/test-probe/lockd-outage/write', methods: ['POST'])]
    public static function write(Request $request, array $params = [])
    {
        Lockd_Outage_Fixture_Controller::$ran[] = 'api_write';

        return ['ran' => 'api_write'];
    }
}
