<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Sso_Abstract;
use App\RSpade\Core\Sso\Sso_Identity_Model;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;

/**
 * Rsx_Sso - THE federated sign-in facade for the STAFF realm, and the only class in this
 * subsystem staff application code touches.
 *
 * The owner of a connection is a LOGIN IDENTITY (login_users), for exactly the reason a
 * second factor is: a Google account proves who is holding the browser, which is what
 * login_users is. Connections live in _sso_identities, where (provider_key,
 * provider_user_key) is unique - one provider account, one login identity.
 *
 * The whole API - the provider registry, the ceremony, the pending identity, account
 * management - is written once in Rsx_Sso_Abstract, whose docblock carries the flow and the
 * reasoning. This class binds it to the staff realm:
 *
 *   URLs     /_sso/<provider>/begin and /_sso/<provider>/callback (Rsx_Sso_Controller)
 *   hooks    sso.identity.unlinked, sso.login.authorize, sso.two_factor.verify_url,
 *            sso.login.destination, sso.link.destination - each payload carrying
 *            'login_user'
 *   sign-in  RsxAuth::login(), which refuses an identity holding no enabled site membership
 *   records  Login_History (STATUS_FAILED_SSO, STATUS_FAILED_DISABLED, SUCCESS), which
 *            feeds Login_Throttle
 *   2FA      Rsx_Two_Factor
 *
 * The portal realm's facade is Rsx_Portal_Sso.
 *
 * See: php artisan rsx:man sso
 */
class Rsx_Sso extends Rsx_Sso_Abstract
{
    /** Session value holding the in-flight ceremony: {state, provider, intent, code_verifier}. */
    public const STATE_KEY = 'sso.state';

    /** Session value holding a proven provider identity that is connected to no local account. */
    public const PENDING_KEY = 'sso.pending';

    /** The path prefix every staff ceremony URL is built from. Rsx_Sso_Controller routes it. */
    public const BASE_PATH = '/_sso';

    /**
     * Apple's staff callback, spelled out as a constant because Rsx_Csrf::enforce() exempts
     * this exact path - Sign in with Apple returns its authorization as a cross-site form
     * POST. A constant and not a computed string: an exemption list must be readable as a
     * list. (The portal realm's Apple callback is exempted beside it, through
     * Rsx_Portal_Sso::apple_callback_path().)
     */
    public const APPLE_CALLBACK_PATH = '/_sso/apple/callback';

    /**
     * Where a failed ceremony sends the browser.
     *
     * The same literal the framework's own login_redirect configuration defaults to
     * (rsx.login_redirect.excluded_prefixes), and the path the starter template's login page
     * lives at. An application that moves its login page elsewhere is expected to move it
     * with a route, not to teach the framework a second spelling.
     */
    private const LOGIN_PATH = '/login';

    public static function base_path(): string
    {
        return self::BASE_PATH;
    }

    public static function absolute_url(string $path): string
    {
        return rsx_absolute_url($path);
    }

    public static function _link_model(): string
    {
        return Sso_Identity_Model::class;
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
     * Always: a staff provider is live because its own enabled flag is true, and for no
     * other reason.
     */
    protected static function __realm_enabled(): bool
    {
        return true;
    }

    protected static function __hook(string $name): string
    {
        return 'sso.' . $name;
    }

    protected static function __payload_key(): string
    {
        return 'login_user';
    }

    protected static function __two_factor(): string
    {
        return Rsx_Two_Factor::class;
    }

    protected static function __login_path(): string
    {
        return self::LOGIN_PATH;
    }

    protected static function __home_path(): string
    {
        return '/';
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
     * Staff links are not site-scoped: login_users is cross-site, and so are its links.
     */
    protected static function __scope_links($query)
    {
        return $query;
    }

    protected static function __fill_new_link(Rsx_Model_Abstract $row, int $owner_id): void
    {
    }

    /**
     * RsxAuth::login() stamps last_login and REFUSES an identity holding no active site
     * membership (users.is_enabled + sites.is_enabled - the framework's switches).
     */
    protected static function __sign_in(Rsx_Model_Abstract $identity): bool
    {
        return (bool) RsxAuth::login($identity);
    }

    protected static function __record_success(Rsx_Model_Abstract $identity): void
    {
        Login_History::record_success((int) $identity->id, (string) $identity->email);
    }

    /**
     * Login_History::record_failure() - which ALREADY feeds Login_Throttle.
     */
    protected static function __record_failure(
        string $email,
        ?string $reason,
        ?int $identity_id,
        bool $refused = false
    ): void {
        Login_History::record_failure(
            $email,
            $refused ? Login_History::STATUS_FAILED_DISABLED : Login_History::STATUS_FAILED_SSO,
            $refused ? 'no enabled site membership' : $reason,
            $identity_id
        );
    }
}
