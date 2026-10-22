<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Request_Channel::classify() - the one decision about what kind of request this is.
 *
 * A synthetic request in, a channel and a realm out - plus, for a portal request, the
 * site its URL resolved to (Portal_Site_Resolver). The portal's address is driven through
 * config overrides: the default prefix on the application host, a host of its own, and the
 * multi-tenant templates (per-site path, tenant subdomain, a custom domain's 301). The full
 * resolution matrix is Portal_Site_Resolver_Test's, the PORTAL_URL layout matrix
 * Portal_Url_Test's. Fixture sites live inside the test's transaction.
 */
class Request_Channel_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const PORTAL_HOST = 'portal.request-channel-test.invalid';

    public static function teardown()
    {
        Rsx_Request_Channel::reset();
    }

    /**
     * Classify one request and return [channel, realm, portal_host].
     */
    private static function __classify(string $url, string $method = 'GET'): array
    {
        $channel = Rsx_Request_Channel::classify(Request::create($url, $method));

        return [$channel, Rsx_Request_Channel::realm(), Rsx_Request_Channel::is_portal_host()];
    }

    /**
     * Run $fn with the portal at the root of a host of its own.
     */
    private static function __with_portal_host(callable $fn): void
    {
        $original = config('rsx.portal.url');
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);

        try {
            $fn();
        } finally {
            config(['rsx.portal.url' => $original]);
        }
    }

    /**
     * Run $fn with the given config values, restoring every one afterwards.
     */
    private static function __with_settings(array $settings, callable $fn): void
    {
        $saved = [];
        foreach (array_keys($settings) as $key) {
            $saved[$key] = config($key);
        }

        config($settings);

        try {
            $fn();
        } finally {
            config($saved);
        }
    }

    /**
     * A fixture site, saved through the model (the resolver's cache hooks run).
     */
    private static function __fixture_site(array $fields = []): Site_Model
    {
        $site = new Site_Model();
        $site->slug = 'channel-' . strtolower(substr(uniqid(), -8));
        $site->name = 'Request Channel Test';

        foreach ($fields as $field => $value) {
            $site->$field = $value;
        }

        $site->save();

        return $site;
    }

    public static function test_the_staff_host_channels()
    {
        static::__assert_equals(['asset', 'staff', false], static::__classify('/_compiled/Frontend_Bundle__app.0123abcd.js'));
        static::__assert_equals(['asset', 'staff', false], static::__classify('/_vendor/0123456789abcdef0123456789abcdef_x.woff2'));
        static::__assert_equals(['api', 'staff', false], static::__classify('/api/v1/me'));
        static::__assert_equals(['ajax', 'staff', false], static::__classify('/_ajax/Some_Controller/action', 'POST'));
        static::__assert_equals(['page', 'staff', false], static::__classify('/clients'));
    }

    /**
     * A name the artifact store could never have produced is not an artifact: it is an
     * ordinary page path (and a 404 page).
     */
    public static function test_a_malformed_artifact_name_is_a_page()
    {
        static::__assert_equals('page', static::__classify('/_compiled/../../.env')[0]);
        static::__assert_equals('page', static::__classify('/_vendor/bootstrap.css')[0]);
    }

    /**
     * Ajax is a POST transport; a GET under /_ajax/ is a page request (it 404s).
     */
    public static function test_a_get_under_ajax_is_a_page()
    {
        static::__assert_equals('page', static::__classify('/_ajax/Some_Controller/action')[0]);
    }

    public static function test_the_portal_prefix_is_the_portal_realm()
    {
        $prefix = Rsx_Portal_Url::prefix();

        static::__assert_equals(['page', 'portal', false], static::__classify($prefix));
        static::__assert_equals(['page', 'portal', false], static::__classify($prefix . '/dashboard'));
        static::__assert_equals(['ajax', 'portal', false], static::__classify($prefix . '/_ajax/Some_Controller/action', 'POST'));
        static::__assert_true(Rsx_Portal::is_portal_request(), 'the portal predicate reads the classification');

        // A build artifact under the prefix is the same file as without it.
        static::__assert_equals(['asset', 'portal', false], static::__classify($prefix . '/_compiled/Portal_Bundle__app.0123abcd.js'));
        static::__assert_equals('/_compiled/Portal_Bundle__app.0123abcd.js', Rsx_Request_Channel::realm_path());

        // A path that merely starts with the same letters is not under the prefix.
        static::__assert_equals(['page', 'staff', false], static::__classify($prefix . 'x/dashboard'));
        static::__assert_false(Rsx_Portal::is_portal_request());

        // Under a path prefix, /api/ on the staff host is the API; the prefixed spelling
        // is a portal page like any other.
        static::__assert_equals(['api', 'staff', false], static::__classify('/api/v1/me'));
        static::__assert_equals(['page', 'portal', false], static::__classify($prefix . '/api/v1/me'));
    }

    public static function test_a_portal_host_of_its_own_is_the_portal_realm()
    {
        static::__with_portal_host(function () {
            $base = 'https://' . self::PORTAL_HOST;

            static::__assert_equals(['page', 'portal', true], static::__classify($base . '/dashboard'));
            static::__assert_equals(['ajax', 'portal', true], static::__classify($base . '/_ajax/Some_Controller/action', 'POST'));
            static::__assert_equals(['asset', 'portal', true], static::__classify($base . '/_compiled/Portal_Bundle__app.0123abcd.js'));

            // The API is classified as the API everywhere - a staff identity - and the
            // portal-host fact is what makes it refuse there.
            static::__assert_equals(['api', 'staff', true], static::__classify($base . '/api/v1/me'));

            // The staff host is not the portal, and the default prefix means nothing there
            // once the portal has a host of its own.
            static::__assert_equals(['page', 'staff', false], static::__classify('http://localhost/dashboard'));
            static::__assert_equals(['page', 'staff', false], static::__classify('http://localhost' . Rsx_Portal_Url::DEFAULT_PREFIX . '/dashboard'));
        });
    }

    public static function test_a_single_tenant_portal_request_serves_site_one()
    {
        static::__classify(Rsx_Portal_Url::prefix() . '/login');

        static::__assert_equals(Rsx_Tenancy::SINGLE_TENANT_SITE_ID, Rsx_Request_Channel::portal_site_id());
        static::__assert_equals(Rsx_Portal_Url::prefix(), Rsx_Request_Channel::portal_base());
        static::__assert_false(Rsx_Request_Channel::portal_not_served());
        static::__assert_null(Rsx_Request_Channel::portal_redirect_to());

        static::__classify('/dashboard');
        static::__assert_null(Rsx_Request_Channel::portal_site_id(), 'a staff request names no portal site');
    }

    /**
     * Multi tenancy: the base is per site, and classification never asks the single-valued
     * accessors (which throw there).
     */
    public static function test_a_multi_tenant_path_template_classifies_per_site()
    {
        $site = static::__fixture_site();

        static::__with_settings(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug'], function () use ($site) {
            $base = '/_portal/' . $site->slug;

            static::__assert_equals(['page', 'portal', false], static::__classify($base . '/login'));
            static::__assert_equals('/login', Rsx_Request_Channel::realm_path());
            static::__assert_equals($base, Rsx_Request_Channel::portal_base());
            static::__assert_equals((int) $site->id, Rsx_Request_Channel::portal_site_id());

            static::__assert_equals(['ajax', 'portal', false], static::__classify($base . '/_ajax/Some_Controller/action', 'POST'));
            static::__assert_equals('/_ajax/Some_Controller/action', Rsx_Request_Channel::realm_path());

            static::__assert_equals(['page', 'portal', false], static::__classify('/_portal/no-such-tenant/login'));
            static::__assert_true(Rsx_Request_Channel::portal_not_served(), 'an unknown slug is the portal\'s 404');
            static::__assert_null(Rsx_Request_Channel::portal_site_id());

            static::__assert_equals(['page', 'staff', false], static::__classify('/dashboard'));
            static::__assert_equals(['api', 'staff', false], static::__classify('/api/v1/me'));
        });
    }

    public static function test_a_tenant_subdomain_is_a_portal_host()
    {
        $site = static::__fixture_site();

        static::__with_settings([
            'app.url' => 'https://app.request-channel-test.invalid',
            'rsx.tenancy' => 'multi',
            'rsx.portal.url' => 'https://:slug.request-channel-test.invalid',
        ], function () use ($site) {
            $origin = 'https://' . $site->slug . '.request-channel-test.invalid';

            static::__assert_equals(['page', 'portal', true], static::__classify($origin . '/login'));
            static::__assert_equals('', Rsx_Request_Channel::portal_base());
            static::__assert_equals((int) $site->id, Rsx_Request_Channel::portal_site_id());

            static::__assert_equals(['api', 'staff', true], static::__classify($origin . '/api/v1/me'), 'the API refuses on a portal host');
            static::__assert_equals(['page', 'staff', false], static::__classify('https://app.request-channel-test.invalid/login'), 'the APP host wins');
        });
    }

    public static function test_an_override_custom_domain_redirects_the_template_address()
    {
        $domain = 'cd-' . strtolower(substr(uniqid(), -8)) . '.request-channel-test.invalid';
        $site = static::__fixture_site(['portal_domain' => $domain]);

        static::__with_settings([
            'rsx.tenancy' => 'multi',
            'rsx.portal.url' => '/_portal/:slug',
            'rsx.portal.custom_domains' => 'override',
        ], function () use ($site, $domain) {
            static::__classify('/_portal/' . $site->slug . '/projects?tab=files');
            static::__assert_equals('https://' . $domain . '/projects?tab=files', Rsx_Request_Channel::portal_redirect_to());
            static::__assert_null(Rsx_Request_Channel::portal_site_id());

            static::__assert_equals(['page', 'portal', true], static::__classify('https://' . $domain . '/projects'));
            static::__assert_equals((int) $site->id, Rsx_Request_Channel::portal_site_id());
            static::__assert_null(Rsx_Request_Channel::portal_redirect_to());
        });
    }

    public static function test_a_disabled_portal_classifies_nothing_as_the_portal()
    {
        static::__with_settings(['rsx.portal.enabled' => false], function () {
            static::__assert_equals(['page', 'staff', false], static::__classify(Rsx_Portal_Url::DEFAULT_PREFIX . '/login'));
        });
    }

    /**
     * Outside a request, nothing classified: a staff page, and set_portal_request() is the
     * seam that declares otherwise.
     */
    public static function test_outside_a_request_the_answer_is_staff_until_declared()
    {
        Rsx_Request_Channel::reset();
        static::__assert_equals('page', Rsx_Request_Channel::current());
        static::__assert_false(Rsx_Portal::is_portal_request());

        Rsx_Portal::set_portal_request(true);
        static::__assert_true(Rsx_Portal::is_portal_request());

        Rsx_Portal::set_portal_request(false);
        static::__assert_false(Rsx_Portal::is_portal_request());
    }
}
