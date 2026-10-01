<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_App_Url;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * PORTAL_URL: one setting, the derived portal address, its refusals, request
 * classification across every layout, and URL generation from inside and outside the
 * portal's host.
 *
 * parse() and check() are pure. The layouts are driven through config('rsx.portal.url')
 * overrides and synthetic requests; Route() context is driven by rebinding the ambient
 * request (CLI's own request carries APP_URL's host, exactly as a task's does). The boot
 * guard itself (Rsx_Framework_Provider -> validate()) runs on every boot and is the same
 * check(); a refused value cannot be driven through a live boot in-process.
 */
class Portal_Url_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const APP = 'https://app.example.test';

    private const PORTAL_HOST = 'portal.example.test';

    private const PORTAL_TARGET = 'Portal_Route_Parity_Fixture_Controller::item';

    private static $saved_portal_url;

    private static $saved_request;

    public static function setup()
    {
        self::$saved_portal_url = config('rsx.portal.url');
        self::$saved_request = app('request');
    }

    public static function teardown()
    {
        config(['rsx.portal.url' => self::$saved_portal_url]);
        app()->instance('request', self::$saved_request);
        Rsx_Request_Channel::reset();
    }

    /**
     * The application host of this box, which synthetic staff requests are addressed to.
     */
    private static function __app_origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Classify one request and return [channel, realm, portal_host, realm_path].
     */
    private static function __classify(string $url, string $method = 'GET'): array
    {
        $channel = Rsx_Request_Channel::classify(Request::create($url, $method));

        return [$channel, Rsx_Request_Channel::realm(), Rsx_Request_Channel::is_portal_host(), Rsx_Request_Channel::realm_path()];
    }

    // =========================================================================
    // parse() - the derivation
    // =========================================================================

    public static function test_a_blank_portal_url_derives_from_app_url()
    {
        static::__assert_equals(
            ['url' => self::APP . '/_portal', 'origin' => self::APP, 'host' => 'app.example.test', 'prefix' => '/_portal', 'separate_host' => false],
            Rsx_Portal_Url::parse('', self::APP . '/')
        );

        // A non-default port is part of the origin; the default one is dropped.
        static::__assert_equals('http://localhost:8080', Rsx_Portal_Url::parse('', 'http://localhost:8080')['origin']);
        static::__assert_equals('https://app.example.test', Rsx_Portal_Url::parse('', 'https://app.example.test:443')['origin']);
    }

    public static function test_a_blank_portal_url_with_no_app_url_derives_only_the_prefix()
    {
        // The development first-run state: nothing is known about the host yet.
        static::__assert_equals(
            ['url' => '/_portal', 'origin' => '', 'host' => '', 'prefix' => '/_portal', 'separate_host' => false],
            Rsx_Portal_Url::parse('', '')
        );
    }

    public static function test_a_path_on_the_application_host_is_a_same_host_prefix()
    {
        $parsed = Rsx_Portal_Url::parse('https://APP.example.test/clients/', self::APP);

        static::__assert_equals('https://app.example.test', $parsed['origin']);
        static::__assert_equals('/clients', $parsed['prefix']);
        static::__assert_false($parsed['separate_host']);
    }

    public static function test_a_host_of_its_own_without_a_prefix()
    {
        $parsed = Rsx_Portal_Url::parse('https://Portal.Example.test:443/', self::APP);

        static::__assert_equals('https://portal.example.test', $parsed['origin']);
        static::__assert_equals('portal.example.test', $parsed['host']);
        static::__assert_equals('', $parsed['prefix']);
        static::__assert_true($parsed['separate_host']);
    }

    public static function test_a_host_of_its_own_with_a_prefix()
    {
        $parsed = Rsx_Portal_Url::parse('http://portal.example.test:8443/x/y/', self::APP);

        static::__assert_equals('http://portal.example.test:8443', $parsed['origin']);
        static::__assert_equals('/x/y', $parsed['prefix']);
        static::__assert_equals('http://portal.example.test:8443/x/y', $parsed['url']);
        static::__assert_true($parsed['separate_host']);
    }

    public static function test_the_hostname_token_resolves_in_portal_url()
    {
        $raw = 'https://portal.$HOSTNAME/x';
        $saved = [$_ENV['PORTAL_URL'] ?? null, $_SERVER['PORTAL_URL'] ?? null, getenv('PORTAL_URL')];

        try {
            $_ENV['PORTAL_URL'] = $raw;
            $_SERVER['PORTAL_URL'] = $raw;
            putenv('PORTAL_URL=' . $raw);

            Rsx_App_Url::patch_environment();

            $expected = 'https://portal.' . gethostname() . '/x';
            static::__assert_equals($expected, $_ENV['PORTAL_URL']);
            static::__assert_equals($expected, getenv('PORTAL_URL'));
            static::__assert_equals('/x', Rsx_Portal_Url::parse($expected, self::APP)['prefix']);
        } finally {
            $_ENV['PORTAL_URL'] = $saved[0] ?? '';
            $_SERVER['PORTAL_URL'] = $saved[1] ?? '';
            putenv('PORTAL_URL=' . ($saved[2] === false ? '' : $saved[2]));
        }

        // The braces spelling too, through the same resolver APP_URL uses.
        static::__assert_equals('https://portal.box1', Rsx_App_Url::resolve('https://portal.${HOSTNAME}/', 'box1'));
    }

    // =========================================================================
    // check() - the boot guard's refusals
    // =========================================================================

    public static function test_usable_values_pass()
    {
        foreach (['', 'https://portal.example.test', 'https://portal.example.test/', 'https://portal.example.test/x/y-z_1',
            self::APP . '/clients', self::APP . '/_portal', 'https://portal.example.test/_portal'] as $value) {
            static::__assert_null(Rsx_Portal_Url::check($value, self::APP, false), "'{$value}' is usable");
        }

        // http is the development allowance, exactly as for APP_URL.
        static::__assert_null(Rsx_Portal_Url::check('http://portal.example.test', self::APP, true));
    }

    public static function test_nothing_is_checked_while_app_url_is_empty()
    {
        static::__assert_null(Rsx_Portal_Url::check('not a url at all', '', false));
    }

    public static function test_equal_to_app_url_is_refused_after_normalisation()
    {
        foreach ([self::APP, self::APP . '/', 'https://APP.Example.test', 'https://app.example.test:443/', 'http://app.example.test'] as $value) {
            static::__assert_contains(
                'PORTAL_URL must not equal APP_URL',
                (string) Rsx_Portal_Url::check($value, self::APP, true),
                "'{$value}' is APP_URL"
            );
        }
    }

    public static function test_the_app_url_scheme_rule_applies()
    {
        static::__assert_contains('PORTAL_URL must be https outside development', (string) Rsx_Portal_Url::check('http://portal.example.test', self::APP, false));
        static::__assert_contains('PORTAL_URL must be an http:// or https:// URL', (string) Rsx_Portal_Url::check('ftp://portal.example.test', self::APP, true));
    }

    public static function test_a_value_that_is_not_an_absolute_url_is_refused()
    {
        static::__assert_contains('PORTAL_URL must be an absolute', (string) Rsx_Portal_Url::check('portal.example.test', self::APP, true));
        static::__assert_contains('PORTAL_URL must be an absolute', (string) Rsx_Portal_Url::check('/clients', self::APP, true));
    }

    public static function test_credentials_query_and_fragment_are_refused()
    {
        foreach (['https://u:p@portal.example.test', 'https://portal.example.test/?a=1', 'https://portal.example.test/x#y'] as $value) {
            static::__assert_contains('PORTAL_URL may carry only', (string) Rsx_Portal_Url::check($value, self::APP, false), $value);
        }
    }

    public static function test_malformed_path_segments_are_refused()
    {
        foreach (['https://portal.example.test/a.b', 'https://portal.example.test/a//b', 'https://portal.example.test/a%20b', 'https://portal.example.test/a b'] as $value) {
            static::__assert_contains('PORTAL_URL path segments', (string) Rsx_Portal_Url::check($value, self::APP, false), $value);
        }
    }

    public static function test_a_framework_owned_first_segment_is_refused()
    {
        foreach (['/api', '/API/x', '/error', '/ws', '/_sys', '/_compiled', '/_vendor', '/_ajax', '/_upload', '/_', '/_portalx'] as $path) {
            static::__assert_contains(
                'belongs to the framework',
                (string) Rsx_Portal_Url::check('https://portal.example.test' . $path, self::APP, false),
                $path
            );
        }

        // A deeper segment is the portal's own business.
        static::__assert_null(Rsx_Portal_Url::check('https://portal.example.test/x/api', self::APP, false));
    }

    public static function test_the_health_row_reports_the_derivation_and_the_refusal()
    {
        config(['rsx.portal.url' => '']);
        $row = Rsx_Portal_Url::health_row();
        static::__assert_equals('OK', $row['status']);
        static::__assert_contains('/_portal', $row['detail']);
        static::__assert_contains('on the application host', $row['detail']);

        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        $row = Rsx_Portal_Url::health_row();
        static::__assert_equals('OK', $row['status']);
        static::__assert_contains('https://' . self::PORTAL_HOST . ' prefix (none) - its own host', $row['detail']);

        config(['rsx.portal.url' => static::__app_origin()]);
        $row = Rsx_Portal_Url::health_row();
        static::__assert_equals('FAIL', $row['status']);
        static::__assert_contains('must not equal APP_URL', $row['detail']);
    }

    // =========================================================================
    // Classification
    // =========================================================================

    public static function test_the_default_layout_classifies_by_prefix_on_any_host()
    {
        config(['rsx.portal.url' => '']);
        $app = static::__app_origin();

        static::__assert_equals(['page', 'portal', false, '/dashboard'], static::__classify($app . '/_portal/dashboard'));
        static::__assert_equals(['page', 'portal', false, '/'], static::__classify($app . '/_portal'));
        static::__assert_equals(['page', 'staff', false, '/dashboard'], static::__classify($app . '/dashboard'));
        static::__assert_equals(['page', 'staff', false, '/_portalx'], static::__classify($app . '/_portalx'));

        // Loopback (the http shell tests) reaches the same-host portal too.
        static::__assert_equals(['ajax', 'portal', false, '/_ajax/A/b'], static::__classify('http://localhost/_portal/_ajax/A/b', 'POST'));

        // Artifacts are realm-agnostic and the same file under the prefix.
        static::__assert_equals(['asset', 'portal', false, '/_compiled/Portal_Bundle__app.0123abcd.js'], static::__classify($app . '/_portal/_compiled/Portal_Bundle__app.0123abcd.js'));
        static::__assert_equals(['asset', 'staff', false, '/_vendor/0123456789abcdef0123456789abcdef_x.woff2'], static::__classify($app . '/_vendor/0123456789abcdef0123456789abcdef_x.woff2'));
    }

    public static function test_a_same_host_prefix_wins_over_staff_routes_beneath_it()
    {
        config(['rsx.portal.url' => static::__app_origin() . '/clients']);
        $app = static::__app_origin();

        static::__assert_equals(['page', 'portal', false, '/view/5'], static::__classify($app . '/clients/view/5'));
        static::__assert_equals(['page', 'staff', false, '/clientsx'], static::__classify($app . '/clientsx'));
        static::__assert_equals(['page', 'staff', false, '/_portal/dashboard'], static::__classify($app . '/_portal/dashboard'));
        static::__assert_equals(['api', 'staff', false, '/api/v1/me'], static::__classify($app . '/api/v1/me'));
    }

    public static function test_a_separate_host_without_a_prefix_is_the_portal_throughout()
    {
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        $portal = 'https://' . self::PORTAL_HOST;

        static::__assert_equals(['page', 'portal', true, '/dashboard'], static::__classify($portal . '/dashboard'));
        static::__assert_equals(['page', 'portal', true, '/dashboard'], static::__classify('https://PORTAL.example.test/dashboard'));
        static::__assert_equals(['ajax', 'portal', true, '/_ajax/A/b'], static::__classify($portal . '/_ajax/A/b', 'POST'));
        static::__assert_equals(['asset', 'portal', true, '/_compiled/Portal_Bundle__app.0123abcd.js'], static::__classify($portal . '/_compiled/Portal_Bundle__app.0123abcd.js'));

        // The API is refused there (Api_Dispatcher reads portal_host).
        static::__assert_equals(['api', 'staff', true, '/api/v1/me'], static::__classify($portal . '/api/v1/me'));

        // The application host is staff, the old default prefix included.
        static::__assert_equals(['page', 'staff', false, '/dashboard'], static::__classify(static::__app_origin() . '/dashboard'));
        static::__assert_equals(['page', 'staff', false, '/_portal/dashboard'], static::__classify(static::__app_origin() . '/_portal/dashboard'));
    }

    public static function test_a_separate_host_with_a_prefix_keeps_its_whole_host()
    {
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/x']);
        $portal = 'https://' . self::PORTAL_HOST;

        static::__assert_equals(['page', 'portal', true, '/dash'], static::__classify($portal . '/x/dash'));
        static::__assert_equals(['ajax', 'portal', true, '/_ajax/A/b'], static::__classify($portal . '/x/_ajax/A/b', 'POST'));

        // Outside the prefix on the portal host: portal realm, never staff (it 404s).
        static::__assert_equals(['page', 'portal', true, '/dash'], static::__classify($portal . '/dash'));
        static::__assert_equals(['ajax', 'portal', true, '/_ajax/A/b'], static::__classify($portal . '/_ajax/A/b', 'POST'));

        // /api/ outside the prefix is the (refused) API; under it, a portal path.
        static::__assert_equals(['api', 'staff', true, '/api/v1/me'], static::__classify($portal . '/api/v1/me'));
        static::__assert_equals(['page', 'portal', true, '/api/v1/me'], static::__classify($portal . '/x/api/v1/me'));

        // Artifacts answer with and without the prefix.
        static::__assert_equals(['asset', 'portal', true, '/_compiled/Portal_Bundle__app.0123abcd.js'], static::__classify($portal . '/x/_compiled/Portal_Bundle__app.0123abcd.js'));
        static::__assert_equals(['asset', 'portal', true, '/_compiled/Portal_Bundle__app.0123abcd.js'], static::__classify($portal . '/_compiled/Portal_Bundle__app.0123abcd.js'));

        // The prefix means nothing on the application host.
        static::__assert_equals(['page', 'staff', false, '/x/dash'], static::__classify(static::__app_origin() . '/x/dash'));
    }

    public static function test_outside_the_prefix_on_the_portal_host_is_the_portal_404()
    {
        static::__reset_session();
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/x']);
        $portal = 'https://' . self::PORTAL_HOST;

        $inside = Request::create($portal . '/x/test-route-parity/5/7');
        app()->instance('request', $inside);
        static::__assert_equals(200, Rsx_Front_Controller::handle($inside)->getStatusCode(), 'the fixture under the prefix');

        $outside = Request::create($portal . '/test-route-parity/5/7');
        app()->instance('request', $outside);
        static::__assert_equals(404, Rsx_Front_Controller::handle($outside)->getStatusCode(), 'the same path outside the prefix');
    }

    // =========================================================================
    // URL generation
    // =========================================================================

    public static function test_a_same_host_portal_generates_paths_everywhere()
    {
        config(['rsx.portal.url' => '']);

        // CLI's request carries APP_URL's host; a loopback request is the same layout.
        static::__assert_equals('/_portal/test-route-parity/5/7', Rsx_Portal::Route(self::PORTAL_TARGET, ['id' => 5, 'id_type' => 7]));

        app()->instance('request', Request::create('http://localhost/dashboard'));
        static::__assert_equals('/_portal/login', Rsx_Portal::portal_path('/login'));
    }

    public static function test_a_separate_host_portal_generates_absolute_urls_off_its_host()
    {
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/x']);

        // CLI / a task / a staff page: the portal's own origin.
        app()->instance('request', Request::create(static::__app_origin() . '/clients'));
        static::__assert_equals('https://' . self::PORTAL_HOST . '/x/test-route-parity/5/7', Rsx_Portal::Route(self::PORTAL_TARGET, ['id' => 5, 'id_type' => 7]));
        static::__assert_equals('https://' . self::PORTAL_HOST . '/x/login', Rsx_Portal::portal_path('/login'));
        static::__assert_equals('#', Rsx_Portal::Route('Anything::#later'));

        // On the portal host: a host-relative path.
        app()->instance('request', Request::create('https://' . self::PORTAL_HOST . '/x/dashboard'));
        static::__assert_equals('/x/test-route-parity/5/7', Rsx_Portal::Route(self::PORTAL_TARGET, ['id' => 5, 'id_type' => 7]));
    }

    public static function test_rsx_absolute_url_passes_an_absolute_url_through()
    {
        static::__assert_equals('https://portal.example.test/x/a?b=1', rsx_absolute_url('https://portal.example.test/x/a?b=1'));
        static::__assert_equals('HTTP://portal.example.test/', rsx_absolute_url('HTTP://portal.example.test/'));

        // A path still takes the caller's authority.
        static::__assert_true(str_starts_with(rsx_absolute_url('/a'), 'http'));
        static::__assert_true(str_ends_with(rsx_absolute_url('/a'), '/a'));

        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        app()->instance('request', Request::create(static::__app_origin() . '/clients'));
        static::__assert_equals(
            'https://' . self::PORTAL_HOST . '/test-route-parity/5/7',
            rsx_absolute_url(Rsx_Portal::Route(self::PORTAL_TARGET, ['id' => 5, 'id_type' => 7])),
            'the emailed-link spelling is right from the staff side'
        );
    }

    public static function test_prefix_helpers_respect_segment_boundaries()
    {
        config(['rsx.portal.url' => '']);

        static::__assert_true(Rsx_Portal::is_under_prefix('/_portal'));
        static::__assert_true(Rsx_Portal::is_under_prefix('/_portal/x'));
        static::__assert_true(Rsx_Portal::is_under_prefix('/_portal?tab=1'));
        static::__assert_false(Rsx_Portal::is_under_prefix('/_portalx'));
        static::__assert_equals('/', Rsx_Portal::strip_prefix('/_portal'));
        static::__assert_equals('/?tab=1', Rsx_Portal::strip_prefix('/_portal?tab=1'));
        static::__assert_equals('/x?y', Rsx_Portal::strip_prefix('/_portal/x?y'));
        static::__assert_equals('/_portalx', Rsx_Portal::strip_prefix('/_portalx'));

        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        static::__assert_true(Rsx_Portal::is_under_prefix('/anything'));
        static::__assert_equals('/anything', Rsx_Portal::strip_prefix('/anything'));
    }
}
