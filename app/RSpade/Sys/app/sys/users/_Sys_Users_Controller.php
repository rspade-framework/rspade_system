<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Users;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Sys\App\Sys\Users\_Sys_User_Signins_DataGrid;
use App\RSpade\Sys\App\Sys\Users\_Sys_Users_DataGrid;
use App\RSpade\Sys\App\Sys\_Sys_Impersonation_Controller;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;

/**
 * The Users screen's endpoints: the login-identity grid, one identity's facts,
 * memberships, sessions, sign-in history and security state, and the administration
 * actions on them - status, a membership's switch, ending sessions, removing second
 * factors, disconnecting sign-in providers, and signing in as the identity
 * (impersonation). There is no delete, and is_developer is never written here (it is
 * set by hand in the database, by contract).
 *
 * THE RECORD IS THE LOGIN IDENTITY (login_users), not a membership. One identity reaches
 * any number of site memberships (users rows); the panel shows them all, across every
 * site, so every membership read lifts the site scope. A soft-deleted identity answers
 * not_found exactly as a missing id does: the panel neither lists nor restores deleted
 * identities.
 *
 * @DB-UNBOUNDED-01-EXCEPTION - every whole-set read here is narrowed to ONE identity: its
 * memberships, and the login identities that impersonated its own sessions.
 *
 * NO SECRET LEAVES HERE. The security answer is built from the two framework readers that
 * are metadata-only by contract - Rsx_Two_Factor::list_credentials() (never a TOTP seed,
 * a passkey's public key or a recovery-code hash) and Rsx_Sso::identities_list() (never
 * the provider's user key) - plus a COUNT of recovery codes. Invitation codes are read to
 * derive the invite state word and never returned.
 *
 * NO ACTION LOCKS THE ACTING DEVELOPER OUT. Each refusal is ONE private function
 * (__status_refusal, __security_refusal, __membership_refusal, __session_refusal,
 * __impersonate_refusal) that both the action endpoint enforces and the read endpoints
 * publish on the row it concerns, so the screen can explain a refusal before asking to
 * confirm and the rule still lives once. A refusal answers ERROR_VALIDATION with the reason.
 *
 * WHAT RECORDS THESE ACTIONS. A status change and a membership switch are model saves,
 * so they stamp updated_at and the updated_by pair (last writer only - neither model
 * records revisions). Ending sessions fires 'session.terminated' per row
 * (Session::developer_terminate_sessions()). Removing a factor and disconnecting a
 * provider record nothing: Rsx_Two_Factor and Rsx_Sso keep no audit trail of their own.
 * Signing in as an identity stamps the session row's impersonator_login_user_id and
 * impersonation_started_at (Session::begin_impersonation()).
 */
#[Auth('is_sysadmin')]
class _Sys_Users_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The identity grid (_Sys_Users_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return _Sys_Users_DataGrid::fetch($params);
    }

    /**
     * The status filter's options, in the enum's order.
     *
     * @return array [{value, label}] - value is the lowercase status word
     */
    #[Ajax_Endpoint]
    public static function status_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::enum_options(Login_User_Model::class, 'status_id');
    }

    /**
     * One identity: its facts and every live membership, across every site.
     *
     * @param array $params id (login_users.id)
     * @return array {user: {id, email, status_id, status, status_label, is_developer,
     *                is_verified, is_activated, last_login, timezone, timezone_auto,
     *                created_at, updated_at, is_self, membership_count, status_refusal,
     *                status_options, impersonate_refusal},
     *                memberships: [{id, site_id, site_name, site_is_enabled, site_is_deleted,
     *                has_site_screen, first_name, last_name, role_id, role_label, is_enabled,
     *                is_active, is_2fa_required, is_api_access_enabled, invite, created_at}]}
     *                invite is null (no invitation), 'pending', 'accepted' or 'expired'.
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $memberships = static::__memberships((int) $login_user->id);
        $status_id = (int) $login_user->status_id;

        return [
            'user' => [
                'id' => (int) $login_user->id,
                'email' => $login_user->email,
                'status_id' => $status_id,
                'status' => _Sys_Enum_Words::enum_word(Login_User_Model::class, 'status_id', $status_id),
                'status_label' => Login_User_Model::$enums['status_id'][$status_id]['label'],
                'is_developer' => (bool) $login_user->is_developer,
                'is_verified' => (bool) $login_user->is_verified,
                'is_activated' => (bool) $login_user->is_activated,
                'last_login' => Rsx_Time::to_iso($login_user->last_login),
                'timezone' => $login_user->timezone,
                'timezone_auto' => (bool) $login_user->timezone_auto,
                'created_at' => Rsx_Time::to_iso($login_user->created_at),
                'updated_at' => Rsx_Time::to_iso($login_user->updated_at),
                'is_self' => static::__is_self($login_user),
                'membership_count' => count($memberships),
                'status_refusal' => static::__status_refusal($login_user),
                'status_options' => _Sys_Enum_Words::enum_options(Login_User_Model::class, 'status_id'),
                'impersonate_refusal' => static::__impersonate_refusal($login_user, $memberships),
            ],
            'memberships' => $memberships,
        ];
    }

    /**
     * The identity's live sessions: Session::get_sessions_for_user() (active rows, most
     * recently active first), each gaining the row's site, session type and impersonation
     * columns. is_current marks the session the developer is reading this with - true only
     * on their own identity.
     *
     * @param array $params id (login_users.id)
     * @return array {sessions: [{id, device_summary, user_agent, ip_address, location,
     *                created_at, last_active, is_current, type_label, site_id, site_name,
     *                impersonator_login_user_id, impersonator_email, impersonation_started_at,
 *                terminate_refusal}]}
 *                terminate_refusal is null, or the reason the session cannot be ended here.
     */
    #[Ajax_Endpoint]
    public static function sessions(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $sessions = Session::get_sessions_for_user((int) $login_user->id);
        $extra = $sessions === [] ? collect() : DB::table('_sessions')
            ->whereIn('id', array_column($sessions, 'id'))
            ->get(['id', 'site_id', 'type_id', 'impersonator_login_user_id', 'impersonation_started_at'])
            ->keyBy('id');

        $site_names = _Sys_Enum_Words::site_names($extra->pluck('site_id')->filter(fn ($id) => $id !== null)->unique()->values()->all());
        $impersonator_ids = $extra->pluck('impersonator_login_user_id')->filter()->unique()->values()->all();
        // Bounded: the impersonators of this one identity's sessions, deleted ones included.
        $impersonator_emails = $impersonator_ids === [] ? [] : Login_User_Model::withTrashed()
            ->whereIn('id', $impersonator_ids)
            ->pluck('email', 'id')
            ->all();

        $out = [];

        foreach ($sessions as $session) {
            $row = $extra[$session['id']];
            $site_id = $row->site_id === null ? null : (int) $row->site_id;
            $impersonator_id = $row->impersonator_login_user_id === null ? null : (int) $row->impersonator_login_user_id;

            $out[] = [
                'id' => (int) $session['id'],
                'device_summary' => $session['device_summary'],
                'user_agent' => $session['user_agent'],
                'ip_address' => $session['ip_address'],
                'location' => $session['location'],
                'created_at' => Rsx_Time::to_iso($session['created_at']),
                'last_active' => Rsx_Time::to_iso($session['last_active']),
                'is_current' => (bool) $session['is_current'],
                'type_label' => Session::$enums['type_id'][(int) $row->type_id]['label'] ?? ('Type ' . $row->type_id),
                'site_id' => $site_id,
                'site_name' => $site_id === null ? null : ($site_names[$site_id] ?? null),
                'impersonator_login_user_id' => $impersonator_id,
                'impersonator_email' => $impersonator_id === null ? null : ($impersonator_emails[$impersonator_id] ?? null),
                'impersonation_started_at' => Rsx_Time::to_iso($row->impersonation_started_at),
                'terminate_refusal' => static::__session_refusal((int) $session['id']),
            ];
        }

        return ['sessions' => $out];
    }

    /**
     * The identity's sign-in history (_Sys_User_Signins_DataGrid); login_user_id is the
     * grid's fixed param.
     *
     * @param array $params login_user_id, plus the grid's paging/sort/filter params
     */
    #[Ajax_Endpoint]
    public static function signins_fetch(Request $request, array $params = [])
    {
        if (static::__find_identity($params['login_user_id'] ?? null) === null) {
            return response_not_found('No login identity with that id.');
        }

        return _Sys_User_Signins_DataGrid::fetch($params);
    }

    /**
     * The identity's second factors and connected sign-in providers, as metadata only.
     *
     * @param array $params id (login_users.id)
     * @return array {refusal, two_factor: {is_enabled, has_passkey, recovery_codes_remaining,
     *                credentials: [{id, type_id, type_id__label, label, confirmed_at,
     *                last_used_at}]},
     *                sso: {is_enabled, identities: [{id, provider_key, provider_label, email,
     *                name, last_login_at, created_at}]}}
     *                refusal is null, or the reason neither section can be changed here.
     */
    #[Ajax_Endpoint]
    public static function security(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $id = (int) $login_user->id;

        $credentials = array_map(function ($credential) {
            $credential['confirmed_at'] = Rsx_Time::to_iso($credential['confirmed_at']);
            $credential['last_used_at'] = Rsx_Time::to_iso($credential['last_used_at']);

            return $credential;
        }, Rsx_Two_Factor::list_credentials($id));

        $identities = array_map(function ($identity) {
            $identity['last_login_at'] = Rsx_Time::to_iso($identity['last_login_at']);
            $identity['created_at'] = Rsx_Time::to_iso($identity['created_at']);

            return $identity;
        }, Rsx_Sso::identities_list($id));

        return [
            'refusal' => static::__security_refusal($login_user),
            'two_factor' => [
                'is_enabled' => Rsx_Two_Factor::is_enabled($id),
                'has_passkey' => Rsx_Two_Factor::has_passkey($id),
                'recovery_codes_remaining' => Rsx_Two_Factor::recovery_codes_remaining($id),
                'credentials' => $credentials,
            ],
            'sso' => [
                'is_enabled' => Rsx_Sso::is_enabled(),
                'identities' => $identities,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Administration actions
    // -------------------------------------------------------------------------

    /**
     * Set an identity's status (login_users.status_id) - Active, Inactive or Suspended.
     *
     * Status is APPLICATION vocabulary (rsx:man session): the framework neither refuses a
     * sign-in nor ends a session on it, so what a status does is whatever this
     * application's own login code makes of it. Refused on the acting identity.
     *
     * @param array $params id (login_users.id), status (the lowercase word: 'active',
     *                      'inactive', 'suspended')
     * @return array {status, status_label}
     */
    #[Ajax_Endpoint]
    public static function set_status(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $status_id = _Sys_Enum_Words::enum_value(Login_User_Model::class, 'status_id', (string) ($params['status'] ?? ''));

        if ($status_id === null) {
            return response_error(Ajax::ERROR_VALIDATION, 'status must be one of: '
                . implode(', ', array_column(_Sys_Enum_Words::enum_options(Login_User_Model::class, 'status_id'), 'value')) . '.');
        }

        $refusal = static::__status_refusal($login_user);

        if ($refusal !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $refusal);
        }

        if ((int) $login_user->status_id !== $status_id) {
            $login_user->status_id = $status_id;
            $login_user->save();
        }

        return [
            'status' => _Sys_Enum_Words::enum_word(Login_User_Model::class, 'status_id', $status_id),
            'status_label' => Login_User_Model::$enums['status_id'][$status_id]['label'],
        ];
    }

    /**
     * Enable or disable one membership (users.is_enabled), on any site.
     *
     * Disabling is enforced by the framework: the identity can no longer sign in to that
     * site, and a live session on it ends on its next request
     * (Session::enforce_enabled_membership()). Refused for the membership the acting
     * session is on.
     *
     * @param array $params id (users.id), enabled (bool)
     * @return array {is_enabled, is_active}
     */
    #[Ajax_Endpoint]
    public static function set_membership_enabled(Request $request, array $params = [])
    {
        $membership = static::__find_membership($params['id'] ?? null);

        if ($membership === null) {
            return response_not_found('No membership with that id.');
        }

        $enabled = filter_var($params['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            return response_error(Ajax::ERROR_VALIDATION, 'enabled must be true or false.');
        }

        if (!$enabled) {
            $refusal = static::__membership_refusal($membership);

            if ($refusal !== null) {
                return response_error(Ajax::ERROR_VALIDATION, $refusal);
            }
        }

        if ((bool) $membership->is_enabled !== $enabled) {
            $membership->is_enabled = $enabled;
            User_Model::without_site_scope(fn () => $membership->save());
        }

        return [
            'is_enabled' => (bool) $membership->is_enabled,
            'is_active' => $membership->is_active(),
        ];
    }

    /**
     * End one of an identity's live sessions (Session::developer_terminate_sessions()).
     * The browser holding it is pushed to reload signed out. Refused for the session the
     * panel is being used from; not_found when the id is not a live session of this
     * identity.
     *
     * @param array $params id (login_users.id), session_id (_sessions.id)
     * @return array {count} - always 1
     */
    #[Ajax_Endpoint]
    public static function terminate_session(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);
        $session_id = static::__positive_int($params['session_id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        if ($session_id === null) {
            return response_not_found('No live session with that id.');
        }

        $refusal = static::__session_refusal($session_id);

        if ($refusal !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $refusal);
        }

        $count = Session::developer_terminate_sessions((int) $login_user->id, $session_id);

        if ($count === 0) {
            return response_not_found("Session #{$session_id} is not a live session of this identity.");
        }

        return ['count' => $count];
    }

    /**
     * End every live session of an identity (Session::developer_terminate_sessions()),
     * except the one the panel is being used from - so ending your own identity's sessions
     * keeps this browser signed in. 0 when there was nothing to end.
     *
     * @param array $params id (login_users.id)
     * @return array {count}
     */
    #[Ajax_Endpoint]
    public static function terminate_all_sessions(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        return ['count' => Session::developer_terminate_sessions((int) $login_user->id)];
    }

    /**
     * Remove one second factor, or all of them - the calls rsx:users:2fa:remove makes.
     *
     * One factor: Rsx_Two_Factor::remove_credential(), whose recovery codes go with it
     * when it was the last. All: Rsx_Two_Factor::remove_all(), recovery codes included.
     * Either way a password (or provider) sign-in stops being challenged once no factor
     * is left. The credential id is checked against the identity's own factors first, as
     * the command does, because the facade treats a foreign id as a silent no-op.
     * Refused on the acting identity.
     *
     * @param array $params id (login_users.id), credential_id (omit for every factor)
     * @return array {is_enabled} - whether the identity still has a second factor
     */
    #[Ajax_Endpoint]
    public static function remove_two_factor(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $refusal = static::__security_refusal($login_user);

        if ($refusal !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $refusal);
        }

        $id = (int) $login_user->id;

        if (($params['credential_id'] ?? null) === null) {
            Rsx_Two_Factor::remove_all($id);
        } else {
            $credential_id = static::__positive_int($params['credential_id']);

            if ($credential_id === null || !in_array($credential_id, array_column(Rsx_Two_Factor::list_credentials($id), 'id'), true)) {
                return response_not_found('This identity holds no second factor with that id.');
            }

            Rsx_Two_Factor::remove_credential($id, $credential_id);
        }

        return ['is_enabled' => Rsx_Two_Factor::is_enabled($id)];
    }

    /**
     * Disconnect one sign-in provider account, or all of them - Rsx_Sso::unlink() /
     * unlink_all(). The link id is checked against the identity's own links first (the
     * facade treats a foreign id as a silent no-op). Nothing checks that the identity
     * keeps a way to sign in: that is application vocabulary (Rsx_Sso::unlink()).
     * Refused on the acting identity.
     *
     * @param array $params id (login_users.id), link_id (omit for every provider)
     * @return array {count} - the providers still connected
     */
    #[Ajax_Endpoint]
    public static function unlink_sso(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $refusal = static::__security_refusal($login_user);

        if ($refusal !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $refusal);
        }

        $id = (int) $login_user->id;

        if (($params['link_id'] ?? null) === null) {
            Rsx_Sso::unlink_all($id);
        } else {
            $link_id = static::__positive_int($params['link_id']);

            if ($link_id === null || !in_array($link_id, array_column(Rsx_Sso::identities_list($id), 'id'), true)) {
                return response_not_found('This identity has no connected provider with that id.');
            }

            Rsx_Sso::unlink($id, $link_id);
        }

        return ['count' => count(Rsx_Sso::identities_list($id))];
    }

    /**
     * Sign in as this identity - Session::begin_impersonation() - on one of its ACTIVE
     * memberships' sites. The developer's current site is stored first
     * (_Sys_Impersonation_Controller::RETURN_SITE_KEY), and the way back is that
     * controller's stop route, which restores it.
     *
     * Session::set_site_id() is called with the chosen site BEFORE the identity swap, so
     * the impersonation starts on that membership. That is where it STAYS only if the
     * application lets the session's site stand: an application that declares its staff
     * site on every request (Main::init() calling Session::set_site_id()) moves the
     * session back to its own site on the very next request, and the framework's
     * membership check then asks about the impersonated identity on THAT site - with no
     * active membership there, the whole session is signed out (Session::logout() is a
     * hard exit, and the impersonation goes with it).
     *
     * Status is application vocabulary and is not refused on. Refused
     * (__impersonate_refusal): the acting identity, a developer, a session already
     * impersonating, an identity with no active membership. A site_id that is not one of
     * the identity's active memberships is a field error.
     *
     * @param array $params id (login_users.id), site_id (the chosen membership's site)
     * @return array {destination} - the URL to load with a full page load (the bundle changes)
     */
    #[Ajax_Endpoint]
    public static function sign_in_as(Request $request, array $params = [])
    {
        $login_user = static::__find_identity($params['id'] ?? null);

        if ($login_user === null) {
            return response_not_found('No login identity with that id.');
        }

        $memberships = static::__memberships((int) $login_user->id);
        $refusal = static::__impersonate_refusal($login_user, $memberships);

        if ($refusal !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $refusal);
        }

        $site_id = static::__positive_int($params['site_id'] ?? null);
        $active_sites = array_column(array_filter($memberships, fn ($row) => $row['is_active']), 'site_id');

        if ($site_id === null || !in_array($site_id, $active_sites, true)) {
            return response_form_error('Choose a site this user has an active membership on.', [
                'site_id' => $site_id === null ? 'Choose a site.' : 'Not an active membership of ' . $login_user->email . '.',
            ]);
        }

        Session::put_value(_Sys_Impersonation_Controller::RETURN_SITE_KEY, Session::get_site_id());
        Session::set_site_id($site_id);
        Session::begin_impersonation((int) $login_user->id);

        return ['destination' => '/'];
    }

    // -------------------------------------------------------------------------
    // Refusals - each enforced by its action and published on the row it concerns
    // -------------------------------------------------------------------------

    /**
     * Why the status of this identity cannot be set here, or null.
     */
    private static function __status_refusal(Login_User_Model $login_user): ?string
    {
        if (static::__is_self($login_user)) {
            return 'This is your own identity. Your status cannot be changed from the panel you are signed in to.';
        }

        return null;
    }

    /**
     * Why this identity's second factors and sign-in providers cannot be changed here,
     * or null.
     */
    private static function __security_refusal(Login_User_Model $login_user): ?string
    {
        if (static::__is_self($login_user)) {
            return 'This is your own identity. Change your own second factors and sign-in providers from your '
                . 'account settings; removing them here could leave you unable to sign in.';
        }

        return null;
    }

    /**
     * Why the panel cannot sign in as this identity, or null.
     *
     * @param array $memberships __memberships() of the identity (is_active is read)
     */
    private static function __impersonate_refusal(Login_User_Model $login_user, array $memberships): ?string
    {
        if (static::__is_self($login_user)) {
            return 'This is your own identity.';
        }

        if ($login_user->is_developer) {
            return $login_user->email . ' is a developer. The panel never signs in as a developer: '
                . 'the session would carry that identity\'s access to this panel.';
        }

        if (Session::is_impersonating()) {
            return 'This session is already signed in as another user. Stop impersonating first.';
        }

        if (!in_array(true, array_column($memberships, 'is_active'), true)) {
            return $login_user->email . ' has no active membership (an enabled membership on an enabled site), '
                . 'so there is no site to sign in to.';
        }

        return null;
    }

    /**
     * Why this membership cannot be disabled here, or null. Enabling is never refused.
     */
    private static function __membership_refusal(User_Model $membership): ?string
    {
        if (static::__is_current_membership($membership)) {
            return "This is the membership your own session is on (site #{$membership->site_id}); "
                . 'disabling it would sign you out. Switch to another site first.';
        }

        return null;
    }

    /**
     * Why this session cannot be ended here, or null.
     */
    private static function __session_refusal(int $session_id): ?string
    {
        if (Session::has_session() && $session_id === Session::get_session_id()) {
            return 'This is the session you are using right now. Sign out instead.';
        }

        return null;
    }

    /**
     * Whether an identity is the one the panel is being used as.
     */
    private static function __is_self(Login_User_Model $login_user): bool
    {
        return (int) $login_user->id === (int) Session::get_login_user_id();
    }

    /**
     * Whether a membership is the one the acting session is on.
     */
    private static function __is_current_membership(User_Model $membership): bool
    {
        return (int) $membership->login_user_id === (int) Session::get_login_user_id()
            && (int) $membership->site_id === Session::get_site_id();
    }

    /**
     * A positive integer id from a param, or null.
     */
    private static function __positive_int($value): ?int
    {
        if (!is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== (string) $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * A live membership by id, on any site, whose identity is live; null otherwise.
     */
    private static function __find_membership($id): ?User_Model
    {
        $id = static::__positive_int($id);

        if ($id === null) {
            return null;
        }

        $membership = User_Model::without_site_scope(fn () => User_Model::find($id));

        if ($membership === null || static::__find_identity($membership->login_user_id) === null) {
            return null;
        }

        return $membership;
    }

    /**
     * A live login identity by id; null for a missing or soft-deleted one.
     */
    private static function __find_identity($id): ?Login_User_Model
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return Login_User_Model::find((int) $id);
    }

    /**
     * Every live membership of an identity, across every site, by site then id.
     *
     * is_active is User_Model::is_active() - the membership enabled AND its site enabled
     * and not deleted. has_site_screen says whether the Sites screen shows the site (never
     * site 0, never a soft-deleted one).
     */
    private static function __memberships(int $login_user_id): array
    {
        // Bounded: one identity's memberships.
        $memberships = User_Model::without_site_scope(
            fn () => User_Model::query()->where('login_user_id', $login_user_id)->orderBy('site_id')->orderBy('id')->get()
        );

        $sites = $memberships->isEmpty() ? collect() : Site_Model::withTrashed()
            ->whereIn('id', $memberships->pluck('site_id')->unique()->values()->all())
            ->get(['id', 'name', 'is_enabled', 'deleted_at'])
            ->keyBy('id');
        $roles = User_Model::role_id__enum();

        $out = [];

        foreach ($memberships as $membership) {
            $site_id = (int) $membership->site_id;
            $site = $sites[$site_id] ?? null;
            $role_id = $membership->role_id === null ? null : (int) $membership->role_id;

            $out[] = [
                'id' => (int) $membership->id,
                'site_id' => $site_id,
                'site_name' => $site->name ?? null,
                'site_is_enabled' => $site !== null && (bool) $site->is_enabled,
                'site_is_deleted' => $site === null || $site->deleted_at !== null,
                'has_site_screen' => $site_id !== 0 && $site !== null && $site->deleted_at === null,
                'first_name' => $membership->first_name,
                'last_name' => $membership->last_name,
                'role_id' => $role_id,
                'role_label' => $role_id === null ? null : ($roles[$role_id]['label'] ?? ('Role ' . $role_id)),
                'is_enabled' => (bool) $membership->is_enabled,
                'is_active' => $membership->is_active(),
                'is_2fa_required' => (bool) $membership->is_2fa_required,
                'is_api_access_enabled' => (bool) $membership->is_api_access_enabled,
                'invite' => static::__invite_state($membership),
                'created_at' => Rsx_Time::to_iso($membership->created_at),
                'is_current' => static::__is_current_membership($membership),
                'disable_refusal' => static::__membership_refusal($membership),
            ];
        }

        return $out;
    }

    /**
     * A membership's invitation as a word: null when it was never an invitation (no code,
     * never accepted), else User_Model::get_invitation_status() - 'pending', 'accepted' or
     * 'expired'. The code itself never leaves the server.
     */
    private static function __invite_state(User_Model $membership): ?string
    {
        if ($membership->invite_accepted_at === null && $membership->invite_code === null) {
            return null;
        }

        return $membership->get_invitation_status();
    }
}
