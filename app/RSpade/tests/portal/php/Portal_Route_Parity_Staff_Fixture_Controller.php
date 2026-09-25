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
 * The staff twin of Portal_Route_Parity_Fixture_Controller: the same pattern on a staff
 * #[Route], so Portal_Route_Parity_Test can hold the two realms' URLs side by side.
 *
 * Indexed only while the suite is running, like every other test-tree surface.
 */
class Portal_Route_Parity_Staff_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Route('/test-route-parity/:id/:id_type')]
    #[Auth('public')]
    public static function item(Request $request, array $params = [])
    {
        return ['route' => 'staff_item'];
    }
}
