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
 * REAL, routable GET surfaces for the redirect sanitizer's ROUTABILITY GATE.
 *
 * Login_Redirect::_validate() rejects every '/_'-prefixed path outright (framework
 * internal / asset / Ajax-endpoint routes are never legitimate return targets), and
 * every route the FRAMEWORK itself declares is '/_'-prefixed. So the accept half of
 * the matrix cannot be driven against a framework route at all, and driving it
 * against an application route is what made this concern fail downstream.
 *
 * These three routes are the accept half: a plain page, a page carrying a :id URL
 * parameter, and a second plain page for the "another registered route" row. They
 * are ordinary server-rendered GET routes - which is all the gate looks at, since
 * Dispatcher::resolve_url_to_route() answers from ONE route table that #[Route] and
 * #[SPA] rows share.
 *
 * The '/test-login-redirect/' prefix carries no underscore ON PURPOSE - an
 * underscore would be rejected before the gate is reached. The routes are indexed
 * only while the suite is running (Manifest::scan_directories() appends the test
 * trees under Rsx_Test_Abstract::suite_is_running()), so a served site never carries
 * them.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Login_Redirect_Route_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** A plain routable page path. */
    public const PAGE = '/test-login-redirect/page';

    /** A second plain routable page path. */
    public const OTHER_PAGE = '/test-login-redirect/other';

    /** A routable page path carrying a :id URL parameter (the pattern, not a URL). */
    public const ITEM_PATTERN = '/test-login-redirect/item/:id';

    #[Route('/test-login-redirect/page')]
    #[Auth('public')]
    public static function page(Request $request, array $params = [])
    {
        return ['route' => 'page'];
    }

    #[Route('/test-login-redirect/other')]
    #[Auth('public')]
    public static function other(Request $request, array $params = [])
    {
        return ['route' => 'other'];
    }

    #[Route('/test-login-redirect/item/:id')]
    #[Auth('public')]
    public static function item(Request $request, array $params = [])
    {
        return ['route' => 'item', 'id' => $params['id'] ?? null];
    }
}
