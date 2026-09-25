<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;

/**
 * API endpoints that fail with a CODED outcome, for Api_Coded_Failure_Test: abort(404),
 * abort(418), and the thrown unauthorized exception Permission::require_permission()
 * raises. Present only while the suite runs (the test tree enters the manifest then).
 */
#[Auth('is_logged_in')]
class Api_Abort_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * Answer abort(404).
     */
    #[Api_Endpoint('/api/v1/test-probe/abort-404', methods: ['GET'])]
    public static function abort_404(Request $request, array $params = [])
    {
        abort(404);
    }

    /**
     * Answer abort(418) with a message.
     */
    #[Api_Endpoint('/api/v1/test-probe/abort-418', methods: ['GET'])]
    public static function abort_418(Request $request, array $params = [])
    {
        abort(418, 'Probe teapot');
    }

    /**
     * Deny by throwing, as Permission::require_permission() does.
     */
    #[Api_Endpoint('/api/v1/test-probe/denied', methods: ['GET'])]
    public static function denied(Request $request, array $params = [])
    {
        throw new AjaxUnauthorizedException('Probe permission denied');
    }
}
