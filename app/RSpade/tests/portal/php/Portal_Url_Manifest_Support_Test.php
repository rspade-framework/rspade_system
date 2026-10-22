<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use RuntimeException;
use App\RSpade\Core\Portal\Portal_Url_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The build-time judge of the client portal's address: the settings refusals become a
 * manifest-build FATAL, and a portal path prefix on the application host may not collide
 * with a staff route.
 *
 * find_route_conflicts() is pure and asserted over synthetic route tables; rebuild() is
 * driven with config overrides and a synthetic `routes` section, which is exactly what the
 * manifest build hands it.
 */
class Portal_Url_Manifest_Support_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static $saved;

    public static function setup()
    {
        self::$saved = [
            'rsx.tenancy' => config('rsx.tenancy'),
            'rsx.portal.enabled' => config('rsx.portal.enabled'),
            'rsx.portal.url' => config('rsx.portal.url'),
        ];
    }

    public static function teardown()
    {
        config(self::$saved);
    }

    /**
     * A routes section in the manifest's shape: pattern => row.
     */
    private static function __routes(array $patterns): array
    {
        $routes = [];
        foreach ($patterns as $pattern) {
            $routes[$pattern] = ['type' => 'standard', 'class' => 'Fixture_Controller', 'method' => 'index', 'file' => 'fixture.php', 'pattern' => $pattern];
        }

        return $routes;
    }

    private static function __conflicts(string $prefix, array $patterns): array
    {
        return array_keys(Portal_Url_ManifestSupport::find_route_conflicts($prefix, static::__routes($patterns)));
    }

    // =========================================================================
    // find_route_conflicts()
    // =========================================================================

    public static function test_a_route_at_or_under_the_prefix_collides()
    {
        static::__assert_equals(
            ['/clients', '/clients/view/:id', '/Clients/add'],
            static::__conflicts('/clients', ['/clients', '/clients/view/:id', '/Clients/add', '/clientsx', '/contacts'])
        );
    }

    public static function test_a_route_whose_parameters_cover_the_prefix_collides()
    {
        // '/clients/:id' would answer '/clients/portal' as id "portal".
        static::__assert_equals(
            ['/clients/:id', '/clients/:id/edit'],
            static::__conflicts('/clients/portal', ['/clients/:id', '/clients/:id/edit', '/clients', '/clients/add'])
        );
    }

    public static function test_a_shorter_static_route_does_not_collide()
    {
        static::__assert_equals([], static::__conflicts('/clients/portal', ['/clients', '/clients/add', '/clients/portalx']));
    }

    public static function test_a_route_beginning_with_a_parameter_collides_with_nothing()
    {
        static::__assert_equals([], static::__conflicts('/clients', ['/:slug', '/:slug/clients', '/']));
    }

    public static function test_an_empty_prefix_collides_with_nothing()
    {
        static::__assert_equals([], static::__conflicts('', ['/', '/clients']));
    }

    // =========================================================================
    // rebuild() - the FATALs
    // =========================================================================

    private static function __rebuild_problem(array $patterns): ?string
    {
        $manifest_data = ['data' => ['routes' => static::__routes($patterns)]];

        try {
            Portal_Url_ManifestSupport::rebuild($manifest_data);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public static function test_a_colliding_prefix_is_fatal_and_names_the_routes()
    {
        config(['rsx.tenancy' => 'single', 'rsx.portal.enabled' => true, 'rsx.portal.url' => '/dashboard']);

        $problem = (string) static::__rebuild_problem(['/dashboard', '/dashboard/:id', '/contacts']);

        static::__assert_contains('Invalid client portal address', $problem);
        static::__assert_contains('collides with these staff routes', $problem);
        static::__assert_contains('/dashboard (standard Fixture_Controller::index in fixture.php)', $problem);
        static::__assert_contains('/dashboard/:id', $problem);
        static::__assert_true(!str_contains($problem, '/contacts'), 'an unrelated route was named');
        static::__assert_contains('PORTAL_URL', $problem);
    }

    public static function test_a_template_collides_through_its_fixed_prefix()
    {
        config(['rsx.tenancy' => 'multi', 'rsx.portal.enabled' => true, 'rsx.portal.url' => '/clients/:slug']);

        static::__assert_contains('/clients/view/:id', (string) static::__rebuild_problem(['/clients/view/:id']));
        static::__assert_null(static::__rebuild_problem(['/contacts']));
    }

    public static function test_a_portal_on_its_own_host_collides_with_nothing()
    {
        config(['rsx.tenancy' => 'single', 'rsx.portal.enabled' => true, 'rsx.portal.url' => 'https://portal.example.test/dashboard']);
        static::__assert_null(static::__rebuild_problem(['/dashboard']));

        config(['rsx.tenancy' => 'multi', 'rsx.portal.url' => 'https://:slug.example.test/dashboard']);
        static::__assert_null(static::__rebuild_problem(['/dashboard']));
    }

    public static function test_a_refused_setting_is_fatal()
    {
        config(['rsx.tenancy' => 'multi', 'rsx.portal.enabled' => true, 'rsx.portal.url' => '/_portal']);

        $problem = (string) static::__rebuild_problem([]);
        static::__assert_contains('Invalid client portal address', $problem);
        static::__assert_contains('rsx.tenancy = multi requires', $problem);

        config(['rsx.tenancy' => 'single', 'rsx.portal.url' => '/_portal/:slug']);
        static::__assert_contains('rsx.tenancy is single', (string) static::__rebuild_problem([]));
    }

    /** Per-site portal domains are multi-tenant only: override and only are fatal in single tenancy. */
    public static function test_custom_domains_are_fatal_in_single_tenancy()
    {
        try {
            foreach (['override', 'only'] as $mode) {
                config(['rsx.tenancy' => 'single', 'rsx.portal.enabled' => true, 'rsx.portal.url' => '', 'rsx.portal.custom_domains' => $mode]);

                $problem = (string) static::__rebuild_problem([]);
                static::__assert_contains('Invalid client portal address', $problem, $mode);
                static::__assert_contains('PORTAL_CUSTOM_DOMAINS is "' . $mode . '", but rsx.tenancy is single', $problem, $mode);
                static::__assert_contains('portal_domain on a site record is ignored', $problem, $mode);
            }

            config(['rsx.portal.custom_domains' => 'off']);
            static::__assert_null(static::__rebuild_problem([]), 'off is fine in single tenancy');

            config(['rsx.tenancy' => 'multi', 'rsx.portal.custom_domains' => 'override']);
            static::__assert_null(static::__rebuild_problem([]), 'override is fine in multi tenancy');
        } finally {
            config(['rsx.portal.custom_domains' => 'off']);
        }
    }

    public static function test_a_disabled_portal_is_not_judged()
    {
        config(['rsx.tenancy' => 'multi', 'rsx.portal.enabled' => false, 'rsx.portal.url' => '/dashboard']);

        static::__assert_null(static::__rebuild_problem(['/dashboard']));
    }

    public static function test_the_shipped_default_passes_against_the_live_route_table()
    {
        config(['rsx.tenancy' => 'single', 'rsx.portal.enabled' => true, 'rsx.portal.url' => '']);

        $manifest_data = ['data' => ['routes' => \App\RSpade\Core\Manifest\Manifest::get_full_manifest()['data']['routes'] ?? []]];
        Portal_Url_ManifestSupport::rebuild($manifest_data);

        config(['rsx.tenancy' => 'multi']);
        Portal_Url_ManifestSupport::rebuild($manifest_data);

        static::__pass();
    }
}
