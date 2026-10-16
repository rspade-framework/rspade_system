<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Logging\System_Timezone_Log_Tap;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The three clocks of an RSpade process:
 *
 *   - the PROCESS clock is UTC whatever the host's is - PHP's default zone, and the MySQL
 *     session time_zone every connection sets;
 *   - the SERVER timezone (RSX_SERVER_TIMEZONE, Rsx_Time::get_server_timezone(), blank =
 *     UTC) is the zone a new site gets when it was not given one;
 *   - the SYSTEM timezone (Rsx_Time::get_system_timezone()) is the operating system's,
 *     and only log records are stamped in it, through System_Timezone_Log_Tap.
 *
 * The server zone's other two uses - #[Schedule] wall-clock times and their re-registration
 * - are Cron_Parser_Timezone_Test and Task_Schedule_Reconcile_Test.
 */
class Server_Timezone_Test extends Rsx_Test_Abstract
{
    private static function __with_server_timezone(?string $zone, callable $fn)
    {
        $original = config('rsx.datetime.server_timezone');
        config(['rsx.datetime.server_timezone' => $zone]);
        try {
            return $fn();
        } finally {
            config(['rsx.datetime.server_timezone' => $original]);
        }
    }

    public static function test_the_process_clock_is_utc()
    {
        static::__assert_equals('UTC', date_default_timezone_get(), 'PHP');
        static::__assert_equals('+00:00', DB::selectOne('SELECT @@session.time_zone AS tz')->tz, 'the MySQL session');
    }

    public static function test_the_server_timezone_is_the_configured_zone_and_blank_is_utc()
    {
        static::__with_server_timezone('America/Chicago', function () {
            static::__assert_equals('America/Chicago', Rsx_Time::get_server_timezone());
        });
        static::__with_server_timezone('', function () {
            static::__assert_equals('UTC', Rsx_Time::get_server_timezone());
        });
        static::__with_server_timezone(null, function () {
            static::__assert_equals('UTC', Rsx_Time::get_server_timezone());
        });
    }

    public static function test_an_unknown_server_timezone_throws_naming_the_key()
    {
        static::__with_server_timezone('Central', function () {
            static::__assert_throws(\RuntimeException::class, function () {
                Rsx_Time::get_server_timezone();
            }, 'RSX_SERVER_TIMEZONE');
        });
    }

    public static function test_a_new_site_without_a_timezone_takes_the_server_timezone()
    {
        static::__with_server_timezone('Asia/Tokyo', function () {
            $site = new Site_Model();
            $site->slug = 'server-tz-' . uniqid();
            $site->name = 'Server Timezone Default';
            $site->save();

            static::__assert_equals('Asia/Tokyo', Site_Model::find($site->id)->timezone);
        });
    }

    public static function test_a_new_site_given_a_timezone_keeps_it()
    {
        static::__with_server_timezone('Asia/Tokyo', function () {
            $site = new Site_Model();
            $site->slug = 'server-tz-' . uniqid();
            $site->name = 'Server Timezone Explicit';
            $site->timezone = 'Europe/Berlin';
            $site->save();

            static::__assert_equals('Europe/Berlin', Site_Model::find($site->id)->timezone);
        });
    }

    public static function test_the_system_timezone_reads_tz_first()
    {
        $original = getenv('TZ');
        try {
            putenv('TZ=Europe/Berlin');
            static::__assert_equals('Europe/Berlin', Rsx_Time::get_system_timezone());

            putenv('TZ=:Asia/Tokyo');
            static::__assert_equals('Asia/Tokyo', Rsx_Time::get_system_timezone(), 'the POSIX leading colon');

            putenv('TZ');
            static::__assert_true(
                in_array(Rsx_Time::get_system_timezone(), \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true),
                'unset, the host files answer with a known zone'
            );
        } finally {
            putenv($original === false ? 'TZ' : 'TZ=' . $original);
        }
    }

    public static function test_the_log_tap_stamps_records_in_the_system_timezone()
    {
        $original = getenv('TZ');
        try {
            putenv('TZ=America/Chicago');
            $logger = new Logger(new \Monolog\Logger('server-timezone-test'));
            (new System_Timezone_Log_Tap())($logger);

            static::__assert_equals('America/Chicago', $logger->getLogger()->getTimezone()->getName());
        } finally {
            putenv($original === false ? 'TZ' : 'TZ=' . $original);
        }
    }

    public static function test_every_log_channel_taps_the_system_timezone()
    {
        // 'null' writes nothing, and Laravel's runtime 'deprecations' channel is a copy of
        // whichever channel logging.deprecations names - 'null' unless configured otherwise.
        $null = config('logging.channels.null');
        foreach (config('logging.channels') as $name => $channel) {
            if ($channel == $null) {
                continue;
            }
            static::__assert_true(
                in_array(System_Timezone_Log_Tap::class, $channel['tap'] ?? [], true),
                "log channel '{$name}' taps System_Timezone_Log_Tap"
            );
        }
    }
}
