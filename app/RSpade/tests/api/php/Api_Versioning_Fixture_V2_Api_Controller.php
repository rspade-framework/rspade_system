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
 * The v2 revision of Api_Versioning_Fixture_Api_Controller's resource, for
 * Api_Versioning_Test. A distinct class name (class names are unique per file type), listed
 * with its v1 sibling through the shared #[Api_Resource]. Present only while the suite runs.
 */
#[Auth('is_logged_in')]
#[Api_Resource('Versioning_Probe')]
class Api_Versioning_Fixture_V2_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * One probe item, v2 shape.
     */
    #[Api_Endpoint('/api/v2/test-probe/versioning/items/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Item id')]
    public static function get(Request $request, array $params = [])
    {
        return ['version' => 2, 'id' => $params['id']];
    }
}
