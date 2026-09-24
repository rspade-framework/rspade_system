<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\Hash;
use RuntimeException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Portal_Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * The CLIENT PORTAL realm of the second-factor subsystem: Rsx_Portal_Two_Factor, over
 * _portal_two_factor_credentials and Portal_Session.
 *
 * The engine is the one Rsx_Two_Factor runs and its behaviour is pinned by the staff
 * classes; what is pinned HERE is the REALM BOUNDARY, because that is what this realm adds
 * and what a mistake in it would silently break:
 *  - a portal enrollment writes the PORTAL table and never the staff one, under the
 *    'portal-<id>' user handle (a bare id would let a portal passkey overwrite a staff
 *    passkey with the same numeric id on one authenticator);
 *  - a staff passkey cannot sign anybody in on the portal, and a portal passkey cannot sign
 *    anybody in as staff - both realms share one relying party, so the table is the boundary;
 *  - a passkey signs its portal user in only on the site the application DECLARED: a valid
 *    credential of another tenant's portal user is refused;
 *  - the portal's own admission rule (Portal_User_Model::can_login()) refuses a suspended
 *    user exactly like a failure;
 *  - "View as Client" impersonation refuses enrollment;
 *  - the facade refuses an identity from the other realm at the call site.
 *
 * Every test runs as a PORTAL REQUEST declaring its site (Rsx_Portal::set_portal_request +
 * Portal_Session::set_site_id), the context the facade serves in production.
 */
class Portal_Two_Factor_Test extends Rsx_Test_Abstract
{
    private static int $_site_id = 0;

    private static int $_other_site_id = 0;

    public static function setup(): void
    {
        static::$_site_id = static::__make_site('home');
        static::$_other_site_id = static::__make_site('other');

        static::__acting_as_site(static::$_site_id);
    }

    public static function teardown(): void
    {
        Login_Throttle::reset('CLI');
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private static function __make_site(string $tag): int
    {
        $site = new Site_Model();
        $site->slug = 'portal-2fa-' . $tag . '-' . uniqid();
        $site->name = 'Portal 2FA ' . $tag;
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    /**
     * A portal request on the given site, anonymous.
     */
    private static function __as_portal(int $site_id): void
    {
        Login_Throttle::reset('CLI');
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id($site_id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
    }

    private static function __make_portal_user(int $site_id, int $status_id = Portal_User_Model::STATUS_ACTIVE): Portal_User_Model
    {
        static::__as_portal($site_id);

        $user = new Portal_User_Model();
        $user->site_id = $site_id;
        $user->email = 'portal_2fa_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = $status_id;
        $user->save();

        return $user;
    }

    /**
     * A portal user with a registered passkey, enrolled through the portal facade on their
     * own site. Leaves the portal session anonymous on that site.
     *
     * @return array {portal_user, authenticator}
     */
    private static function __enrolled_portal_user(int $site_id): array
    {
        $portal_user = static::__make_portal_user($site_id);

        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        $authenticator = new Webauthn_Authenticator_Fixture(Rsx_Portal_Two_Factor::_relying_party_id());

        Rsx_Portal_Two_Factor::begin_passkey_registration();
        Rsx_Portal_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Rsx_Portal_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
            'Portal key'
        );

        Portal_Session::cli_set_portal_user_id(0);

        return ['portal_user' => $portal_user, 'authenticator' => $authenticator];
    }

    /**
     * A staff login identity with a passkey, enrolled through the STAFF facade in a staff
     * request. Leaves the staff session anonymous.
     *
     * @return array {login_user, authenticator}
     */
    private static function __enrolled_staff_identity(): array
    {
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_site(static::$_site_id);

        $email = 'staff_2fa_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Staff';
        $user->is_enabled = 1;
        $user->save();

        Session::set_login_user_id((int) $login_user->id);

        $authenticator = new Webauthn_Authenticator_Fixture(Rsx_Two_Factor::_relying_party_id());

        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
            'Staff key'
        );

        Session::logout();

        return ['login_user' => $login_user, 'authenticator' => $authenticator];
    }

    /**
     * Begin a portal passwordless ceremony and return its challenge.
     */
    private static function __begin_portal(): string
    {
        Rsx_Portal_Two_Factor::begin_passkey_login();

        return (string) Session::get_value(Rsx_Portal_Two_Factor::PASSKEY_LOGIN_CHALLENGE_KEY);
    }

    // -------------------------------------------------------------------------
    // Enrollment
    // -------------------------------------------------------------------------

    /**
     * A portal enrollment writes the portal table, never the staff one, and the registration
     * names the portal user under a prefixed handle.
     */
    public static function test_a_portal_enrollment_writes_only_the_portal_table()
    {
        $portal_user = static::__make_portal_user(static::$_site_id);
        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        $options = Rsx_Portal_Two_Factor::begin_passkey_registration();

        static::__assert_equals(
            Webauthn_Authenticator_Fixture::base64url_encode('portal-' . $portal_user->id),
            $options['publicKey']['user']['id'],
            'the user handle is realm-prefixed'
        );

        $authenticator = new Webauthn_Authenticator_Fixture(Rsx_Portal_Two_Factor::_relying_party_id());
        $codes = Rsx_Portal_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Rsx_Portal_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
            'Portal key'
        );

        static::__assert_not_null($codes, 'the first factor mints a recovery sheet');
        static::__assert_true(Rsx_Portal_Two_Factor::is_enabled($portal_user), 'the portal user has a second factor');
        static::__assert_equals(
            1,
            Portal_Two_Factor_Credential_Model::where('portal_user_id', $portal_user->id)
                ->where('type_id', Portal_Two_Factor_Credential_Model::TYPE_PASSKEY)
                ->count(),
            'in the portal table'
        );
        static::__assert_false(
            Two_Factor_Credential_Model::where('credential_key', $authenticator->credential_key())->exists(),
            'and not in the staff table'
        );
    }

    /**
     * "View as Client" must never be able to enroll a passkey onto the client's account.
     */
    public static function test_enrollment_refuses_while_viewing_as_client()
    {
        $portal_user = static::__make_portal_user(static::$_site_id);
        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);
        Portal_Session::cli_set_impersonator_user_id(99);

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Portal_Two_Factor::begin_passkey_registration(),
            'impersonating'
        );
        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Portal_Two_Factor::begin_totp_enrollment(),
            'impersonating'
        );
    }

    /**
     * The facade refuses an identity from the other realm rather than reading its id as one
     * of its own.
     */
    public static function test_the_facade_refuses_an_identity_of_the_other_realm()
    {
        $staff = static::__enrolled_staff_identity();

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Portal_Two_Factor::is_enabled($staff['login_user']),
            'operates on Portal_User_Model'
        );
    }

    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    /**
     * A portal passkey signs its portal user in, passwordless, through Portal_Session - and
     * leaves the staff identity on the same session untouched.
     */
    public static function test_a_portal_passkey_signs_its_portal_user_in()
    {
        $fixture = static::__enrolled_portal_user(static::$_site_id);

        static::__as_portal(static::$_site_id);

        $challenge = static::__begin_portal();

        $signed_in = Rsx_Portal_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1));

        static::__assert_instance_of(Portal_User_Model::class, $signed_in);
        static::__assert_equals((int) $fixture['portal_user']->id, (int) Portal_Session::get_portal_user_id(), 'signed in to the portal');
        static::__assert_false(Session::is_logged_in(), 'and nobody is signed in to staff');
    }

    /**
     * A STAFF passkey cannot sign anybody in on the portal. Both realms share one relying
     * party, so the browser would offer it; the portal realm never reads the staff table.
     */
    public static function test_a_staff_passkey_cannot_sign_in_on_the_portal()
    {
        $staff = static::__enrolled_staff_identity();

        static::__as_portal(static::$_site_id);

        $challenge = static::__begin_portal();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Portal_Two_Factor::verify_passkey_login($staff['authenticator']->assertion_response($challenge, 1)),
            'could not sign you in'
        );

        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in to the portal');
    }

    /**
     * And the reverse: a PORTAL passkey cannot sign anybody in as staff.
     */
    public static function test_a_portal_passkey_cannot_sign_in_as_staff()
    {
        $fixture = static::__enrolled_portal_user(static::$_site_id);

        Rsx_Portal::set_portal_request(false);
        static::__acting_as_site(static::$_site_id);

        Rsx_Two_Factor::begin_passkey_login();
        $challenge = (string) Session::get_value(Rsx_Two_Factor::PASSKEY_LOGIN_CHALLENGE_KEY);

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1)),
            'could not sign you in'
        );

        static::__assert_false(Session::is_logged_in(), 'nobody is signed in to staff');
    }

    /**
     * A valid passkey of ANOTHER tenant's portal user is refused on this portal: credential
     * rows name a portal user by a global id, and the declared site is the boundary.
     */
    public static function test_a_passkey_of_another_sites_portal_user_is_refused()
    {
        $fixture = static::__enrolled_portal_user(static::$_other_site_id);

        static::__as_portal(static::$_site_id);

        $challenge = static::__begin_portal();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Portal_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1)),
            'could not sign you in'
        );

        static::__assert_false(Portal_Session::is_logged_in(), 'a foreign tenant signs nobody in here');
    }

    /**
     * The portal's own admission rule: a suspended portal user is refused exactly like a
     * failed assertion.
     */
    public static function test_a_portal_user_can_login_refuses_is_refused()
    {
        $fixture = static::__enrolled_portal_user(static::$_site_id);

        Portal_User_Model::where('id', $fixture['portal_user']->id)
            ->update(['status_id' => Portal_User_Model::STATUS_SUSPENDED]);

        static::__as_portal(static::$_site_id);

        $challenge = static::__begin_portal();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Portal_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1)),
            'could not sign you in'
        );

        static::__assert_false(Portal_Session::is_logged_in());
    }

    /**
     * The portal second-factor challenge: a portal user parked after their password answers
     * with their passkey and is signed in to the portal.
     */
    public static function test_the_portal_challenge_signs_in_with_a_portal_passkey()
    {
        $fixture = static::__enrolled_portal_user(static::$_site_id);

        static::__as_portal(static::$_site_id);

        Rsx_Portal_Two_Factor::begin_challenge($fixture['portal_user']);

        static::__assert_true(Rsx_Portal_Two_Factor::challenge_pending()['has_passkey'], 'the passkey is offered');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'and nothing is pending in the staff realm');

        Rsx_Portal_Two_Factor::challenge_passkey_options();
        $challenge = (string) Session::get_value(Rsx_Portal_Two_Factor::WEBAUTHN_CHALLENGE_KEY);

        Rsx_Portal_Two_Factor::verify_challenge([
            'assertion' => $fixture['authenticator']->assertion_response($challenge, 1),
        ]);

        static::__assert_equals((int) $fixture['portal_user']->id, (int) Portal_Session::get_portal_user_id());
        static::__assert_null(Rsx_Portal_Two_Factor::challenge_pending(), 'the challenge is spent');
    }
}
