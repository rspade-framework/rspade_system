<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Check_Fixture;

/**
 * Auth_Gates evaluation: strictness, LIVE evaluation, AND semantics, unknown-name
 * diagnostics, and can_access() target resolution.
 *
 * Every test installs a synthetic index through Auth_Gates::_set_index_for_testing()
 * so the check bodies are the local fixtures rather than the application's real
 * vocabulary, then resets the engine.
 */
class Auth_Gates_Evaluation_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FIXTURE = Auth_Gates_Check_Fixture::class;

    // =========================================================================
    // STRICTNESS
    // =========================================================================

    /**
     * Only an exact `true` grants; every other return value denies (fail-closed).
     */
    public static function test_only_exact_true_grants()
    {
        static::__install();

        static::__assert_true(Auth_Gates::evaluate('grants', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::evaluate('denies', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::evaluate('returns_one', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::evaluate('returns_true_string', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::evaluate('returns_nothing', Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // AND SEMANTICS
    // =========================================================================

    /**
     * gates_pass() requires every name; an empty list is an open gate list.
     */
    public static function test_gates_pass_requires_every_check()
    {
        static::__install();

        static::__assert_true(Auth_Gates::gates_pass([], Auth_Gates::REALM_STAFF));
        static::__assert_true(Auth_Gates::gates_pass(['grants'], Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::gates_pass(['grants', 'denies'], Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::gates_pass(['denies', 'grants'], Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // LIVE EVALUATION
    // =========================================================================

    /**
     * A check body runs on EVERY ask - there is no cache between two asks, however
     * they arrive (a bare evaluate(), a gate list, a can_access() target).
     */
    public static function test_check_body_executes_on_every_ask()
    {
        static::__install();
        Auth_Gates_Check_Fixture::reset_counter();

        Auth_Gates::evaluate('counted', Auth_Gates::REALM_STAFF);
        static::__assert_equals(1, Auth_Gates_Check_Fixture::$call_count);

        Auth_Gates::evaluate('counted', Auth_Gates::REALM_STAFF);
        static::__assert_equals(2, Auth_Gates_Check_Fixture::$call_count);

        Auth_Gates::gates_pass(['counted', 'grants'], Auth_Gates::REALM_STAFF);
        static::__assert_equals(3, Auth_Gates_Check_Fixture::$call_count);

        Auth_Gates::can_access('Eval_Counted_Action', Auth_Gates::REALM_STAFF);
        static::__assert_equals(4, Auth_Gates_Check_Fixture::$call_count);

        // The portal realm is a separate namespace, and its body runs live too.
        Auth_Gates::evaluate('counted', Auth_Gates::REALM_PORTAL);
        static::__assert_equals(5, Auth_Gates_Check_Fixture::$call_count);

        Auth_Gates_Check_Fixture::reset_counter();
        Auth_Gates::_reset_for_testing();
    }

    /**
     * THE REGRESSION THIS ENGINE EXISTS TO NOT HAVE: two identities in one process.
     *
     * A check that answers from the session is asked once as a user it denies and
     * once as the user it admits. The old decision cache was keyed by realm and
     * check name only - it carried no identity - so the second ask silently
     * inherited the first identity's verdict: a denial that was wrong, plausible,
     * and completely silent. One process serving two identities is not exotic
     * (impersonation, the headless API identity swap, a per-user CLI loop, a test
     * harness), so the answer has to follow whoever is asking, every time.
     */
    public static function test_evaluation_follows_the_identity_that_is_asking()
    {
        static::__install();

        $granted_id = Auth_Gates_Check_Fixture::GRANTED_USER_ID;

        try {
            // Identity one: a user this check does not admit.
            Session::_set_api_identity(1, 1, $granted_id + 1);

            static::__assert_false(
                Auth_Gates::evaluate('identity_bound', Auth_Gates::REALM_STAFF),
                'the first identity is denied'
            );

            Session::_reset_api_identity();

            // Identity two, same process: the user this check does admit.
            Session::_set_api_identity(1, 1, $granted_id);

            static::__assert_true(
                Auth_Gates::evaluate('identity_bound', Auth_Gates::REALM_STAFF),
                'the second identity is granted, not handed the first identity answer'
            );

            // The client export is built from the same engine, so it moves too.
            $grants = Auth_Gates::export_grants(Auth_Gates::REALM_STAFF);
            static::__assert_true(array_key_exists('identity_bound', $grants));
        } finally {
            Session::_reset_api_identity();
            Auth_Gates::_reset_for_testing();
        }
    }

    // =========================================================================
    // UNKNOWN NAMES
    // =========================================================================

    /**
     * A typo throws, and the message lists the realm's defined names so the fix is
     * a copy-paste rather than a hunt.
     */
    public static function test_unknown_check_throws_listing_defined_names()
    {
        static::__install();

        $exception = static::__assert_throws(
            \RuntimeException::class,
            function () {
                Auth_Gates::evaluate('can_manage_uzers', Auth_Gates::REALM_STAFF);
            },
            "Unknown auth check 'can_manage_uzers' in the staff realm"
        );

        static::__assert_contains('counted, denies, grants', $exception->getMessage());

        Auth_Gates::_reset_for_testing();
    }

    /**
     * Realms are separate namespaces: a staff-only name is unknown in the portal.
     */
    public static function test_realms_are_separate_namespaces()
    {
        static::__install();

        static::__assert_true(Auth_Gates::evaluate('denies', Auth_Gates::REALM_STAFF) === false);

        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Auth_Gates::evaluate('denies', Auth_Gates::REALM_PORTAL);
            },
            'in the portal realm'
        );

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // CAN_ACCESS
    // =========================================================================

    /**
     * A route target passes when all of its gates pass and fails when one denies.
     */
    public static function test_can_access_route_target()
    {
        static::__install();

        static::__assert_true(Auth_Gates::can_access('Eval_Open_Controller::index', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::can_access('Eval_Closed_Controller::index', Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A bare controller name implies ::index, exactly like Rsx::Route().
     */
    public static function test_can_access_implies_index_method()
    {
        static::__install();

        static::__assert_true(Auth_Gates::can_access('Eval_Open_Controller', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::can_access('Eval_Closed_Controller', Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A SPA action target is the bare action class name.
     */
    public static function test_can_access_action_target()
    {
        static::__install();

        static::__assert_true(Auth_Gates::can_access('Eval_Counted_Action', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::can_access('Eval_Denied_Action', Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An ungated surface is reachable (closed-by-default arrives with the
     * validation pass, not with the engine).
     */
    public static function test_can_access_ungated_surface_passes()
    {
        static::__install();

        static::__assert_true(Auth_Gates::can_access('Eval_Ungated_Controller::index', Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A realm-agnostic surface ('any', e.g. an Ajax endpoint) resolves from either
     * realm and is evaluated in the caller's realm.
     */
    public static function test_can_access_realm_agnostic_surface()
    {
        static::__install();

        static::__assert_true(Auth_Gates::can_access('Eval_Shared_Controller::save', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::can_access('Eval_Shared_Controller::save', Auth_Gates::REALM_PORTAL));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An unknown target throws - a typo must never silently hide or show a link.
     */
    public static function test_can_access_unknown_target_throws()
    {
        static::__install();

        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Auth_Gates::can_access('No_Such_Controller::index', Auth_Gates::REALM_STAFF);
            },
            "Unknown auth target 'No_Such_Controller::index'"
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A portal target is not resolvable from the staff realm, and vice versa.
     */
    public static function test_can_access_rejects_cross_realm_target()
    {
        static::__install();

        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Auth_Gates::can_access('Eval_Portal_Controller::index', Auth_Gates::REALM_STAFF);
            },
            'belongs to the portal realm'
        );

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // SYNTHETIC INDEX
    // =========================================================================

    /**
     * Install the fixture-backed check registry and surface index.
     */
    private static function __install(): void
    {
        $checks = [
            Auth_Gates::REALM_STAFF => [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'denies' => ['class' => self::FIXTURE, 'method' => 'denies'],
                'returns_one' => ['class' => self::FIXTURE, 'method' => 'returns_one'],
                'returns_true_string' => ['class' => self::FIXTURE, 'method' => 'returns_true_string'],
                'returns_nothing' => ['class' => self::FIXTURE, 'method' => 'returns_nothing'],
                'counted' => ['class' => self::FIXTURE, 'method' => 'count_calls'],
                'identity_bound' => [
                    'class' => self::FIXTURE,
                    'method' => 'grants_only_for_the_granted_user',
                ],
                // Same NAME in both realms, opposite meanings - realm decides.
                'shared_check' => ['class' => self::FIXTURE, 'method' => 'grants'],
            ],
            Auth_Gates::REALM_PORTAL => [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'counted' => ['class' => self::FIXTURE, 'method' => 'count_calls'],
                'shared_check' => ['class' => self::FIXTURE, 'method' => 'denies'],
            ],
        ];

        $surfaces = [
            'Eval_Open_Controller::index' => static::__surface('staff', ['grants']),
            'Eval_Closed_Controller::index' => static::__surface('staff', ['grants', 'denies']),
            'Eval_Ungated_Controller::index' => static::__surface('staff', []),
            'Eval_Counted_Action' => static::__surface('staff', ['counted']),
            'Eval_Denied_Action' => static::__surface('staff', ['denies']),
            'Eval_Portal_Controller::index' => static::__surface('portal', ['grants']),
            // Reachable from both realms. Its one gate name resolves to a granting
            // body for staff and a denying body for portal, so the answer differs by
            // realm even though the declaration is one.
            'Eval_Shared_Controller::save' => static::__surface('any', ['shared_check']),
        ];

        Auth_Gates::_set_index_for_testing($checks, $surfaces);
    }

    /**
     * One synthetic surface entry.
     */
    private static function __surface(string $realm, array $gates): array
    {
        return [
            'kinds' => ['route'],
            'realm' => $realm,
            'auth' => $gates,
            'file' => 'fixture/eval.php',
            'member' => 'fixture',
        ];
    }
}
