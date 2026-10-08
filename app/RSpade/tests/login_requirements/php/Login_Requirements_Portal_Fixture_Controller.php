<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRequirements\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * The portal fixture requirement's screen.
 */
class Login_Requirements_Portal_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Portal_Route('/_test/login-requirements/portal-screen')]
    #[Auth('is_logged_in')]
    public static function screen(Request $request, array $params = [])
    {
        return ['surface' => 'portal_screen'];
    }
}
