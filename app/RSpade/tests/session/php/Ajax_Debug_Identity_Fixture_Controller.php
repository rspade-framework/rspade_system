<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * One Ajax endpoint that reports the three parts of the session identity as the
 * endpoint sees them. Ajax_Debug_Identity_Test runs it through `rsx:ajax` to pin what
 * --user and --site establish. Public gate: the identity under test is the one the
 * command declares, not one a gate would demand.
 */
class Ajax_Debug_Identity_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function identity(Request $request, array $params = [])
    {
        return [
            'login_user_id' => Session::get_login_user_id(),
            'site_id' => Session::get_site_id(),
            'user_id' => Session::get_user_id(),
        ];
    }
}
