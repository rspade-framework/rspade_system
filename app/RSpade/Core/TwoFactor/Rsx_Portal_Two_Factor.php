<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Session\Portal_Login_History;
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
 *   AND they belong to the site this request's portal URL resolved to
 *   (Portal_Session::get_site_id()). A credential row names a portal user by id, and ids are
 *   global, so the site check is what keeps a passkey minted on one tenant's portal from
 *   signing its owner in to another's. A refusal answers exactly like a wrong answer. An
 *   emailed answer (an issued code, or its link) sets is_verified BEFORE that question is
 *   asked (__mark_address_verified()), so the answer that proves the mailbox is also the one
 *   that satisfies the portal's "verified" rule.
 *
 *   RECORDING. Into the portal's own login history, Portal_Login_History
 *   (_portal_login_history), under the same statuses staff write to theirs: a success and a
 *   passkey ENROLLMENT outcome (begun, enrolled, failed, abandoned) are rows, a failure is
 *   Portal_Login_History::record_failure() - which feeds Login_Throttle, so the brute-force
 *   budget is shared with the portal's password form and spent exactly once.
 *
 *   IMPERSONATION. "View as Client" is Portal_Session::is_impersonating(), and every
 *   enrollment and removal refuses under it: a staff member viewing a client's portal must
 *   never be able to enroll a passkey onto that client's account.
 *
 *   THE RELYING PARTY is the host the portal request is served on: the current site's
 *   portal host when it has one of its own (a PORTAL_URL host, a tenant subdomain, a custom
 *   domain - Rsx_Portal_Url::host(), which in a portal request answers for the site its URL
 *   resolved to); under a prefix on the application host the portal shares that host, and
 *   so shares its rpId with staff - which is safe because the two realms' credentials live
 *   in different tables (see Passkeys). A passkey therefore belongs to the host it was
 *   enrolled on: one enrolled on a tenant subdomain does not sign in on the custom domain
 *   the site later moves to. The USER HANDLE is
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
     * The session-value realm every value this realm's flows park lives in (Session::put_value()):
     * cleared when the portal sign-in on the browser ends, untouched by the other realm's.
     */
    public const SESSION_REALM = 'portal';

    /**
     * Session value key holding the portal user who has passed their password and is waiting
     * on a second factor.
     */
    public const CHALLENGE_KEY = 'portal_two_factor.challenge';

    /** This realm's key in config('rsx.two_factor.trusted_device_days'). */
    public const TRUST_REALM = 'portal';

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
     * Session value key marking a passkey enrollment that has begun and not yet been
     * confirmed - the staff facade's PASSKEY_ENROLLMENT_KEY, for the portal realm.
     */
    public const PASSKEY_ENROLLMENT_KEY = 'portal_two_factor.passkey_enrollment';

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
     * The current site's portal host when it has one of its own (Rsx_Portal_Url - the
     * request's portal host in a portal request), else the application's hostname.
     *
     * @return string
     */
    public static function _relying_party_id(): string
    {
        if (!Rsx_Portal_Url::is_separate_host()) {
            return Passkeys::relying_party_id();
        }

        return Passkeys::bare_host(Rsx_Portal_Url::host());
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
     * portal_users.is_verified - which can_login() requires, so a portal user whose address
     * was never verified is admitted by the very emailed answer that verifies it.
     */
    protected static function __mark_address_verified(Rsx_Model_Abstract $identity): void
    {
        if ($identity->is_verified) {
            return;
        }

        $identity->is_verified = true;
        $identity->save();
    }

    /**
     * <portal prefix>/_email_link/:token on the portal's own host.
     */
    protected static function __email_link_route(string $token): string
    {
        $url = Rsx_Portal::Route('Rsx_Email_Link_Controller::open', ['token' => $token]);

        if ($url === false) {
            // A challenge is begun in a portal request, for the site its URL resolved to.
            shouldnt_happen('Rsx_Portal_Two_Factor: the current site has no client portal to link an emailed code to');
        }

        return rsx_absolute_url($url);
    }

    protected static function __event(string $name): string
    {
        return 'portal.two_factor.' . $name;
    }

    protected static function __record_success(Rsx_Model_Abstract $identity, string $email): void
    {
        Portal_Login_History::record_success((int) $identity->id, $email);
    }

    /**
     * Portal_Login_History::record_failure() - which ALREADY feeds Login_Throttle, so the
     * throttle is never touched directly here.
     */
    protected static function __record_failure(
        string $email,
        string $status,
        ?int $identity_id,
        ?string $reason = null
    ): void {
        Portal_Login_History::record_failure($email, $status, $reason, $identity_id);
    }

    /**
     * A _portal_login_history row - the portal user's history, where its sign-ins already are.
     */
    protected static function __record_passkey_enrollment(
        int $identity_id,
        string $email,
        string $status,
        ?string $reason,
        array $client_context
    ): void {
        Portal_Login_History::record_passkey_enrollment($identity_id, $email, $status, $reason, $client_context);
    }
}
