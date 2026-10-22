<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Site_Resolver;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Portal_Site_Resolver - which site a portal request serves, read from its URL.
 *
 * Every layout is driven through config overrides (RSX_TENANCY, PORTAL_URL,
 * PORTAL_CUSTOM_DOMAINS, PORTAL_ENABLED, APP_URL) with synthetic hosts and paths, against
 * fixture sites created inside the test's transaction (the rollback also clears the
 * resolver's cache scope). Each test restores the configuration it changed in a finally:
 * setup() and teardown() run once per class.
 */
class Portal_Site_Resolver_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const APP = 'https://app.resolver-test.invalid';

    private const APP_HOST = 'app.resolver-test.invalid';

    /**
     * Run $fn with the portal settings given (anything not given at its single-tenant,
     * blank-PORTAL_URL default), restoring the live configuration afterwards.
     */
    private static function __with(array $settings, callable $fn): void
    {
        $keys = ['app.url', 'rsx.tenancy', 'rsx.portal.url', 'rsx.portal.enabled', 'rsx.portal.custom_domains'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = config($key);
        }

        config(array_merge([
            'app.url' => self::APP,
            'rsx.tenancy' => 'single',
            'rsx.portal.url' => '',
            'rsx.portal.enabled' => true,
            'rsx.portal.custom_domains' => 'off',
        ], $settings));

        try {
            $fn();
        } finally {
            config($saved);
        }
    }

    /**
     * A fixture site. Saved through the model, so the resolver's cache hooks run.
     */
    private static function __site(array $fields = []): Site_Model
    {
        $site = new Site_Model();
        $site->slug = $fields['slug'] ?? 'resolver-' . strtolower(substr(uniqid(), -8));
        $site->name = 'Resolver Test';

        foreach ($fields as $field => $value) {
            if ($field !== 'slug') {
                $site->$field = $value;
            }
        }

        $site->save();

        return $site;
    }

    /**
     * The fields of a resolve() answer a test compares.
     */
    private static function __brief(?array $answer): ?array
    {
        if ($answer === null) {
            return null;
        }

        return [
            'site_id' => $answer['site_id'],
            'base_path' => $answer['base_path'],
            'realm_path' => $answer['realm_path'],
            'redirect_to' => $answer['redirect_to'],
            'not_served' => $answer['not_served'],
        ];
    }

    private static function __served(int $site_id, string $base, string $realm_path): array
    {
        return ['site_id' => $site_id, 'base_path' => $base, 'realm_path' => $realm_path, 'redirect_to' => null, 'not_served' => false];
    }

    private static function __not_served(string $base, string $realm_path): array
    {
        return ['site_id' => null, 'base_path' => $base, 'realm_path' => $realm_path, 'redirect_to' => null, 'not_served' => true];
    }

    // =========================================================================
    // Single tenancy
    // =========================================================================

    public static function test_single_tenancy_serves_site_one_under_the_prefix_on_any_host()
    {
        static::__with([], function () {
            $one = Rsx_Tenancy::SINGLE_TENANT_SITE_ID;

            static::__assert_equals(static::__served($one, '/_portal', '/login'), static::__brief(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/login')));
            static::__assert_equals(static::__served($one, '/_portal', '/'), static::__brief(Portal_Site_Resolver::resolve('localhost', '/_portal')));
            static::__assert_null(Portal_Site_Resolver::resolve(self::APP_HOST, '/dashboard'), 'a staff path');
            static::__assert_null(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portalx'), 'a segment boundary');

            $match = Portal_Site_Resolver::match(self::APP_HOST, '/_portal/login');
            static::__assert_false($match['portal_host'], 'a prefix on the application host is not a portal host');
        });
    }

    public static function test_single_tenancy_on_a_host_of_its_own_claims_the_whole_host()
    {
        static::__with(['rsx.portal.url' => 'https://portal.resolver-test.invalid/x'], function () {
            $one = Rsx_Tenancy::SINGLE_TENANT_SITE_ID;

            static::__assert_equals(static::__served($one, '/x', '/dash'), static::__brief(Portal_Site_Resolver::resolve('portal.resolver-test.invalid', '/x/dash')));

            // Outside the prefix: still the portal (its 404), and still site 1.
            $outside = Portal_Site_Resolver::resolve('portal.resolver-test.invalid', '/dash');
            static::__assert_equals(static::__served($one, '/x', '/dash'), static::__brief($outside));
            static::__assert_false($outside['under_base']);
            static::__assert_true($outside['portal_host']);

            static::__assert_null(Portal_Site_Resolver::resolve(self::APP_HOST, '/x/dash'), 'the prefix means nothing on the application host');
        });
    }

    // =========================================================================
    // Multi tenancy, path templates
    // =========================================================================

    public static function test_multi_path_by_slug()
    {
        $site = static::__site();

        static::__with(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug'], function () use ($site) {
            $base = '/_portal/' . $site->slug;

            static::__assert_equals(static::__served($site->id, $base, '/login'), static::__brief(Portal_Site_Resolver::resolve(self::APP_HOST, $base . '/login')));
            static::__assert_equals(static::__served($site->id, $base, '/'), static::__brief(Portal_Site_Resolver::resolve(self::APP_HOST, $base)));

            $upper = '/_portal/' . strtoupper($site->slug);
            static::__assert_equals($site->id, Portal_Site_Resolver::resolve(self::APP_HOST, $upper . '/login')['site_id'], 'a slug compares lowercased');

            static::__assert_equals(static::__not_served('/_portal/no-such-tenant', '/login'), static::__brief(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/no-such-tenant/login')));
            static::__assert_equals(static::__not_served('/_portal', '/'), static::__brief(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal')), 'the fixed prefix alone names no tenant');
            static::__assert_null(Portal_Site_Resolver::resolve(self::APP_HOST, '/clients'));
        });
    }

    public static function test_multi_path_by_site_id_is_the_blank_default()
    {
        $site = static::__site();

        static::__with(['rsx.tenancy' => 'multi'], function () use ($site) {
            $base = '/_portal/' . $site->id;

            static::__assert_equals(static::__served($site->id, $base, '/x'), static::__brief(Portal_Site_Resolver::resolve(self::APP_HOST, $base . '/x')));
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/0' . $site->id . '/x')['not_served'], 'an id is canonical only');
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->id . 'a/x')['not_served']);
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/0/x')['not_served'], 'site 0 is never a tenant');
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/987654321/x')['not_served'], 'an unknown id');
        });
    }

    public static function test_site_zero_is_never_served_by_slug_either()
    {
        static::__with(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug'], function () {
            $slug = (string) Site_Model::withTrashed()->where('id', 0)->value('slug');

            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $slug . '/login')['not_served']);
        });
    }

    // =========================================================================
    // Multi tenancy, host template
    // =========================================================================

    public static function test_multi_subdomain_by_slug()
    {
        $site = static::__site();

        static::__with([
            'app.url' => 'https://app.portals.resolver-test.invalid',
            'rsx.tenancy' => 'multi',
            'rsx.portal.url' => 'https://:slug.portals.resolver-test.invalid',
        ], function () use ($site) {
            $host = $site->slug . '.portals.resolver-test.invalid';

            $answer = Portal_Site_Resolver::resolve($host, '/login');
            static::__assert_equals(static::__served($site->id, '', '/login'), static::__brief($answer));
            static::__assert_true($answer['portal_host']);

            static::__assert_true(Portal_Site_Resolver::resolve('unknown.portals.resolver-test.invalid', '/')['not_served']);
            static::__assert_null(Portal_Site_Resolver::resolve('a.' . $host, '/'), 'one label only');
            static::__assert_null(Portal_Site_Resolver::resolve('portals.resolver-test.invalid', '/'), 'the bare suffix');
            static::__assert_null(Portal_Site_Resolver::resolve('elsewhere.invalid', '/'));

            // The application host fits the pattern and is still staff.
            static::__assert_null(Portal_Site_Resolver::resolve('app.portals.resolver-test.invalid', '/login'), 'the APP host wins');
        });
    }

    // =========================================================================
    // Custom domains
    // =========================================================================

    public static function test_override_redirects_a_template_address_to_the_custom_domain()
    {
        $domain = 'jobs-' . strtolower(substr(uniqid(), -8)) . '.resolver-test.invalid';
        $site = static::__site(['portal_domain' => $domain]);
        $plain = static::__site();

        static::__with([
            'rsx.tenancy' => 'multi',
            'rsx.portal.url' => '/_portal/:slug',
            'rsx.portal.custom_domains' => 'override',
        ], function () use ($site, $plain, $domain) {
            $answer = Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug . '/projects/5', 'tab=files&x=1');
            static::__assert_equals('https://' . $domain . '/projects/5?tab=files&x=1', $answer['redirect_to']);
            static::__assert_null($answer['site_id']);
            static::__assert_false($answer['not_served']);

            static::__assert_equals('https://' . $domain . '/', Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug)['redirect_to'], 'the base itself');

            // The domain itself serves the site at its root.
            $on_domain = Portal_Site_Resolver::resolve($domain, '/projects/5');
            static::__assert_equals(static::__served($site->id, '', '/projects/5'), static::__brief($on_domain));
            static::__assert_true($on_domain['portal_host']);

            // A site without a domain keeps its template address.
            static::__assert_equals($plain->id, Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $plain->slug . '/x')['site_id']);
        });
    }

    public static function test_only_serves_the_custom_domain_and_nothing_else()
    {
        $domain = 'only-' . strtolower(substr(uniqid(), -8)) . '.resolver-test.invalid';
        $site = static::__site(['portal_domain' => $domain]);

        static::__with([
            'rsx.tenancy' => 'multi',
            'rsx.portal.url' => '/_portal/:slug',
            'rsx.portal.custom_domains' => 'only',
        ], function () use ($site, $domain) {
            static::__assert_equals(static::__served($site->id, '', '/x'), static::__brief(Portal_Site_Resolver::resolve($domain, '/x')));
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug . '/x')['not_served'], 'the template address is not served');
        });
    }

    public static function test_off_ignores_portal_domains()
    {
        $domain = 'off-' . strtolower(substr(uniqid(), -8)) . '.resolver-test.invalid';
        static::__site(['portal_domain' => $domain]);

        static::__with(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug'], function () use ($domain) {
            static::__assert_null(Portal_Site_Resolver::resolve($domain, '/x'));
            static::__assert_false(Portal_Site_Resolver::is_portal_domain($domain));
        });
    }

    public static function test_single_tenancy_serves_only_site_one_at_a_custom_domain()
    {
        $domain = 'single-' . strtolower(substr(uniqid(), -8)) . '.resolver-test.invalid';
        $other = static::__site(['portal_domain' => $domain]);

        static::__with(['rsx.portal.custom_domains' => 'override'], function () use ($domain, $other) {
            static::__assert_true(Portal_Site_Resolver::resolve($domain, '/x')['not_served'], 'site ' . $other->id . ' is not this install\'s tenant');
        });
    }

    // =========================================================================
    // The site's own switches
    // =========================================================================

    public static function test_a_disabled_deleted_or_portal_off_site_is_not_served()
    {
        $disabled = static::__site(['is_enabled' => false]);
        $portal_off = static::__site(['is_portal_enabled' => false]);
        $deleted = static::__site();
        $deleted->delete();

        static::__with(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug'], function () use ($disabled, $portal_off, $deleted) {
            foreach ([$disabled, $portal_off, $deleted] as $site) {
                $answer = Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug . '/login');
                static::__assert_equals(static::__not_served('/_portal/' . $site->slug, '/login'), static::__brief($answer), $site->slug);
            }
        });
    }

    public static function test_a_change_to_the_site_reaches_the_cached_lookups()
    {
        $site = static::__site();

        static::__with(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug'], function () use ($site) {
            $old_slug = $site->slug;
            static::__assert_equals($site->id, Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $old_slug)['site_id'], 'warms the cache');

            $site->is_portal_enabled = false;
            $site->save();
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $old_slug)['not_served'], 'switched off');

            $site->is_portal_enabled = true;
            $site->slug = $old_slug . '-renamed';
            $site->save();
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $old_slug)['not_served'], 'the old slug names nothing');
            static::__assert_equals($site->id, Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug)['site_id'], 'the new slug');

            $site->delete();
            static::__assert_true(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug)['not_served'], 'deleted');

            $site->restore();
            static::__assert_equals($site->id, Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/' . $site->slug)['site_id'], 'restored');
        });
    }

    public static function test_a_disabled_portal_makes_nothing_a_portal_request()
    {
        static::__with(['rsx.portal.enabled' => false], function () {
            static::__assert_null(Portal_Site_Resolver::resolve(self::APP_HOST, '/_portal/login'));
            static::__assert_false(Portal_Site_Resolver::needs_host());
        });
    }

    // =========================================================================
    // The host guards' half
    // =========================================================================

    public static function test_host_patterns_name_every_host_the_template_adds()
    {
        static::__assert_equals([], Portal_Site_Resolver::host_patterns('', self::APP, 'single', true));
        static::__assert_equals([], Portal_Site_Resolver::host_patterns('/clients', self::APP, 'single', true));
        static::__assert_equals([], Portal_Site_Resolver::host_patterns(self::APP . '/clients', self::APP, 'single', true));
        static::__assert_equals(['portal.resolver-test.invalid'], Portal_Site_Resolver::host_patterns('https://Portal.resolver-test.invalid:8443/x', self::APP, 'single', true));
        static::__assert_equals(['*.myapp.test'], Portal_Site_Resolver::host_patterns('https://:slug.myapp.test', self::APP, 'multi', true));
        static::__assert_equals([], Portal_Site_Resolver::host_patterns('https://:slug.myapp.test', self::APP, 'multi', false), 'a disabled portal adds nothing');

        static::__assert_true(Portal_Site_Resolver::host_matches('acme.myapp.test', '*.myapp.test'));
        static::__assert_false(Portal_Site_Resolver::host_matches('a.b.myapp.test', '*.myapp.test'), 'one label');
        static::__assert_false(Portal_Site_Resolver::host_matches('myapp.test', '*.myapp.test'));
        static::__assert_false(Portal_Site_Resolver::host_matches('xmyapp.test', '*.myapp.test'));
        static::__assert_true(Portal_Site_Resolver::host_matches('portal.myapp.test', 'portal.myapp.test'));
        static::__assert_false(Portal_Site_Resolver::host_matches('sub.portal.myapp.test', 'portal.myapp.test'));
    }

    public static function test_a_custom_domain_is_a_served_host_while_custom_domains_are_on()
    {
        $domain = 'guard-' . strtolower(substr(uniqid(), -8)) . '.resolver-test.invalid';
        $site = static::__site(['portal_domain' => $domain, 'is_enabled' => false]);

        static::__with(['rsx.tenancy' => 'multi', 'rsx.portal.url' => '/_portal/:slug', 'rsx.portal.custom_domains' => 'only'], function () use ($domain) {
            static::__assert_true(Portal_Site_Resolver::is_portal_domain($domain), 'a disabled site\'s domain is answered (its 404), not refused');
            static::__assert_true(Portal_Site_Resolver::served_by_portal($domain));
            static::__assert_false(Portal_Site_Resolver::served_by_portal('unknown.resolver-test.invalid'));
        });
    }
}
