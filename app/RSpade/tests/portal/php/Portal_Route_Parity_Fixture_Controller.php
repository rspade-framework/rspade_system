<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Fixture for Portal_Route_Parity_Test: one portal route whose pattern carries two tokens
 * sharing a prefix (':id', ':id_type'), so the test sees the portal URL built by the same
 * token replacement, query string and `at` anchor as the staff twin
 * (Portal_Route_Parity_Staff_Fixture_Controller, same pattern).
 *
 * The path is declared in portal-namespace terms (unprefixed). Indexed only while the
 * suite is running, like every other test-tree surface.
 */
class Portal_Route_Parity_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Portal_Route('/test-route-parity/:id/:id_type')]
    #[Auth('public')]
    public static function item(Request $request, array $params = [])
    {
        return ['route' => 'portal_item'];
    }
}
