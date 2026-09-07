<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The user timezone PREFERENCE surface: Rsx_Time::set_user_timezone() /
 * timezone_options() and the Rsx_Timezone_Controller endpoints that expose them.
 *
 * The setter's cache invalidation is the load-bearing part: get_user_timezone()
 * memoizes per (experience, user, site), so without an explicit clear the setter's
 * own response would carry the zone resolved BEFORE the write.
 */
class Rsx_Timezone_Preference_Test extends Rsx_Test_Abstract
{
    const ZONE_A = 'Europe/Berlin';
    const ZONE_B = 'Asia/Tokyo';

    /**
     * Sign in as the first user in the test database and start from a known zone.
     *
     * @return int|null The user id, or null when the database has no user
     */
    private static function __sign_in(): ?int
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::orderBy('id')->first();
        });

        if (!$user) {
            return null;
        }

        static::__acting_as_user((int) $user->id);
        Rsx_Time::_clear_user_timezone_cache();

        return (int) $user->id;
    }

    private static function __sign_out(): void
    {
        static::__reset_session();
        Rsx_Time::_clear_user_timezone_cache();
    }

    // =========================================================================
    // Rsx_Time::set_user_timezone()
    // =========================================================================

    public static function test_setter_persists_the_zone_and_reports_the_change()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        $changed = Rsx_Time::set_user_timezone(static::ZONE_A);

        static::__assert_true($changed, 'moving to a different zone is a change');
        static::__assert_equals(static::ZONE_A, Session::get_login_user()->timezone);

        static::__sign_out();
    }

    public static function test_setting_the_same_zone_is_not_a_change()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Rsx_Time::set_user_timezone(static::ZONE_A);
        $changed = Rsx_Time::set_user_timezone(static::ZONE_A);

        static::__assert_false($changed, 'the resolved zone did not move');

        static::__sign_out();
    }

    public static function test_timezone_auto_only_change_persists_without_reporting_a_change()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Rsx_Time::set_user_timezone(static::ZONE_A, true);
        $changed = Rsx_Time::set_user_timezone(static::ZONE_A, false);

        static::__assert_false($changed, 'only the zone drives a re-render');
        static::__assert_false((bool) Session::get_login_user()->timezone_auto);

        // And back on again.
        Rsx_Time::set_user_timezone(static::ZONE_A, true);
        static::__assert_true((bool) Session::get_login_user()->timezone_auto);

        static::__sign_out();
    }

    public static function test_setter_rejects_an_unknown_identifier()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Rsx_Time::set_user_timezone(static::ZONE_A);

        static::__assert_throws(
            \InvalidArgumentException::class,
            function () {
                Rsx_Time::set_user_timezone('Mars/Olympus_Mons');
            },
            'unknown timezone identifier'
        );

        // Nothing was written.
        static::__assert_equals(static::ZONE_A, Session::get_login_user()->timezone);

        static::__sign_out();
    }

    public static function test_setter_refuses_a_portal_request()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Rsx_Portal::set_portal_request(true);
        Rsx_Time::_clear_user_timezone_cache();

        try {
            static::__assert_throws(
                \RuntimeException::class,
                function () {
                    Rsx_Time::set_user_timezone(static::ZONE_B);
                },
                'portal'
            );
        } finally {
            Rsx_Portal::set_portal_request(false);
            Rsx_Time::_clear_user_timezone_cache();
        }

        static::__sign_out();
    }

    /**
     * The reason the setter clears the cache at all: a get() that already ran this
     * process must not keep serving the pre-write zone.
     */
    public static function test_setter_invalidates_the_resolution_cache()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Rsx_Time::set_user_timezone(static::ZONE_A);

        // Warm the cache, then move the zone underneath it.
        static::__assert_equals(static::ZONE_A, Rsx_Time::get_user_timezone());

        Rsx_Time::set_user_timezone(static::ZONE_B);

        static::__assert_equals(static::ZONE_B, Rsx_Time::get_user_timezone());

        static::__sign_out();
    }

    // =========================================================================
    // Rsx_Time::timezone_options()
    // =========================================================================

    public static function test_timezone_options_shape()
    {
        $options = Rsx_Time::timezone_options();

        static::__assert_greater_than(400, count($options), 'the IANA database has hundreds of identifiers');
        static::__assert_array_has_key('America/Chicago', $options);

        // The label carries the identifier and its CURRENT offset (CST -06:00 or
        // CDT -05:00, depending on when the suite runs).
        static::__assert_true(
            (bool) preg_match('/^America\/Chicago \(UTC-0[56]:00\)$/', $options['America/Chicago']),
            'unexpected label: ' . $options['America/Chicago']
        );

        // UTC itself is the zero case.
        static::__assert_equals('UTC (UTC+00:00)', $options['UTC']);

        // ksorted.
        $keys = array_keys($options);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        static::__assert_equals($sorted, $keys, 'options are sorted by identifier');
    }

    // =========================================================================
    // Rsx_Timezone_Controller
    // =========================================================================

    public static function test_endpoints_refuse_an_anonymous_caller()
    {
        static::__sign_out();

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () {
                Ajax::internal('Rsx_Timezone_Controller', 'timezone_options');
            }
        );

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () {
                Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => static::ZONE_A]);
            }
        );
    }

    public static function test_endpoints_serve_a_signed_in_user()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        $options = Ajax::internal('Rsx_Timezone_Controller', 'timezone_options');
        static::__assert_greater_than(400, count($options));
        static::__assert_array_has_key('value', $options[0]);
        static::__assert_array_has_key('label', $options[0]);

        $result = Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', [
            'timezone' => static::ZONE_B,
            'timezone_auto' => false,
        ]);

        static::__assert_true($result['changed']);
        static::__assert_equals(static::ZONE_B, $result['timezone']);

        $settings = Ajax::internal('Rsx_Timezone_Controller', 'get_settings');
        static::__assert_equals(static::ZONE_B, $settings['timezone']);
        static::__assert_equals(static::ZONE_B, $settings['resolved_timezone']);
        static::__assert_false($settings['timezone_auto']);

        static::__sign_out();
    }

    public static function test_set_timezone_endpoint_returns_a_validation_error()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        // The internal entry point raises the validation response as the coded
        // form-error exception, carrying the per-field details.
        $missing = static::__assert_throws(
            AjaxFormErrorException::class,
            function () {
                Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', []);
            }
        );
        static::__assert_array_has_key('timezone', $missing->get_details());

        $unknown = static::__assert_throws(
            AjaxFormErrorException::class,
            function () {
                Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => 'Mars/Olympus_Mons']);
            }
        );
        static::__assert_array_has_key('timezone', $unknown->get_details());

        static::__sign_out();
    }
}
