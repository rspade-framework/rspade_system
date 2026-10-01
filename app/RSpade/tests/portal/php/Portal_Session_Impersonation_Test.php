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
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Portal\Php\Portal_Impersonation_Grant_Fixture;

/**
 * Portal_Session::begin_impersonation_from_staff() - the one entry point of staff "View as
 * Client" - plus is_impersonating() / get_impersonator_user_id() / stop_impersonation().
 *
 * Same host (the portal under a prefix on APP_URL's host): the impersonation lands on the
 * caller's own session row and the portal's landing URL comes back. Separate host
 * (PORTAL_URL names another host): nothing is written yet, and the first leg of the
 * linked-session handshake comes back, absolute on the portal origin. The handshake legs
 * themselves are Portal_Session_Link_Test.
 *
 * The caller is a real staff user whose session is this process's CLI row; its
 * can_impersonate answer is set by Portal_Impersonation_Grant_Fixture, since that check is
 * the application's rule. Layouts are config('rsx.portal.url') overrides,
 * set by each test and restored in teardown.
 *
 * Default per-test transaction (rolled back afterward). Read-only enforcement is
 * Portal_Impersonation_Read_Only_Test.
 */
class Portal_Session_Impersonation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const PORTAL_HOST = 'portal.example.test';

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
        Portal_Impersonation_Grant_Fixture::uninstall();
        static::__reset_session();
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'impersonate_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * A staff user on the test site, signed in as the caller, whose can_impersonate check
     * answers $may_impersonate (Portal_Impersonation_Grant_Fixture).
     */
    private static function __sign_in_staff(bool $may_impersonate): User_Model
    {
        Portal_Impersonation_Grant_Fixture::install($may_impersonate);
        static::__acting_as_site(self::SITE_ID);

        $email = 'impersonator_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Staff';
        $user->last_name = 'Impersonator';
        $user->role_id = static::most_privileged_role_id();
        $user->is_enabled = 1;
        $user->save();

        static::__acting_as_user($user->id);

        return $user;
    }

    /**
     * Point the configuration at a layout and the request at the staff host.
     */
    private static function __layout(string $portal_url): void
    {
        config(['rsx.portal.url' => $portal_url]);
        app()->instance('request', Request::create(rtrim((string) config('app.url'), '/') . '/contacts'));
    }

    // =====================================================================
    // Same host
    // =====================================================================

    public static function test_same_host_writes_the_impersonation_onto_the_callers_row()
    {
        static::__layout('');
        $staff = static::__sign_in_staff(true);
        $target = static::__make_portal_user();

        $url = Portal_Session::begin_impersonation_from_staff($target->id, $staff->id, self::SITE_ID);

        static::__assert_equals('/_portal/', $url, 'the portal landing page, on this host');

        $row = Session::find(Session::get_session_id());
        static::__assert_equals($target->id, (int) $row->portal_user_id, 'the target portal user, on the caller\'s own row');
        static::__assert_equals(self::SITE_ID, (int) $row->portal_site_id, 'its tenant');
        static::__assert_equals($staff->id, (int) $row->impersonator_user_id, 'the staff impersonator');
        static::__assert_not_null($row->impersonation_started_at, 'the start time');
        static::__assert_equals($staff->login_user_id, (int) $row->login_user_id, 'the staff login on that row is untouched');

        static::__assert_equals($target->id, Portal_Session::get_portal_user_id(), 'the portal identity is in force');
        static::__assert_true(Portal_Session::is_impersonating(), 'and it is an impersonation');
        static::__assert_equals($staff->id, Portal_Session::get_impersonator_user_id(), 'by the caller');
    }

    public static function test_same_host_under_another_prefix_lands_under_it()
    {
        static::__layout(rtrim((string) config('app.url'), '/') . '/clients');
        $staff = static::__sign_in_staff(true);
        $target = static::__make_portal_user();

        $url = Portal_Session::begin_impersonation_from_staff($target->id, $staff->id, self::SITE_ID);

        static::__assert_equals('/clients/', $url);
    }

    public static function test_begin_does_not_touch_the_targets_last_login()
    {
        static::__layout('');
        $staff = static::__sign_in_staff(true);
        $target = static::__make_portal_user();
        static::__assert_null($target->last_login, 'seeded user has no last_login');

        Portal_Session::begin_impersonation_from_staff($target->id, $staff->id, self::SITE_ID);

        static::__assert_null(Portal_User_Model::find($target->id)->last_login, 'impersonation must not pollute the contact last_login');
    }

    // =====================================================================
    // Separate host
    // =====================================================================

    public static function test_separate_host_returns_the_first_handshake_leg_and_writes_nothing_yet()
    {
        static::__layout('https://' . self::PORTAL_HOST . '/x');
        $staff = static::__sign_in_staff(true);
        $target = static::__make_portal_user();

        $url = Portal_Session::begin_impersonation_from_staff($target->id, $staff->id, self::SITE_ID);

        static::__assert_true(
            str_starts_with($url, 'https://' . self::PORTAL_HOST . '/x/_session_link/open?'),
            "leg 1, absolute on the portal origin, under its prefix; got {$url}"
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        static::__assert_equals(['c', 's'], array_keys($query), 'a code and a signature, nothing else');
        static::__assert_false(str_contains($url, Session::find(Session::get_session_id())->session_token), 'the session token is never in the URL');

        $link = DB::table('_session_links')->where('code_hash', hash('sha256', $query['c']))->first();
        static::__assert_not_null($link, 'the code is stored as its hash');
        static::__assert_equals(Session::get_session_id(), (int) $link->session_id, 'bound to the caller\'s row');

        $row = Session::find(Session::get_session_id());
        static::__assert_null($row->portal_user_id, 'nothing is applied until the staff leg');
        static::__assert_null($row->impersonator_user_id);
    }

    // =====================================================================
    // Refusals
    // =====================================================================

    public static function test_a_caller_without_can_impersonate_is_refused()
    {
        static::__layout('');
        $staff = static::__sign_in_staff(false);
        $target = static::__make_portal_user();

        static::__assert_throws(AjaxUnauthorizedException::class, function () use ($target, $staff) {
            Portal_Session::begin_impersonation_from_staff($target->id, $staff->id, self::SITE_ID);
        });

        static::__assert_false(Portal_Session::is_impersonating(), 'nothing was started');
    }

    public static function test_the_impersonator_must_be_the_signed_in_staff_user()
    {
        static::__layout('');
        $staff = static::__sign_in_staff(true);
        $target = static::__make_portal_user();

        static::__assert_throws(\RuntimeException::class, function () use ($target, $staff) {
            Portal_Session::begin_impersonation_from_staff($target->id, $staff->id + 1000, self::SITE_ID);
        }, 'must be the signed-in staff user');
    }

    // =====================================================================
    // is_impersonating / get_impersonator_user_id / stop_impersonation (CLI flag)
    // =====================================================================

    public static function test_is_impersonating_reflects_cli_flag()
    {
        static::__assert_false(Portal_Session::is_impersonating(), 'no impersonation by default');
        static::__assert_null(Portal_Session::get_impersonator_user_id(), 'no impersonator by default');

        Portal_Session::cli_set_impersonator_user_id(99);

        static::__assert_true(Portal_Session::is_impersonating(), 'flag set -> impersonating');
        static::__assert_equals(99, Portal_Session::get_impersonator_user_id(), 'impersonator id reported');
    }

    public static function test_stop_impersonation_clears_the_flag()
    {
        Portal_Session::cli_set_portal_user_id(123);
        Portal_Session::cli_set_impersonator_user_id(99);
        static::__assert_true(Portal_Session::is_impersonating(), 'impersonating before stop');

        Portal_Session::stop_impersonation();

        static::__assert_false(Portal_Session::is_impersonating(), 'no longer impersonating after stop');
    }
}
