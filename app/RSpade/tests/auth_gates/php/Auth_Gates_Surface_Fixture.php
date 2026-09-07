<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use Illuminate\Http\Request;

/**
 * A LIVE surface carrying real #[Auth] declarations, so the class-level plus
 * method-level merge is regression-tested against the manifest the framework
 * actually builds - not only against synthetic metadata.
 *
 * It is intentionally NOT an Rsx_Controller_Abstract subclass, which makes it
 * undispatchable (Ajax::handle_browser_request refuses a non-controller) and keeps
 * it out of JavaScript stub generation (Controller_BundleIntegration gates on the
 * controller lineage). The manifest still indexes the attributes, which is all the
 * auth index needs.
 */
#[Auth('is_logged_in')]
class Auth_Gates_Surface_Fixture
{
    /**
     * Expected gate list in the auth index: ['is_logged_in', 'can_view_data'] - the
     * class-level gate first, the method's own appended, the repeat de-duplicated.
     *
     * The method-level list deliberately avoids 'public': a member-level 'public'
     * under a restricting class-level gate is a build-time CONTRADICTION (the
     * validation pass), which is exercised with synthetic metadata instead.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    #[Auth('can_view_data', 'is_logged_in')]
    public static function merged_gates(Request $request, array $params = [])
    {
        return ['ok' => true];
    }

    /**
     * Carries only the inherited class-level gate.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function class_gate_only(Request $request, array $params = [])
    {
        return ['ok' => true];
    }
}
