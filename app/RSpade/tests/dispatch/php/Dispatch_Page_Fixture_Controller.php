<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * A REAL, routable GET page standing in for "the protected page the caller asked for" in
 * the full-page auth-rejection tests.
 *
 * Those tests assert that the intended URL is threaded back as ?redirect=, and
 * Login_Redirect only threads a target that is routable AND carries no leading
 * underscore - which every framework route does, so the concern registers its own.
 * Sibling of Dispatch_Abort_Fixture_Controller, which is underscore-prefixed because its
 * assertions are about status codes and never about redirect threading.
 *
 * Indexed only while the suite is running, like every other test-tree surface.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Dispatch_Page_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** The routable page path the rejection tests present as the intended URL. */
    public const PAGE = '/test-dispatch/page';

    /** That path URL-encoded, as it appears inside a ?redirect= value. */
    public const PAGE_ENCODED = '%2Ftest-dispatch%2Fpage';

    #[Route('/test-dispatch/page')]
    #[Auth('public')]
    public static function page(Request $request, array $params = [])
    {
        return ['route' => 'page'];
    }
}
