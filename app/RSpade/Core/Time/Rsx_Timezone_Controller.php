<?php

namespace App\RSpade\Core\Time;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * RSX:USE
 * Rsx_Timezone_Controller - the signed-in user's timezone preference.
 *
 * The preference is ONE value: an IANA identifier the user chose, or null for AUTOMATIC
 * (each browser's own zone, carried by the rsx_tz cookie). Nothing writes the account's
 * zone but an explicit save here: the browser's zone lives in the browser's cookie.
 *
 * Usage from JavaScript:
 *     const options  = await Rsx_Timezone_Controller.timezone_options();
 *     const settings = await Rsx_Timezone_Controller.get_settings();
 *     const result   = await Rsx_Timezone_Controller.set_timezone({timezone: 'Europe/Berlin'});
 *     const result   = await Rsx_Timezone_Controller.set_timezone({timezone: null});   // automatic
 *
 * AUTHORIZATION: is_logged_in, staff realm. A personal timezone belongs to a
 * login_users row, and a portal account has none (see Rsx_Time::get_user_timezone).
 */
#[Auth('is_logged_in')]
class Rsx_Timezone_Controller extends Rsx_Controller_Abstract
{
    /**
     * Every IANA timezone identifier as Select_Input-native options, labelled with
     * the identifier and its current UTC offset.
     *
     * @return array [{value, label}, ...]
     */
    #[Ajax_Endpoint]
    public static function timezone_options(Request $request, array $params = [])
    {
        $options = [];

        foreach (Rsx_Time::timezone_options() as $identifier => $label) {
            $options[] = ['value' => $identifier, 'label' => $label];
        }

        return $options;
    }

    /**
     * The current user's timezone preference, as the settings screen needs it: the stored
     * preference (null = automatic), and the RESOLVED zone this request renders in - under
     * automatic, the browser's zone (or the site's when the browser reported none).
     *
     * @return array {timezone: string|null, resolved_timezone: string}
     */
    #[Ajax_Endpoint]
    public static function get_settings(Request $request, array $params = [])
    {
        $login_user = Session::get_login_user();
        if (!$login_user) {
            shouldnt_happen('Rsx_Timezone_Controller::get_settings reached with no signed-in login user');
        }

        return [
            'timezone' => Rsx_Time::explicit_user_timezone(),
            'resolved_timezone' => Rsx_Time::get_user_timezone(),
        ];
    }

    /**
     * Set the user's timezone preference: an IANA identifier, or null / '' for automatic.
     *
     * @param array $params ['timezone' => 'Europe/Berlin' | null]
     * @return array ['changed' => bool, 'timezone' => string|null] - 'changed' says the
     *               RESOLVED zone moved, which is what tells the client its page is stale.
     */
    #[Ajax_Endpoint]
    public static function set_timezone(Request $request, array $params = [])
    {
        $timezone = $params['timezone'] ?? null;

        if ($timezone === '') {
            $timezone = null;
        }

        if ($timezone !== null && !is_string($timezone)) {
            return response_error(Ajax::ERROR_VALIDATION, ['timezone' => 'Unknown timezone']);
        }

        try {
            $changed = Rsx_Time::set_user_timezone($timezone);
        } catch (\InvalidArgumentException $e) {
            // The one expected failure: an identifier the client sent that PHP does
            // not know. Everything else bubbles.
            return response_error(Ajax::ERROR_VALIDATION, ['timezone' => 'Unknown timezone']);
        }

        return [
            'changed' => $changed,
            'timezone' => $timezone,
        ];
    }
}
