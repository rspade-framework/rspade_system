<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History_Abstract;

/**
 * Login History - the STAFF realm's record of sign-in outcomes, over `_login_history`.
 *
 * The API, the status vocabulary and the two-store design (rows for outcomes only a credential
 * holder can cause, ephemeral counters for failures) are written once in
 * Login_History_Abstract, whose docblock carries the reasoning. This class binds them to the
 * staff identity: a row names a login_users id, and the readers take one.
 *
 * Portal_Login_History is the client portal's twin, over `_portal_login_history`.
 *
 * THERE IS NO SITE COLUMN, because there is no site to record: a staff sign-in authenticates
 * the login identity before any site is chosen, and a row records the identity alone - so its
 * zone, for an automatic identity with no browser zone, is the server's. Entering a SITE is
 * recorded separately, by the framework, in Site_Sign_In_History (the record a site's own
 * screens read); which of an identity's sessions are on a site is
 * Session::get_sessions_for_user($login_user_id, $site_id).
 *
 * Similar to Session class design - static methods for developers to interact with system
 * tables without needing to know the underlying table structure.
 */
class Login_History extends Login_History_Abstract
{
    protected static function __table(): string
    {
        return '_login_history';
    }

    protected static function __query()
    {
        return DB::table('_login_history');
    }

    protected static function __identity_column(): string
    {
        return 'login_user_id';
    }

    protected static function __counter_prefix(): string
    {
        return 'login_failures';
    }

    protected static function __failure_log_message(): string
    {
        return 'Login failure';
    }

    /**
     * Nothing beyond the shared columns - see the class docblock.
     */
    protected static function __row_columns(int $identity_id): array
    {
        return [];
    }

    /**
     * The identity's own explicit zone (login_users.timezone, NULL = automatic) and no site:
     * the identity signs in before any site is chosen.
     */
    protected static function __timezone_inputs(int $identity_id): array
    {
        return [
            'explicit' => Login_User_Model::withTrashed()->where('id', $identity_id)->value('timezone'),
            'site' => null,
        ];
    }

    /**
     * The city, region and country columns the staff table carries.
     */
    protected static function __format_location(object $record): ?string
    {
        $parts = array_filter([
            $record->location_city,
            $record->location_region,
            $record->location_country,
        ]);

        return !empty($parts) ? implode(', ', $parts) : null;
    }
}
