<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The closed-by-default validation pass: every surface carries a gate, every gate
 * name resolves in a registry the surface can be evaluated against, and a class-level
 * gate never combines with a member-level 'public'.
 *
 * Two drivers:
 *   - Auth_ManifestSupport::validate() over a synthetic index, for the finding matrix
 *     and the message contract (what the developer is told, and whether it is
 *     actionable without a hunt).
 *   - Auth_ManifestSupport::build_index() over synthetic file metadata, for the
 *     findings that are DETECTED during collection (the contradiction) and for the
 *     JS-action spelling.
 *
 * Nothing here touches the real registry: the fixture check names are unique to this
 * file, so they can never become part of an application's vocabulary.
 */
class Auth_Validation_Test extends Rsx_Test_Abstract
{
    // Pure array logic over synthetic metadata.
    protected static $use_database_transactions = false;

    /**
     * A synthetic per-realm check registry.
     */
    private static function __checks(): array
    {
        return [
            Auth_Gates::REALM_STAFF => [
                'public' => ['class' => 'Fixture_Permission', 'method' => 'public', 'file' => 'fixture.php'],
                'is_logged_in' => ['class' => 'Fixture_Permission', 'method' => 'is_logged_in', 'file' => 'fixture.php'],
                'val_staff_only' => ['class' => 'Fixture_Permission', 'method' => 'val_staff_only', 'file' => 'fixture.php'],
            ],
            Auth_Gates::REALM_PORTAL => [
                'public' => ['class' => 'Fixture_Portal_Permission', 'method' => 'public', 'file' => 'fixture.php'],
                'is_logged_in' => ['class' => 'Fixture_Portal_Permission', 'method' => 'is_logged_in', 'file' => 'fixture.php'],
                'val_portal_only' => ['class' => 'Fixture_Portal_Permission', 'method' => 'val_portal_only', 'file' => 'fixture.php'],
            ],
        ];
    }

    /**
     * One synthetic surface entry.
     */
    private static function __surface(string $realm, array $gates, string $kind = 'route', string $file = 'rsx/app/val/val_controller.php'): array
    {
        return [
            'kinds' => [$kind],
            'realm' => $realm,
            'auth' => $gates,
            'file' => $file,
            'member' => 'Val_Fixture_Controller::index',
        ];
    }

    /**
     * Run validate() and return the failure message, or null when it passed.
     */
    private static function __validation_message(array $surfaces, array $violations = []): ?string
    {
        try {
            Auth_ManifestSupport::validate($surfaces, static::__checks(), $violations);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    // =========================================================================
    // MISSING GATE
    // =========================================================================

    /**
     * A surface with an empty gate list fails the build.
     */
    public static function test_gateless_surface_is_fatal()
    {
        $message = static::__validation_message([
            'Val_Fixture_Controller::index' => static::__surface(Auth_Gates::REALM_STAFF, []),
        ]);

        static::__assert_not_empty($message, 'a gateless surface raises the validation failure');
        static::__assert_contains('MISSING GATE', $message, 'the finding is named');
        static::__assert_contains('Val_Fixture_Controller::index', $message, 'the member is named');
        static::__assert_contains('rsx/app/val/val_controller.php', $message, 'the file is named');
    }

    /**
     * The message is written to be ACTED ON: the exact syntax to add, where the check
     * vocabulary lives, the names currently defined in each realm, and the man page.
     */
    public static function test_missing_gate_message_carries_the_full_remediation()
    {
        $message = static::__validation_message([
            'Val_Fixture_Controller::index' => static::__surface(Auth_Gates::REALM_STAFF, []),
        ]);

        static::__assert_contains("#[Auth('is_logged_in')]", $message, 'the exact attribute syntax to add');
        static::__assert_contains('rsx/permission.php', $message, 'the staff check vocabulary home');
        static::__assert_contains('rsx/portal_permission.php', $message, 'the portal check vocabulary home');
        static::__assert_contains('val_staff_only', $message, "the staff realm's defined names are listed");
        static::__assert_contains('val_portal_only', $message, "the portal realm's defined names are listed");
        static::__assert_contains('php artisan rsx:man auth_gates', $message, 'the man page pointer');
    }

    /**
     * A JS action's gate is spelled @auth on the class, and the message says so.
     */
    public static function test_gateless_js_action_reports_the_decorator_spelling()
    {
        $message = static::__validation_message([
            'Val_Fixture_Action' => static::__surface(
                Auth_Gates::REALM_STAFF,
                [],
                'js_action',
                'rsx/app/val/Val_Fixture_Action.js'
            ),
        ]);

        static::__assert_contains("@auth('is_logged_in')", $message, 'the decorator syntax to add');
        static::__assert_contains('on the action class', $message, 'where it goes');
        static::__assert_contains('Val_Fixture_Action', $message, 'the action class is named');
    }

    /**
     * A real @route action with no @auth is a MISSING GATE surface in the index.
     */
    public static function test_js_route_action_without_auth_is_indexed_gateless()
    {
        $index = Auth_ManifestSupport::build_index([]);

        foreach ($index['surfaces'] as $target => $surface) {
            if (in_array('js_action', $surface['kinds'], true) || in_array('portal_js_action', $surface['kinds'], true)) {
                static::__assert_not_empty(
                    $surface['auth'],
                    "the real action {$target} carries @auth (a gateless one would fail the build)"
                );
            }
        }
    }

    // =========================================================================
    // UNKNOWN CHECK NAME
    // =========================================================================

    /**
     * A name defined in no registry fails, naming the name and the realm.
     */
    public static function test_unknown_check_name_is_fatal()
    {
        $message = static::__validation_message([
            'Val_Fixture_Controller::index' => static::__surface(Auth_Gates::REALM_STAFF, ['val_nonexistent']),
        ]);

        static::__assert_contains('UNKNOWN CHECK', $message, 'the finding is named');
        static::__assert_contains('val_nonexistent', $message, 'the offending name is quoted');
        static::__assert_contains('staff realm', $message, 'the realm it was resolved against');
        static::__assert_contains('val_staff_only', $message, "the realm's defined names are listed");
    }

    /**
     * Realms never blur: a portal-only name on a staff surface is unknown.
     */
    public static function test_cross_realm_check_name_is_unknown()
    {
        $message = static::__validation_message([
            'Val_Fixture_Controller::index' => static::__surface(Auth_Gates::REALM_STAFF, ['val_portal_only']),
        ]);

        static::__assert_contains('UNKNOWN CHECK', $message, 'a portal name on a staff surface does not resolve');
    }

    /**
     * An 'any' surface is evaluated in the REQUEST's realm, so the build only requires
     * the name to exist in at least one registry; the seam denies in a realm that
     * lacks it.
     */
    public static function test_any_realm_surface_accepts_a_name_defined_in_one_realm()
    {
        static::__assert_null(
            static::__validation_message([
                'Val_Fixture_Model::related' => static::__surface(Auth_Gates::REALM_ANY, ['val_portal_only'], 'model_relationship'),
            ]),
            "a name defined in one realm satisfies an 'any' surface"
        );

        static::__assert_not_empty(
            static::__validation_message([
                'Val_Fixture_Model::related' => static::__surface(Auth_Gates::REALM_ANY, ['val_nowhere'], 'model_relationship'),
            ]),
            "a name defined in NO realm still fails an 'any' surface"
        );
    }

    // =========================================================================
    // CONTRADICTION
    // =========================================================================

    /**
     * A class-level restricting gate plus a member-level 'public' is fatal: gates AND,
     * so the member's declaration opens nothing while reading as though it does.
     */
    public static function test_class_gate_plus_member_public_is_fatal()
    {
        $index = Auth_ManifestSupport::build_index([
            'rsx/app/val/val_contradiction_controller.php' => [
                'class' => 'Val_Contradiction_Controller',
                'fqcn' => 'Rsx\\App\\Val\\Val_Contradiction_Controller',
                'attributes' => ['Auth' => [['is_logged_in']]],
                'public_static_methods' => [
                    'index' => ['attributes' => ['Route' => [['/val-contradiction']], 'Auth' => [['public']]]],
                ],
            ],
        ]);

        static::__assert_count(1, $index['violations'], 'the contradiction is detected during collection');

        $message = null;
        try {
            Auth_ManifestSupport::validate($index['surfaces'], static::__checks(), $index['violations']);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        static::__assert_contains('CONTRADICTION', $message, 'the finding is named');
        static::__assert_contains('Val_Contradiction_Controller::index', $message, 'the member is named');
        static::__assert_contains('gates AND', $message, 'the message explains why the member opens nothing');
    }

    /**
     * A class declaring only 'public' restricts nothing, so a member repeating it is
     * redundant - not contradictory - and must not brick the build.
     */
    public static function test_class_public_plus_member_public_is_not_a_contradiction()
    {
        $index = Auth_ManifestSupport::build_index([
            'rsx/app/val/val_public_controller.php' => [
                'class' => 'Val_Public_Controller',
                'fqcn' => 'Rsx\\App\\Val\\Val_Public_Controller',
                'attributes' => ['Auth' => [['public']]],
                'public_static_methods' => [
                    'index' => ['attributes' => ['Route' => [['/val-public']], 'Auth' => [['public']]]],
                ],
            ],
        ]);

        static::__assert_count(0, $index['violations'], 'a non-restricting class gate raises nothing');
    }

    // =========================================================================
    // BATCHING
    // =========================================================================

    /**
     * The error list IS the worklist: every finding rides in ONE exception, numbered,
     * rather than the build failing one surface at a time.
     */
    public static function test_all_violations_are_batched_into_one_message()
    {
        $message = static::__validation_message([
            'Val_A_Controller::index' => static::__surface(Auth_Gates::REALM_STAFF, [], 'route', 'rsx/app/val/a.php'),
            'Val_B_Controller::save' => static::__surface(Auth_Gates::REALM_STAFF, ['val_nonexistent'], 'ajax', 'rsx/app/val/b.php'),
            'Val_C_Action' => static::__surface(Auth_Gates::REALM_PORTAL, [], 'portal_js_action', 'rsx/portal/C.js'),
        ]);

        static::__assert_contains('3 violations', $message, 'the count is stated');
        static::__assert_contains('[1]', $message, 'findings are numbered');
        static::__assert_contains('[2]', $message);
        static::__assert_contains('[3]', $message);
        static::__assert_contains('Val_A_Controller::index', $message);
        static::__assert_contains('Val_B_Controller::save', $message);
        static::__assert_contains('Val_C_Action', $message);
    }

    /**
     * A portal surface is offered the portal realm's floor check, not the staff one.
     */
    public static function test_portal_surface_suggestion_uses_the_portal_registry()
    {
        $message = static::__validation_message([
            'Val_Portal_Controller::index' => static::__surface(Auth_Gates::REALM_PORTAL, [], 'portal_route', 'rsx/portal/val.php'),
        ]);

        static::__assert_contains('portal realm', $message, 'the surface realm is stated');
        static::__assert_contains("#[Auth('is_logged_in')]", $message, 'the portal floor is suggested');
    }

    // =========================================================================
    // THE LIVE TREE
    // =========================================================================

    /**
     * The application as it stands satisfies the pass. This is the regression guard
     * for the flip itself: adding an ungated surface anywhere fails here as well as
     * at build time.
     */
    public static function test_the_live_index_validates()
    {
        $index = Auth_Gates::get_index();

        Auth_ManifestSupport::validate($index['surfaces'], $index['checks']);

        static::__pass('every surface in the built manifest carries a resolvable gate');
    }
}
