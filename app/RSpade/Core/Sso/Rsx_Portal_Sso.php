<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Session\Portal_Login_History;
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
 *            (Rsx_Portal_Sso_Controller, #[Portal_Route]) - '<portal prefix>/_sso/...' on the
 *            portal's host ('/_portal/_sso/...' by default). A ceremony must finish on the
 *            host whose cookie jar started it, so the portal callback is a SECOND redirect URI
 *            to register in each provider's console; callback_url() prints it.
 *   STORAGE  _portal_sso_identities, unique per (site_id, provider_key, provider_user_key):
 *            portal users are site-scoped, so one Google account may be a portal user of two
 *            sites. Every lookup is scoped to the site the request's portal URL resolved
 *            to (Portal_Session::get_site_id()), which is why the ceremony runs entirely
 *            inside portal dispatch.
 *   HOOKS    portal.sso.identity.unlinked, portal.sso.login.authorize,
 *            portal.sso.two_factor.verify_url, portal.sso.two_factor.accepts,
 *            portal.sso.login.destination,
 *            portal.sso.link.destination - each payload carrying 'portal_user'. DISTINCT
 *            NAMES from the staff hooks, on purpose: an application's staff policy (which may
 *            match a verified address against login_users) must never run for a client.
 *   SIGN-IN  a portal user Portal_User_Model::can_login() admits, of the declared site,
 *            through Portal_Session::set_portal_user_id(). Anything else is refused and
 *            answered like a denied gate.
 *   RECORDS  the portal's own login history, Portal_Login_History (STATUS_FAILED_SSO,
 *            STATUS_FAILED_DISABLED, SUCCESS), which feeds Login_Throttle exactly once per
 *            failure.
 *   2FA      Rsx_Portal_Two_Factor.
 *   IMPERSONATION  "View as Client" (Portal_Session::is_impersonating()) refuses linking and
 *            unlinking, as staff impersonation does on the staff side.
 *
 * See: php artisan rsx:man sso
 */
class Rsx_Portal_Sso extends Rsx_Sso_Abstract
{
    /** The session-value realm the ceremony state and the pending identity live in. */
    public const SESSION_REALM = 'portal';

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

    /**
     * The portal's SSO ceremony path ON THE PORTAL HOST: the request's portal base + /_sso
     * (Rsx_Request_Channel::portal_base() - per site in multi tenancy; the ceremony runs
     * inside portal dispatch).
     *
     * ONE HOST PER CEREMONY: a ceremony finishes on the host whose cookie jar started it, so
     * every portal host is a redirect URI of its own in each provider console. In multi
     * tenancy with a host template or custom domains that is one URI per tenant host - the
     * ceremony on the application host handing its result to the tenant's portal is not
     * built yet, and rsx:health warns while rsx.sso.portal_enabled is on there
     * (health_row()).
     */
    public static function base_path(): string
    {
        return Rsx_Request_Channel::portal_base() . Rsx_Sso::BASE_PATH;
    }

    /**
     * A portal-host path made absolute: on the current site's portal origin when it has its
     * own host, else on the application's (rsx_absolute_url()).
     */
    public static function absolute_url(string $path): string
    {
        if (!Rsx_Portal_Url::is_separate_host()) {
            return rsx_absolute_url($path);
        }

        return Rsx_Portal_Url::origin() . $path;
    }

    /**
     * Portal SSO on a multi-tenant install whose portals live on hosts of their own.
     *
     * WARN while rsx.sso.portal_enabled is on with rsx.tenancy = multi and either a host
     * template (https://:slug.myapp.com) or custom portal domains
     * (PORTAL_CUSTOM_DOMAINS override or only): each tenant host would need its own redirect
     * URI in every provider console, and the cross-host ceremony that would spare it is not
     * built yet. OK otherwise.
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    #[Health_Check('Portal SSO')]
    public static function health_row(): array
    {
        if (!static::__realm_enabled()) {
            return ['status' => 'OK', 'detail' => 'portal federated sign-in is off (rsx.sso.portal_enabled)', 'remediation' => null];
        }

        $multi_host = Rsx_Tenancy::is_multi() && Rsx_Portal_Url::is_enabled()
            && (Rsx_Portal_Url::template()['mode'] === Rsx_Portal_Url::MODE_HOST
                || Rsx_Portal_Url::custom_domains() !== Rsx_Portal_Url::CUSTOM_DOMAINS_OFF);

        if ($multi_host) {
            return [
                'status' => 'WARN',
                'detail' => 'portal federated sign-in is on, and this multi-tenant install serves portals on hosts of'
                    . ' their own (a host template or custom portal domains): a sign-in ceremony finishes only on the'
                    . ' host that started it, so every tenant host needs its own redirect URI in each provider console.'
                    . ' The ceremony on the application host that hands the result to the tenant\'s portal is not built yet.',
                'remediation' => 'turn rsx.sso.portal_enabled off, or register each tenant portal host\'s callback'
                    . ' (<portal>/_sso/<provider>/callback) with every provider (rsx:man sso)',
            ];
        }

        return ['status' => 'OK', 'detail' => 'portal federated sign-in is on', 'remediation' => null];
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
        return static::__own_portal_path('/login');
    }

    protected static function __home_path(): string
    {
        return static::__own_portal_path('/');
    }

    /**
     * A path of the portal this ceremony runs in. The ceremony runs inside portal dispatch,
     * for a served site, whose portal always has an address.
     */
    private static function __own_portal_path(string $path): string
    {
        $url = Rsx_Portal::portal_path($path);

        if ($url === false) {
            shouldnt_happen('Rsx_Portal_Sso: the portal ceremony ran for a site with no client portal');
        }

        return $url;
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

    protected static function __record_success(Rsx_Model_Abstract $identity): void
    {
        Portal_Login_History::record_success((int) $identity->id, (string) $identity->email);
    }

    /**
     * Portal_Login_History::record_failure() - which ALREADY feeds Login_Throttle. A sign-in
     * step that refused the identity (can_login(), or a portal user of another site) is
     * FAILED_DISABLED, as the portal's second-factor doors record it.
     */
    protected static function __record_failure(
        string $email,
        ?string $reason,
        ?int $identity_id,
        bool $refused = false
    ): void {
        Portal_Login_History::record_failure(
            $email,
            $refused ? Portal_Login_History::STATUS_FAILED_DISABLED : Portal_Login_History::STATUS_FAILED_SSO,
            $refused ? 'the portal refused the sign-in (can_login() or another site)' : $reason,
            $identity_id
        );
    }
}
