<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * The portal twin of Auth_Gates_Seam_Fixture_Controller: a REAL #[Portal_Route]
 * surface so the Portal_Dispatcher seam can be exercised without temporarily
 * annotating application portal code.
 *
 * Its gate name resolves in the PORTAL registry (Portal_Permission), which is the
 * point: the same spelling on a staff surface would resolve somewhere else entirely.
 *
 * @PORTAL-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Auth_Gates_Portal_Seam_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** Marker the tests assert on when a gated portal route actually dispatched. */
    public const DISPATCHED = 'auth_gates_portal_seam_fixture_dispatched';

    /**
     * Gated portal route: dispatches only for an authenticated portal caller.
     */
    #[Portal_Route('/_test/auth-gates/portal-gated')]
    #[Auth('is_logged_in')]
    public static function gated(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED];
    }
}
