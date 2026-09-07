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
 * A REAL, dispatchable surface used by the dispatch-seam tests.
 *
 * The route seam reads its gate list from the manifest ROUTE ROW, not from the
 * surface index, so Auth_Gates::_set_index_for_testing() cannot reach it - the only
 * way to test that seam is a genuinely routed controller. These two routes are that
 * controller.
 *
 * The path prefix /_test/auth-gates/ is deliberately obscure and framework-owned:
 * these ARE live routes in a development install, and they exist so the seam can be
 * exercised without temporarily annotating application code. Both handlers return a
 * marker array and touch nothing.
 *
 * Every member carries a gate, so the closed-by-default validation pass has nothing
 * to flag here. Only names that really exist in the staff registry are used - the
 * unknown-name-at-a-seam behavior is covered through the Ajax/ORM seams, which
 * resolve gates from the (overridable) surface index.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Auth_Gates_Seam_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** Marker the tests assert on when a gated route actually dispatched. */
    public const DISPATCHED = 'auth_gates_seam_fixture_dispatched';

    /**
     * Open route: the explicit 'public' gate always passes.
     */
    #[Route('/_test/auth-gates/open')]
    #[Auth('public')]
    public static function open(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'route' => 'open'];
    }

    /**
     * Gated route: dispatches only for an authenticated staff caller.
     */
    #[Route('/_test/auth-gates/gated')]
    #[Auth('is_logged_in')]
    public static function gated(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'route' => 'gated'];
    }

    /**
     * Ajax endpoint used by the Ajax-seam tests. Its declared gate is 'public'; the
     * tests install their own synthetic surface entry for this target, so the gate
     * lists they exercise (granting, denying, unknown name) never enter the real
     * registry.
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function endpoint(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'endpoint' => 'endpoint'];
    }
}
