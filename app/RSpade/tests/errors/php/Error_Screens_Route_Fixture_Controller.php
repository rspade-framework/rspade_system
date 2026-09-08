<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Errors\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * A REAL, routable GET surface standing in for "the deep page the caller was trying to
 * reach" in the unauthorized-split tests.
 *
 * The split threads the intended URL through Login_Redirect, whose sanitizer drops any
 * '/_'-prefixed path and any path no route pattern handles - so a target that is BOTH
 * routable and free of a leading underscore is required, and the framework declares no
 * such route of its own. This is it.
 *
 * Indexed only while the suite is running, like every other test-tree surface.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Error_Screens_Route_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** A URL matching the registered pattern below. */
    public const DEEP_URL = '/test-errors/record/5';

    /** The path segment the threaded ?redirect= value must carry. */
    public const DEEP_URL_MARKER = 'test-errors';

    #[Route('/test-errors/record/:id')]
    #[Auth('public')]
    public static function record(Request $request, array $params = [])
    {
        return ['route' => 'record', 'id' => $params['id'] ?? null];
    }
}
