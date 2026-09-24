<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Portal_Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Abstract;

/**
 * Rsx_Portal_Two_Factor - THE second-factor and passkey facade for the CLIENT PORTAL realm,
 * and the only class in this subsystem portal application code touches.
 *
 * The same API as Rsx_Two_Factor, method for method - is_enabled(), enrollment, the
 * second-factor challenge, PASSWORDLESS sign-in, recovery codes - written once in
 * Rsx_Two_Factor_Abstract, whose docblock carries the flows and the rulings. This class binds
 * it to the portal: credentials belong to a Portal_User_Model and live in
 * _portal_two_factor_credentials, the session is Portal_Session, and every method that
 * returns an identity returns a Portal_User_Model.
 *
 * WHAT THE PORTAL REALM DOES DIFFERENTLY, and why:
 *
 *   ADMISSION. A portal user is signed in only when Portal_User_Model::can_login() admits
 *   them (active and verified - the portal's account vocabulary, which the framework owns)
 *   AND they belong to the site the application declared for this request
 *   (Portal_Session::set_site_id). A credential row names a portal user by id, and ids are
 *   global, so the site check is what keeps a passkey minted on one tenant's portal from
 *   signing its owner in to another's. A refusal answers exactly like a wrong answer.
 *
 *   RECORDING. The portal has no login history - _login_history is the staff identity's
 *   store. A failure feeds Login_Throttle::record_failure() directly (so the brute-force
 *   budget is shared with the portal's password form and spent exactly once), and a success
 *   records nothing beyond the last_login stamp Portal_Session::set_portal_user_id() writes.
 *
 *   IMPERSONATION. "View as Client" is Portal_Session::is_impersonating(), and every
 *   enrollment and removal refuses under it: a staff member viewing a client's portal must
 *   never be able to enroll a passkey onto that client's account.
 *
 *   THE RELYING PARTY is the portal's own host. With a dedicated portal domain
 *   (rsx.portal.domain) that is that domain; in prefix mode the portal shares the
 *   application's host, and so shares its rpId with staff - which is safe because the two
 *   realms' credentials live in different tables (see Passkeys). The USER HANDLE is
 *   'portal-<id>', never the bare id, so a portal passkey and a staff passkey on one
 *   authenticator can never overwrite each other.
 *
 * A PORTAL CREDENTIAL NEVER SATISFIES A STAFF CHALLENGE, OR THE REVERSE. The two facades read
 * different tables and park their pending values under different session keys, so there is
 * no code path along which one realm's credential reaches the other's verification.
 *
 * See: php artisan rsx:man two_factor
 */
class Rsx_Portal_Two_Factor extends Rsx_Two_Factor_Abstract
{
    /**
     * Session value key holding the portal user who has passed their password and is waiting
     * on a second factor.
     */
    public const CHALLENGE_KEY = 'portal_two_factor.challenge';

    /**
     * Session value key holding an in-flight TOTP seed, before it has been confirmed.
     */
    public const TOTP_PENDING_KEY = 'portal_two_factor.totp_pending';

    /**
     * Session value key holding the challenge of an in-flight registration or second-factor
     * assertion ceremony (base64url).
     */
    public const WEBAUTHN_CHALLENGE_KEY = 'portal_two_factor.webauthn_challenge';

    /**
     * Session value key holding the challenge of an in-flight PASSWORDLESS sign-in.
     */
    public const PASSKEY_LOGIN_CHALLENGE_KEY = 'portal_two_factor.passkey_login_challenge';

    /**
     * The prefix on every portal WebAuthn user handle.
     */
    private const USER_HANDLE_PREFIX = 'portal-';

    public static function _credential_model(): string
    {
        return Portal_Two_Factor_Credential_Model::class;
    }

    public static function _owner_column(): string
    {
        return 'portal_user_id';
    }

    public static function _identity_model(): string
    {
        return Portal_User_Model::class;
    }

    /**
     * The dedicated portal domain when one is configured, else the application's hostname.
     *
     * @return string
     */
    public static function _relying_party_id(): string
    {
        if (!Rsx_Portal::has_dedicated_domain()) {
            return Passkeys::relying_party_id();
        }

        $domain = (string) Rsx_Portal::get_domain();

        // Accept either spelling an operator might write - a bare host or a URL.
        $host = str_contains($domain, '://') ? (string) parse_url($domain, PHP_URL_HOST) : $domain;

        return Passkeys::bare_host($host);
    }

    /**
     * 'portal-<id>' - see the class docblock.
     *
     * @param int $identity_id
     * @return string
     */
    public static function _user_handle(int $identity_id): string
    {
        return self::USER_HANDLE_PREFIX . $identity_id;
    }

    protected static function __signed_in_identity(): ?Rsx_Model_Abstract
    {
        return Portal_Session::get_portal_user();
    }

    protected static function __is_impersonating(): bool
    {
        return Portal_Session::is_impersonating();
    }

    /**
     * A portal user of the DECLARED site only. Portal_Session::get_site_id() throws when the
     * application declared none, which is the portal's contract everywhere.
     */
    protected static function __find_identity(int $identity_id): ?Rsx_Model_Abstract
    {
        return Portal_User_Model::where('id', $identity_id)
            ->where('site_id', Portal_Session::get_site_id())
            ->first();
    }

    /**
     * Admits a portal user can_login() accepts, of the declared site, and signs them in.
     * Portal_Session::set_portal_user_id() stamps last_login and applies the portal sign-in
     * cap.
     */
    protected static function __sign_in(Rsx_Model_Abstract $identity): bool
    {
        if (!$identity->can_login()) {
            return false;
        }

        if ((int) $identity->site_id !== (int) Portal_Session::get_site_id()) {
            return false;
        }

        Portal_Session::set_portal_user_id((int) $identity->id);

        return true;
    }

    protected static function __sign_out(): void
    {
        Portal_Session::logout();
    }

    /**
     * Nothing to record: the portal has no login history. See the class docblock.
     */
    protected static function __record_success(Rsx_Model_Abstract $identity, string $email): void
    {
    }

    /**
     * The throttle, directly - the portal's failures have no history store to feed it
     * through. Exactly once per failure.
     */
    protected static function __record_failure(
        string $email,
        string $status,
        ?int $identity_id,
        ?string $reason = null
    ): void {
        Login_Throttle::record_failure();
    }
}
