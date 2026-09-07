<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The panel's ENTRY ROUTE is reachable from an application bundle that does not - and may
 * not - contain the panel.
 *
 * `_Sys_Dashboard_Action` is the one name in the reserved namespace an application spells,
 * and two mechanisms make it work in the browser:
 *
 *   - config('rsx.always_published_routes') is resolved against the manifest at COMPILE
 *     time and emitted into every bundle's client route table, so Rsx.Route() answers.
 *   - Auth_Gates::export_published_route_grants() ships those targets' reachability on
 *     EVERY page (window.rsxapp.auth_routes_published, carrying explicit 0s), so
 *     Permission.can_access() answers.
 *
 * Everything here derives the expected pattern FROM THE MANIFEST rather than writing
 * '/_sys' down, which is the property the feature exists to provide: move the panel's
 * route and every published link moves with it.
 */
class Sys_Panel_Published_Route_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const ENTRY_TARGET = '_Sys_Dashboard_Action';

    /**
     * The route patterns the manifest holds for a target, which is exactly what
     * Rsx::Route() resolves server-side.
     *
     * @return string[]
     */
    private static function __manifest_patterns(string $target): array
    {
        $manifest = Manifest::get_full_manifest();
        $rows = $manifest['data']['routes_by_target'][$target] ?? [];

        return array_values(array_filter(array_column($rows, 'pattern')));
    }

    /**
     * RP-PUB-01 - The framework ships the panel's INDEX ACTION as an always-published
     * target, and it resolves in the manifest.
     */
    public static function test_the_entry_action_is_configured_and_resolvable()
    {
        $targets = config('rsx.always_published_routes', []);

        static::__assert_true(
            in_array(self::ENTRY_TARGET, $targets, true),
            'the panel entry action is missing from rsx.always_published_routes'
        );

        $patterns = static::__manifest_patterns(self::ENTRY_TARGET);

        static::__assert_not_empty(
            $patterns,
            'the entry action has no routes in the manifest - every published link would be wrong'
        );

        // The BOOTSTRAP CONTROLLER is deliberately NOT the target: a SPA route is registered
        // under its JS action class, which is why Rsx::Route('_Sys_Spa_Controller::index')
        // throws.
        static::__assert_empty(
            static::__manifest_patterns('_Sys_Spa_Controller::index'),
            'a SPA route must never be registered under its bootstrap controller'
        );
    }

    /**
     * RP-PUB-02 - An application bundle's generated route JS carries the target and the
     * pattern the manifest holds for it.
     */
    public static function test_an_application_bundle_publishes_the_entry_route()
    {
        $compiler = new BundleCompiler();
        $compiled = $compiler->compile('Frontend_Bundle');

        $app_js = $compiled['app_js_bundle_path'] ?? null;

        static::__assert_not_empty($app_js, 'Frontend_Bundle produced no app JS bundle');

        // compile() answers the OUTPUT FILENAME; the build artifacts live under
        // storage/rsx-build/bundles/, which storage_path() resolves.
        $absolute = str_starts_with($app_js, '/')
            ? $app_js
            : storage_path('rsx-build/bundles/' . basename($app_js));

        static::__assert_true(file_exists($absolute), "compiled bundle missing at {$absolute}");

        $contents = file_get_contents($absolute);

        static::__assert_contains(
            'Rsx._define_published_spa_routes(',
            $contents,
            'the bundle emits no published-route table'
        );
        static::__assert_contains(
            self::ENTRY_TARGET,
            $contents,
            'the published-route table does not name the panel entry action'
        );

        foreach (static::__manifest_patterns(self::ENTRY_TARGET) as $pattern) {
            static::__assert_contains(
                '"' . $pattern . '"',
                $contents,
                "the published pattern {$pattern} is not in the bundle - it was not resolved from the manifest"
            );
        }

        // And the tree itself stayed out: an application bundle may not include it
        // (CONV-BUNDLE-02), so nothing from the panel's own module is in this file.
        static::__assert_false(
            str_contains($contents, '_Sys_Layout'),
            'panel code reached an application bundle - the published route is the ONLY thing that crosses'
        );
    }

    /**
     * RP-PUB-03 - A misconfigured target FAILS THE COMPILE. A published entry that
     * silently vanished would leave every page linking to a placeholder URL.
     */
    public static function test_an_unresolvable_published_target_fails_the_compile()
    {
        $previous = config('rsx.always_published_routes');

        try {
            config(['rsx.always_published_routes' => ['No_Such_Published_Action']]);

            $compiler = new BundleCompiler();
            $routes = [];

            $method = new \ReflectionMethod(BundleCompiler::class, '_collect_always_published_routes');
            $method->setAccessible(true);

            static::__assert_throws(
                RuntimeException::class,
                function () use ($method, $compiler, &$routes) {
                    $method->invokeArgs($compiler, [&$routes]);
                }
            );
        } finally {
            config(['rsx.always_published_routes' => $previous]);
        }
    }

    /**
     * RP-PUB-04 - The grants map is shipped on every page, answers 1 for a user who
     * passes the panel's gate and 0 for an anonymous one, and never omits the target.
     *
     * An explicit 0 is the whole point: absence has to keep meaning "not published",
     * so an anonymous visitor reads a definite denial rather than an unknown target.
     */
    public static function test_the_entry_grant_is_exported_for_both_answers()
    {
        static::__reset_session();

        try {
            $anonymous = Auth_Gates::export_published_route_grants(Auth_Gates::REALM_STAFF);

            static::__assert_array_has_key(
                self::ENTRY_TARGET,
                $anonymous,
                'the published grant must be present even when it denies'
            );
            static::__assert_equals(0, $anonymous[self::ENTRY_TARGET], 'anonymous must be denied');

            static::__acting_as_user(1);

            $signed_in = Auth_Gates::export_published_route_grants(Auth_Gates::REALM_STAFF);

            static::__assert_equals(
                1,
                $signed_in[self::ENTRY_TARGET] ?? null,
                'a signed-in sysadmin must be granted'
            );
        } finally {
            static::__reset_session();
        }
    }

    /**
     * RP-PUB-05 - The map is REALM-SCOPED. The published targets are staff surfaces, so a
     * portal page ships an empty map rather than a staff answer - identity is not
     * experience, and Portal_Permission has no business answering for /_sys.
     */
    public static function test_the_grant_map_is_realm_scoped()
    {
        $portal = Auth_Gates::export_published_route_grants(Auth_Gates::REALM_PORTAL);

        static::__assert_false(
            array_key_exists(self::ENTRY_TARGET, $portal),
            'a staff surface must not be answered for in the portal realm'
        );
    }
}
