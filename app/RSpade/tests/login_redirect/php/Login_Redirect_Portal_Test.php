<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRedirect\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Login_Redirect portal-context behavior. Exercises the same four calls under a
 * client-portal request: the prefix-mode namespace rule (a target must live under
 * config('rsx.portal.prefix')), the portal exclusion list, domain-mode unprefixed
 * targets, cross-context isolation (staff <-> portal), the closed capture/validate
 * asymmetry (non-page paths rejected by the validator too), and the
 * portal_excluded_prefixes config override. No database.
 *
 * Context is forced with Rsx_Portal::set_portal_request() and config() overrides
 * (setup/teardown are per-CLASS, so every test sets its own context explicitly and
 * teardown restores a non-portal baseline for the classes that run after this one).
 */
class Login_Redirect_Portal_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Config snapshot restored in teardown so this class does not leak portal
     * context / config into other test classes.
     */
    private static $saved_domain;
    private static $saved_prefix;
    private static $saved_portal_excluded;

    public static function setup()
    {
        self::$saved_domain = config('rsx.portal.domain');
        self::$saved_prefix = config('rsx.portal.prefix');
        self::$saved_portal_excluded = config('rsx.login_redirect.portal_excluded_prefixes');
    }

    public static function teardown()
    {
        Rsx_Portal::set_portal_request(false);
        // Rebind a fresh request rather than forgetInstance(): an unbound 'request'
        // fatals any later test whose code path calls request().
        app()->instance('request', \Illuminate\Http\Request::create('/'));

        config([
            'rsx.portal.domain' => self::$saved_domain,
            'rsx.portal.prefix' => self::$saved_prefix,
            'rsx.login_redirect.portal_excluded_prefixes' => self::$saved_portal_excluded,
        ]);
    }

    // =====================================================================
    // Context helpers
    // =====================================================================

    /**
     * Portal request served under the /_portal prefix (no dedicated domain).
     */
    private static function __portal_prefix_context(): void
    {
        config(['rsx.portal.domain' => null, 'rsx.portal.prefix' => '/_portal']);
        Rsx_Portal::set_portal_request(true);
    }

    /**
     * Portal request served on a dedicated domain (paths unprefixed).
     */
    private static function __portal_domain_context(): void
    {
        config(['rsx.portal.domain' => 'portal.example.com']);
        Rsx_Portal::set_portal_request(true);
    }

    /**
     * Staff (non-portal) request context.
     */
    private static function __staff_context(): void
    {
        config(['rsx.portal.domain' => null]);
        Rsx_Portal::set_portal_request(false);
    }

    /**
     * Bind an ambient GET request carrying the given raw redirect value, so
     * params()/consume() read it.
     */
    private static function __bind_redirect(string $raw): void
    {
        app()->instance('request', Request::create('/portal-page?redirect=' . rawurlencode($raw), 'GET'));
    }

    // =====================================================================
    // capture() - prefix mode
    // =====================================================================

    public static function test_capture_prefix_portal_page_returns_target()
    {
        static::__portal_prefix_context();
        $request = Request::create('/_portal/test-login-redirect/item/5', 'GET');
        static::__assert_equals(
            ['redirect' => '/_portal/test-login-redirect/item/5'],
            Login_Redirect::capture($request)
        );
    }

    public static function test_capture_prefix_portal_login_excluded()
    {
        static::__portal_prefix_context();
        $request = Request::create('/_portal/login', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    // =====================================================================
    // params() - prefix mode
    // =====================================================================

    public static function test_params_prefix_accepts_portal_page()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/test-login-redirect/item/5');
        static::__assert_equals(['redirect' => '/_portal/test-login-redirect/item/5'], Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_non_prefix_path()
    {
        // A staff route is not under the portal prefix - rejected in portal context.
        static::__portal_prefix_context();
        static::__bind_redirect('/login');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_portal_login()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/login');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_portal_register()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/register');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_portal_password_reset()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/password/reset');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_portal_impersonate_subpath()
    {
        // '/impersonate/claim' rides the '/impersonate' exclusion prefix.
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/impersonate/claim');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_portal_ajax_remainder()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/_ajax/anything');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_portal_api_remainder()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/api/v1/x');
        static::__assert_empty(Login_Redirect::params());
    }

    // =====================================================================
    // params() - domain mode
    // =====================================================================

    public static function test_params_domain_accepts_unprefixed_page()
    {
        static::__portal_domain_context();
        static::__bind_redirect('/test-login-redirect/item/5');
        static::__assert_equals(['redirect' => '/test-login-redirect/item/5'], Login_Redirect::params());
    }

    public static function test_params_domain_rejects_login()
    {
        static::__portal_domain_context();
        static::__bind_redirect('/login');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_domain_rejects_register()
    {
        static::__portal_domain_context();
        static::__bind_redirect('/register');
        static::__assert_empty(Login_Redirect::params());
    }

    // =====================================================================
    // Cross-context isolation (staff side) - closed capture/validate asymmetry
    // =====================================================================

    public static function test_params_staff_rejects_portal_prefix_target()
    {
        // A staff redirect can never point under the portal prefix.
        static::__staff_context();
        static::__bind_redirect('/_portal/test-login-redirect/item/5');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_staff_rejects_non_page_path()
    {
        // The validator (not just capture()) now rejects /_... paths.
        static::__staff_context();
        static::__bind_redirect('/_ajax/foo');
        static::__assert_empty(Login_Redirect::params());
    }

    // =====================================================================
    // Config override
    // =====================================================================

    public static function test_portal_excluded_prefixes_config_override_honored()
    {
        static::__portal_prefix_context();
        config(['rsx.login_redirect.portal_excluded_prefixes' => ['/custom']]);

        // The custom prefix is now excluded.
        static::__bind_redirect('/_portal/custom/thing');
        static::__assert_empty(Login_Redirect::params());

        // A route dropped from the list (default '/login') is no longer excluded.
        static::__bind_redirect('/_portal/login');
        static::__assert_equals(['redirect' => '/_portal/login'], Login_Redirect::params());
    }

    // =====================================================================
    // consume() - portal context
    // =====================================================================

    public static function test_consume_prefix_returns_valid_target()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/test-login-redirect/item/5');
        static::__assert_equals(
            '/_portal/test-login-redirect/item/5',
            Login_Redirect::consume('/_portal/test-login-redirect/page')
        );
    }

    public static function test_consume_prefix_returns_default_on_hostile()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('//evil.example');
        static::__assert_equals('/_portal/test-login-redirect/page', Login_Redirect::consume('/_portal/test-login-redirect/page'));
    }

    // =====================================================================
    // No-op portal root - the portal prefix root reduces to namespace path
    // '/' (the default portal landing), so a bare-root target with NO query is
    // dropped, exactly like the staff '/' no-op. A portal root WITH a query is
    // kept. Covers both the validator (params) and capture().
    // =====================================================================

    public static function test_params_prefix_rejects_bare_portal_root()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_params_prefix_rejects_bare_portal_root_trailing_slash()
    {
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_capture_prefix_ignores_bare_portal_root()
    {
        static::__portal_prefix_context();
        $request = Request::create('/_portal', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    public static function test_params_prefix_accepts_bare_portal_root_with_query()
    {
        // A portal root carrying a query still carries intent - kept (and the
        // prefix root is routable via the portal route table).
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal?tab=activity');
        static::__assert_equals(['redirect' => '/_portal?tab=activity'], Login_Redirect::params());
    }

    // =====================================================================
    // Routability gate, portal branch - a return target in portal context is
    // resolved against the PORTAL route table (Portal_Dispatcher), not the
    // staff Dispatcher. A registered portal route is kept.
    //
    // NOTE: the routability-REJECTION branch cannot be exercised here because
    // the template app registers a portal '/*' catch-all (Portal_Spa_Controller),
    // so every structurally-valid, under-prefix path resolves. The gate's portal
    // rejection would only fire for a portal deployment WITHOUT such a catch-all;
    // see test_catalog.md (portal routability-rejection row, deferred). The
    // staff-side rejection is proven by
    // Login_Redirect_Test::test_rejects_unroutable_target_via_params.
    // =====================================================================

    public static function test_params_prefix_accepts_routable_portal_target()
    {
        // Confirms the gate resolves against the portal table (a portal route
        // the staff Dispatcher does not know).
        static::__portal_prefix_context();
        static::__bind_redirect('/_portal/test-login-redirect/page');
        static::__assert_equals(['redirect' => '/_portal/test-login-redirect/page'], Login_Redirect::params());
    }
}
