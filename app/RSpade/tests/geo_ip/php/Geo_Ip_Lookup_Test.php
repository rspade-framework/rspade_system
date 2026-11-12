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
 * Rsx_Geo_Ip::lookup() / label() - the order of a lookup, the answer's shape and the update
 * trigger:
 *
 *   - an invalid or non-global address answers all-null at once: nothing cached, nothing
 *     dispatched even with no database;
 *   - a cached answer is the answer, whatever the databases say;
 *   - the answer is cached for a day when both databases answered, an address they do not
 *     know included;
 *   - country is the alpha-3 code (the countries table), an unmapped code null;
 *   - org is the configured name of a listed network, null for any other; a listed CIDR
 *     range wins over the ASN (the most specific range among several) and needs no ASN
 *     record; the application's rsx.geo_ip.networks adds, renames and (null) unlists
 *     entries; an invalid list leaves org null and the answer uncached;
 *   - label() is the org when set, else the place;
 *   - a database that will not open, or is missing, leaves its part null and the answer
 *     uncached; a missing one dispatches the update task once per process - not when a run
 *     was created in the last 6 hours, nor when its pending or scheduled run is due within
 *     5 minutes;
 *   - a database past its validity window (Rsx_Geo_Ip::DATA_VALIDITY_DAYS after its build
 *     epoch) is absent: its part null, nothing cached, the update triggered; the day before,
 *     it answers; a fresh release in its place answers again; the other database and the
 *     listed CIDR ranges still answer (time is fixed through _set_now_for_tests()).
 *
 * Lookups read fixture MMDB files written by Geo_Ip_Mmdb_Fixture into a directory of this
 * class's own (Rsx_Geo_Ip::_set_database_files_for_tests()); the installed databases are never
 * read or touched. Each test runs in the default rolled-back transaction.
 */
class Geo_Ip_Lookup_Test extends Rsx_Test_Abstract
{
    private const PUBLIC_KNOWN = '8.8.8.8';
    private const PUBLIC_UNKNOWN = '9.9.9.9';
    private const PUBLIC_UNLISTED = '73.162.0.1';
    private const PUBLIC_UNMAPPED = '5.5.5.5';

    public static function teardown()
    {
        Rsx_Geo_Ip::_set_database_files_for_tests(null, null);
        Rsx_Geo_Ip::_set_now_for_tests(null);
        if (static::$shipped_networks !== null) {
            config([Rsx_Geo_Ip::NETWORKS_CONFIG => static::$shipped_networks]);
        }
        static::__remove_dir();
    }

    /** The shipped list, restored after a test that changes the configuration. */
    private static ?array $shipped_networks = null;

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

    /**
     * A fresh fixture directory, lookups pointed at city.mmdb and asn.mmdb in it (neither
     * written), no cached answers, no update runs. Answers the city file's path.
     */
    private static function __fresh(): string
    {
        static::$shipped_networks ??= config(Rsx_Geo_Ip::NETWORKS_CONFIG);
        config([Rsx_Geo_Ip::NETWORKS_CONFIG => static::$shipped_networks]);

        Rsx_Geo_Ip::_set_now_for_tests(null);
        static::__remove_dir();
        mkdir(static::__dir(), 0775, true);
        $file = static::__dir() . '/city.mmdb';
        Rsx_Geo_Ip::_set_database_files_for_tests($file, static::__asn_file());

        foreach ([self::PUBLIC_KNOWN, self::PUBLIC_UNKNOWN, self::PUBLIC_UNLISTED, self::PUBLIC_UNMAPPED, '10.1.2.3', '153.61.208.1', '160.79.110.1', '160.79.104.1', '52.94.236.248', '2607:6bc0::1'] as $ip) {
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

    private static function __asn_file(): string
    {
        return static::__dir() . '/asn.mmdb';
    }

    /** The build epoch of the release fixtures in the expiry tests: midday 2026-03-15 UTC. */
    private const RELEASE_EPOCH = 1773576000;

    /**
     * A city fixture written beside $file and renamed into place - a new file, as the update
     * installs one, so an open reader sees it replaced.
     */
    private static function __write_city_fixture(string $file, ?int $build_epoch = null): void
    {
        Geo_Ip_Mmdb_Fixture::write($file . '.new', [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::city_record('Mountain View', 'California', 'United States', 'US'),
        ], 'DBIP-City-Lite', $build_epoch);
        rename($file . '.new', $file);
    }

    /** Both fixture databases: 8.8.8.0/24 is Google (listed), 73.162.0.0/16 Comcast (an ISP, unlisted). */
    private static function __write_fixture(string $file): void
    {
        Geo_Ip_Mmdb_Fixture::write($file, [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::city_record('Mountain View', 'California', 'United States', 'US'),
            '73.162.0.0/16' => Geo_Ip_Mmdb_Fixture::city_record('Salinas', 'California', 'United States', 'US'),
            '5.5.5.0/24' => Geo_Ip_Mmdb_Fixture::city_record('Nowhere', null, 'Nowhere', 'ZZ'),
        ]);
        static::__write_asn_fixture();
    }

    /** The ASN fixture, written beside the file and renamed into place (see __write_city_fixture()). */
    private static function __write_asn_fixture(?int $build_epoch = null): void
    {
        Geo_Ip_Mmdb_Fixture::write(static::__asn_file() . '.new', [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::asn_record(15169, 'Google LLC'),
            '73.162.0.0/16' => Geo_Ip_Mmdb_Fixture::asn_record(7922, 'Comcast Cable Communications, LLC'),
            // As the real database has them: part of Anthropic's published 160.79.104.0/21 is
            // attributed to Amazon's ASN, and 153.61.208.0/20 is not in it at all.
            '160.79.104.0/24' => Geo_Ip_Mmdb_Fixture::asn_record(399358, 'Anthropic, PBC'),
            '160.79.110.0/24' => Geo_Ip_Mmdb_Fixture::asn_record(16509, 'Amazon.com, Inc.'),
            '52.94.0.0/16' => Geo_Ip_Mmdb_Fixture::asn_record(16509, 'Amazon.com, Inc.'),
        ], 'DBIP-ASN-Lite (compat=GeoLite2-ASN)', $build_epoch);
        rename(static::__asn_file() . '.new', static::__asn_file());
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

        $expected = ['city' => 'Mountain View', 'state' => 'California', 'country' => 'USA', 'org' => 'Google'];
        static::__assert_equals($expected, Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN));
        static::__assert_equals($expected, static::__cached(self::PUBLIC_KNOWN), 'the found answer is cached');

        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup(self::PUBLIC_UNKNOWN));
        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), static::__cached(self::PUBLIC_UNKNOWN), 'an address the database does not know is cached all-null');
    }

    public static function test_label_is_the_org_when_listed_else_the_place()
    {
        static::__write_fixture(static::__fresh());

        static::__assert_equals('Google', Rsx_Geo_Ip::label(self::PUBLIC_KNOWN), 'a listed network shows its name');
        static::__assert_equals('Salinas, California, USA', Rsx_Geo_Ip::label(self::PUBLIC_UNLISTED), 'an unlisted network shows the place, country alpha-3');
        static::__assert_null(Rsx_Geo_Ip::label(self::PUBLIC_UNKNOWN), 'nothing known is null');
        static::__assert_null(Rsx_Geo_Ip::label('10.1.2.3'));
    }

    public static function test_an_unlisted_network_answers_org_null_and_the_place_still()
    {
        static::__write_fixture(static::__fresh());

        static::__assert_equals(
            ['city' => 'Salinas', 'state' => 'California', 'country' => 'USA', 'org' => null],
            Rsx_Geo_Ip::lookup(self::PUBLIC_UNLISTED),
            'an ISP is never named'
        );
    }

    public static function test_a_country_code_with_no_countries_row_answers_country_null()
    {
        static::__write_fixture(static::__fresh());

        static::__assert_equals(
            ['city' => 'Nowhere', 'state' => null, 'country' => null, 'org' => null],
            Rsx_Geo_Ip::lookup(self::PUBLIC_UNMAPPED),
            'an unmapped code is null, never fatal'
        );
        static::__assert_equals('GBR', Rsx_Geo_Ip::country_alpha3('gb'), 'the mapping reads the countries table');
    }

    public static function test_a_listed_range_wins_over_the_asn()
    {
        static::__write_fixture(static::__fresh());

        static::__assert_equals('Anthropic', Rsx_Geo_Ip::lookup('153.61.208.1')['org'], 'a range the ASN database does not know');
        static::__assert_equals('Anthropic', Rsx_Geo_Ip::lookup('160.79.110.1')['org'], "a range the ASN database gives to Amazon's ASN is Anthropic, not AWS");
        static::__assert_equals('Anthropic', Rsx_Geo_Ip::lookup('160.79.104.1')['org']);
        static::__assert_equals('AWS', Rsx_Geo_Ip::lookup('52.94.236.248')['org'], "an unlisted range of Amazon's ASN is AWS");
        static::__assert_equals('Anthropic', Rsx_Geo_Ip::lookup('2607:6bc0::1')['org'], 'an IPv6 range');
        static::__assert_equals('Anthropic', Rsx_Geo_Ip::label('153.61.208.1'));
    }

    public static function test_the_application_adds_renames_and_unlists_networks()
    {
        static::__write_fixture(static::__fresh());
        config([Rsx_Geo_Ip::NETWORKS_CONFIG => array_merge(static::$shipped_networks, [
            'AS7922' => 'Example Listed ISP',
            'AS15169' => null,
            '160.79.110.0/24' => 'Example Narrower Range',
        ])]);

        static::__assert_equals('Example Listed ISP', Rsx_Geo_Ip::lookup(self::PUBLIC_UNLISTED)['org'], 'an added entry is listed');
        static::__assert_null(Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN)['org'], 'an entry set to null is unlisted');
        static::__assert_equals('Example Narrower Range', Rsx_Geo_Ip::lookup('160.79.110.1')['org'], 'the most specific range wins');
        static::__assert_equals('Anthropic', Rsx_Geo_Ip::lookup('160.79.104.1')['org'], 'the wider range still answers outside it');

        $networks = Rsx_Geo_Ip::networks();
        static::__assert_equals('AWS', $networks['AS16509'], 'the shipped entries stay');
        static::__assert_false(array_key_exists('AS15169', $networks));
    }

    public static function test_an_invalid_network_list_answers_org_null_uncached()
    {
        static::__write_fixture(static::__fresh());
        config([Rsx_Geo_Ip::NETWORKS_CONFIG => ['16509' => 'AWS']]);

        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Geo_Ip::networks(), "is neither 'AS<number>'");

        $answer = Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN);
        static::__assert_null($answer['org'], 'the network part is null');
        static::__assert_equals('USA', $answer['country'], 'the place still answers');
        static::__assert_null(static::__cached(self::PUBLIC_KNOWN), 'an answer with a failed part is never cached');
    }

    public static function test_a_missing_asn_database_leaves_org_null_uncached_and_triggers_the_update()
    {
        $file = static::__fresh();
        Geo_Ip_Mmdb_Fixture::write($file, [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::city_record('Mountain View', 'California', 'United States', 'US'),
        ]);

        static::__assert_equals(
            ['city' => 'Mountain View', 'state' => 'California', 'country' => 'USA', 'org' => null],
            Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN),
            'the city database still answers its part'
        );
        static::__assert_null(static::__cached(self::PUBLIC_KNOWN), 'an incomplete answer is never cached');
        static::__assert_equals(1, static::__update_runs(), 'the missing database dispatches the update');
    }

    public static function test_an_expired_database_is_absent_uncached_and_triggers_the_update()
    {
        $file = static::__fresh();
        static::__write_city_fixture($file, self::RELEASE_EPOCH);
        static::__write_asn_fixture(self::RELEASE_EPOCH);
        static::__assert_equals(gmmktime(12, 0, 0, 3, 15, 2026), self::RELEASE_EPOCH, 'the fixture release epoch');

        $ends = Rsx_Geo_Ip::validity_ends(self::RELEASE_EPOCH);
        static::__assert_equals('2026-09-14 12:00:00', gmdate('Y-m-d H:i:s', $ends), 'the window is 183 days from the build epoch');

        Rsx_Geo_Ip::_set_now_for_tests($ends);
        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN), 'both databases expired: the address stands alone');
        static::__assert_null(static::__cached(self::PUBLIC_KNOWN), 'nothing is cached for an expired database');
        static::__assert_equals(1, static::__update_runs(), 'an expired database dispatches the update, as a missing one does');
        static::__assert_true(Rsx_Geo_Ip::installed_metadata(Rsx_Geo_Ip::DATABASE_CITY)['expired'], 'the metadata reads it as expired');

        Rsx_Geo_Ip::_set_now_for_tests($ends - 86400);
        static::__assert_equals(
            ['city' => 'Mountain View', 'state' => 'California', 'country' => 'USA', 'org' => 'Google'],
            Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN),
            'the day before its window ends, the previous release still answers'
        );
        static::__assert_not_null(static::__cached(self::PUBLIC_KNOWN), 'and that answer is cached');
    }

    public static function test_a_fresh_release_in_place_of_an_expired_one_answers_again()
    {
        $file = static::__fresh();
        static::__write_city_fixture($file, self::RELEASE_EPOCH);
        static::__write_asn_fixture(self::RELEASE_EPOCH);
        $later = Rsx_Geo_Ip::validity_ends(self::RELEASE_EPOCH) + 30 * 86400;
        Rsx_Geo_Ip::_set_now_for_tests($later);

        static::__assert_equals(Rsx_Geo_Ip::empty_answer(), Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN), 'expired');

        // A successful update writes a new file; the open reader sees the new file and reopens.
        static::__write_city_fixture($file, $later - 86400);
        static::__write_asn_fixture($later - 86400);
        static::__assert_equals(
            ['city' => 'Mountain View', 'state' => 'California', 'country' => 'USA', 'org' => 'Google'],
            Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN),
            'a fresh release revives the lookup'
        );
    }

    public static function test_one_expired_database_leaves_the_other_and_the_listed_ranges_answering()
    {
        $file = static::__fresh();
        static::__write_city_fixture($file, self::RELEASE_EPOCH);
        $now = Rsx_Geo_Ip::validity_ends(self::RELEASE_EPOCH) + 86400;
        static::__write_asn_fixture($now - 86400);
        Rsx_Geo_Ip::_set_now_for_tests($now);

        static::__assert_equals(
            ['city' => null, 'state' => null, 'country' => null, 'org' => 'Google'],
            Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN),
            'the expired city database is absent; the current ASN database still answers'
        );
        static::__assert_null(static::__cached(self::PUBLIC_KNOWN), 'an answer missing a part is never cached');

        static::__write_asn_fixture(self::RELEASE_EPOCH);
        static::__assert_equals('Anthropic', Rsx_Geo_Ip::lookup('153.61.208.1')['org'], 'a listed CIDR range needs no database and never expires');
        static::__assert_null(Rsx_Geo_Ip::lookup('52.94.236.248')['org'], 'an ASN entry answers nothing once the ASN database has expired');
    }

    public static function test_a_cached_answer_wins_over_the_database()
    {
        static::__write_fixture(static::__fresh());

        $cached = ['city' => 'Cached City', 'state' => null, 'country' => 'CCC', 'org' => null];
        RsxCache::set_persistent(Rsx_Geo_Ip::_cache_key_for_tests(self::PUBLIC_KNOWN), $cached, 60);

        static::__assert_equals($cached, Rsx_Geo_Ip::lookup(self::PUBLIC_KNOWN));
    }

    public static function test_a_database_that_will_not_open_answers_nothing_uncached()
    {
        $file = static::__fresh();
        file_put_contents($file, 'this is not a MaxMind database');
        file_put_contents(static::__asn_file(), 'nor is this');

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
