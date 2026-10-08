<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRequirements\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Login\Login_Requirements;
use App\RSpade\Core\Login\Login_Requirements_Health_Checks;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Rsx_Response_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\LoginRequirements\Php\Login_Requirements_Fixture_Controller;
use App\RSpade\Tests\LoginRequirements\Php\Login_Requirements_Portal_Fixture;
use App\RSpade\Tests\LoginRequirements\Php\Login_Requirements_Second_Staff_Fixture;
use App\RSpade\Tests\LoginRequirements\Php\Login_Requirements_Staff_Fixture;

/**
 * Login requirements: a signed-in identity with an unmet requirement reads as signed OUT to
 * every identity reader, except on the surfaces the requirement lists.
 *
 * THE CENTRAL PROPERTY is that the concealment is in the READERS and fails closed: a surface
 * nobody listed sees an anonymous caller, so nothing has to remember to check a flag.
 *
 * Driven in process. The CLI is never subject to requirements in production, so every test
 * switches the CLI enforcement seam on (Login_Requirements::$_enforce_in_cli_for_testing),
 * which makes the CLI identity behave exactly as a browser session's; teardown resets it.
 * The fixture requirements are inert until a test names an id in their $unsatisfied lists.
 */
class Login_Requirements_Test extends Rsx_Test_Abstract
{
    private const SCREEN = 'Login_Requirements_Fixture_Controller::screen';
    private const LISTED = 'Login_Requirements_Fixture_Controller::listed';
    private const UNLISTED = 'Login_Requirements_Fixture_Controller::unlisted';

    public static function setup(): void
    {
        Login_Requirements::_reset_for_testing();
        Login_Requirements::$_enforce_in_cli_for_testing = true;
    }

    public static function teardown(): void
    {
        Login_Requirements_Staff_Fixture::$unsatisfied = [];
        Login_Requirements_Staff_Fixture::$while_impersonating = false;
        Login_Requirements_Second_Staff_Fixture::$unsatisfied = [];
        Login_Requirements_Portal_Fixture::$unsatisfied = [];

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        static::__reset_session();

        Login_Requirements::_reset_for_testing();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A login identity with an enabled membership on the session's site.
     */
    private static function __make_user(): User_Model
    {
        $email = 'login_requirements_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Requirement';
        $user->is_enabled = 1;
        $user->save();

        return $user;
    }

    /**
     * Sign a user in the way a login function does, and start a fresh request: nothing
     * bound yet.
     */
    private static function __sign_in(User_Model $user): void
    {
        Session::set_site_id((int) $user->site_id);
        Session::set_login_user_id((int) $user->login_user_id);
        Login_Requirements::_reset_request_state();
    }

    /**
     * A user who has not met the first staff fixture requirement, signed in.
     */
    private static function __pending_user(): User_Model
    {
        $user = static::__make_user();
        Login_Requirements_Staff_Fixture::$unsatisfied = [(int) $user->login_user_id];
        static::__sign_in($user);

        return $user;
    }

    // -------------------------------------------------------------------------
    // The concealment
    // -------------------------------------------------------------------------

    /**
     * With nothing outstanding a sign-in is a sign-in: the readers answer normally.
     */
    public static function test_nothing_outstanding_reads_as_signed_in()
    {
        $user = static::__make_user();
        static::__sign_in($user);

        static::__assert_equals([], Login_Requirements::outstanding('staff'));
        static::__assert_true(Session::is_logged_in(), 'signed in');
        static::__assert_equals((int) $user->id, (int) Session::get_user()->id);
    }

    /**
     * THE CENTRAL PROPERTY. An unmet requirement conceals the identity from every reader
     * before any surface is bound - and the identity is still there underneath.
     */
    public static function test_an_unmet_requirement_conceals_the_identity()
    {
        $user = static::__pending_user();

        static::__assert_equals(['Login_Requirements_Staff_Fixture'], Login_Requirements::outstanding('staff'));
        static::__assert_true(Login_Requirements::is_pending('staff'));

        static::__assert_false(Session::is_logged_in(), 'reads as signed out');
        static::__assert_null(Session::get_login_user_id());
        static::__assert_null(Session::get_login_user());
        static::__assert_null(Session::get_user());

        static::__assert_equals((int) $user->login_user_id, Session::_get_login_user_id_unconcealed(), 'but the identity is held');
    }

    /**
     * The requirement's screen and its listed surfaces see the identity; anything else does
     * not.
     */
    public static function test_the_screen_and_listed_surfaces_see_the_identity()
    {
        $user = static::__pending_user();

        Login_Requirements::_bind_surface(self::SCREEN);
        static::__assert_true(Session::is_logged_in(), 'the screen sees the user');
        static::__assert_equals((int) $user->id, (int) Session::get_user()->id);

        Login_Requirements::_bind_surface(self::LISTED);
        static::__assert_true(Session::is_logged_in(), 'a listed surface sees the user');

        Login_Requirements::_bind_surface(self::UNLISTED);
        static::__assert_false(Session::is_logged_in(), 'an unlisted surface does not');
    }

    /**
     * Meeting the requirement admits the user on the next request for anything else: a
     * refused surface re-evaluates once before turning the user away.
     */
    public static function test_meeting_the_requirement_admits_on_the_next_refused_request()
    {
        static::__pending_user();

        Login_Requirements::_bind_surface(self::UNLISTED);
        static::__assert_false(Session::is_logged_in(), 'still outstanding');

        // The user does what was asked, and asks for something else in a new request.
        Login_Requirements_Staff_Fixture::$unsatisfied = [];
        Login_Requirements::_reset_request_state();
        Login_Requirements::_bind_surface(self::UNLISTED);

        static::__assert_true(Session::is_logged_in(), 'admitted');
        static::__assert_equals([], Login_Requirements::outstanding('staff'), 'and the list is cleared');
    }

    // -------------------------------------------------------------------------
    // Order, destination, recheck
    // -------------------------------------------------------------------------

    /**
     * Requirements are taken in ORDER; the destination is the first one's screen, and
     * recheck() moves on to the next once the first is met.
     */
    public static function test_order_destination_and_recheck()
    {
        $user = static::__make_user();
        Login_Requirements_Staff_Fixture::$unsatisfied = [(int) $user->login_user_id];
        Login_Requirements_Second_Staff_Fixture::$unsatisfied = [(int) $user->login_user_id];
        static::__sign_in($user);

        static::__assert_equals(
            ['Login_Requirements_Staff_Fixture', 'Login_Requirements_Second_Staff_Fixture'],
            Login_Requirements::outstanding('staff')
        );
        static::__assert_equals(Rsx::Route(self::SCREEN), Login_Requirements::destination('staff'));

        Login_Requirements_Staff_Fixture::$unsatisfied = [];
        static::__assert_equals(
            Rsx::Route('Login_Requirements_Fixture_Controller::second_screen'),
            Login_Requirements::recheck('staff'),
            'the next requirement'
        );

        Login_Requirements_Second_Staff_Fixture::$unsatisfied = [];
        static::__assert_null(Login_Requirements::recheck('staff'), 'everything met');
    }

    // -------------------------------------------------------------------------
    // The dispatch seams
    // -------------------------------------------------------------------------

    /**
     * An Ajax endpoint the requirement does not list answers ERROR_REQUIREMENT_PENDING with
     * the screen as its destination; a listed one runs.
     */
    public static function test_ajax_steers_to_the_requirement()
    {
        static::__pending_user();
        $request = Request::create('/_ajax/Login_Requirements_Fixture_Controller/unlisted', 'POST');

        $refused = Ajax::execute('Login_Requirements_Fixture_Controller', 'unlisted', [], $request);

        static::__assert_true($refused instanceof Rsx_Response_Abstract, 'refused');
        static::__assert_equals(Ajax::ERROR_REQUIREMENT_PENDING, $refused->get_type());
        static::__assert_equals(Rsx::Route(self::SCREEN), $refused->get_details()['destination']);

        $listed = Ajax::execute('Login_Requirements_Fixture_Controller', 'listed', [], $request);

        static::__assert_equals(Login_Requirements_Fixture_Controller::DISPATCHED, $listed['marker'] ?? null, 'the listed endpoint runs');
    }

    /**
     * A page the requirement does not list redirects to its screen - not to the login page.
     */
    public static function test_a_refused_page_redirects_to_the_screen()
    {
        static::__pending_user();

        $response = Dispatcher::dispatch(
            '/_test/login-requirements/unlisted-page',
            'GET',
            [],
            Request::create('/_test/login-requirements/unlisted-page', 'GET')
        );

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/_test/login-requirements/screen', (string) $response->headers->get('Location'));

        Login_Requirements::_reset_request_state();

        $screen = Dispatcher::dispatch(
            '/_test/login-requirements/screen',
            'GET',
            [],
            Request::create('/_test/login-requirements/screen', 'GET')
        );

        static::__assert_equals(200, $screen->getStatusCode(), 'the screen itself is served');
        static::__assert_contains(Login_Requirements_Fixture_Controller::DISPATCHED, $screen->getContent());
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Signing out clears the realm's list; it does not follow the session to the next
     * identity.
     */
    public static function test_signing_out_clears_the_list()
    {
        static::__pending_user();
        static::__assert_true(Login_Requirements::is_pending('staff'));

        Session::logout();

        static::__assert_false(Login_Requirements::is_pending('staff'));
    }

    /**
     * A requirement does not apply while impersonating unless it says so.
     */
    public static function test_impersonation_is_exempt_unless_the_requirement_says_otherwise()
    {
        $user = static::__make_user();
        Login_Requirements_Staff_Fixture::$unsatisfied = [(int) $user->login_user_id];

        Session::cli_set_impersonator_login_user_id(1);
        static::__sign_in($user);

        static::__assert_false(Login_Requirements::is_pending('staff'), 'exempt by default');

        Login_Requirements_Staff_Fixture::$while_impersonating = true;
        Login_Requirements::recheck('staff');

        static::__assert_true(Login_Requirements::is_pending('staff'), 'unless it applies while impersonating');
    }

    /**
     * recheck_user() re-evaluates a user's live sessions - the policy-changed-mid-session
     * path - writing each row's list.
     */
    public static function test_recheck_user_updates_live_sessions()
    {
        $user = static::__make_user();

        $session_id = DB::table('_sessions')->insertGetId([
            'active' => 1,
            'site_id' => (int) $user->site_id,
            'login_user_id' => (int) $user->login_user_id,
            'session_token' => random_hash(32),
            'ip_address' => '127.0.0.1',
            'last_active' => date('Y-m-d H:i:s'),
        ]);

        Login_Requirements_Staff_Fixture::$unsatisfied = [(int) $user->login_user_id];
        Login_Requirements::recheck_user($user);

        $stored = json_decode((string) DB::table('_sessions')->where('id', $session_id)->value('login_requirements'), true);
        static::__assert_equals(['staff' => ['Login_Requirements_Staff_Fixture']], $stored);

        Login_Requirements_Staff_Fixture::$unsatisfied = [];
        Login_Requirements::recheck_user($user);

        static::__assert_null(DB::table('_sessions')->where('id', $session_id)->value('login_requirements'), 'none outstanding is stored as NULL');
    }

    // -------------------------------------------------------------------------
    // The portal realm
    // -------------------------------------------------------------------------

    /**
     * The portal realm is its own list: a portal requirement conceals the portal identity
     * and leaves a staff sign-in on the same session alone.
     */
    public static function test_the_portal_realm_is_separate()
    {
        $staff_user = static::__make_user();
        static::__sign_in($staff_user);

        $site_id = (int) $staff_user->site_id;
        Portal_Session::set_site_id($site_id);
        Rsx_Portal::set_portal_request(true);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = $site_id;
        $portal_user->email = 'login_requirements_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Login_Requirements_Portal_Fixture::$unsatisfied = [(int) $portal_user->id];
        Portal_Session::set_portal_user_id((int) $portal_user->id);
        Login_Requirements::_reset_request_state();

        static::__assert_equals(['Login_Requirements_Portal_Fixture'], Login_Requirements::outstanding('portal'));
        static::__assert_false(Portal_Session::is_logged_in(), 'the portal identity is concealed');
        static::__assert_true(Session::is_logged_in(), 'the staff sign-in is not');

        Login_Requirements::_bind_surface('Login_Requirements_Portal_Fixture_Controller::screen');
        static::__assert_true(Portal_Session::is_logged_in(), 'the portal screen sees the portal user');
        static::__assert_equals(Rsx_Portal::Route('Login_Requirements_Portal_Fixture_Controller::screen'), Login_Requirements::destination('portal'));
    }

    // -------------------------------------------------------------------------
    // The health row
    // -------------------------------------------------------------------------

    /**
     * The declaration check passes for well-formed requirements and counts them per realm.
     */
    public static function test_the_health_row_accepts_well_formed_requirements()
    {
        $row = Login_Requirements_Health_Checks::login_requirements();

        static::__assert_equals('OK', $row['status'], $row['detail']);
        static::__assert_contains('portal', $row['detail']);
    }
}
