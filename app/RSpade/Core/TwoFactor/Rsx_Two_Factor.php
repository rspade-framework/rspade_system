<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Abstract;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;

/**
 * Rsx_Two_Factor - THE second-factor and passkey facade for the STAFF realm, and the only
 * class in this subsystem staff application code touches.
 *
 * Credentials belong to the LOGIN IDENTITY (login_users), like the password: a second factor
 * proves who is holding the browser, which is exactly what login_users is, and enrolling a
 * phone once per tenant would be wrong. They live in _two_factor_credentials.
 *
 * The whole API - is_enabled(), enrollment, the second-factor challenge, passwordless
 * sign-in, recovery codes, the operator path - is written once in Rsx_Two_Factor_Abstract,
 * whose docblock carries the login flow and the rulings. This class binds it to the staff
 * realm: Session, RsxAuth (which refuses an identity holding no enabled site membership),
 * and Login_History (which records every outcome and feeds Login_Throttle). The portal
 * realm's facade is Rsx_Portal_Two_Factor.
 *
 * Everything the API calls an "identity" is a Login_User_Model here, and every method that
 * returns one returns a Login_User_Model.
 *
 * See: php artisan rsx:man two_factor
 */
class Rsx_Two_Factor extends Rsx_Two_Factor_Abstract
{
    /**
     * Session value key holding the identity that has passed its password and is waiting on
     * a second factor.
     */
    public const CHALLENGE_KEY = 'two_factor.challenge';

    /**
     * Session value key holding an in-flight TOTP seed, before it has been confirmed.
     *
     * PENDING, not stored on a row: a seed nobody has proved they can generate codes from
     * is not a credential. Keeping it out of the table means an abandoned enrollment leaves
     * nothing behind that a later query has to remember to filter out.
     */
    public const TOTP_PENDING_KEY = 'two_factor.totp_pending';

    /**
     * Session value key holding the challenge of an in-flight registration or second-factor
     * assertion ceremony (base64url).
     */
    public const WEBAUTHN_CHALLENGE_KEY = 'two_factor.webauthn_challenge';

    /**
     * Session value key holding the challenge of an in-flight PASSWORDLESS sign-in.
     */
    public const PASSKEY_LOGIN_CHALLENGE_KEY = 'two_factor.passkey_login_challenge';

    public static function _credential_model(): string
    {
        return Two_Factor_Credential_Model::class;
    }

    public static function _owner_column(): string
    {
        return 'login_user_id';
    }

    public static function _identity_model(): string
    {
        return Login_User_Model::class;
    }

    /**
     * The APP_URL hostname.
     *
     * @return string
     */
    public static function _relying_party_id(): string
    {
        return Passkeys::relying_party_id();
    }

    /**
     * The bare login_users id - the handle every staff passkey has carried since the
     * subsystem shipped, so an existing credential keeps its place on the authenticator.
     *
     * @param int $identity_id
     * @return string
     */
    public static function _user_handle(int $identity_id): string
    {
        return (string) $identity_id;
    }

    protected static function __signed_in_identity(): ?Rsx_Model_Abstract
    {
        return Session::get_login_user();
    }

    protected static function __is_impersonating(): bool
    {
        return Session::is_impersonating();
    }

    protected static function __find_identity(int $identity_id): ?Rsx_Model_Abstract
    {
        return Login_User_Model::where('id', $identity_id)->first();
    }

    /**
     * RsxAuth::login() stamps last_login and REFUSES an identity holding no active site
     * membership (users.is_enabled + sites.is_enabled - the framework's switches). It records nothing itself, by
     * design, so the caller records the outcome.
     */
    protected static function __sign_in(Rsx_Model_Abstract $identity): bool
    {
        return (bool) RsxAuth::login($identity);
    }

    protected static function __sign_out(): void
    {
        Session::logout();
    }

    protected static function __record_success(Rsx_Model_Abstract $identity, string $email): void
    {
        Login_History::record_success((int) $identity->id, $email);
    }

    /**
     * Login_History::record_failure() - which ALREADY feeds Login_Throttle, so the throttle
     * is never touched directly here.
     */
    protected static function __record_failure(
        string $email,
        string $status,
        ?int $identity_id,
        ?string $reason = null
    ): void {
        Login_History::record_failure($email, $status, $reason, $identity_id);
    }
}
