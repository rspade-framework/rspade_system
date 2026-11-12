<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\GeoIp\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;
use App\RSpade\Tests\GeoIp\Php\Geo_Ip_Mmdb_Fixture;

/**
 * Rsx_Geo_Ip::lookup() - the order of a lookup and the update trigger:
 *
 *   - an invalid or non-global address answers all-null at once: nothing cached, nothing
 *     dispatched even with no database;
 *   - a cached answer is the answer, whatever the database says;
 *   - a database answer is cached for a day, an address the database does not know included;
 *   - a database that will not open answers all-null, uncached (the failure is reported);
 *   - no database answers all-null, uncached, and dispatches the update task once per
 *     process - not when a run was created in the last 6 hours, nor when its pending or
 *     scheduled run is due within 5 minutes.
 *
 * Lookups read fixture MMDB files written by Geo_Ip_Mmdb_Fixture into a directory of this
 * class's own (Rsx_Geo_Ip::_set_database_file_for_tests()); the installed database is never
 * read or touched. Each test runs in the default rolled-back transaction.
 */
class Geo_Ip_Lookup_Test extends Rsx_Test_Abstract
{
    private const PUBLIC_KNOWN = '8.8.8.8';
    private const PUBLIC_UNKNOWN = '9.9.9.9';

    public static function teardown()
    {
        Rsx_Geo_Ip::_set_database_file_for_tests(null);
        static::__remove_dir();
    }

    private static function __dir(): string
    {
        return Rsx_Project_Paths::tmp_path('test-geo-ip-lookup');
    }

    private static function __remove_dir(): void
    {
        foreach (glob(static::__dir() . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir(static::__dir())) {
            rmdir(static::__dir());
        }
    }

    /** A fresh fixture directory, lookups pointed at $file_name in it, no cached answers, no update runs. */
    private static function __fresh(string $file_name = 'city.mmdb'): string
    {
        static::__remove_dir();
        mkdir(static::__dir(), 0775, true);
        $file = static::__dir() . '/' . $file_name;
        Rsx_Geo_Ip::_set_database_file_for_tests($file);

        foreach ([self::PUBLIC_KNOWN, self::PUBLIC_UNKNOWN, '10.1.2.3'] as $ip) {
            RsxCache::delete_persistent(Rsx_Geo_Ip::_cache_key_for_tests($ip));
        }

        $class = static::__update_class();
        DB::table('_tasks')->where('class', $class)->delete();
        DB::table('_task_schedules')->where('class', $class)->delete();

        return $file;
    }

    private static function __update_class(): string
    {
        return Task::resolve_task_class(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);
    }

    private static function __write_fixture(string $file): void
    {
        Geo_Ip_Mmdb_Fixture::write($file, [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::city_record('Mountain View', 'California', 'United States', 'US'),
        ]);
    }

    private static function __update_runs(): int
    {
        return DB::table('_tasks')->where('class', static::__update_class())->where('method', Rsx_Geo_Ip::UPDATE_METHOD)->count();
    }

    private static function __cached(string $ip): mixed
    {
        return RsxCache::get_persistent(Rsx_Geo_Ip::_cache_key_for_tests($ip));
    }

    public static function test_a_non_global_or_invalid_address_answers_nothing_and_touches_nothing()
    {
        static::__fresh();   // no database file at all

        foreach (['10.1.2.3', '127.0.0.1', '192.168.0.9', '169.254.1.1', '::1', 'fe80::1', '198.51.100.7', 'not an address', ''] as $ip) {
            static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup($ip), "lookup('{$ip}')");
        }

        static::__assert_null(static::__cached('10.1.2.3'), 'a private address is never cached');
        static::__assert_equals(0, static::__update_runs(), 'a non-global address never triggers the update');
    }

    public static function test_an_ipv4_mapped_address_is_read_as_ipv4()
    {
        static::__assert_equals('8.8.8.8', Rsx_Geo_Ip::canonical_public_ip('::ffff:8.8.8.8'));
        static::__assert_null(Rsx_Geo_Ip::canonical_public_ip('::ffff:10.0.0.1'));
    }

    public static function test_a_database_answer_is_cached_including_not_found()
    {
        static::__write_fixture(static::__fresh());

        $expected = ['city' => 'Mountain View', 'state' => 'California', 'country' => 'United States'];
        static::__assert_equals($expected, Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN));
        static::__assert_equals($expected, static::__cached(self::PUBLIC_KNOWN), 'the found answer is cached');

        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup(self::PUBLIC_UNKNOWN));
        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), static::__cached(self::PUBLIC_UNKNOWN), 'an address the database does not know is cached all-null');
    }

    public static function test_label_joins_the_known_parts()
    {
        static::__write_fixture(static::__fresh());

        static::__assert_equals('Mountain View, California, United States', Rsx_Geo_Ip::label(self::PUBLIC_KNOWN));
        static::__assert_null(Rsx_Geo_Ip::label(self::PUBLIC_UNKNOWN), 'nothing known is null');
        static::__assert_null(Rsx_Geo_Ip::label('10.1.2.3'));
    }

    public static function test_a_cached_answer_wins_over_the_database()
    {
        static::__write_fixture(static::__fresh());

        $cached = ['city' => 'Cached City', 'state' => null, 'country' => 'Cached Country'];
        RsxCache::set_persistent(Rsx_Geo_Ip::_cache_key_for_tests(self::PUBLIC_KNOWN), $cached, 60);

        static::__assert_equals($cached, Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN));
    }

    public static function test_a_database_that_will_not_open_answers_nothing_uncached()
    {
        $file = static::__fresh();
        file_put_contents($file, 'this is not a MaxMind database');

        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN), 'a lookup error is never thrown');
        static::__assert_null(static::__cached(self::PUBLIC_KNOWN), 'an error answer is never cached');
    }

    public static function test_no_database_answers_nothing_uncached_and_triggers_the_update_once()
    {
        static::__fresh();

        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN));
        static::__assert_null(static::__cached(self::PUBLIC_KNOWN), 'the no-database answer is never cached');
        static::__assert_equals(1, static::__update_runs(), 'the first lookup with no database dispatches the update');

        DB::table('_tasks')->where('class', static::__update_class())->delete();
        Rsx_Geo_Ip::lookup(self::PUBLIC_UNKNOWN);
        static::__assert_equals(0, static::__update_runs(), 'the trigger is considered once per process');
    }

    public static function test_a_run_in_the_last_six_hours_suppresses_the_trigger()
    {
        static::__fresh();

        // A finished run five hours ago: inside the window.
        static::__insert_run(Task_Run_Model::STATUS_FAILED, now()->subHours(5), now()->subHours(5));
        Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN);
        static::__assert_equals(1, static::__update_runs(), 'a run five hours ago suppresses the dispatch');

        // Seven hours ago: outside it.
        static::__fresh();
        static::__insert_run(Task_Run_Model::STATUS_COMPLETED, now()->subHours(7), now()->subHours(7));
        Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN);
        static::__assert_equals(2, static::__update_runs(), 'a run seven hours ago does not');
    }

    public static function test_a_run_due_within_five_minutes_suppresses_the_trigger()
    {
        // A pending run created long ago, due in two minutes.
        static::__fresh();
        static::__insert_run(Task_Run_Model::STATUS_PENDING, now()->subDays(2), now()->addMinutes(2));
        Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN);
        static::__assert_equals(1, static::__update_runs(), 'a pending run due within 5 minutes suppresses the dispatch');

        // The schedule due in two minutes.
        static::__fresh();
        static::__insert_schedule(now()->addMinutes(2));
        Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN);
        static::__assert_equals(0, static::__update_runs(), 'a schedule due within 5 minutes suppresses the dispatch');

        // The schedule due in an hour.
        static::__fresh();
        static::__insert_schedule(now()->addHour());
        Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN);
        static::__assert_equals(1, static::__update_runs(), 'a schedule due in an hour does not');
    }

    private static function __insert_run(int $status_id, $created_at, $scheduled_for): void
    {
        DB::table('_tasks')->insert([
            'class' => static::__update_class(),
            'method' => Rsx_Geo_Ip::UPDATE_METHOD,
            'params' => '[]',
            'params_hash' => \App\RSpade\Core\Task\Task_Concurrency::params_hash([]),
            'origin_id' => Task_Run_Model::ORIGIN_DISPATCHED,
            'status_id' => $status_id,
            'priority' => 0,
            'scheduled_for' => $scheduled_for->format('Y-m-d H:i:s.v'),
            'created_at' => $created_at->format('Y-m-d H:i:s.v'),
            'updated_at' => $created_at->format('Y-m-d H:i:s.v'),
        ]);
    }

    private static function __insert_schedule($next_run_at): void
    {
        DB::table('_task_schedules')->insert([
            'class' => static::__update_class(),
            'method' => Rsx_Geo_Ip::UPDATE_METHOD,
            'cron_expression' => 'maintenance',
            'cron_resolved' => '0 2 * * *',
            'timezone' => 'UTC',
            'next_run_at' => $next_run_at->format('Y-m-d H:i:s'),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
