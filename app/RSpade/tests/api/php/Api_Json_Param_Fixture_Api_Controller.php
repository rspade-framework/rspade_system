<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;

/**
 * API endpoints declaring 'json' params, for Api_Json_Param_Test (the value each one
 * receives, through the real dispatcher) and Api_Openapi_Test (how the document describes
 * one as a query parameter and as a body property). Each answers the decoded values it
 * received, keyed by name. Present only while the suite runs (the test tree enters the
 * manifest then).
 */
#[Auth('is_logged_in')]
class Api_Json_Param_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * A json query parameter: its text is JSON.
     */
    #[Api_Endpoint('/api/v1/test-probe/json/echo', methods: ['GET'])]
    #[Api_Param('filters', type: 'json')]
    public static function echo_query(Request $request, array $params = [])
    {
        return ['received' => $params];
    }

    /**
     * json body properties: one required, one defaulted to an array literal.
     */
    #[Api_Endpoint('/api/v1/test-probe/json/submit', methods: ['POST'])]
    #[Api_Param('payload', type: 'json', required: true)]
    #[Api_Param('options', type: 'json', required: false, default: ['mode' => 'fast'])]
    public static function submit(Request $request, array $params = [])
    {
        return ['received' => $params];
    }
}
