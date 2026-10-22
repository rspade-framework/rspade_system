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
use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
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
 * request (CLI's own request carries APP_URL's host, exactly as a task's does). The build
 * judge (Portal_Url_ManifestSupport) calls the same check(); its route-collision rule and
 * its FATAL are asserted in Portal_Url_Manifest_Support_Test. The tenancy and portal
 * switches (rsx.tenancy, PORTAL_ENABLED, PORTAL_CUSTOM_DOMAINS) are
 * asserted here through their accessors, which the boot check calls.
 */
class Portal_Url_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const APP = 'https://app.example.test';

    private const PORTAL_HOST = 'portal.example.test';

    private const PORTAL_TARGET = 'Portal_Route_Parity_Fixture_Controller::item';

    private static $saved_portal_url;

    private static $saved_settings;

    private static $saved_request;

    public static function setup()
    {
        self::$saved_portal_url = config('rsx.portal.url');
        self::$saved_settings = [
            'rsx.tenancy' => config('rsx.tenancy'),
            'rsx.portal.enabled' => config('rsx.portal.enabled'),
            'rsx.portal.custom_domains' => config('rsx.portal.custom_domains'),
        ];
        self::$saved_request = app('request');
    }

    public static function teardown()
    {
        config(['rsx.portal.url' => self::$saved_portal_url]);
        config(self::$saved_settings);
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
            ['url' => self::APP . '/_portal', 'origin' => self::APP, 'host' => 'app.example.test', 'prefix' => '/_portal', 'separate_host' => false,
                'mode' => 'path', 'placeholder' => null, 'host_suffix' => null, 'fixed_prefix' => '/_portal'],
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
            ['url' => '/_portal', 'origin' => '', 'host' => '', 'prefix' => '/_portal', 'separate_host' => false,
                'mode' => 'path', 'placeholder' => null, 'host_suffix' => null, 'fixed_prefix' => '/_portal'],
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

    public static function test_a_value_that_is_neither_an_absolute_url_nor_a_path_is_refused()
    {
        static::__assert_contains('PORTAL_URL must be an absolute', (string) Rsx_Portal_Url::check('portal.example.test', self::APP, true));
        static::__assert_contains('PORTAL_URL must be an absolute', (string) Rsx_Portal_Url::check('clients/x', self::APP, true));
    }

    public static function test_a_bare_path_names_at_least_one_segment()
    {
        static::__assert_contains('name at least one segment', (string) Rsx_Portal_Url::check('/', self::APP, true));
        static::__assert_null(Rsx_Portal_Url::check('/c', self::APP, false));
        static::__assert_null(Rsx_Portal_Url::check('/clients', self::APP, false));
        static::__assert_null(Rsx_Portal_Url::check('/clients/area', self::APP, false));

        // The path rules apply to a bare path exactly as to an absolute one.
        static::__assert_contains('belongs to the framework', (string) Rsx_Portal_Url::check('/api', self::APP, false));
        static::__assert_contains('PORTAL_URL path segments', (string) Rsx_Portal_Url::check('/a.b', self::APP, false));
        static::__assert_contains('PORTAL_URL path segments', (string) Rsx_Portal_Url::check('/clients?x=1', self::APP, false));
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

    // =========================================================================
    // Templates - parse() shapes
    // =========================================================================

    public static function test_a_bare_path_is_a_prefix_on_the_application_host()
    {
        $parsed = Rsx_Portal_Url::parse('/clients/', self::APP);

        static::__assert_equals(self::APP, $parsed['origin']);
        static::__assert_equals('app.example.test', $parsed['host']);
        static::__assert_equals('/clients', $parsed['prefix']);
        static::__assert_equals('/clients', $parsed['fixed_prefix']);
        static::__assert_equals('path', $parsed['mode']);
        static::__assert_null($parsed['placeholder']);
        static::__assert_false($parsed['separate_host']);
    }

    public static function test_a_blank_portal_url_in_multi_tenancy_is_the_site_id_path_template()
    {
        static::__assert_equals(
            ['url' => self::APP . '/_portal/:site_id', 'origin' => self::APP, 'host' => 'app.example.test', 'prefix' => '/_portal/:site_id',
                'separate_host' => false, 'mode' => 'path', 'placeholder' => 'site_id', 'host_suffix' => null, 'fixed_prefix' => '/_portal'],
            Rsx_Portal_Url::parse('', self::APP, Rsx_Tenancy::MULTI)
        );
    }

    public static function test_a_path_template_carries_its_placeholder_and_fixed_prefix()
    {
        $parsed = Rsx_Portal_Url::parse('/clients/area/:slug', self::APP, Rsx_Tenancy::MULTI);

        static::__assert_equals('/clients/area/:slug', $parsed['prefix']);
        static::__assert_equals('/clients/area', $parsed['fixed_prefix']);
        static::__assert_equals('slug', $parsed['placeholder']);
        static::__assert_equals('path', $parsed['mode']);
        static::__assert_equals(self::APP, $parsed['origin']);

        $absolute = Rsx_Portal_Url::parse('https://app.example.test/_portal/:site_id/', self::APP, Rsx_Tenancy::MULTI);
        static::__assert_equals('/_portal/:site_id', $absolute['prefix']);
        static::__assert_equals('/_portal', $absolute['fixed_prefix']);
        static::__assert_false($absolute['separate_host']);
    }

    public static function test_a_host_template_carries_its_suffix()
    {
        $parsed = Rsx_Portal_Url::parse('https://:slug.Example.test:8443/', self::APP, Rsx_Tenancy::MULTI);

        static::__assert_equals('host', $parsed['mode']);
        static::__assert_equals('slug', $parsed['placeholder']);
        static::__assert_equals(':slug.example.test', $parsed['host']);
        static::__assert_equals('.example.test', $parsed['host_suffix']);
        static::__assert_equals('https://:slug.example.test:8443', $parsed['origin']);
        static::__assert_equals('', $parsed['prefix']);
        static::__assert_equals('', $parsed['fixed_prefix']);
        static::__assert_true($parsed['separate_host']);

        $site_id = Rsx_Portal_Url::parse('https://:site_id.portals.example.test/x', self::APP, Rsx_Tenancy::MULTI);
        static::__assert_equals('site_id', $site_id['placeholder']);
        static::__assert_equals('.portals.example.test', $site_id['host_suffix']);
        static::__assert_equals('/x', $site_id['fixed_prefix']);

        // Not the first label: no suffix, which check() refuses.
        static::__assert_null(Rsx_Portal_Url::parse('https://portal.:slug.example.test', self::APP, Rsx_Tenancy::MULTI)['host_suffix']);
    }

    // =========================================================================
    // Templates - check() by tenancy
    // =========================================================================

    public static function test_usable_multi_tenant_templates_pass()
    {
        foreach (['', '/_portal/:slug', '/_portal/:site_id', '/clients/area/:slug', self::APP . '/clients/:site_id',
            'https://:slug.example.test', 'https://:site_id.portals.example.test/x'] as $value) {
            static::__assert_null(Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::MULTI), "'{$value}' is usable in multi tenancy");
        }
    }

    /** A tenant's portal is its own address: a path placeholder on a dedicated portal host is refused. */
    public static function test_a_path_placeholder_on_a_portal_host_of_its_own_is_refused()
    {
        foreach (['https://portal.example.test/x/:slug', 'https://portal.example.test/area/:site_id'] as $value) {
            static::__assert_contains('must be on the application\'s own host', (string) Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::MULTI), $value);
        }
    }

    public static function test_single_tenancy_refuses_a_placeholder()
    {
        foreach (['/_portal/:slug', 'https://:slug.example.test', self::APP . '/x/:site_id'] as $value) {
            static::__assert_contains('rsx.tenancy is single', (string) Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::SINGLE), $value);
        }
    }

    public static function test_multi_tenancy_requires_a_placeholder()
    {
        foreach (['/_portal', '/clients', 'https://portal.example.test', self::APP . '/clients'] as $value) {
            static::__assert_contains('rsx.tenancy = multi requires', (string) Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::MULTI), $value);
        }
    }

    public static function test_exactly_one_placeholder()
    {
        foreach (['/_portal/:slug/:site_id', 'https://:slug.example.test/:site_id'] as $value) {
            static::__assert_contains('exactly one tenant placeholder', (string) Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::MULTI), $value);
        }
    }

    public static function test_a_path_placeholder_ends_the_path_after_a_fixed_segment()
    {
        foreach (['/:slug', '/:site_id/', '/_portal/:slug/area', 'https://portal.example.test/:slug'] as $value) {
            static::__assert_contains('must END in /:slug or /:site_id', (string) Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::MULTI), $value);
        }
    }

    public static function test_a_host_placeholder_is_the_whole_first_label()
    {
        foreach (['https://portal.:slug.example.test', 'https://portal-:slug.example.test', 'https://:slug'] as $value) {
            static::__assert_contains('whole first label', (string) Rsx_Portal_Url::check($value, self::APP, false, Rsx_Tenancy::MULTI), $value);
        }
    }

    public static function test_an_unknown_placeholder_is_refused()
    {
        static::__assert_contains('placeholders are :slug and :site_id', (string) Rsx_Portal_Url::check('/_portal/:tenant', self::APP, false, Rsx_Tenancy::MULTI));
        static::__assert_contains('placeholders are :slug and :site_id', (string) Rsx_Portal_Url::check('/_portal/:slugs', self::APP, false, Rsx_Tenancy::SINGLE));
    }

    public static function test_the_existing_rules_apply_to_a_template()
    {
        static::__assert_contains('belongs to the framework', (string) Rsx_Portal_Url::check('/api/:slug', self::APP, false, Rsx_Tenancy::MULTI));
        static::__assert_contains('belongs to the framework', (string) Rsx_Portal_Url::check('/_sys/:slug', self::APP, false, Rsx_Tenancy::MULTI));
        static::__assert_contains('PORTAL_URL path segments', (string) Rsx_Portal_Url::check('/a.b/:slug', self::APP, false, Rsx_Tenancy::MULTI));
        static::__assert_contains('PORTAL_URL must be https outside development', (string) Rsx_Portal_Url::check('http://:slug.example.test', self::APP, false, Rsx_Tenancy::MULTI));
        static::__assert_contains('"http://:slug.example.test"', (string) Rsx_Portal_Url::check('http://:slug.example.test', self::APP, false, Rsx_Tenancy::MULTI), 'the message quotes the template as written');
        static::__assert_contains('may carry only', (string) Rsx_Portal_Url::check('https://:slug.example.test/?a=1', self::APP, false, Rsx_Tenancy::MULTI));
    }

    public static function test_nothing_is_checked_while_the_portal_is_disabled()
    {
        static::__assert_null(Rsx_Portal_Url::check('not a url at all', self::APP, false, Rsx_Tenancy::MULTI, false));
        static::__assert_null(Rsx_Portal_Url::check('/_portal', self::APP, false, Rsx_Tenancy::MULTI, false));
    }

    // =========================================================================
    // The live accessors under each tenancy
    // =========================================================================

    public static function test_single_tenancy_accessors_answer_a_bare_path_layout()
    {
        try {
            config(['rsx.tenancy' => 'single', 'rsx.portal.url' => '/clients']);

            static::__assert_equals(static::__app_origin(), Rsx_Portal_Url::origin());
            static::__assert_equals('/clients', Rsx_Portal_Url::prefix());
            static::__assert_equals(static::__app_origin() . '/clients', Rsx_Portal_Url::url());
            static::__assert_false(Rsx_Portal_Url::is_separate_host());
        } finally {
            config(self::$saved_settings);
        }
    }

    public static function test_the_accessors_answer_for_the_current_site()
    {
        try {
            $site = static::__site(['slug' => 'Acme-' . static::__suffix()]);
            config(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug']);

            Rsx_Portal::set_portal_request(true);
            Portal_Session::set_site_id($site->id);

            static::__assert_equals(static::__app_origin(), Rsx_Portal_Url::origin());
            static::__assert_equals('/_portal/' . strtolower($site->slug), Rsx_Portal_Url::prefix(), 'the slug, lowercased');
            static::__assert_equals(static::__app_origin() . '/_portal/' . strtolower($site->slug), Rsx_Portal_Url::url());
            static::__assert_false(Rsx_Portal_Url::is_separate_host());

            // A current site with no portal: the accessors refuse, for_site() answers false.
            Portal_Session::set_site_id(static::__site(['is_portal_enabled' => 0])->id);
            static::__assert_false(Rsx_Portal_Url::for_site());
            static::__assert_throws(\RuntimeException::class, fn () => Rsx_Portal_Url::prefix(), 'has none');
        } finally {
            config(self::$saved_settings);
            config(['rsx.portal.url' => self::$saved_portal_url]);
            Rsx_Request_Channel::reset();
            Portal_Session::_testing_reset();
        }
    }

    // =========================================================================
    // for_site() - one site's portal address
    // =========================================================================

    /**
     * A fixture site, saved through the model so the resolver's cached lookups follow it.
     */
    private static function __site(array $fields = []): Site_Model
    {
        $site = new Site_Model();
        $site->slug = $fields['slug'] ?? 'urltest-' . static::__suffix();
        $site->name = 'Portal Url Test';

        foreach ($fields as $field => $value) {
            if ($field !== 'slug') {
                $site->$field = $value;
            }
        }

        $site->save();

        return $site;
    }

    private static function __suffix(): string
    {
        return strtolower(substr(uniqid(), -8));
    }

    /**
     * Run $fn with the portal settings given, restoring the live ones afterwards.
     */
    private static function __with(array $settings, callable $fn): void
    {
        try {
            config(array_merge([
                'rsx.tenancy' => 'multi',
                'rsx.portal.url' => '',
                'rsx.portal.enabled' => true,
                'rsx.portal.custom_domains' => 'off',
            ], $settings));

            $fn();
        } finally {
            config(self::$saved_settings);
            config(['rsx.portal.url' => self::$saved_portal_url]);
            Rsx_Request_Channel::reset();
            Portal_Session::_testing_reset();
        }
    }

    public static function test_for_site_in_single_tenancy_is_the_template_for_site_one()
    {
        static::__with(['rsx.tenancy' => 'single', 'rsx.portal.url' => ''], function () {
            $expected = [
                'origin' => static::__app_origin(),
                'prefix' => '/_portal',
                'host' => strtolower((string) parse_url(static::__app_origin(), PHP_URL_HOST)),
                'separate_host' => false,
            ];

            static::__assert_equals($expected, Rsx_Portal_Url::for_site());
            static::__assert_equals($expected, Rsx_Portal_Url::for_site(Rsx_Tenancy::SINGLE_TENANT_SITE_ID));
            static::__assert_false(Rsx_Portal_Url::for_site(static::__site()->id), 'a single-tenant install has one tenant');

            config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/x']);
            static::__assert_equals(
                ['origin' => 'https://' . self::PORTAL_HOST, 'prefix' => '/x', 'host' => self::PORTAL_HOST, 'separate_host' => true],
                Rsx_Portal_Url::for_site()
            );
        });
    }

    public static function test_for_site_fills_a_path_template_with_the_slug_or_the_id()
    {
        static::__with(['rsx.portal.url' => '/_portal/:slug'], function () {
            $site = static::__site(['slug' => 'Mixed-' . static::__suffix()]);

            static::__assert_equals(
                ['origin' => static::__app_origin(), 'prefix' => '/_portal/' . strtolower($site->slug),
                    'host' => strtolower((string) parse_url(static::__app_origin(), PHP_URL_HOST)), 'separate_host' => false],
                Rsx_Portal_Url::for_site($site->id)
            );

            config(['rsx.portal.url' => '']);
            static::__assert_equals('/_portal/' . $site->id, Rsx_Portal_Url::for_site($site->id)['prefix'], 'blank is /_portal/:site_id');

            // A slug that cannot be a path segment names no address.
            $odd = static::__site(['slug' => 'has space ' . static::__suffix()]);
            config(['rsx.portal.url' => '/_portal/:slug']);
            static::__assert_false(Rsx_Portal_Url::for_site($odd->id));
        });
    }

    public static function test_for_site_fills_a_host_template()
    {
        static::__with(['rsx.portal.url' => 'https://:slug.portals.example.test/x'], function () {
            $site = static::__site();

            static::__assert_equals(
                ['origin' => 'https://' . $site->slug . '.portals.example.test', 'prefix' => '/x',
                    'host' => $site->slug . '.portals.example.test', 'separate_host' => true],
                Rsx_Portal_Url::for_site($site->id)
            );

            // A slug that cannot be a DNS label names no address.
            static::__assert_false(Rsx_Portal_Url::for_site(static::__site(['slug' => 'under_score_' . static::__suffix()])->id));
        });
    }

    /** APP_URL's own host is never a tenant: a site whose slug lands on it has no portal address. */
    public static function test_for_site_answers_false_for_a_slug_that_lands_on_the_app_host()
    {
        $app_host = Rsx_Portal_Url::parse('', (string) config('app.url'))['host'];
        $label = explode('.', $app_host, 2)[0];
        $suffix = substr($app_host, strlen($label));

        static::__with(['rsx.portal.url' => 'https://:slug' . $suffix], function () use ($label) {
            $existing = \App\RSpade\Core\Models\Site_Model::where('slug', $label)->first();
            $site = $existing ?? static::__site(['slug' => $label]);

            static::__assert_false(Rsx_Portal_Url::for_site($site->id), 'the slug "' . $label . '" names the application host');
        });
    }

    public static function test_for_site_honours_a_custom_domain_per_mode()
    {
        static::__with(['rsx.portal.url' => '/_portal/:slug'], function () {
            $domain = 'portal-' . static::__suffix() . '.customer.test';
            $with = static::__site(['portal_domain' => $domain]);
            $without = static::__site();

            $custom = ['origin' => 'https://' . $domain, 'prefix' => '', 'host' => $domain, 'separate_host' => true];

            config(['rsx.portal.custom_domains' => 'off']);
            static::__assert_equals('/_portal/' . $with->slug, Rsx_Portal_Url::for_site($with->id)['prefix'], 'off: the domain is ignored');

            config(['rsx.portal.custom_domains' => 'override']);
            static::__assert_equals($custom, Rsx_Portal_Url::for_site($with->id), 'override: the domain');
            static::__assert_equals('/_portal/' . $without->slug, Rsx_Portal_Url::for_site($without->id)['prefix'], 'override: the template without one');

            config(['rsx.portal.custom_domains' => 'only']);
            static::__assert_equals($custom, Rsx_Portal_Url::for_site($with->id), 'only: the domain');
            static::__assert_false(Rsx_Portal_Url::for_site($without->id), 'only: no domain, no portal');
        });
    }

    public static function test_for_site_answers_false_when_there_is_no_portal()
    {
        static::__with(['rsx.portal.url' => '/_portal/:slug'], function () {
            static::__assert_false(Rsx_Portal_Url::for_site(0), 'site 0');
            static::__assert_false(Rsx_Portal_Url::for_site(987654321), 'no such site');
            static::__assert_false(Rsx_Portal_Url::for_site(static::__site(['is_enabled' => 0])->id), 'a disabled site');
            static::__assert_false(Rsx_Portal_Url::for_site(static::__site(['is_portal_enabled' => 0])->id), 'portal switched off');

            $deleted = static::__site();
            $deleted->delete();
            static::__assert_false(Rsx_Portal_Url::for_site($deleted->id), 'a deleted site');

            $live = static::__site();
            config(['rsx.portal.enabled' => false]);
            static::__assert_false(Rsx_Portal_Url::for_site($live->id), 'PORTAL_ENABLED=false');
            config(['rsx.portal.enabled' => true]);

            // No site named, none current (a command with no site).
            static::__acting_as_site(0);
            static::__assert_null(Rsx_Portal_Url::current_site_id());
            static::__assert_false(Rsx_Portal_Url::for_site());

            // The staff side's current site is the session's.
            static::__acting_as_site($live->id);
            static::__assert_equals($live->id, Rsx_Portal_Url::current_site_id());
            static::__assert_equals('/_portal/' . $live->slug, Rsx_Portal_Url::for_site()['prefix']);
        });
    }

    public static function test_route_takes_a_named_site_and_answers_false_without_a_portal()
    {
        static::__with(['rsx.portal.url' => '/_portal/:slug'], function () {
            $a = static::__site();
            $b = static::__site();
            $off = static::__site(['is_portal_enabled' => 0]);

            app()->instance('request', Request::create(static::__app_origin() . '/clients'));
            static::__acting_as_site($a->id);

            $params = ['id' => 5, 'id_type' => 7];
            static::__assert_equals('/_portal/' . $a->slug . '/test-route-parity/5/7', Rsx_Portal::Route(self::PORTAL_TARGET, $params), 'the current site');
            static::__assert_equals('/_portal/' . $b->slug . '/test-route-parity/5/7', Rsx_Portal::Route(self::PORTAL_TARGET, $params, site_id: $b->id), 'a named site');
            static::__assert_false(Rsx_Portal::Route(self::PORTAL_TARGET, $params, site_id: $off->id), 'a site without a portal');
            static::__assert_equals('/_portal/' . $b->slug . '/login', Rsx_Portal::portal_path('/login', site_id: $b->id));
            static::__assert_false(Rsx_Portal::portal_path('/login', site_id: $off->id));
            static::__assert_false(Rsx_Portal::is_on_portal_host($off->id));

            // Host mode: another tenant's host is absolute from the staff side.
            config(['rsx.portal.url' => 'https://:slug.portals.example.test']);
            static::__assert_equals('https://' . $b->slug . '.portals.example.test/login', Rsx_Portal::portal_path('/login', site_id: $b->id));

            // Inside a portal request of site A, its own portal is the request's base.
            config(['rsx.portal.url' => '/_portal/:slug']);
            Rsx_Request_Channel::classify(Request::create(static::__app_origin() . '/_portal/' . $a->slug . '/dashboard'));
            static::__assert_equals('/_portal/' . $a->slug . '/login', Rsx_Portal::portal_path('/login'));
            static::__assert_equals('/_portal/' . $b->slug . '/login', Rsx_Portal::portal_path('/login', site_id: $b->id));
        });
    }

    public static function test_the_client_payload_carries_the_current_sites_base()
    {
        static::__with(['rsx.portal.url' => '/_portal/:slug'], function () {
            $site = static::__site();
            $off = static::__site(['is_portal_enabled' => 0]);
            $origin = static::__app_origin();

            static::__acting_as_site($site->id);
            static::__assert_equals(
                ['origin' => $origin, 'prefix' => '/_portal/' . $site->slug, 'separate_host' => false,
                    'base' => $origin . '/_portal/' . $site->slug, 'app_host_prefix' => '/_portal'],
                Rsx_Portal_Url::client_payload()
            );

            static::__acting_as_site($off->id);
            static::__assert_equals(
                ['origin' => null, 'prefix' => null, 'separate_host' => false, 'base' => null, 'app_host_prefix' => '/_portal'],
                Rsx_Portal_Url::client_payload(),
                'a staff page whose site has no portal'
            );

            config(['rsx.portal.url' => 'https://:slug.portals.example.test']);
            static::__acting_as_site($site->id);
            static::__assert_null(Rsx_Portal_Url::client_payload()['app_host_prefix'], 'a host template claims nothing on the application host');
        });
    }

    // =========================================================================
    // The switches
    // =========================================================================

    public static function test_the_tenancy_switch_takes_single_or_multi_and_nothing_else()
    {
        try {
            config(['rsx.tenancy' => 'single']);
            static::__assert_equals('single', Rsx_Tenancy::mode());
            static::__assert_false(Rsx_Tenancy::is_multi());

            config(['rsx.tenancy' => 'multi']);
            static::__assert_true(Rsx_Tenancy::is_multi());

            config(['rsx.tenancy' => '']);
            static::__assert_equals('single', Rsx_Tenancy::mode(), 'blank is the default');

            foreach (['Multi', 'mutli', 'true', 1] as $value) {
                config(['rsx.tenancy' => $value]);
                static::__assert_throws(\RuntimeException::class, fn () => Rsx_Tenancy::mode(), 'rsx.tenancy config value must be');
            }
        } finally {
            config(self::$saved_settings);
        }
    }

    public static function test_the_portal_enabled_switch_is_a_boolean()
    {
        try {
            foreach ([[true, true], [false, false], ['true', true], ['false', false], ['1', true], ['0', false], ['', true], [null, true]] as [$value, $expected]) {
                config(['rsx.portal.enabled' => $value]);
                static::__assert_equals($expected, Rsx_Portal_Url::is_enabled(), var_export($value, true));
            }

            config(['rsx.portal.enabled' => 'maybe']);
            static::__assert_throws(\RuntimeException::class, fn () => Rsx_Portal_Url::is_enabled(), 'PORTAL_ENABLED must be');
        } finally {
            config(self::$saved_settings);
        }
    }

    public static function test_the_custom_domains_switch_takes_off_override_or_only()
    {
        try {
            foreach (['off', 'override', 'only'] as $value) {
                config(['rsx.portal.custom_domains' => $value]);
                static::__assert_equals($value, Rsx_Portal_Url::custom_domains());
            }

            config(['rsx.portal.custom_domains' => '']);
            static::__assert_equals('off', Rsx_Portal_Url::custom_domains(), 'blank is the default');

            foreach (['on', 'Override', 'true'] as $value) {
                config(['rsx.portal.custom_domains' => $value]);
                static::__assert_throws(\RuntimeException::class, fn () => Rsx_Portal_Url::custom_domains(), 'PORTAL_CUSTOM_DOMAINS must be');
            }
        } finally {
            config(self::$saved_settings);
        }
    }

    public static function test_the_boot_check_refuses_each_bad_switch()
    {
        Rsx_Tenancy::enforce_settings();

        foreach (['rsx.tenancy' => 'several', 'rsx.portal.enabled' => 'perhaps', 'rsx.portal.custom_domains' => 'yes'] as $key => $value) {
            $saved = config($key);
            config([$key => $value]);
            static::__assert_throws(\RuntimeException::class, fn () => Rsx_Tenancy::enforce_settings(), 'must be');
            config([$key => $saved]);
        }
    }

    public static function test_the_health_row_reports_the_derivation_and_the_refusal()
    {
        try {
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

            config(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug']);
            $row = Rsx_Portal_Url::health_row();
            static::__assert_equals('OK', $row['status']);
            static::__assert_contains('prefix /_portal/:slug', $row['detail']);
            static::__assert_contains('(multi tenancy, path mode)', $row['detail']);

            config(['rsx.portal.url' => '/_portal']);
            $row = Rsx_Portal_Url::health_row();
            static::__assert_equals('FAIL', $row['status']);
            static::__assert_contains('rsx.tenancy = multi requires', $row['detail']);

            config(['rsx.portal.enabled' => false]);
            $row = Rsx_Portal_Url::health_row();
            static::__assert_equals('OK', $row['status']);
            static::__assert_contains('disabled', $row['detail']);
        } finally {
            config(self::$saved_settings);
        }
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
