<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRedirect\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Login_Redirect - the single intended-URL redirect sanitizer + the four wiring
 * calls. Exercises the validator matrix (accept/reject), capture() request
 * classification, the params()/consume() ambient-request read paths, and
 * hidden_input() escaping. No database.
 *
 * The validator is private; it is exercised through the public surface. The raw
 * reject matrix (scheme, protocol-relative, backslash, control chars, fragment,
 * over-length) rides params()/consume() by binding an ambient request carrying the
 * hostile ?redirect= value, since capture() derives a clean path from the request
 * URI and cannot itself express those shapes. capture() is exercised for its
 * request classification (method / XHR / framework / API / login-flow).
 */
class Login_Redirect_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Bind an ambient GET request carrying the given raw redirect value (null =
     * no redirect param present), so params()/consume()/hidden_input() read it.
     */
    private static function __bind_redirect($raw): void
    {
        $uri = '/somepage';
        if ($raw !== null) {
            $uri .= '?redirect=' . rawurlencode($raw);
        }
        app()->instance('request', Request::create($uri, 'GET'));
    }

    public static function teardown()
    {
        // Rebind a fresh request rather than forgetInstance(): an unbound 'request'
        // fatals any later test whose code path calls request().
        app()->instance('request', \Illuminate\Http\Request::create('/'));
    }

    // =====================================================================
    // Validator - accepted values (via params())
    // =====================================================================

    public static function test_accepts_plain_local_path()
    {
        static::__bind_redirect('/dashboard');
        static::__assert_equals(['redirect' => '/dashboard'], Login_Redirect::params());
    }

    public static function test_accepts_path_with_query_preserved_verbatim()
    {
        // A registered route (routability gate) whose query is preserved verbatim.
        static::__bind_redirect('/frontend/settings/profile_edit?tab=history&x=1');
        static::__assert_equals(
            ['redirect' => '/frontend/settings/profile_edit?tab=history&x=1'],
            Login_Redirect::params()
        );
    }

    // =====================================================================
    // Validator - rejected values (via params()) -> []
    // =====================================================================

    public static function test_rejects_protocol_relative()
    {
        static::__bind_redirect('//evil.example');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_absolute_url()
    {
        static::__bind_redirect('https://evil.example');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_javascript_scheme()
    {
        static::__bind_redirect('javascript:alert(1)');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_other_scheme()
    {
        static::__bind_redirect('ftp://host/x');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_backslash()
    {
        static::__bind_redirect('/path\\to\\evil');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_control_characters()
    {
        static::__bind_redirect("/foo\nbar");
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_fragment()
    {
        static::__bind_redirect('/dashboard#section');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_login_prefix()
    {
        static::__bind_redirect('/login');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_login_subpath()
    {
        static::__bind_redirect('/login/2fa');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_logout_prefix()
    {
        static::__bind_redirect('/logout');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_over_length()
    {
        static::__bind_redirect('/' . str_repeat('a', 2001));
        static::__assert_empty(Login_Redirect::params());
    }

    // ---------------------------------------------------------------------
    // Non-page paths are rejected by the VALIDATOR too, not only by capture()
    // (the closed capture/validate asymmetry - a hand-threaded value meets the
    // same bar as a captured one).
    // ---------------------------------------------------------------------

    public static function test_rejects_ajax_endpoint_path_via_params()
    {
        static::__bind_redirect('/_ajax/Foo_Controller/bar');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_api_path_via_params()
    {
        static::__bind_redirect('/api/v1/contacts');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_empty_string()
    {
        static::__bind_redirect('');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_absent_param()
    {
        static::__bind_redirect(null);
        static::__assert_empty(Login_Redirect::params());
    }

    // =====================================================================
    // consume()
    // =====================================================================

    public static function test_consume_returns_valid_target()
    {
        static::__bind_redirect('/dashboard');
        static::__assert_equals('/dashboard', Login_Redirect::consume('/home'));
    }

    public static function test_consume_returns_default_on_hostile()
    {
        static::__bind_redirect('//evil.example');
        static::__assert_equals('/home', Login_Redirect::consume('/home'));
    }

    public static function test_consume_returns_default_when_absent()
    {
        static::__bind_redirect(null);
        static::__assert_equals('/home', Login_Redirect::consume('/home'));
    }

    // =====================================================================
    // hidden_input()
    // =====================================================================

    public static function test_hidden_input_empty_when_absent()
    {
        static::__bind_redirect(null);
        static::__assert_equals('', Login_Redirect::hidden_input());
    }

    public static function test_hidden_input_renders_valid_value()
    {
        static::__bind_redirect('/dashboard');
        static::__assert_equals(
            '<input type="hidden" name="redirect" value="/dashboard">',
            Login_Redirect::hidden_input()
        );
    }

    public static function test_hidden_input_escapes_value()
    {
        // A valid, ROUTABLE local path whose query carries HTML-significant chars.
        static::__bind_redirect('/frontend/settings/profile_edit?q=a&b="x"<y>');
        $html = Login_Redirect::hidden_input();

        static::__assert_true(str_contains($html, '&quot;'), 'double quotes escaped');
        static::__assert_true(str_contains($html, '&amp;'), 'ampersand escaped');
        static::__assert_true(str_contains($html, '&lt;'), 'less-than escaped');
        static::__assert_false(str_contains($html, 'value="/frontend/settings/profile_edit?q=a&b="x"'), 'raw quotes do not break out of the attribute');
    }

    // =====================================================================
    // capture() - request classification
    // =====================================================================

    public static function test_capture_get_page_returns_target()
    {
        $request = Request::create('/frontend/settings/profile_edit', 'GET');
        static::__assert_equals(['redirect' => '/frontend/settings/profile_edit'], Login_Redirect::capture($request));
    }

    public static function test_capture_preserves_query_string()
    {
        $request = Request::create('/frontend/settings/profile_edit?tab=x&y=2', 'GET');
        static::__assert_equals(
            ['redirect' => '/frontend/settings/profile_edit?tab=x&y=2'],
            Login_Redirect::capture($request)
        );
    }

    public static function test_capture_ignores_post()
    {
        $request = Request::create('/settings/onedrive', 'POST');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    public static function test_capture_ignores_xhr()
    {
        $request = Request::create('/settings/onedrive', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        static::__assert_empty(Login_Redirect::capture($request));
    }

    public static function test_capture_ignores_ajax_endpoint_path()
    {
        $request = Request::create('/_ajax/Foo_Controller/bar', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    public static function test_capture_ignores_api_path()
    {
        $request = Request::create('/api/v1/contacts', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    public static function test_capture_ignores_login_route()
    {
        $request = Request::create('/login', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    // =====================================================================
    // No-op bare root - the bare landing root (namespace path '/') with NO
    // query is where a just-authenticated user lands anyway, so threading it
    // back is pure URL noise and is dropped. A bare root WITH a query still
    // carries intent and is kept. Applied in the VALIDATOR (params/hand-threaded)
    // and in capture() alike.
    // =====================================================================

    public static function test_rejects_bare_root_no_query_via_params()
    {
        // Hand-threaded ?redirect=/ - the no-op drop (parity with capture()).
        static::__bind_redirect('/');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_accepts_bare_root_with_query_via_params()
    {
        // A bare root carrying a query string is kept (root '/' is routable).
        static::__bind_redirect('/?tab=activity');
        static::__assert_equals(['redirect' => '/?tab=activity'], Login_Redirect::params());
    }

    public static function test_capture_ignores_bare_root_no_query()
    {
        $request = Request::create('/', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }

    public static function test_capture_keeps_bare_root_with_query()
    {
        $request = Request::create('/?tab=activity', 'GET');
        static::__assert_equals(['redirect' => '/?tab=activity'], Login_Redirect::capture($request));
    }

    // =====================================================================
    // Routability gate - the target must resolve to a REGISTERED GET route in
    // the active context (staff Dispatcher). Structure alone is not enough; a
    // path no route pattern handles would dead-end on a 404 post-login, so it
    // degrades to the default. This checks route REGISTRATION only - NOT that
    // the requested record exists (no 404 probe) and NOT that this user is
    // authorized (no permission probe). Applied to hand-threaded values
    // (params) and captured values alike.
    // =====================================================================

    public static function test_accepts_routable_spa_target()
    {
        // A registered SPA action route.
        static::__bind_redirect('/dashboard');
        static::__assert_equals(['redirect' => '/dashboard'], Login_Redirect::params());
    }

    public static function test_accepts_routable_spa_id_route()
    {
        // A registered SPA route carrying a :id URL parameter.
        static::__bind_redirect('/tasks/edit/5');
        static::__assert_equals(['redirect' => '/tasks/edit/5'], Login_Redirect::params());
    }

    public static function test_accepts_routable_blade_target()
    {
        // A registered server-rendered (non-SPA, non-login) GET route.
        static::__bind_redirect('/signup');
        static::__assert_equals(['redirect' => '/signup'], Login_Redirect::params());
    }

    public static function test_rejects_unroutable_target_via_params()
    {
        // Structurally valid but no route pattern handles it - dropped.
        static::__bind_redirect('/does-not-exist-xyz');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_rejects_unroutable_undeclared_route_via_params()
    {
        // /clients/5 has no registered route in this template; the gate checks
        // route registration, so it is dropped (this is NOT a record-existence
        // probe - it never asks whether client 5 exists).
        static::__bind_redirect('/clients/5');
        static::__assert_empty(Login_Redirect::params());
    }

    public static function test_capture_ignores_unroutable_target()
    {
        // capture() side of the routability gate (parity with params()).
        $request = Request::create('/does-not-exist-xyz', 'GET');
        static::__assert_empty(Login_Redirect::capture($request));
    }
}
