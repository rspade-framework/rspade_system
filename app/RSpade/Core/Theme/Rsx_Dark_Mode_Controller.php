<?php

namespace App\RSpade\Core\Theme;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Theme\Rsx_Dark_Mode;

/**
 * Rsx_Dark_Mode_Controller - the theme preference's read/write surface.
 *
 * The framework owns the preference, so it owns the endpoints that change it: an app
 * builds whatever settings screen it likes and posts here, exactly as it does for the
 * timezone. A pass-through endpoint in the app would be a second way to do one thing.
 *
 * STAFF REALM ONLY. The preference lives on login_users and portal accounts have none,
 * so a portal request has nothing to read or write here.
 *
 * See: php artisan rsx:man dark_mode
 */
#[Auth('is_logged_in')]
class Rsx_Dark_Mode_Controller extends Rsx_Controller_Abstract
{
    /**
     * The current preference plus everything a settings widget needs to render itself.
     *
     * 'mode' is what the user chose; 'is_dark' is the resolved answer and is null under
     * auto, where only the browser can say. 'options' comes from the model enum, so the
     * labels are declared in exactly one place.
     */
    #[Ajax_Endpoint]
    public static function get_settings(Request $request, array $params = [])
    {
        static::_refuse_portal();

        return [
            'mode' => Rsx_Dark_Mode::get_mode(),
            'is_dark' => Rsx_Dark_Mode::is_dark(),
            'options' => Rsx_Dark_Mode::mode_options(),
        ];
    }

    /**
     * Store the preference.
     *
     * Returns 'changed', which says the RESOLVED theme moved - that is what tells the
     * client the page it is looking at is now painted wrong. The client's job on a change
     * is to make the NEXT navigation a full page load (Spa.disable()), because the theme
     * is rendered server-side into <body> and only a real request can re-render it.
     */
    #[Ajax_Endpoint]
    public static function set_dark_mode(Request $request, array $params = [])
    {
        static::_refuse_portal();

        if (!array_key_exists('dark_mode', $params) || $params['dark_mode'] === null || $params['dark_mode'] === '') {
            return response_error(Ajax::ERROR_VALIDATION, ['dark_mode' => 'A theme is required']);
        }

        $mode = (int) $params['dark_mode'];

        if (!Rsx_Dark_Mode::is_valid_mode($mode)) {
            return response_error(Ajax::ERROR_VALIDATION, ['dark_mode' => 'Unknown theme']);
        }

        $changed = Rsx_Dark_Mode::set_mode($mode);

        return [
            'changed' => $changed,
            'mode' => $mode,
            'is_dark' => Rsx_Dark_Mode::is_dark(),
        ];
    }

    /**
     * The portal has no theme preference; reaching these endpoints from it is a wiring
     * mistake, not a user error.
     */
    private static function _refuse_portal(): void
    {
        if (Rsx_Portal::is_portal_request()) {
            shouldnt_happen('Rsx_Dark_Mode_Controller reached on a portal request - portal accounts have no theme preference');
        }
    }
}
