<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\Forced_Password_Change_Exception;
use App\RSpade\Core\Auth\Portal_Auth;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;
use App\RSpade\Lib\Portal\Rsx_Portal;

/**
 * The forced password change at sign-in: RsxAuth::begin_forced_password_change() /
 * forced_password_change_pending() / complete_forced_password_change() and the portal twins
 * on Portal_Auth (engine: Forced_Password_Change).
 *
 * WHAT IS PINNED:
 *  - begin signs the realm out and parks the identity: nothing is signed in, the pending
 *    address is readable;
 *  - complete saves the new password through the model (the flag clears, the new password
 *    verifies, the old does not), forgets the parked value, answers the identity and STILL
 *    signs nobody in and records no success - the caller's sign-in does both;
 *  - the current password and an empty one are refused with the parked value kept;
 *  - nothing pending, and a parked value whose identity's password changed some other way
 *    since (stale proof), are both REASON_NOTHING_PENDING;
 *  - the portal realm behaves the same, on the portal's own site.
 */
class Forced_Password_Change_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one. */
    protected static $acts_as_browser = true;

    private const PASSWORD = 'the-temporary-password';

    private const NEW_PASSWORD = 'the-users-own-password';

    public static function teardown()
    {
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();
    }

    private static function __flagged_login_user(): Login_User_Model
    {
        $email = 'forced_change_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->require_password_reset();
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Forced';
        $user->last_name = 'Change';
        $user->is_enabled = 1;
        $user->save();

        return $login_user;
    }

    /**
     * The identity the verifier hands a login function for a flagged identity.
     */
    private static function __proven(Login_User_Model $login_user): Login_User_Model
    {
        try {
            RsxAuth::verify_credentials(['email' => $login_user->email, 'password' => self::PASSWORD], record: false);
        } catch (Auth_Password_Reset_Required_Exception $e) {
            return $e->identity;
        }

        shouldnt_happen('a flagged identity was not refused');
    }

    private static function __stored(int $id, string $table = 'login_users'): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    /**
     * fpc-01: begin signs the staff realm out and parks the identity; nothing is signed in.
     */
    public static function test_begin_parks_the_identity_signed_out()
    {
        $login_user = static::__flagged_login_user();

        Session::set_login_user_id((int) $login_user->id);
        RsxAuth::begin_forced_password_change(static::__proven($login_user));

        static::__assert_false(Session::is_logged_in(), 'half-authenticated is NOT authenticated');
        static::__assert_equals(['email' => $login_user->email], RsxAuth::forced_password_change_pending());
    }

    /**
     * fpc-02: complete saves through the model, clears the flag, forgets the parked value and
     * answers the identity - signing nobody in and recording no success.
     */
    public static function test_complete_saves_the_password_and_signs_nobody_in()
    {
        $login_user = static::__flagged_login_user();
        RsxAuth::begin_forced_password_change(static::__proven($login_user));

        $identity = RsxAuth::complete_forced_password_change(self::NEW_PASSWORD);

        static::__assert_equals((int) $login_user->id, (int) $identity->id);

        $row = static::__stored((int) $login_user->id);
        static::__assert_true(Hash::check(self::NEW_PASSWORD, $row->password), 'the new password is stored');
        static::__assert_false(Hash::check(self::PASSWORD, $row->password), 'the temporary one is gone');
        static::__assert_equals(0, (int) $row->password_reset_required, 'the flag is cleared');

        static::__assert_null(RsxAuth::forced_password_change_pending(), 'the parked value is forgotten');
        static::__assert_false(Session::is_logged_in(), 'nobody is signed in by the step');
        static::__assert_equals(
            0,
            DB::table('_login_history')->where('login_user_id', $login_user->id)->where('status', Login_History::STATUS_SUCCESS)->count(),
            'no success is recorded by the step'
        );

        $verified = RsxAuth::verify_credentials(['email' => $login_user->email, 'password' => self::NEW_PASSWORD], record: false);
        static::__assert_equals((int) $login_user->id, (int) $verified->id, 'the new password signs in without the refusal');
    }

    /**
     * fpc-03: the current password and an empty one are refused, nothing changes and the step
     * stays pending.
     */
    public static function test_the_current_password_and_an_empty_one_are_refused()
    {
        $login_user = static::__flagged_login_user();
        RsxAuth::begin_forced_password_change(static::__proven($login_user));

        foreach ([self::PASSWORD => Forced_Password_Change_Exception::REASON_UNCHANGED, '' => Forced_Password_Change_Exception::REASON_EMPTY] as $password => $reason) {
            $thrown = null;

            try {
                RsxAuth::complete_forced_password_change((string) $password);
            } catch (Forced_Password_Change_Exception $e) {
                $thrown = $e;
            }

            static::__assert_not_null($thrown, "refused: {$reason}");
            static::__assert_equals($reason, $thrown->reason());
        }

        static::__assert_equals(1, (int) static::__stored((int) $login_user->id)->password_reset_required, 'still flagged');
        static::__assert_not_null(RsxAuth::forced_password_change_pending(), 'still pending');
    }

    /**
     * fpc-04: nothing pending, and a parked value made stale by a password change elsewhere,
     * are both "nothing pending".
     */
    public static function test_nothing_pending_and_a_stale_proof_are_refused()
    {
        $thrown = null;

        try {
            RsxAuth::complete_forced_password_change(self::NEW_PASSWORD);
        } catch (Forced_Password_Change_Exception $e) {
            $thrown = $e;
        }

        static::__assert_equals(Forced_Password_Change_Exception::REASON_NOTHING_PENDING, $thrown?->reason(), 'never begun');

        $login_user = static::__flagged_login_user();
        RsxAuth::begin_forced_password_change(static::__proven($login_user));

        // An administrator sets another temporary password meanwhile.
        $other = Login_User_Model::where('id', $login_user->id)->first();
        $other->set_password('a-second-temporary-password');
        $other->require_password_reset();
        $other->save();

        static::__assert_null(RsxAuth::forced_password_change_pending(), 'the proof is stale');
        static::__assert_throws(
            Forced_Password_Change_Exception::class,
            fn () => RsxAuth::complete_forced_password_change(self::NEW_PASSWORD)
        );
        static::__assert_true(
            Hash::check('a-second-temporary-password', static::__stored((int) $login_user->id)->password),
            'the newer password stands'
        );
    }

    /**
     * fpc-05: the portal twin parks, refuses the current password and completes on the
     * portal's own site, signing nobody in.
     */
    public static function test_the_portal_realm_behaves_the_same()
    {
        $site = new Site_Model();
        $site->slug = 'forced-change-' . uniqid();
        $site->name = 'Forced Change';
        $site->is_enabled = true;
        $site->save();

        Portal_Session::_testing_reset();
        Portal_Session::set_site_id((int) $site->id);
        Rsx_Portal::set_portal_request(true);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = (int) $site->id;
        $portal_user->email = 'forced_change_portal_' . uniqid() . '@example.com';
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->require_password_reset();
        $portal_user->save();

        $proven = null;

        try {
            Portal_Auth::verify_credentials((int) $site->id, ['email' => $portal_user->email, 'password' => self::PASSWORD], record: false);
        } catch (Auth_Password_Reset_Required_Exception $e) {
            $proven = $e->identity;
        }

        Portal_Auth::begin_forced_password_change($proven);

        static::__assert_false(Portal_Session::is_logged_in());
        static::__assert_equals(['email' => $portal_user->email], Portal_Auth::forced_password_change_pending());
        static::__assert_throws(
            Forced_Password_Change_Exception::class,
            fn () => Portal_Auth::complete_forced_password_change(self::PASSWORD)
        );

        $identity = Portal_Auth::complete_forced_password_change(self::NEW_PASSWORD);

        static::__assert_equals((int) $portal_user->id, (int) $identity->id);
        static::__assert_equals(0, (int) static::__stored((int) $portal_user->id, 'portal_users')->password_reset_required);
        static::__assert_true($identity->fresh()->check_password(self::NEW_PASSWORD));
        static::__assert_null(Portal_Auth::forced_password_change_pending());
        static::__assert_false(Portal_Session::is_logged_in());
    }
}
