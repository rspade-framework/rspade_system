<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRedirect\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * The portal twin of Login_Redirect_Route_Fixture_Controller.
 *
 * In portal context the routability gate resolves against the PORTAL route table, so the
 * accept half of the portal matrix needs a registered #[Portal_Route] - and the framework
 * declares none. The paths are declared in portal-namespace terms (unprefixed); the
 * dispatcher adds the configured prefix, which the tests spell '/_portal'.
 *
 * Indexed only while the suite is running, like every other test-tree surface.
 *
 * @PORTAL-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Login_Redirect_Portal_Route_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** The portal-namespace page path (no prefix). */
    public const PAGE = '/test-login-redirect/page';

    /** A portal-namespace URL matching the :id pattern below (no prefix). */
    public const ITEM = '/test-login-redirect/item/5';

    #[Portal_Route('/test-login-redirect/page')]
    #[Auth('public')]
    public static function page(Request $request, array $params = [])
    {
        return ['route' => 'portal_page'];
    }

    #[Portal_Route('/test-login-redirect/item/:id')]
    #[Auth('public')]
    public static function item(Request $request, array $params = [])
    {
        return ['route' => 'portal_item', 'id' => $params['id'] ?? null];
    }
}
