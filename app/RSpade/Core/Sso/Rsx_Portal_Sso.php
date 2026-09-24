<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use RuntimeException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Sso\Portal_Sso_Identity_Model;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Rsx_Sso_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;

/**
 * Rsx_Portal_Sso - THE federated sign-in facade for the CLIENT PORTAL realm, and the only
 * class in this subsystem portal application code touches.
 *
 * The same API as Rsx_Sso, method for method - enabled_providers(), pending(),
 * link_pending(), consume_pending_and_login(), abandon_pending(), identities_list(),
 * unlink(), unlink_all() - written once in Rsx_Sso_Abstract, whose docblock carries the flow
 * and the reasoning. The provider REGISTRY is shared with staff (one Google client id serves
 * both realms); everything else is the portal's own:
 *
 *   SWITCH   rsx.sso.portal_enabled (SSO_PORTAL_ENABLED). OFF by default: turning a provider
 *            on for staff must not quietly put a button on the client portal. With it off,
 *            enabled_providers() is empty and the ceremony refuses to start.
 *   URLs     the portal's own /_sso/<provider>/begin and /_sso/<provider>/callback
 *            (Rsx_Portal_Sso_Controller, #[Portal_Route]) - '/_portal/_sso/...' in prefix
 *            mode, '/_sso/...' on a dedicated portal domain. A ceremony must finish on the
 *            host whose cookie jar started it, so the portal callback is a SECOND redirect URI
 *            to register in each provider's console; callback_url() prints it.
 *   STORAGE  _portal_sso_identities, unique per (site_id, provider_key, provider_user_key):
 *            portal users are site-scoped, so one Google account may be a portal user of two
 *            sites. Every lookup is scoped to the site the application DECLARED for the
 *            request (Portal_Session::set_site_id), which is why the ceremony runs entirely
 *            inside portal dispatch.
 *   HOOKS    portal.sso.identity.unlinked, portal.sso.login.authorize,
 *            portal.sso.two_factor.verify_url, portal.sso.login.destination,
 *            portal.sso.link.destination - each payload carrying 'portal_user'. DISTINCT
 *            NAMES from the staff hooks, on purpose: an application's staff policy (which may
 *            match a verified address against login_users) must never run for a client.
 *   SIGN-IN  a portal user Portal_User_Model::can_login() admits, of the declared site,
 *            through Portal_Session::set_portal_user_id(). Anything else is refused and
 *            answered like a denied gate.
 *   RECORDS  none beyond the throttle: the portal has no login history (_login_history is the
 *            staff identity's), so each failure feeds Login_Throttle::record_failure()
 *            directly, exactly once.
 *   2FA      Rsx_Portal_Two_Factor.
 *   IMPERSONATION  "View as Client" (Portal_Session::is_impersonating()) refuses linking and
 *            unlinking, as staff impersonation does on the staff side.
 *
 * See: php artisan rsx:man sso
 */
class Rsx_Portal_Sso extends Rsx_Sso_Abstract
{
    /** Session value holding the in-flight PORTAL ceremony. */
    public const STATE_KEY = 'portal_sso.state';

    /** Session value holding a proven provider identity connected to no portal user. */
    public const PENDING_KEY = 'portal_sso.pending';

    /**
     * The portal's Apple callback path, as the browser addresses it - exempted from the CSRF
     * check beside Rsx_Sso::APPLE_CALLBACK_PATH, for the reason written there. Computed
     * rather than a constant only because the portal prefix is configuration.
     *
     * @return string
     */
    public static function apple_callback_path(): string
    {
        return static::callback_path(Rsx_Sso::APPLE);
    }

    public static function base_path(): string
    {
        return Rsx_Portal::portal_path(Rsx_Sso::BASE_PATH);
    }

    /**
     * On a dedicated portal domain, the portal host under APP_URL's scheme; in prefix mode
     * the application's own host.
     */
    public static function absolute_url(string $path): string
    {
        if (!Rsx_Portal::has_dedicated_domain()) {
            return rsx_absolute_url($path);
        }

        $domain = (string) Rsx_Portal::get_domain();

        if (str_contains($domain, '://')) {
            return rtrim($domain, '/') . $path;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme . '://' . $domain . $path;
    }

    public static function _link_model(): string
    {
        return Portal_Sso_Identity_Model::class;
    }

    public static function _owner_column(): string
    {
        return 'portal_user_id';
    }

    public static function _identity_model(): string
    {
        return Portal_User_Model::class;
    }

    protected static function __realm_enabled(): bool
    {
        return (bool) config('rsx.sso.portal_enabled');
    }

    protected static function __hook(string $name): string
    {
        return 'portal.sso.' . $name;
    }

    protected static function __payload_key(): string
    {
        return 'portal_user';
    }

    protected static function __two_factor(): string
    {
        return Rsx_Portal_Two_Factor::class;
    }

    protected static function __login_path(): string
    {
        return Rsx_Portal::portal_path('/login');
    }

    protected static function __home_path(): string
    {
        return Rsx_Portal::portal_path('/');
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
     * A portal user of the DECLARED site only.
     */
    protected static function __find_identity(int $identity_id): ?Rsx_Model_Abstract
    {
        return Portal_User_Model::where('id', $identity_id)
            ->where('site_id', Portal_Session::get_site_id())
            ->first();
    }

    /**
     * Links of the declared site only - a Google account connected to a portal user of
     * another tenant is, from here, connected to nobody.
     */
    protected static function __scope_links($query)
    {
        return $query->where('site_id', Portal_Session::get_site_id());
    }

    /**
     * The new link takes its owner's site, and the owner must be of the declared site: a
     * handler that named a portal user of another tenant is a bug, and fails loudly.
     */
    protected static function __fill_new_link(Rsx_Model_Abstract $row, int $owner_id): void
    {
        $site_id = (int) Portal_Session::get_site_id();

        $owner_site_id = Portal_User_Model::where('id', $owner_id)->value('site_id');

        if ($owner_site_id === null || (int) $owner_site_id !== $site_id) {
            throw new RuntimeException(
                'A portal sign-in can only be connected to a portal user of the site this portal serves.'
            );
        }

        $row->site_id = $site_id;
    }

    /**
     * Admits a portal user can_login() accepts, of the declared site.
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

    /**
     * Nothing to record: the portal has no login history.
     */
    protected static function __record_success(Rsx_Model_Abstract $identity): void
    {
    }

    /**
     * The throttle, directly and exactly once.
     */
    protected static function __record_failure(
        string $email,
        ?string $reason,
        ?int $identity_id,
        bool $refused = false
    ): void {
        Login_Throttle::record_failure();
    }
}
