<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\PasswordConfirmation\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Confirmation\Rsx_Password_Confirmation;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * "Confirm it's you": Rsx_Password_Confirmation and the framework endpoints it guards.
 *
 * PINNED:
 *  - nothing is confirmed until the password (or one of the identity's own passkeys) is given;
 *    a wrong password confirms nothing;
 *  - the confirmation belongs to the identity it was given for, ends with clear(), and ends
 *    when its expiry passes;
 *  - impersonation can neither give nor use one;
 *  - a gated framework endpoint refuses with the "refresh and try again" message until the
 *    confirmation is given, and runs once it is;
 *  - the portal realm confirms its own user, separately from staff.
 *
 * The rate-limit refusal is Login_Throttle's (tests/session) and needs a client address, which
 * a CLI process has none of.
 */
class Password_Confirmation_Test extends Rsx_Test_Abstract
{
    /** The confirmation is a session value: each test acts as a browser. */
    protected static $acts_as_browser = true;

    private const PASSWORD = 'correct-horse-battery-staple';

    public static function teardown()
    {
        Session::cli_set_impersonator_login_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();
    }

    /** A signed-in staff identity with an active membership and the known password. */
    private static function __signed_in_staff(): Login_User_Model
    {
        // teardown() runs once per CLASS, so each fixture starts from a plain staff request.
        Session::cli_set_impersonator_login_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);

        $email = 'confirm_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Confirm';
        $user->is_enabled = 1;
        $user->save();

        Session::set_login_user_id((int) $login_user->id, false);

        return $login_user;
    }

    public static function test_nothing_is_confirmed_until_the_password_is_given()
    {
        static::__signed_in_staff();

        static::__assert_false(Rsx_Password_Confirmation::is_confirmed());
        static::__assert_throws(AjaxFormErrorException::class, fn () => Rsx_Password_Confirmation::require_confirmed(), 'timed out');

        static::__assert_false(Rsx_Password_Confirmation::confirm_password('wrong'), 'a wrong password');
        static::__assert_false(Rsx_Password_Confirmation::is_confirmed(), 'confirms nothing');

        static::__assert_true(Rsx_Password_Confirmation::confirm_password(self::PASSWORD), 'the right one');
        static::__assert_true(Rsx_Password_Confirmation::is_confirmed());
        Rsx_Password_Confirmation::require_confirmed();
    }

    public static function test_options_describe_what_can_confirm()
    {
        static::__signed_in_staff();

        $options = Rsx_Password_Confirmation::options();

        static::__assert_true($options['has_password']);
        static::__assert_false($options['has_passkey']);
        static::__assert_false($options['confirmed']);
        static::__assert_equals(5, $options['minutes']);
    }

    public static function test_the_confirmation_belongs_to_its_identity()
    {
        static::__signed_in_staff();
        Rsx_Password_Confirmation::confirm_password(self::PASSWORD);

        static::__signed_in_staff();

        static::__assert_false(Rsx_Password_Confirmation::is_confirmed(), 'another identity on the same browser is not confirmed');
    }

    public static function test_clear_ends_it()
    {
        static::__signed_in_staff();
        Rsx_Password_Confirmation::confirm_password(self::PASSWORD);

        Rsx_Password_Confirmation::clear();

        static::__assert_false(Rsx_Password_Confirmation::is_confirmed());
    }

    public static function test_it_ends_when_its_expiry_passes()
    {
        static::__signed_in_staff();
        Rsx_Password_Confirmation::confirm_password(self::PASSWORD);

        DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->where('value_key', Rsx_Password_Confirmation::SESSION_KEY)
            ->update(['expires_at' => '2000-01-01 00:00:00']);

        static::__assert_false(Rsx_Password_Confirmation::is_confirmed(), 'an expired confirmation is none');
    }

    public static function test_impersonation_can_neither_give_nor_use_one()
    {
        $target = static::__signed_in_staff();
        Session::cli_set_impersonator_login_user_id((int) $target->id + 100000);

        static::__assert_throws(AjaxFormErrorException::class, fn () => Rsx_Password_Confirmation::confirm_password(self::PASSWORD), 'impersonating');
        static::__assert_throws(AjaxFormErrorException::class, fn () => Rsx_Password_Confirmation::require_confirmed(), 'impersonating');
    }

    /**
     * A gated framework endpoint: credential_remove with no id answers "No credential was
     * named." once it runs, so reaching that message proves the gate let it through.
     */
    public static function test_a_gated_endpoint_refuses_until_confirmed()
    {
        static::__signed_in_staff();

        static::__assert_throws(
            AjaxFormErrorException::class,
            fn () => Ajax::internal('Rsx_Two_Factor_Controller', 'credential_remove', []),
            'timed out'
        );

        Rsx_Password_Confirmation::confirm_password(self::PASSWORD);

        static::__assert_throws(
            \Throwable::class,
            fn () => Ajax::internal('Rsx_Two_Factor_Controller', 'credential_remove', []),
            'No credential was named'
        );
    }

    public static function test_a_passkey_of_the_identity_confirms_and_another_does_not()
    {
        static::__signed_in_staff();

        $authenticator = new Webauthn_Authenticator_Fixture(Rsx_Two_Factor::_relying_party_id());
        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
            'Fixture key'
        );

        static::__assert_true(Rsx_Password_Confirmation::options()['has_passkey']);

        $stranger = new Webauthn_Authenticator_Fixture(Rsx_Two_Factor::_relying_party_id());
        Rsx_Password_Confirmation::passkey_options();
        static::__assert_false(
            Rsx_Password_Confirmation::confirm_passkey($stranger->assertion_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY), 1)),
            'a key this server does not know'
        );
        static::__assert_false(Rsx_Password_Confirmation::is_confirmed());

        Rsx_Password_Confirmation::passkey_options();
        static::__assert_true(
            Rsx_Password_Confirmation::confirm_passkey($authenticator->assertion_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY), 1)),
            'the identity\'s own key'
        );
        static::__assert_true(Rsx_Password_Confirmation::is_confirmed());
    }

    public static function test_the_portal_confirms_its_own_user()
    {
        // A staff confirmation on this browser...
        static::__signed_in_staff();
        Rsx_Password_Confirmation::confirm_password(self::PASSWORD);

        // ...is not one for the portal.
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id(1);
        Rsx_Portal::set_portal_request(true);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = 1;
        $portal_user->email = 'confirm_portal_' . uniqid() . '@example.com';
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        static::__assert_false(Rsx_Password_Confirmation::is_confirmed(), 'the staff confirmation does not cross realms');
        static::__assert_false(Rsx_Password_Confirmation::confirm_password('wrong'));
        static::__assert_true(Rsx_Password_Confirmation::confirm_password(self::PASSWORD));
        static::__assert_true(Rsx_Password_Confirmation::is_confirmed());
    }
}
