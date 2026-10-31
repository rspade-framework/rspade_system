<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * What the account stores and what an external API response carries:
 *
 *  - login_users.timezone NULL is AUTOMATIC, and there is no separate flag column;
 *  - a new login identity starts at rsx.datetime.default_user_timezone (automatic / site /
 *    server / an identifier) when its creator set none - on creation only;
 *  - under the external API every DATETIME column of a serialized model carries
 *    <col>__localized and <col>__localized_tz in the key holder's zone, through the same
 *    encoder a hand-built payload uses (Rsx_Time::encode_for_output()), and an Ajax
 *    payload carries none. (A DATE column's absence of companions is pinned by the
 *    application suite, against a model that has one.)
 */
class Rsx_Timezone_Storage_Test extends Rsx_Test_Abstract
{
    private static $default_user_timezone = null;
    private static $server_timezone = null;

    public static function setup(): void
    {
        static::$default_user_timezone = config('rsx.datetime.default_user_timezone');
        static::$server_timezone = config('rsx.datetime.server_timezone');
    }

    public static function teardown(): void
    {
        config([
            'rsx.datetime.default_user_timezone' => static::$default_user_timezone,
            'rsx.datetime.server_timezone' => static::$server_timezone,
        ]);
        Session::_reset_api_identity();
        static::__reset_session();
    }

    private static function __new_login_user(?string $timezone = null, bool $set_timezone = false): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'timezone_storage_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('a-password-1');
        if ($set_timezone) {
            $login_user->timezone = $timezone;
        }
        $login_user->save();

        return $login_user->fresh();
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    public static function test_the_automatic_flag_column_is_gone()
    {
        $columns = array_column(DB::select('SHOW COLUMNS FROM login_users'), 'Field');

        static::__assert_true(in_array('timezone', $columns, true));
        static::__assert_false(in_array('timezone_auto', $columns, true), 'NULL is automatic; there is no second switch');
    }

    // -------------------------------------------------------------------------
    // default_user_timezone
    // -------------------------------------------------------------------------

    public static function test_each_default_form_applies_on_create()
    {
        static::__acting_as_site(1);
        config(['rsx.datetime.server_timezone' => 'Asia/Tokyo']);

        config(['rsx.datetime.default_user_timezone' => 'automatic']);
        static::__assert_null(static::__new_login_user()->timezone, 'automatic: NULL');

        config(['rsx.datetime.default_user_timezone' => 'server']);
        static::__assert_equals('Asia/Tokyo', static::__new_login_user()->timezone, 'server: a copy of the server zone');

        config(['rsx.datetime.default_user_timezone' => 'site']);
        static::__assert_equals(Rsx_Time::get_site_timezone(), static::__new_login_user()->timezone, 'site: a copy of the creating site\'s zone');

        config(['rsx.datetime.default_user_timezone' => 'America/Denver']);
        static::__assert_equals('America/Denver', static::__new_login_user()->timezone, 'an identifier: itself');
    }

    public static function test_an_explicit_value_on_create_wins_and_an_update_is_untouched()
    {
        config(['rsx.datetime.default_user_timezone' => 'America/Denver']);

        static::__assert_equals('Europe/Berlin', static::__new_login_user('Europe/Berlin', true)->timezone, 'the creator\'s value');
        static::__assert_null(static::__new_login_user(null, true)->timezone, 'an explicit NULL (automatic) too');

        $login_user = static::__new_login_user(null, true);
        $login_user->email = 'timezone_storage_renamed_' . uniqid() . '@example.com';
        $login_user->save();
        static::__assert_null($login_user->fresh()->timezone, 'a later save never applies the default');
    }

    public static function test_an_unknown_default_throws()
    {
        config(['rsx.datetime.default_user_timezone' => 'Mars/Olympus_Mons']);

        try {
            static::__assert_throws(\RuntimeException::class, fn () => static::__new_login_user(), 'RSX_DEFAULT_USER_TIMEZONE');
        } finally {
            // Configuration is process state, not a row: the rollback does not restore it.
            config(['rsx.datetime.default_user_timezone' => static::$default_user_timezone]);
        }
    }

    // -------------------------------------------------------------------------
    // External API companions
    // -------------------------------------------------------------------------

    public static function test_an_api_payload_localizes_every_datetime_in_the_key_holders_zone()
    {
        $subject = static::__new_login_user();

        Session::_set_api_identity(1, 1, 1);
        Session::get_login_user()->timezone = 'America/Chicago';
        Rsx_Time::_clear_user_timezone_cache();

        $payload = $subject->toArray();

        static::__assert_equals($subject->created_at, $payload['created_at'], 'the UTC value is unchanged');
        static::__assert_equals('America/Chicago', $payload['created_at__localized_tz']);
        static::__assert_equals(
            Rsx_Time::parse($subject->created_at)->setTimezone('America/Chicago')->format('Y-m-d\TH:i:s.vP'),
            $payload['created_at__localized'],
            'the same instant, with its offset'
        );
        static::__assert_true(array_key_exists('last_login__localized', $payload), 'a NULL datetime gets companions too');
        static::__assert_null($payload['last_login__localized']);

        // The hand-built-payload helper gives exactly what the serializer gave.
        $helper = Rsx_Time::encode_for_output('created_at', $subject->created_at);
        static::__assert_equals($helper, array_intersect_key($payload, $helper), 'byte for byte');
    }

    public static function test_an_ajax_payload_carries_utc_alone()
    {
        $subject = static::__new_login_user();

        $payload = $subject->toArray();

        static::__assert_true(array_key_exists('created_at', $payload));
        static::__assert_false(array_key_exists('created_at__localized', $payload), 'the browser converts with _user_timezone');
        static::__assert_false(array_key_exists('created_at__localized_tz', $payload));
    }

    public static function test_the_helper_handles_null_and_any_input_form()
    {
        static::__acting_as_user(1);
        Session::get_login_user()->timezone = 'Asia/Tokyo';
        Rsx_Time::_clear_user_timezone_cache();

        static::__assert_equals(
            ['at' => null, 'at__localized' => null, 'at__localized_tz' => null],
            Rsx_Time::encode_for_output('at', null)
        );

        static::__assert_equals(
            [
                'at' => '2026-10-29T10:13:03.000Z',
                'at__localized' => '2026-10-29T19:13:03.000+09:00',
                'at__localized_tz' => 'Asia/Tokyo',
            ],
            Rsx_Time::encode_for_output('at', '2026-10-29 10:13:03'),
            'a database-format value is UTC, and Tokyo is nine hours on'
        );
    }
}
