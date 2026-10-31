<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Time\Php\Timezone_Companion_Fixture_Model;

/**
 * Under the external API a DATETIME column carries its localized companions and a DATE
 * column carries none: a calendar date has no zone, and converting an evening date west of
 * UTC would move it a day.
 *
 * The fixture table is created and dropped here (DDL commits, so the class runs without
 * the per-test transaction), and Rsx_Schema is told about each change.
 */
class Rsx_Timezone_Companion_Date_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TABLE = 'timezone_companion_fixtures';

    public static function teardown()
    {
        Session::_reset_api_identity();
        static::__reset_session();
    }

    public static function test_a_datetime_is_localized_and_a_date_is_not()
    {
        DB::statement('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        DB::statement('CREATE TABLE `' . self::TABLE . '` (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, happened_at DATETIME NULL, due_on DATE NULL)');
        Rsx_Schema::forget();

        try {
            $record = new Timezone_Companion_Fixture_Model();
            $record->happened_at = '2026-10-29T02:30:00.000Z';
            $record->due_on = '2026-10-29';

            Session::_set_api_identity(1, 1, 1);
            Session::get_login_user()->timezone = 'America/Chicago';
            Rsx_Time::_clear_user_timezone_cache();

            $payload = $record->toArray();

            static::__assert_equals('2026-10-29T02:30:00.000Z', $payload['happened_at']);
            static::__assert_equals('2026-10-28T21:30:00.000-05:00', $payload['happened_at__localized'], 'the evening before, in Chicago');
            static::__assert_equals('America/Chicago', $payload['happened_at__localized_tz']);

            static::__assert_equals('2026-10-29', $payload['due_on'], 'a date is the date');
            static::__assert_false(array_key_exists('due_on__localized', $payload), 'and has no companions');
            static::__assert_false(array_key_exists('due_on__localized_tz', $payload));
        } finally {
            DB::statement('DROP TABLE IF EXISTS `' . self::TABLE . '`');
            Rsx_Schema::forget();
        }
    }
}
