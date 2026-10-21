<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\PortalUsers;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Portal_Sso;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Sys\App\Sys\PortalUsers\_Sys_Portal_User_Signins_DataGrid;
use App\RSpade\Sys\App\Sys\PortalUsers\_Sys_Portal_Users_DataGrid;
use App\RSpade\Sys\App\Sys\Users\_Sys_Audit_Sessions_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;

/**
 * The Portal Users screen's endpoints: the portal-user grid across every site, one portal
 * user's facts, where it is signed in (each session with its own screen of the addresses and
 * browsers it acted from), portal sign-in history and security state. READ-ONLY:
 * the screen changes nothing - no status, no session ending, no factor removal, no View
 * as Client. Portal accounts are administered by the application that invites them.
 *
 * THE RECORD IS THE PORTAL USER (portal_users), the client portal's identity. Unlike a
 * staff login identity it belongs to exactly ONE site, and portal_users is site-scoped, so
 * every read here lifts the site scope - the developer's own session site narrows
 * nothing. A soft-deleted portal user answers not_found exactly as a missing id does.
 *
 * Every answer is built from the portal realm's published readers:
 * Portal_Session::get_sessions_for_user(), Audit_Session::list_page_for_user_session()
 * (through _Sys_Audit_Sessions_DataGrid), Portal_Login_History::list_page() (through
 * _Sys_Portal_User_Signins_DataGrid), and the metadata-only Rsx_Portal_Two_Factor /
 * Rsx_Portal_Sso readers - no TOTP seed, passkey key, recovery-code hash or provider user
 * key leaves here, by the readers' own contract.
 */
#[Auth('is_sysadmin')]
class _Sys_Portal_Users_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The portal-user grid (_Sys_Portal_Users_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return _Sys_Portal_Users_DataGrid::fetch($params);
    }

    /**
     * The status filter's options, in the enum's order.
     *
     * @return array [{value, label}] - value is the lowercase status word
     */
    #[Ajax_Endpoint]
    public static function status_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::enum_options(Portal_User_Model::class, 'status_id');
    }

    /**
     * The site filter's options: every site that has a live portal user.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function site_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::site_options(Portal_User_Model::class);
    }

    /**
     * One portal user's facts.
     *
     * @param array $params id (portal_users.id)
     * @return array {user: {id, email, printed_name, site_id, site_name, has_site_screen,
     *                status_id, status, status_label, is_verified, can_login,
     *                password_reset_required, last_login, last_seen, created_at, updated_at}}
     *                can_login is Portal_User_Model::can_login() - active AND verified, the
     *                portal's own sign-in rule. has_site_screen says whether the Sites screen
     *                shows the site (never site 0, never a soft-deleted one).
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        $portal_user = static::__find_portal_user($params['id'] ?? null);

        if ($portal_user === null) {
            return response_not_found('No portal user with that id.');
        }

        $site_id = (int) $portal_user->site_id;
        $site = Site_Model::withTrashed()->find($site_id, ['id', 'name', 'deleted_at']);
        $status_id = (int) $portal_user->status_id;

        return [
            'user' => [
                'id' => (int) $portal_user->id,
                'email' => $portal_user->email,
                'printed_name' => $portal_user->get_printed_name(),
                'site_id' => $site_id,
                'site_name' => $site->name ?? null,
                'has_site_screen' => $site_id !== 0 && $site !== null && $site->deleted_at === null,
                'status_id' => $status_id,
                'status' => _Sys_Enum_Words::enum_word(Portal_User_Model::class, 'status_id', $status_id),
                'status_label' => Portal_User_Model::$enums['status_id'][$status_id]['label'],
                'is_verified' => (bool) $portal_user->is_verified,
                'can_login' => $portal_user->can_login(),
                'password_reset_required' => $portal_user->is_password_reset_required(),
                'last_login' => Rsx_Time::to_iso($portal_user->last_login),
                'last_seen' => Rsx_Time::to_iso($portal_user->last_seen),
                'created_at' => Rsx_Time::to_iso($portal_user->created_at),
                'updated_at' => Rsx_Time::to_iso($portal_user->updated_at),
            ],
        ];
    }

    /**
     * Where the portal user is signed in: Portal_Session::get_sessions_for_user() with View as
     * Client sessions - one row per browser (x staff viewer), SIGNED IN and recently SIGNED
     * OUT, most recently active first. Each entry is __present_sessions()'s shape. Bounded by
     * the portal user's browsers, signed-out entries only within
     * rsx.sessions.unused_session_days, and answered whole.
     *
     * @param array $params id (portal_users.id)
     * @return array {sessions: [__present_sessions() entries]}
     */
    #[Ajax_Endpoint]
    public static function sessions(Request $request, array $params = [])
    {
        $portal_user = static::__find_portal_user($params['id'] ?? null);

        if ($portal_user === null) {
            return response_not_found('No portal user with that id.');
        }

        return ['sessions' => static::__present_sessions(Portal_Session::get_sessions_for_user((int) $portal_user->id, true))];
    }

    /**
     * One user session of the portal user, for the session detail screen: the portal user's
     * id and email, and the entry exactly as sessions() lists it. not_found unless the id is
     * one of sessions()' entries for THIS portal user.
     *
     * @param array $params id (portal_users.id), user_session_id
     * @return array {user: {id, email}, session: a __present_sessions() entry}
     */
    #[Ajax_Endpoint]
    public static function session_detail(Request $request, array $params = [])
    {
        $portal_user = static::__find_portal_user($params['id'] ?? null);

        if ($portal_user === null) {
            return response_not_found('No portal user with that id.');
        }

        $session = static::__listed_session($portal_user, $params['user_session_id'] ?? null);

        if ($session === null) {
            return response_not_found('No session of this portal user with that id.');
        }

        return [
            'user' => ['id' => (int) $portal_user->id, 'email' => $portal_user->email],
            'session' => static::__present_sessions([$session])[0],
        ];
    }

    /**
     * Every address and browser one of the portal user's user sessions acted from, a page at
     * a time (_Sys_Audit_Sessions_DataGrid over Audit_Session::list_page_for_user_session()).
     * not_found unless user_session_id is one of the portal user's listed sessions.
     *
     * @param array $params portal_user_id, user_session_id, plus the grid's page, per_page,
     *                      sort, order, filter
     */
    #[Ajax_Endpoint]
    public static function audit_sessions_fetch(Request $request, array $params = [])
    {
        $portal_user = static::__find_portal_user($params['portal_user_id'] ?? null);

        if ($portal_user === null) {
            return response_not_found('No portal user with that id.');
        }

        $session = static::__listed_session($portal_user, $params['user_session_id'] ?? null);

        if ($session === null) {
            return response_not_found('No session of this portal user with that id.');
        }

        return _Sys_Audit_Sessions_DataGrid::fetch((int) $session['id'], $params);
    }

    /**
     * One portal user's sign-in history, a page at a time (_Sys_Portal_User_Signins_DataGrid).
     *
     * @param array $params portal_user_id, plus the grid's page, per_page, sort, order, filter
     */
    #[Ajax_Endpoint]
    public static function signins_fetch(Request $request, array $params = [])
    {
        if (static::__find_portal_user($params['portal_user_id'] ?? null) === null) {
            return response_not_found('No portal user with that id.');
        }

        return _Sys_Portal_User_Signins_DataGrid::fetch($params);
    }

    /**
     * The portal user's second factors and connected sign-in providers, as metadata only.
     *
     * @param array $params id (portal_users.id)
     * @return array {two_factor: {is_enabled, has_passkey, recovery_codes_remaining,
     *                issues_recovery_codes, credentials: [{id, type_id, type_id__label, label,
     *                confirmed_at, last_used_at}]},
     *                sso: {is_enabled, identities: [{id, provider_key, provider_label, email,
     *                name, last_login_at, created_at}]}}
     */
    #[Ajax_Endpoint]
    public static function security(Request $request, array $params = [])
    {
        $portal_user = static::__find_portal_user($params['id'] ?? null);

        if ($portal_user === null) {
            return response_not_found('No portal user with that id.');
        }

        $id = (int) $portal_user->id;

        $credentials = array_map(function ($credential) {
            $credential['confirmed_at'] = Rsx_Time::to_iso($credential['confirmed_at']);
            $credential['last_used_at'] = Rsx_Time::to_iso($credential['last_used_at']);

            return $credential;
        }, Rsx_Portal_Two_Factor::list_credentials($id));

        $identities = array_map(function ($identity) {
            $identity['last_login_at'] = Rsx_Time::to_iso($identity['last_login_at']);
            $identity['created_at'] = Rsx_Time::to_iso($identity['created_at']);

            return $identity;
        }, Rsx_Portal_Sso::identities_list($id));

        return [
            'two_factor' => [
                'is_enabled' => Rsx_Portal_Two_Factor::is_enabled($id),
                'has_passkey' => Rsx_Portal_Two_Factor::has_passkey($id),
                'recovery_codes_remaining' => Rsx_Portal_Two_Factor::recovery_codes_remaining($id),
                'issues_recovery_codes' => Rsx_Portal_Two_Factor::issues_recovery_codes(),
                'credentials' => $credentials,
            ],
            'sso' => [
                'is_enabled' => Rsx_Portal_Sso::is_enabled(),
                'identities' => $identities,
            ],
        ];
    }

    /**
     * The Portal_Session::get_sessions_for_user() entry (View as Client included) of this
     * portal user with this user session id, or null - the one ownership test behind
     * session_detail() and audit_sessions_fetch().
     */
    private static function __listed_session(Portal_User_Model $portal_user, $user_session_id): ?array
    {
        if (!is_numeric($user_session_id) || (int) $user_session_id <= 0 || (string) (int) $user_session_id !== (string) $user_session_id) {
            return null;
        }

        foreach (Portal_Session::get_sessions_for_user((int) $portal_user->id, true) as $session) {
            if ($session['id'] === (int) $user_session_id) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Portal_Session::get_sessions_for_user() entries as the Sessions tab and the session
     * screen show them, with the staff member behind a View as Client: their membership
     * (users.id), its login identity (the Users screen's record) and email.
     *
     * @param array $sessions get_sessions_for_user() entries
     * @return array [{id, device_summary, user_agent, ip_address, first_seen, signed_in_at,
     *               last_active, is_signed_in, signed_out_at, sign_out_reason, sign_in_count,
     *               is_current, is_impersonation, impersonator_user_id,
     *               impersonator_login_user_id, impersonator_email, impersonation_started_at,
     *               type_label}]
     */
    private static function __present_sessions(array $sessions): array
    {
        $viewer_ids = array_values(array_unique(array_filter(array_column($sessions, 'impersonator_id'))));
        // Bounded: the staff members who viewed this one portal user, deleted ones included.
        $viewers = $viewer_ids === [] ? collect() : User_Model::without_site_scope(
            fn () => User_Model::withTrashed()->whereIn('id', $viewer_ids)->limit(count($viewer_ids))->get(['id', 'login_user_id', 'email'])->keyBy('id')
        );

        $out = [];

        foreach ($sessions as $session) {
            $viewer = $session['impersonator_id'] === null ? null : ($viewers[$session['impersonator_id']] ?? null);

            $out[] = [
                'id' => (int) $session['id'],
                'device_summary' => $session['device_summary'],
                'user_agent' => $session['user_agent'],
                'ip_address' => $session['ip_address'],
                'first_seen' => Rsx_Time::to_iso($session['first_seen']),
                'signed_in_at' => Rsx_Time::to_iso($session['signed_in_at']),
                'last_active' => Rsx_Time::to_iso($session['last_active']),
                'is_signed_in' => (bool) $session['is_signed_in'],
                'signed_out_at' => $session['signed_out_at'] === null ? null : Rsx_Time::to_iso($session['signed_out_at']),
                'sign_out_reason' => $session['sign_out_reason'],
                'sign_in_count' => (int) $session['sign_in_count'],
                'is_current' => (bool) $session['is_current'],
                'is_impersonation' => (bool) $session['is_impersonation'],
                'impersonator_user_id' => $session['impersonator_id'],
                'impersonator_login_user_id' => $viewer === null || $viewer->login_user_id === null ? null : (int) $viewer->login_user_id,
                'impersonator_email' => $viewer === null ? null : $viewer->email,
                'impersonation_started_at' => $session['impersonation_started_at'],
                'type_label' => Session::$enums['type_id'][$session['type_id']]['label'] ?? ('Type ' . $session['type_id']),
            ];
        }

        return $out;
    }

    /**
     * A live portal user by id, on any site; null for a missing, soft-deleted, zero or
     * non-numeric id.
     */
    private static function __find_portal_user($id): ?Portal_User_Model
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return Portal_User_Model::without_site_scope(fn () => Portal_User_Model::find((int) $id));
    }
}
