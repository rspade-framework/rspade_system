<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Users\_Sys_Users_Controller;
use App\RSpade\Sys\App\Sys\_Sys_Impersonation_Controller;

/**
 * The panel's developer impersonation: "Sign in as this user" on the Users screen
 * (_Sys_Users_Controller::sign_in_as, gated is_sysadmin) and the way back,
 * GET /_sys/stop-impersonating (_Sys_Impersonation_Controller::stop, gated is_logged_in
 * and checking the IMPERSONATOR in its body).
 *
 * The CLI session carries the impersonation on static overrides rather than on a
 * _sessions row (Session::begin_impersonation()'s CLI branch), so the facade's answers -
 * is_impersonating(), the impersonator id, the effective identity and site - are what is
 * asserted here; the web path writing the row's two impersonation columns is exercised
 * by rsx:debug. Identities and memberships are written with DB::table(), as the Users
 * test does, so the acting session's site is never forced onto a membership.
 */
class Sys_Impersonation_Test extends Rsx_Test_Abstract
{
    /**
     * Every test starts as the developer (user 1) with no impersonation. setup() runs once
     * per CLASS and the runner keeps the CLI identity declaration between tests, while a
     * begun impersonation swaps that declaration - so each test re-declares it.
     */
    private static function __as_developer(): void
    {
        static::__reset_session();
        static::__acting_as_user(1);
    }

    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        static::__reset_session();
    }

    private static function __site(string $name, bool $enabled = true): int
    {
        $site = new Site_Model();
        $site->slug = 'sys-imp-' . uniqid();
        $site->name = $name;
        $site->is_enabled = $enabled;
        $site->save();

        return (int) $site->id;
    }

    private static function __identity(string $tag, array $columns = []): int
    {
        return (int) DB::table('login_users')->insertGetId(array_merge([
            'email' => "sys-imp-{$tag}-" . uniqid() . '@example.com',
            'password' => 'not-a-hash',
            'status_id' => Login_User_Model::STATUS_ACTIVE,
            'is_verified' => 1,
            'is_activated' => 1,
        ], $columns));
    }

    private static function __membership(int $login_user_id, int $site_id, array $columns = []): int
    {
        return (int) DB::table('users')->insertGetId(array_merge([
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'first_name' => 'Sys',
            'last_name' => 'Impersonation',
            'role_id' => static::most_privileged_role_id(),
            'is_enabled' => 1,
        ], $columns));
    }

    private static function __sign_in_as(array $params)
    {
        return _Sys_Users_Controller::sign_in_as(Request::create('/'), $params);
    }

    private static function __stop()
    {
        return _Sys_Impersonation_Controller::stop(Request::create('/_sys/stop-impersonating'), []);
    }

    private static function __assert_refused(string $code, $response, string $message): Error_Response
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);

        return $response;
    }

    private static function __redirect_path($response): string
    {
        static::__assert_true($response instanceof RedirectResponse, 'expected a redirect, got ' . get_debug_type($response));

        // redirect('/') answers the bare origin, whose path is absent: that IS the root.
        return (string) (parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: '/');
    }

    /**
     * RP-IMP-01 - sign_in_as refuses (ERROR_VALIDATION, nothing begun) the acting
     * identity, a developer, an identity with no ACTIVE membership (a disabled membership;
     * an enabled one on a disabled site), and a session already impersonating; `detail`
     * publishes the same `impersonate_refusal`, null on an ordinary identity. Status is
     * not refused on: a suspended identity carries no refusal.
     */
    public static function test_sign_in_as_refusals()
    {
        static::__as_developer();

        $developer = static::__identity('dev', ['is_developer' => 1]);
        static::__membership($developer, 1);

        $disabled = static::__identity('disabled');
        static::__membership($disabled, 1, ['is_enabled' => 0]);

        $dark_site = static::__identity('dark-site');
        static::__membership($dark_site, static::__site('Sys Imp Disabled Site', false));

        $suspended = static::__identity('suspended', ['status_id' => Login_User_Model::STATUS_SUSPENDED]);
        static::__membership($suspended, 1);

        $cases = [
            'the acting identity' => [1, 'your own identity'],
            'a developer' => [$developer, 'is a developer'],
            'a disabled membership' => [$disabled, 'no active membership'],
            'a membership on a disabled site' => [$dark_site, 'no active membership'],
        ];

        foreach ($cases as $label => [$id, $reason]) {
            $refused = static::__assert_refused(Ajax::ERROR_VALIDATION, static::__sign_in_as(['id' => $id, 'site_id' => 1]), $label);
            static::__assert_contains($reason, $refused->get_metadata()['_message'], "{$label}: the refusal says why");
            static::__assert_false(Session::is_impersonating(), "{$label}: nothing was begun");

            $published = _Sys_Users_Controller::detail(Request::create('/'), ['id' => $id])['user']['impersonate_refusal'];
            static::__assert_contains($reason, (string) $published, "{$label}: detail publishes the refusal");
        }

        static::__assert_null(
            _Sys_Users_Controller::detail(Request::create('/'), ['id' => $suspended])['user']['impersonate_refusal'],
            'status is application vocabulary: a suspended identity is not refused'
        );

        static::__assert_refused(Ajax::ERROR_NOT_FOUND, static::__sign_in_as(['id' => 0, 'site_id' => 1]), 'a missing identity');

        // Already impersonating: refused BEFORE begin_impersonation(), which would throw.
        Session::cli_set_impersonator_login_user_id(1);
        $refused = static::__assert_refused(Ajax::ERROR_VALIDATION, static::__sign_in_as(['id' => $suspended, 'site_id' => 1]), 'a session already impersonating');
        static::__assert_contains('Stop impersonating first', $refused->get_metadata()['_message'], 'the refusal says why');
        Session::cli_set_impersonator_login_user_id(null);
    }

    /**
     * RP-IMP-02 - The site must be one of the identity's ACTIVE memberships: a blank one
     * and a foreign one are field errors on site_id, a disabled membership's site too,
     * and nothing is begun.
     */
    public static function test_sign_in_as_site_must_be_an_active_membership()
    {
        static::__as_developer();

        $target = static::__identity('site');
        static::__membership($target, 1);
        $off_site = static::__site('Sys Imp Off Membership');
        static::__membership($target, $off_site, ['is_enabled' => 0]);
        $foreign = static::__site('Sys Imp Foreign');

        foreach (['blank' => '', 'foreign' => $foreign, 'disabled membership' => $off_site, 'malformed' => 'x'] as $label => $site_id) {
            $refused = static::__assert_refused(Ajax::ERROR_VALIDATION, static::__sign_in_as(['id' => $target, 'site_id' => $site_id]), "a {$label} site");
            static::__assert_array_has_key('site_id', $refused->get_metadata(), "a {$label} site is a field error on site_id");
            static::__assert_false(Session::is_impersonating(), "a {$label} site: nothing was begun");
        }
    }

    /**
     * RP-IMP-03 - sign_in_as on a membership of ANOTHER site stores the developer's site
     * in the session value store, moves the session to the chosen site, begins the
     * impersonation (impersonator = the developer, effective identity = the target) and
     * answers the destination '/'.
     */
    public static function test_sign_in_as_begins_on_the_chosen_site()
    {
        static::__as_developer();

        $site = static::__site('Sys Imp Chosen');
        $target = static::__identity('begin');
        static::__membership($target, 1);
        static::__membership($target, $site);

        $result = static::__sign_in_as(['id' => $target, 'site_id' => (string) $site]);

        static::__assert_equals(['destination' => '/'], $result, 'the destination');
        static::__assert_true(Session::is_impersonating(), 'impersonating');
        static::__assert_equals(1, Session::get_impersonator_login_user_id(), 'the impersonator is the developer');
        static::__assert_equals($target, (int) Session::get_login_user_id(), 'the effective identity is the target');
        static::__assert_equals($site, Session::get_site_id(), 'the session is on the chosen site');
        static::__assert_equals(1, Session::get_value(_Sys_Impersonation_Controller::RETURN_SITE_KEY), 'the developer\'s site is stored');
        static::__assert_not_null(Session::get_impersonation_started_at(), 'the start is stamped');
    }

    /**
     * RP-IMP-04 - stop: while impersonating from the panel it ends the impersonation,
     * restores the developer's identity and stored site, forgets the value and redirects
     * to the impersonated user's detail; with no impersonation it redirects to '/' and
     * changes nothing.
     */
    public static function test_stop_restores_identity_and_site()
    {
        static::__as_developer();

        $site = static::__site('Sys Imp Stop');
        $target = static::__identity('stop');
        static::__membership($target, $site);

        static::__assert_equals('/', static::__redirect_path(static::__stop()), 'not impersonating: to the root');
        static::__assert_equals(1, (int) Session::get_login_user_id(), 'and the identity is untouched');

        static::__sign_in_as(['id' => $target, 'site_id' => $site]);
        static::__assert_equals($site, Session::get_site_id(), 'impersonating on the chosen site');

        $response = static::__stop();

        static::__assert_equals(
            (string) parse_url(Rsx::Route('_Sys_User_View_Action', $target), PHP_URL_PATH),
            static::__redirect_path($response),
            'back to the impersonated user\'s detail'
        );
        static::__assert_false(Session::is_impersonating(), 'no longer impersonating');
        static::__assert_equals(1, (int) Session::get_login_user_id(), 'the developer\'s identity is back');
        static::__assert_equals(1, Session::get_site_id(), 'the developer\'s site is back');
        static::__assert_null(Session::get_value(_Sys_Impersonation_Controller::RETURN_SITE_KEY), 'the stored site is forgotten');
    }

    /**
     * RP-IMP-05 - stop refuses (unauthorized) an impersonation whose IMPERSONATOR is not
     * a developer, and leaves it running.
     */
    public static function test_stop_refuses_a_non_developer_impersonator()
    {
        static::__as_developer();

        $principal = static::__identity('principal');
        $principal_membership = static::__membership($principal, 1);
        $target = static::__identity('victim');

        Session::impersonate(1, $principal, $principal_membership);
        Session::begin_impersonation($target);

        static::__assert_refused(Ajax::ERROR_UNAUTHORIZED, static::__stop(), 'a non-developer impersonator');
        static::__assert_true(Session::is_impersonating(), 'the impersonation is left running');
        static::__assert_equals($principal, Session::get_impersonator_login_user_id(), 'with its impersonator');
        static::__assert_equals($target, (int) Session::get_login_user_id(), 'and its effective identity');
    }

    /**
     * RP-IMP-06 - The gates as the dispatch seams apply them: a signed-in non-developer is
     * refused sign_in_as through the browser transport (is_sysadmin), and reaches the stop
     * route through the page dispatcher (is_logged_in), which - not impersonating -
     * redirects to '/'.
     */
    public static function test_gates_at_the_dispatch_seams()
    {
        static::__as_developer();

        $probe = static::__identity('probe');
        $membership = static::__membership($probe, 1);
        Session::impersonate(1, $probe, $membership);

        static::__assert_false(Session::is_developer(), 'the probe is not a developer');

        Session::get_session_id();
        $request = Request::create('/_ajax/_Sys_Users_Controller/sign_in_as', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_CSRF_TOKEN' => (string) Session::get_csrf_token(),
        ]);
        app()->instance('request', $request);
        $envelope = json_decode(Rsx_Front_Controller::handle($request)->getContent(), true);

        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $envelope['error_code'] ?? null, 'sign_in_as refuses a non-developer: ' . json_encode($envelope));
        static::__assert_false(Session::is_impersonating(), 'nothing was begun');

        // A new request: the transport call above classified this process as AJAX.
        Rsx_Request_Channel::reset();
        $page_request = Request::create('/_sys/stop-impersonating', 'GET');
        app()->instance('request', $page_request);
        Rsx_Bundle_Abstract::$_has_rendered = null;
        $response = Dispatcher::dispatch('/_sys/stop-impersonating', 'GET', [], $page_request);

        static::__assert_equals(302, $response->getStatusCode(), 'the stop route answers a signed-in non-developer');
        static::__assert_equals('/', (string) (parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) ?: '/'), 'with the root, since nothing is being impersonated');
    }

    /**
     * RP-IMP-07 - The stop route is published into every bundle (config
     * rsx.always_published_routes), resolves in the manifest to /_sys/stop-impersonating,
     * and its grant ships as 1 for a signed-in identity and 0 for an anonymous one.
     */
    public static function test_the_stop_route_is_published()
    {
        static::__as_developer();

        $target = '_Sys_Impersonation_Controller::stop';

        static::__assert_true(in_array($target, config('rsx.always_published_routes', []), true), 'the stop route is always published');

        $manifest = Manifest::get_full_manifest();
        $patterns = array_column($manifest['data']['routes_by_target'][$target] ?? [], 'pattern');
        static::__assert_equals(['/_sys/stop-impersonating'], $patterns, 'the manifest pattern');

        static::__assert_equals(1, Auth_Gates::export_published_route_grants(Auth_Gates::REALM_STAFF)[$target] ?? null, 'granted to a signed-in identity');

        static::__reset_session();
        static::__assert_equals(0, Auth_Gates::export_published_route_grants(Auth_Gates::REALM_STAFF)[$target] ?? null, 'denied, explicitly, to an anonymous one');
    }
}
