<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;

/**
 * TRUSTED BROWSERS: after a passed challenge the application may trust this browser for the
 * realm's window, and ask before the next challenge whether it is trusted.
 *
 * PINNED:
 *  - trust_this_device() refuses unless the identity passed a second factor in this request,
 *    and refuses while the realm's window is 0;
 *  - a trusted browser answers device_is_trusted() for that identity, on that device only,
 *    filtered by the kind of answer that earned it, and stops at trusted_until;
 *  - the realms are separate: staff trust is invisible to the portal and vice versa, and a
 *    realm at 0 never answers true;
 *  - trust ends on a password change, on factor removal, on ending one's other sessions
 *    (this browser kept), and the cleanup task removes what has expired.
 *
 * The "browser" is a _sessions row named through Session::$_browser_session_id_for_tests (a
 * CLI process has no browser). Rows roll back with the per-test transaction.
 */
class Two_Factor_Trusted_Device_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    public static function teardown()
    {
        Session::$_browser_session_id_for_tests = null;
        Rsx_Two_Factor::_reset_request_state();
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();
        config(['rsx.two_factor.trusted_device_days' => ['staff' => 0, 'portal' => 0]]);
    }

    private static function __device(): int
    {
        $session_id = static::__insert_session_fixture()['id'];
        Session::$_browser_session_id_for_tests = $session_id;

        return $session_id;
    }

    private static function __make_login_user(): Login_User_Model
    {
        Session::logout();
        static::__reset_session();
        Rsx_Two_Factor::_reset_request_state();

        $email = 'trust_' . uniqid() . '@example.com';

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
        $user->last_name = 'Trust';
        $user->is_enabled = 1;
        $user->save();

        Rsx_Two_Factor::clear_failures($login_user);

        return $login_user;
    }

    /** Pass a staff challenge with an emailed code. */
    private static function __verify_staff(Login_User_Model $login_user): void
    {
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        Rsx_Two_Factor::verify_challenge(['code' => Rsx_Two_Factor::issue_code()]);
    }

    public static function test_trust_needs_a_second_factor_passed_in_this_request()
    {
        config(['rsx.two_factor.trusted_device_days.staff' => 7]);
        static::__device();
        $login_user = static::__make_login_user();

        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::trust_this_device($login_user), 'only valid in the request');

        static::__verify_staff($login_user);
        $other = static::__make_login_user();
        static::__verify_staff($login_user);

        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::trust_this_device($other), 'only valid in the request', 'trust is for the identity that passed');
    }

    public static function test_a_realm_at_zero_refuses_and_never_trusts()
    {
        config(['rsx.two_factor.trusted_device_days.staff' => 0]);
        static::__device();
        $login_user = static::__make_login_user();
        static::__verify_staff($login_user);

        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::trust_this_device($login_user), 'trusted browsers are off');
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user));
    }

    public static function test_a_trusted_browser_is_trusted_for_that_identity_device_and_window()
    {
        config(['rsx.two_factor.trusted_device_days.staff' => 7]);
        $device_id = static::__device();
        $login_user = static::__make_login_user();
        static::__verify_staff($login_user);

        Rsx_Two_Factor::trust_this_device($login_user);

        static::__assert_true(Rsx_Two_Factor::device_is_trusted($login_user));
        static::__assert_true(Rsx_Two_Factor::device_is_trusted($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]));
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user, [Rsx_Two_Factor::ANSWER_TOTP]), 'filtered by what earned it');

        static::__device();
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user), 'another browser is not trusted');

        Session::$_browser_session_id_for_tests = $device_id;
        DB::table('_two_factor_trusted_devices')->update(['trusted_until' => now()->subMinute()->format('Y-m-d H:i:s.v')]);
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user), 'trust ends at trusted_until');

        static::__assert_equals(1, Rsx_Two_Factor::_delete_expired_trust(), 'and the cleanup removes it');
    }

    public static function test_the_realms_are_separate()
    {
        config(['rsx.two_factor.trusted_device_days' => ['staff' => 7, 'portal' => 7]]);
        static::__device();
        $login_user = static::__make_login_user();
        static::__verify_staff($login_user);
        Rsx_Two_Factor::trust_this_device($login_user);

        static::__assert_false(Rsx_Portal_Two_Factor::device_is_trusted((int) $login_user->id), 'staff trust is not portal trust for the same id');

        config(['rsx.two_factor.trusted_device_days.staff' => 0]);
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user), 'a realm turned off never trusts');
    }

    public static function test_the_portal_realm_trusts_on_its_own()
    {
        config(['rsx.two_factor.trusted_device_days' => ['staff' => 0, 'portal' => 7]]);
        static::__device();
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id(1);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Two_Factor::_reset_request_state();

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = 1;
        $portal_user->email = 'trust_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Rsx_Portal_Two_Factor::clear_failures($portal_user);
        Rsx_Portal_Two_Factor::begin_challenge($portal_user, [Rsx_Portal_Two_Factor::ANSWER_ISSUED_CODE]);
        Rsx_Portal_Two_Factor::verify_challenge(['code' => Rsx_Portal_Two_Factor::issue_code()]);

        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::trust_this_device(Login_User_Model::find(1)), 'only valid in the request', 'a portal verification mints no staff trust');

        Rsx_Portal_Two_Factor::trust_this_device($portal_user);
        static::__assert_true(Rsx_Portal_Two_Factor::device_is_trusted($portal_user));

        $portal_user->set_password('another-password');
        $portal_user->save();
        static::__assert_false(Rsx_Portal_Two_Factor::device_is_trusted($portal_user), 'a changed password ends trust');
    }

    public static function test_password_change_and_factor_removal_end_trust()
    {
        config(['rsx.two_factor.trusted_device_days.staff' => 7]);
        static::__device();
        $login_user = static::__make_login_user();
        static::__verify_staff($login_user);
        Rsx_Two_Factor::trust_this_device($login_user);

        $login_user->password = Hash::make('changed');
        $login_user->save();
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user), 'a changed password ends trust');

        static::__verify_staff($login_user);
        Rsx_Two_Factor::trust_this_device($login_user);
        Rsx_Two_Factor::remove_all($login_user);
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user), 'removing factors ends trust');
    }

    public static function test_forgetting_other_devices_keeps_this_one()
    {
        config(['rsx.two_factor.trusted_device_days.staff' => 7]);
        $first = static::__device();
        $login_user = static::__make_login_user();
        static::__verify_staff($login_user);
        Rsx_Two_Factor::trust_this_device($login_user);

        $second = static::__device();
        static::__verify_staff($login_user);
        Rsx_Two_Factor::trust_this_device($login_user);

        static::__assert_equals(1, Rsx_Two_Factor::forget_trusted_devices($login_user, true));
        static::__assert_true(Rsx_Two_Factor::device_is_trusted($login_user), 'this browser is kept');

        Session::$_browser_session_id_for_tests = $first;
        static::__assert_false(Rsx_Two_Factor::device_is_trusted($login_user), 'the other is forgotten');
        static::__assert_true($second !== $first);
    }
}
