<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Confirmation;

use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Auth\Password_Verifier;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Rsx_Password_Confirmation - "confirm it's you" before a sensitive action.
 *
 * A signed-in session is not proof that the person at the keyboard is the account's owner: a
 * session can be stolen, or a browser left signed in. So the actions that ADD or REMOVE a way
 * into the account - registering a passkey, setting up an authenticator app, removing a
 * factor, regenerating recovery codes, connecting or disconnecting a federated sign-in, and
 * whatever an application adds (minting an API key) - first ask for the account's password
 * again, or one of its passkeys. Without it, a session that would expire in a week becomes
 * permanent access.
 *
 * THE CONFIRMATION is a session value (Session::put_value(), realm-tagged) holding the
 * identity it was given for, alive rsx.sessions.password_confirmation_minutes (5) from the
 * moment it was given. A realm's sign-out clears it with every other session value; clear()
 * ends it early (the application calls it when the user leaves the screen that needed it).
 *
 * THE CHECK is require_confirmed(), the first statement of every endpoint that performs a
 * gated action. Unconfirmed, it THROWS a validation error telling the user to refresh and try
 * again - an application's UI asks for the confirmation BEFORE calling such an endpoint, so
 * reaching the throw means it expired in between, and a refresh starts the flow over.
 *
 * GIVING IT: confirm_password() checks the password exactly as a sign-in does - throttled
 * first (Login_Throttle, against this account), a wrong answer recorded as a failure (which
 * feeds the throttle), the comparison Password_Verifier's. confirm_passkey() verifies an
 * assertion from one of THIS identity's passkeys. The endpoint in front of the password path
 * (Rsx_Password_Confirmation_Controller::verify) validates Turnstile first.
 *
 * BOTH REALMS. Every method answers for the request's experience: a portal request confirms
 * the signed-in portal user, a staff request the login identity. Refused while impersonating:
 * the person acting is not the account's owner and does not hold its password.
 *
 * WHERE IT IS NOT: the facades (Rsx_Two_Factor, Rsx_Sso, ...) do not ask. A command, a task or
 * server code acting on an identity is not a browser session that could have been stolen, so
 * the requirement belongs to the web endpoints and nothing below them.
 *
 * See rsx:man password_confirmation.
 */
class Rsx_Password_Confirmation
{
    /**
     * The session value the confirmation is held under (realm-tagged by Session).
     */
    public const SESSION_KEY = 'password_confirmation';

    /**
     * The message a gated endpoint answers when the confirmation is missing or has expired.
     */
    public const EXPIRED_MESSAGE = 'Your password confirmation has timed out. Refresh the page and try again.';

    /**
     * Test seam, the same shape as Rsx_Turnstile::$force_verify_result_for_tests: true answers
     * every is_confirmed() true (impersonation is still refused), so a test whose subject is a
     * gated ENDPOINT can reach it. Nothing outside a test run may set it, and a test that sets
     * it must clear it.
     *
     * @var bool|null
     */
    public static ?bool $force_confirmed_for_tests = null;

    /**
     * Has the signed-in identity confirmed its password recently enough?
     *
     * @return bool
     */
    public static function is_confirmed(): bool
    {
        $realm = self::_realm();

        if ($realm['impersonating']) {
            return false;
        }

        if (static::$force_confirmed_for_tests !== null) {
            return static::$force_confirmed_for_tests;
        }

        if ($realm['identity'] === null) {
            return false;
        }

        $confirmed_for = Session::get_value(self::SESSION_KEY, null, $realm['session_realm']);

        return $confirmed_for !== null && (int) $confirmed_for === (int) $realm['identity']->id;
    }

    /**
     * Refuse a gated action that has no live confirmation behind it.
     *
     * The first statement of every endpoint that performs a gated action.
     *
     * @return void
     * @throws AjaxFormErrorException While impersonating; or when the identity has not
     *         confirmed, or the confirmation expired - the message tells the user to refresh
     *         and try again.
     */
    public static function require_confirmed(): void
    {
        if (self::_realm()['impersonating']) {
            throw new AjaxFormErrorException('You cannot do that while impersonating another account.');
        }

        if (!self::is_confirmed()) {
            throw new AjaxFormErrorException(self::EXPIRED_MESSAGE, ['password_confirmation' => 'required']);
        }
    }

    /**
     * What the confirmation can be given with, for the screen that asks for it.
     *
     * @return array {has_password, has_passkey, confirmed, minutes}
     */
    public static function options(): array
    {
        $realm = self::_realm_or_fail();

        return [
            'has_password' => self::_has_password($realm['identity']),
            'has_passkey' => self::_has_passkey($realm),
            'confirmed' => self::is_confirmed(),
            'minutes' => self::minutes(),
        ];
    }

    /**
     * Confirm with the account's password.
     *
     * @param string $password
     * @return bool True when the password is the account's; the confirmation is then given
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client or the account is
     *         over the sign-in rate limit - before the password is looked at
     */
    public static function confirm_password(string $password): bool
    {
        $realm = self::_realm_or_fail();
        $identity = $realm['identity'];

        Login_Throttle::require_not_throttled((string) $identity->email);

        if (!Password_Verifier::verify($password, self::_has_password($identity) ? (string) $identity->password : null)) {
            $realm['history']::record_failure(
                (string) $identity->email,
                $realm['history']::STATUS_FAILED_PASSWORD,
                'Wrong password on password confirmation',
                (int) $identity->id
            );

            return false;
        }

        self::_give($realm);

        return true;
    }

    /**
     * The arguments for navigator.credentials.get(), for the signed-in identity's own passkeys.
     *
     * @return array JSON-safe request args
     */
    public static function passkey_options(): array
    {
        $realm = self::_realm_or_fail();

        return Passkeys::assertion_options($realm['facade'], (int) $realm['identity']->id);
    }

    /**
     * Confirm with one of the signed-in identity's passkeys.
     *
     * A passkey that verifies but belongs to somebody else confirms nothing.
     *
     * @param array $assertion {id, clientDataJSON, authenticatorData, signature}, base64url
     * @return bool
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client or the account is
     *         over the sign-in rate limit
     */
    public static function confirm_passkey(array $assertion): bool
    {
        $realm = self::_realm_or_fail();
        $identity = $realm['identity'];

        Login_Throttle::require_not_throttled((string) $identity->email);

        try {
            $credential = Passkeys::verify_assertion($realm['facade'], $assertion, false);
        } catch (Two_Factor_Failed_Exception | \lbuchs\WebAuthn\WebAuthnException $e) {
            $credential = null;
        }

        $owner_column = $realm['facade']::_owner_column();

        if ($credential === null || (int) $credential->{$owner_column} !== (int) $identity->id) {
            $realm['history']::record_failure(
                (string) $identity->email,
                $realm['history']::STATUS_FAILED_PASSKEY,
                'Passkey did not verify on password confirmation',
                (int) $identity->id
            );

            return false;
        }

        self::_give($realm);

        return true;
    }

    /**
     * End the confirmation now. Harmless when there is none.
     *
     * @return void
     */
    public static function clear(): void
    {
        $realm = self::_realm();

        if (!Session::has_session()) {
            return;
        }

        Session::forget_value(self::SESSION_KEY, $realm['session_realm']);
    }

    /**
     * How long a confirmation lasts, in minutes.
     *
     * @return int
     */
    public static function minutes(): int
    {
        $minutes = (int) config('rsx.sessions.password_confirmation_minutes', 5);

        if ($minutes <= 0) {
            shouldnt_happen('rsx.sessions.password_confirmation_minutes must be a positive number of minutes');
        }

        return $minutes;
    }

    /**
     * Give the confirmation to the signed-in identity.
     */
    private static function _give(array $realm): void
    {
        Session::put_value(
            self::SESSION_KEY,
            (int) $realm['identity']->id,
            Rsx_Time::add(Rsx_Time::now_iso(), self::minutes() * 60),
            $realm['session_realm']
        );
    }

    private static function _has_password(Rsx_Model_Abstract $identity): bool
    {
        return is_string($identity->password) && $identity->password !== '';
    }

    private static function _has_passkey(array $realm): bool
    {
        $model = $realm['facade']::_credential_model();

        return $model::where($realm['facade']::_owner_column(), (int) $realm['identity']->id)
            ->where('type_id', $model::TYPE_PASSKEY)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * The realm this request confirms in, with a signed-in, non-impersonating identity -
     * or a loud refusal. The gate on the endpoints has already required a sign-in, so a
     * refusal here is impersonation or a caller outside a request.
     */
    private static function _realm_or_fail(): array
    {
        $realm = self::_realm();

        if ($realm['identity'] === null) {
            throw new \RuntimeException('Rsx_Password_Confirmation: nobody is signed in.');
        }

        if ($realm['impersonating']) {
            throw new AjaxFormErrorException('You cannot confirm the password of an account you are impersonating.');
        }

        return $realm;
    }

    /**
     * The request's realm: its facade, signed-in identity, impersonation state, login history
     * and session-value realm.
     */
    private static function _realm(): array
    {
        if (Rsx_Portal::is_portal_request()) {
            return [
                'facade' => Rsx_Portal_Two_Factor::class,
                'identity' => Portal_Session::get_portal_user(),
                'impersonating' => Portal_Session::is_impersonating(),
                'history' => Portal_Login_History::class,
                'session_realm' => Rsx_Portal_Two_Factor::SESSION_REALM,
            ];
        }

        return [
            'facade' => Rsx_Two_Factor::class,
            'identity' => Session::get_login_user(),
            'impersonating' => Session::is_impersonating(),
            'history' => Login_History::class,
            'session_realm' => Rsx_Two_Factor::SESSION_REALM,
        ];
    }
}
