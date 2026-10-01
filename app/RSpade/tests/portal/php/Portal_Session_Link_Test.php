<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Rsx_Signed_Url;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Cleanup_Service;
use App\RSpade\Core\Session\Session_Link;
use App\RSpade\Core\Session\Session_Link_Controller;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Portal\Php\Portal_Impersonation_Grant_Fixture;

/**
 * The linked-session handshake (Session_Link + Session_Link_Controller), driven in-process
 * leg by leg: each leg gets a request rebuilt on the host it is addressed to, carrying
 * exactly the cookies that host's jar would hold.
 *
 *   leg 1  portal host, no cookies          -> 302 to the staff host + the rsx_link nonce cookie
 *   leg 2  staff host, the staff rsx cookie -> impersonation applied to the staff row,
 *                                              302 back to the portal host
 *   leg 3  portal host, the rsx_link cookie -> the staff row's id, which the controller
 *                                              then gives this host's cookie
 *
 * Legs 1 and 2 run through the real controller methods. Leg 3's success path runs through
 * Session_Link::complete(), whose answer the controller hands to
 * Session::_clone_session_to_this_host(): that seam emits a Set-Cookie and refuses in CLI
 * (there is no browser), so the cookie it sets is proved end to end over HTTP by
 * session/http/session_link_handshake.sh. Leg 3's REFUSALS run through the controller.
 *
 * The layout is a portal on its own host with a prefix (https://portal.example.test/x), so
 * every URL is also checked for the prefix. The staff browser's row is this process's CLI
 * session row; the staff user is real, and its can_impersonate answer is set by
 * Portal_Impersonation_Grant_Fixture (the check is the application's rule).
 *
 * Default per-test transaction (rolled back afterward).
 */
class Portal_Session_Link_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const PORTAL_HOST = 'portal.example.test';

    private const PORTAL_ORIGIN = 'https://portal.example.test';

    private const PREFIX = '/x';

    private static $saved_portal_url;

    private static $saved_request;

    public static function setup(): void
    {
        self::$saved_portal_url = config('rsx.portal.url');
        self::$saved_request = app('request');
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        config(['rsx.portal.url' => self::$saved_portal_url]);
        app()->instance('request', self::$saved_request);
        Rsx_Request_Channel::reset();
        Portal_Impersonation_Grant_Fixture::uninstall();
        static::__reset_session();
    }

    // =====================================================================
    // Fixtures and drivers
    // =====================================================================

    private static function __app_origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Bind a GET request for $url (its host included) carrying $cookies, and return it.
     */
    private static function __on(string $url, array $cookies = []): Request
    {
        $request = Request::create($url, 'GET', [], $cookies);
        app()->instance('request', $request);

        return $request;
    }

    /**
     * A staff user signed in on the staff host, a portal user to view as, and leg 1's URL.
     *
     * @return array{staff: User_Model, target: Portal_User_Model, row_id: int, token: string, leg1: string}
     */
    private static function __begin(): array
    {
        Portal_Impersonation_Grant_Fixture::install(true);
        config(['rsx.portal.url' => self::PORTAL_ORIGIN . self::PREFIX]);
        static::__on(static::__app_origin() . '/contacts');
        static::__acting_as_site(self::SITE_ID);

        $email = 'link_staff_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $staff = new User_Model();
        $staff->login_user_id = $login_user->id;
        $staff->email = $email;
        $staff->first_name = 'Link';
        $staff->last_name = 'Staff';
        $staff->role_id = static::most_privileged_role_id();
        $staff->is_enabled = 1;
        $staff->save();

        static::__acting_as_user($staff->id);

        $target = new Portal_User_Model();
        $target->site_id = self::SITE_ID;
        $target->email = 'link_target_' . uniqid() . '@example.com';
        $target->set_password('secret-password');
        $target->is_verified = true;
        $target->status_id = Portal_User_Model::STATUS_ACTIVE;
        $target->save();

        $leg1 = Portal_Session::begin_impersonation_from_staff($target->id, $staff->id, self::SITE_ID);
        $row_id = Session::get_session_id();

        return [
            'staff' => $staff,
            'target' => $target,
            'row_id' => $row_id,
            'token' => Session::find($row_id)->session_token,
            'leg1' => $leg1,
        ];
    }

    /**
     * Run leg 1 through the controller.
     *
     * @return array{response: \Symfony\Component\HttpFoundation\Response, location: ?string, nonce: ?string}
     */
    private static function __leg1(string $url, array $cookies = []): array
    {
        $response = Session_Link_Controller::open(static::__on($url, $cookies));

        $nonce = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === Session_Link::NONCE_COOKIE && $cookie->getValue() !== '' && $cookie->getValue() !== null) {
                $nonce = $cookie->getValue();
            }
        }

        return [
            'response' => $response,
            'location' => $response->getStatusCode() === 302 ? $response->headers->get('Location') : null,
            'nonce' => $nonce,
        ];
    }

    /**
     * Run leg 2 through the controller with the given staff cookie (null = none).
     */
    private static function __leg2(string $url, ?string $staff_token)
    {
        return Session_Link_Controller::confirm(static::__on($url, $staff_token === null ? [] : ['rsx' => $staff_token]));
    }

    /**
     * Legs 1 and 2 of a fresh handshake, both succeeding.
     *
     * @return array{begin: array, nonce: string, leg3: string}
     */
    private static function __through_leg2(): array
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);
        $leg2 = static::__leg2($leg1['location'], $begin['token']);

        return ['begin' => $begin, 'nonce' => $leg1['nonce'], 'leg3' => $leg2->headers->get('Location')];
    }

    private static function __assert_refused($response, string $label): void
    {
        static::__assert_equals(400, $response->getStatusCode(), "{$label}: refused with the generic page");
        static::__assert_contains(Session_Link::FAILURE_MESSAGE, (string) $response->getContent(), "{$label}: the one message");
    }

    private static function __assert_not_linked(int $row_id, string $label): void
    {
        $row = Session::find($row_id);
        static::__assert_null($row->portal_user_id, "{$label}: no portal identity applied");
        static::__assert_null($row->impersonator_user_id, "{$label}: no impersonator applied");
    }

    /**
     * Re-sign a URL's code for another leg and host (a forgery only the server could make).
     */
    private static function __resigned(string $url, int $leg, string $base, string $host): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $base . '?' . http_build_query([
            'c' => $query['c'],
            's' => Rsx_Signed_Url::sign('session_link:' . $leg, $query['c'], $host),
        ]);
    }

    private static function __tampered(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query['s'] = str_repeat('0', 64);

        return strtok($url, '?') . '?' . http_build_query($query);
    }

    // =====================================================================
    // The routes
    // =====================================================================

    public static function test_every_leg_is_routed_in_both_tables_on_one_handler()
    {
        $staff = Manifest::get_routes();
        $portal = Manifest::get_full_manifest()['data']['portal_routes'] ?? [];

        foreach (['open', 'confirm', 'complete'] as $leg) {
            $pattern = "/_session_link/{$leg}";
            static::__assert_array_has_key($pattern, $staff, "{$pattern} is a staff route");
            static::__assert_array_has_key($pattern, $portal, "{$pattern} is a portal route");
            static::__assert_equals('Session_Link_Controller::' . $leg, class_basename((string) $portal[$pattern]['class']) . '::' . $portal[$pattern]['method']);
            static::__assert_equals(['GET'], $staff[$pattern]['methods'] ?? ['GET'], "{$pattern} is GET only");
        }
    }

    public static function test_the_portal_legs_resolve_under_the_prefix_on_the_portal_host()
    {
        config(['rsx.portal.url' => self::PORTAL_ORIGIN . self::PREFIX]);

        foreach (['open', 'complete'] as $leg) {
            Rsx_Request_Channel::classify(Request::create(self::PORTAL_ORIGIN . self::PREFIX . "/_session_link/{$leg}?c=1&s=2"));
            static::__assert_true(Rsx_Request_Channel::is_portal(), "{$leg}: portal realm on the portal host");

            $route = Dispatcher::resolve_url_to_route(Rsx_Request_Channel::realm_path(), 'GET', Auth_Gates::REALM_PORTAL);
            static::__assert_not_null($route, "{$leg}: resolves in the portal table");
            static::__assert_equals($leg, $route['method']);
        }

        Rsx_Request_Channel::classify(Request::create(static::__app_origin() . '/_session_link/confirm?c=1&s=2'));
        static::__assert_false(Rsx_Request_Channel::is_portal(), 'confirm: staff realm on the application host');
        $route = Dispatcher::resolve_url_to_route(Rsx_Request_Channel::realm_path(), 'GET', Auth_Gates::REALM_STAFF);
        static::__assert_equals('confirm', $route['method'] ?? null, 'confirm: resolves in the staff table');
    }

    // =====================================================================
    // The happy path
    // =====================================================================

    public static function test_the_three_legs_link_the_portal_host_to_the_staff_row()
    {
        $begin = static::__begin();

        // Leg 1 - portal host, a browser with no portal cookie yet.
        $leg1 = static::__leg1($begin['leg1']);
        static::__assert_equals(302, $leg1['response']->getStatusCode(), 'leg 1 redirects');
        static::__assert_true(
            str_starts_with((string) $leg1['location'], static::__app_origin() . '/_session_link/confirm?'),
            'leg 1 sends the browser to the staff host; got ' . $leg1['location']
        );
        static::__assert_not_null($leg1['nonce'], 'leg 1 sets the nonce cookie');

        $nonce_cookie = null;
        foreach ($leg1['response']->headers->getCookies() as $cookie) {
            if ($cookie->getName() === Session_Link::NONCE_COOKIE) {
                $nonce_cookie = $cookie;
            }
        }
        static::__assert_equals(self::PREFIX . '/_session_link', $nonce_cookie->getPath(), 'scoped to the portal legs under the prefix');
        static::__assert_true($nonce_cookie->isHttpOnly(), 'HttpOnly');
        static::__assert_equals('lax', strtolower((string) $nonce_cookie->getSameSite()), 'SameSite=Lax');
        static::__assert_null(Session::find($begin['row_id'])->portal_user_id, 'leg 1 applies nothing');

        $nonce_row = DB::table('_session_links')->where('leg', Session_Link::LEG_CONFIRM)->where('session_id', $begin['row_id'])->first();
        static::__assert_equals(hash('sha256', $leg1['nonce']), $nonce_row->nonce_hash, 'only the nonce\'s hash is stored');

        // Leg 2 - staff host, the browser's own staff cookie.
        $leg2 = static::__leg2($leg1['location'], $begin['token']);
        static::__assert_equals(302, $leg2->getStatusCode(), 'leg 2 redirects');
        $leg3_url = (string) $leg2->headers->get('Location');
        static::__assert_true(
            str_starts_with($leg3_url, self::PORTAL_ORIGIN . self::PREFIX . '/_session_link/complete?'),
            'leg 2 sends the browser back to the portal host, under the prefix; got ' . $leg3_url
        );

        $row = Session::find($begin['row_id']);
        static::__assert_equals($begin['target']->id, (int) $row->portal_user_id, 'leg 2 applied the portal identity to the staff row');
        static::__assert_equals(self::SITE_ID, (int) $row->portal_site_id, 'and its tenant');
        static::__assert_equals($begin['staff']->id, (int) $row->impersonator_user_id, 'and the impersonator');
        static::__assert_not_null($row->impersonation_started_at, 'and the start time');
        static::__assert_equals($begin['staff']->login_user_id, (int) $row->login_user_id, 'the staff login is untouched');

        // Leg 3 - portal host, the nonce cookie leg 1 set.
        $session_id = Session_Link::complete(static::__on($leg3_url, [Session_Link::NONCE_COOKIE => $leg1['nonce']]));
        static::__assert_equals($begin['row_id'], $session_id, 'leg 3 names the staff row for this host\'s cookie');

        static::__assert_equals(0, DB::table('_session_links')->where('session_id', $begin['row_id'])->count(), 'every code was burned');

        foreach ([$begin['leg1'], $leg1['location'], $leg3_url] as $url) {
            static::__assert_false(str_contains($url, $begin['token']), 'no URL carries the session token');
        }
    }

    // =====================================================================
    // Leg 1 refusals
    // =====================================================================

    public static function test_leg1_refuses_a_tampered_signature_and_burns_nothing()
    {
        $begin = static::__begin();

        static::__assert_refused(static::__leg1(static::__tampered($begin['leg1']))['response'], 'tampered');
        static::__assert_equals(1, DB::table('_session_links')->where('session_id', $begin['row_id'])->count(), 'the code survives a bad signature');

        static::__assert_equals(302, static::__leg1($begin['leg1'])['response']->getStatusCode(), 'the genuine link still works');
    }

    public static function test_leg1_refuses_a_replayed_code()
    {
        $begin = static::__begin();

        static::__assert_equals(302, static::__leg1($begin['leg1'])['response']->getStatusCode(), 'first use');
        $replay = static::__leg1($begin['leg1']);
        static::__assert_refused($replay['response'], 'replay');
        static::__assert_null($replay['nonce'], 'a refusal sets no nonce');
    }

    public static function test_leg1_refuses_an_expired_code()
    {
        $begin = static::__begin();

        DB::table('_session_links')->where('session_id', $begin['row_id'])->update(['expires_at' => now()->subSeconds(1)]);

        static::__assert_refused(static::__leg1($begin['leg1'])['response'], 'expired');
    }

    public static function test_leg1_refuses_its_url_on_the_staff_host()
    {
        $begin = static::__begin();

        $on_staff_host = static::__app_origin() . '/_session_link/open?' . parse_url($begin['leg1'], PHP_URL_QUERY);

        static::__assert_refused(static::__leg1($on_staff_host)['response'], 'wrong host');
    }

    // =====================================================================
    // Leg 2 refusals
    // =====================================================================

    public static function test_leg2_refuses_a_replayed_code()
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);

        static::__assert_equals(302, static::__leg2($leg1['location'], $begin['token'])->getStatusCode(), 'first use');
        static::__assert_refused(static::__leg2($leg1['location'], $begin['token']), 'replay');
    }

    public static function test_leg2_refuses_a_tampered_signature()
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);

        static::__assert_refused(static::__leg2(static::__tampered($leg1['location']), $begin['token']), 'tampered');
        static::__assert_not_linked($begin['row_id'], 'tampered');
    }

    public static function test_leg2_refuses_an_expired_code()
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);

        DB::table('_session_links')->where('session_id', $begin['row_id'])->update(['expires_at' => now()->subSeconds(1)]);

        static::__assert_refused(static::__leg2($leg1['location'], $begin['token']), 'expired');
        static::__assert_not_linked($begin['row_id'], 'expired');
    }

    /**
     * A leg-1 code re-signed by the server's own key for leg 2 still fails: the code is
     * bound to its leg in the store, not only by the signature.
     */
    public static function test_leg2_refuses_another_legs_code_even_correctly_signed()
    {
        $begin = static::__begin();

        $forged = static::__resigned(
            $begin['leg1'],
            Session_Link::LEG_CONFIRM,
            static::__app_origin() . '/_session_link/confirm',
            parse_url(static::__app_origin(), PHP_URL_HOST)
        );

        static::__assert_refused(static::__leg2($forged, $begin['token']), 'leg-1 code at leg 2');
        static::__assert_not_linked($begin['row_id'], 'wrong leg');
    }

    public static function test_leg2_refuses_a_browser_whose_staff_cookie_names_another_row()
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);

        $other = new Session();
        $other->session_token = bin2hex(random_bytes(32));
        $other->csrf_token = bin2hex(random_bytes(32));
        $other->ip_address = '10.0.0.12';
        $other->user_agent = 'session-link-test';
        $other->last_active = now();
        $other->active = true;
        $other->site_id = self::SITE_ID;
        $other->login_user_id = $begin['staff']->login_user_id;
        $other->type_id = Session::TYPE_WEB;
        $other->version = 1;
        $other->save();

        static::__assert_refused(static::__leg2($leg1['location'], $other->session_token), 'another browser');
        static::__assert_not_linked($begin['row_id'], 'another browser');
        static::__assert_not_linked($other->id, 'another browser (its own row)');
    }

    public static function test_leg2_refuses_a_browser_with_no_staff_cookie()
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);

        static::__assert_refused(static::__leg2($leg1['location'], null), 'no cookie');
        static::__assert_not_linked($begin['row_id'], 'no cookie');
    }

    public static function test_leg2_refuses_an_impersonator_who_lost_can_impersonate()
    {
        $begin = static::__begin();
        $leg1 = static::__leg1($begin['leg1']);

        // The permission is withdrawn between the click and the redirect.
        Portal_Impersonation_Grant_Fixture::$grant = false;

        static::__assert_refused(static::__leg2($leg1['location'], $begin['token']), 'demoted');
        static::__assert_not_linked($begin['row_id'], 'demoted');
    }

    // =====================================================================
    // Leg 3 refusals
    // =====================================================================

    public static function test_leg3_refuses_a_missing_nonce_cookie()
    {
        $run = static::__through_leg2();

        static::__assert_refused(Session_Link_Controller::complete(static::__on($run['leg3'])), 'no nonce');
        static::__assert_null(
            Session_Link::complete(static::__on($run['leg3'], [Session_Link::NONCE_COOKIE => $run['nonce']])),
            'the refusal burned the code: the right nonce afterwards does not revive it'
        );
    }

    public static function test_leg3_refuses_a_mismatched_nonce_cookie()
    {
        $run = static::__through_leg2();

        static::__assert_refused(
            Session_Link_Controller::complete(static::__on($run['leg3'], [Session_Link::NONCE_COOKIE => bin2hex(random_bytes(32))])),
            'another browser\'s nonce'
        );
    }

    public static function test_leg3_refuses_a_replayed_code()
    {
        $run = static::__through_leg2();
        $cookies = [Session_Link::NONCE_COOKIE => $run['nonce']];

        static::__assert_equals($run['begin']['row_id'], Session_Link::complete(static::__on($run['leg3'], $cookies)), 'first use');
        static::__assert_refused(Session_Link_Controller::complete(static::__on($run['leg3'], $cookies)), 'replay');
    }

    public static function test_leg3_refuses_an_expired_code()
    {
        $run = static::__through_leg2();

        DB::table('_session_links')->where('session_id', $run['begin']['row_id'])->update(['expires_at' => now()->subSeconds(1)]);

        static::__assert_refused(
            Session_Link_Controller::complete(static::__on($run['leg3'], [Session_Link::NONCE_COOKIE => $run['nonce']])),
            'expired'
        );
    }

    public static function test_leg3_refuses_a_tampered_signature()
    {
        $run = static::__through_leg2();

        static::__assert_refused(
            Session_Link_Controller::complete(static::__on(static::__tampered($run['leg3']), [Session_Link::NONCE_COOKIE => $run['nonce']])),
            'tampered'
        );
    }

    // =====================================================================
    // The sweep and the cookie seam
    // =====================================================================

    public static function test_the_session_cleanup_sweep_removes_only_expired_links()
    {
        $begin = static::__begin();
        static::__leg1($begin['leg1']); // leaves one live leg-2 code

        DB::table('_session_links')->insert([
            'code_hash' => hash('sha256', 'expired-' . uniqid()),
            'leg' => Session_Link::LEG_OPEN,
            'session_id' => $begin['row_id'],
            'nonce_hash' => null,
            'payload' => '[]',
            'expires_at' => now()->subSeconds(1),
        ]);

        $task = new Task_Instance(Session_Cleanup_Service::class, 'cleanup_session_links');
        $result = Session_Cleanup_Service::cleanup_session_links($task);

        static::__assert_true($result['total_deleted'] >= 1, 'the expired link is reclaimed');
        static::__assert_equals(0, DB::table('_session_links')->where('expires_at', '<', now())->count(), 'no expired link remains');
        static::__assert_equals(1, DB::table('_session_links')->where('session_id', $begin['row_id'])->count(), 'the live link is kept');
    }

    public static function test_the_cookie_seam_refuses_in_cli()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Session::_clone_session_to_this_host(1);
        }, 'no browser');
    }
}
