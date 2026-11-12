<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Auth\Forced_Password_Change_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Auth\Password_Verifier;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;

/**
 * Forced_Password_Change - the "choose a new password before you are signed in" step, written
 * once for BOTH realms. An application never names this class: it calls the realm's facade,
 * RsxAuth (staff) or Portal_Auth (client portal), whose *_forced_password_change() methods
 * delegate here with the realm.
 *
 * THE SHAPE IS THE SECOND-FACTOR CHALLENGE'S (Rsx_Two_Factor_Abstract::begin_challenge()):
 *
 *   1. The credential verifier throws Auth_Password_Reset_Required_Exception, carrying the
 *      identity whose password just matched. Nobody is signed in.
 *   2. The login function calls begin_forced_password_change($e->identity): the realm is
 *      SIGNED OUT first, then the identity is parked in the realm's session values. Half-
 *      authenticated is represented as NOT authenticated, so no surface can forget a flag.
 *   3. The "choose a new password" page reads forced_password_change_pending() and posts the
 *      new password to complete_forced_password_change(), which saves it through the identity
 *      model and answers the identity - STILL NOT SIGNED IN.
 *   4. The login function continues its ladder exactly where verify_credentials() would have
 *      returned: a second factor the identity holds is challenged NEXT, and only then is the
 *      identity signed in. A forced change is therefore never a way around a second factor,
 *      because it is the step BEFORE the factor, not a door beside it.
 *
 * NO EMAIL, NO TOKEN IN A URL. The proof is the password the visitor just typed; what carries
 * it to the next request is the browser's own session. The parked value has no window of its
 * own: it lives as long as the session, and goes with the realm's next sign-out (which clears
 * the realm's session values). It also goes STALE the moment the identity's password changes
 * any other way (an administrator, a reset link, the CLI): the value records a fingerprint of
 * the stored hash it was proven against, and a mismatch reads as nothing pending.
 *
 * COMPLETION:
 *   - Login_Throttle::require_not_throttled() first, on the parked address - the step draws on
 *     the sign-in budget, and a throttled client is refused (Auth_Throttled_Exception) before
 *     anything is checked. Nothing here is a guess, so nothing here is counted.
 *   - The new password must be non-empty and must not BE the current password (a forced
 *     change means a new one). Every other rule - length, complexity, a confirmation field - is
 *     the APPLICATION's password policy, checked by the caller before it calls this.
 *   - The password is set through the identity model (set_password() + save()), so the model's
 *     own consequences follow: the reset flag is cleared, two-factor trusted browsers are
 *     forgotten, and every other sign-in of the identity ends. A portal user is saved inside a
 *     Site_Lock::write() section on its site (reentrant under a #[Writer]).
 *   - The change states its cause, Password_Change::CAUSE_FORCED_CHANGE, so the model's
 *     password-change event reports it ('forced_change'); the sign-in that follows records its
 *     own success.
 *
 * Passkeys and federated sign-ins never present the password, so the flag never refuses them
 * and they never reach this step.
 *
 * See: php artisan rsx:man session (FORCED PASSWORD CHANGE AT SIGN-IN)
 */
class Forced_Password_Change
{
    /** The staff realm (login_users, Session). */
    public const REALM_STAFF = 'staff';

    /** The client-portal realm (portal_users, Portal_Session). */
    public const REALM_PORTAL = 'portal';

    /**
     * Session value key holding the parked identity, in the realm's own session values:
     * {identity_id, email, credential}.
     */
    public const PENDING_KEY = 'auth.forced_password_change';

    /**
     * Sign the realm out, then park the identity whose password just matched.
     *
     * @param string $realm REALM_STAFF or REALM_PORTAL
     * @param Rsx_Model_Abstract $identity A Login_User_Model (staff) or Portal_User_Model (portal)
     * @return void
     */
    public static function _begin(string $realm, Rsx_Model_Abstract $identity): void
    {
        static::__assert_identity($realm, $identity);

        // Sign-out FIRST: a realm's sign-out clears that realm's session values, so a value
        // written before it would be cleared by it.
        if ($realm === self::REALM_STAFF) {
            Session::logout();
        } else {
            Portal_Session::logout();
        }

        Session::put_value(
            self::PENDING_KEY,
            [
                'identity_id' => (int) $identity->id,
                'email' => (string) $identity->email,
                'credential' => static::__credential_fingerprint($identity),
            ],
            null,
            $realm
        );
    }

    /**
     * What the "choose a new password" page needs, or null when nothing is pending.
     *
     * The address is returned in full: the browser holding this value typed it, with its
     * password, one step ago, and the page needs it for the hidden autocomplete="username"
     * field a password manager saves the new password under.
     *
     * @param string $realm
     * @return array|null {email}
     */
    public static function _pending(string $realm): ?array
    {
        $pending = static::__live_pending($realm);

        return $pending === null ? null : ['email' => $pending['value']['email']];
    }

    /**
     * The parked identity, or null when nothing is pending. Server-side only; holding it
     * proves nothing about the person asking and signs nobody in.
     *
     * @param string $realm
     * @return Rsx_Model_Abstract|null
     */
    public static function _pending_identity(string $realm): ?Rsx_Model_Abstract
    {
        $pending = static::__live_pending($realm);

        return $pending === null ? null : $pending['identity'];
    }

    /**
     * Save the new password and answer the identity, NOT signed in. The parked value is
     * forgotten on success; a refusal leaves it in place so the visitor can try again.
     *
     * @param string $realm
     * @param string $new_password
     * @return Rsx_Model_Abstract The identity, its password changed and its flag cleared
     * @throws Auth_Throttled_Exception When this client or the account is over the sign-in rate limit
     * @throws Forced_Password_Change_Exception When nothing is pending, the password is empty,
     *         or it is the current password
     */
    public static function _complete(string $realm, string $new_password): Rsx_Model_Abstract
    {
        $pending = static::__live_pending($realm);

        if ($pending === null) {
            throw new Forced_Password_Change_Exception(Forced_Password_Change_Exception::REASON_NOTHING_PENDING);
        }

        Login_Throttle::require_not_throttled($pending['value']['email']);

        $identity = $pending['identity'];

        if ($new_password === '') {
            throw new Forced_Password_Change_Exception(Forced_Password_Change_Exception::REASON_EMPTY);
        }

        if (Password_Verifier::verify($new_password, $identity->password)) {
            throw new Forced_Password_Change_Exception(Forced_Password_Change_Exception::REASON_UNCHANGED);
        }

        $identity->set_password($new_password, Password_Change::CAUSE_FORCED_CHANGE);

        if ($realm === self::REALM_STAFF) {
            $identity->save();
        } else {
            Site_Lock::write(
                (int) $identity->site_id,
                fn () => $identity->save(),
                'Forced password change at sign-in'
            );
        }

        Session::forget_value(self::PENDING_KEY, $realm);

        return $identity;
    }

    /**
     * Forget the parked identity (a Cancel link, a visitor who signs in as somebody else).
     *
     * @param string $realm
     * @return void
     */
    public static function _abandon(string $realm): void
    {
        Session::forget_value(self::PENDING_KEY, $realm);
    }

    /**
     * The parked value and its identity, or null when nothing usable is pending. A value whose
     * identity is gone, or whose password changed since it was proven, is forgotten here.
     *
     * @param string $realm
     * @return array|null {value, identity}
     */
    private static function __live_pending(string $realm): ?array
    {
        $value = Session::get_value(self::PENDING_KEY, null, $realm);

        if (!is_array($value) || !isset($value['identity_id'], $value['email'], $value['credential'])) {
            return null;
        }

        $identity = static::__find_identity($realm, (int) $value['identity_id']);

        if ($identity === null || !hash_equals((string) $value['credential'], static::__credential_fingerprint($identity))) {
            Session::forget_value(self::PENDING_KEY, $realm);

            return null;
        }

        return ['value' => $value, 'identity' => $identity];
    }

    /**
     * The identity the parked id names, in the realm. A portal user is read on the site this
     * portal request serves, so a value cannot be redeemed on another site's portal.
     */
    private static function __find_identity(string $realm, int $identity_id): ?Rsx_Model_Abstract
    {
        if ($realm === self::REALM_STAFF) {
            return Login_User_Model::where('id', $identity_id)->first();
        }

        $site_id = Portal_Session::get_site_id();

        return Portal_User_Model::without_site_scope(
            fn () => Portal_User_Model::where('id', $identity_id)->where('site_id', $site_id)->first()
        );
    }

    /**
     * A fingerprint of the stored hash: changes whenever the password does, reveals nothing
     * about it (it is a digest of a salted hash, kept server-side in the session values).
     */
    private static function __credential_fingerprint(Rsx_Model_Abstract $identity): string
    {
        return hash('sha256', (string) $identity->password);
    }

    private static function __assert_identity(string $realm, Rsx_Model_Abstract $identity): void
    {
        $expected = match ($realm) {
            self::REALM_STAFF => Login_User_Model::class,
            self::REALM_PORTAL => Portal_User_Model::class,
        };

        if (!$identity instanceof $expected) {
            shouldnt_happen('Forced password change for a ' . get_class($identity) . " in the {$realm} realm");
        }
    }
}
