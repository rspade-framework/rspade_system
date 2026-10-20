<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Login_History_Abstract;

/**
 * Portal Login History - the CLIENT PORTAL realm's record of sign-in outcomes, over
 * `_portal_login_history`.
 *
 * The same API and the same status vocabulary as Login_History, method for method, written
 * once in Login_History_Abstract: record_success(), record_refusal(), record_failure(),
 * record_passkey_enrollment(), get_history_for_user(), list_page() (scope key
 * 'portal_user_id'), present_record(), and the failure counts. Every id here is a
 * portal_users id.
 *
 * WHAT THE PORTAL REALM DOES DIFFERENTLY:
 *
 *   SITE. A row also carries the portal user's site_id, read from the portal user itself (a
 *   portal user belongs to exactly one site, and a recording made outside a portal request -
 *   the hourly sweep recording an abandoned passkey enrollment - has no declared site to read).
 *
 *   COUNTERS. The per-email and per-IP failure statistics are kept under their own keys, so a
 *   client and a staff member typing the same address keep separate counts. The brute-force
 *   budget (Login_Throttle) is NOT separate: one client IP has one budget across both realms.
 *
 *   NO LOCATION. The portal table carries no location columns; present_record() answers null.
 *
 * Framework producers: Portal_Auth::verify_credentials() (the password door),
 * Rsx_Portal_Two_Factor (the second-factor challenge, passwordless passkey sign-in and passkey
 * enrollment) and Rsx_Portal_Sso (federated sign-in). An application reads the table only
 * through this class: `_portal_login_history` is a system table.
 */
class Portal_Login_History extends Login_History_Abstract
{
    protected static function __table(): string
    {
        return '_portal_login_history';
    }

    protected static function __query()
    {
        return DB::table('_portal_login_history');
    }

    protected static function __identity_column(): string
    {
        return 'portal_user_id';
    }

    protected static function __counter_prefix(): string
    {
        return 'portal_login_failures';
    }

    protected static function __failure_log_message(): string
    {
        return 'Portal login failure';
    }

    /**
     * The portal user's own site. Read across sites and including a trashed row: the
     * recording is about the identity, whatever tenant or deletion state the process is in.
     */
    protected static function __row_columns(int $identity_id): array
    {
        $site_id = Portal_User_Model::without_site_scope(
            fn () => Portal_User_Model::withTrashed()->where('id', $identity_id)->value('site_id')
        );

        if ($site_id === null) {
            shouldnt_happen("Portal_Login_History: no portal user with id {$identity_id} to record a sign-in for");
        }

        return ['site_id' => (int) $site_id];
    }

    /**
     * The portal table records no location - see the class docblock.
     */
    protected static function __format_location(object $record): ?string
    {
        return null;
    }
}
