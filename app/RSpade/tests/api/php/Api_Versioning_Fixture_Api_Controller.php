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
 * The v1 half of a two-version resource, for Api_Versioning_Test: served through v2 by its
 * controller-level range, except the one endpoint v2 revises (through: 1) and the one being
 * retired (@api-deprecated). Its v2 sibling, Api_Versioning_Fixture_V2_Api_Controller,
 * declares the same #[Api_Resource]. Present only while the suite runs.
 */
#[Auth('is_logged_in')]
#[Api_Resource('Versioning_Probe')]
#[Api_Versions(through: 2)]
class Api_Versioning_Fixture_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * List the probe items - unchanged in v2.
     */
    #[Api_Endpoint('/api/v1/test-probe/versioning/items', methods: ['GET'])]
    public static function list(Request $request, array $params = [])
    {
        return ['version' => 1, 'handler' => 'list'];
    }

    /**
     * One probe item - revised in v2.
     */
    #[Api_Endpoint('/api/v1/test-probe/versioning/items/:id', methods: ['GET'], through: 1)]
    #[Api_Param('id', type: 'int', required: true, description: 'Item id')]
    public static function get(Request $request, array $params = [])
    {
        return ['version' => 1, 'id' => $params['id']];
    }

    /**
     * The probe's retired listing.
     *
     * @api-deprecated Use GET /api/v2/test-probe/versioning/items instead.
     */
    #[Api_Endpoint('/api/v1/test-probe/versioning/old', methods: ['GET'], through: 1)]
    public static function old(Request $request, array $params = [])
    {
        return ['version' => 1, 'handler' => 'old'];
    }
}
