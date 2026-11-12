<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\GeoIp\Php;

use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Geo\Ip\Geo_Ip_Health_Checks;
use App\RSpade\Lib\Geo\Ip\Geo_Ip_Service;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;
use App\RSpade\Tests\GeoIp\Php\Geo_Ip_Mmdb_Fixture;

/**
 * Geo_Ip_Service::update - what it installs and what it refuses, for both databases (DB-IP
 * City Lite and ASN Lite), never touching the network:
 *
 *   - the download page parser reads each MMDB card's release month and SHA1, for the
 *     database whose page it is;
 *   - verification refuses a wrong checksum, a wrong database type, a build outside the
 *     release month and a failed sanity lookup (city: 8.8.8.8 in US; ASN: 8.8.8.8 in AS15169),
 *     and accepts a correct release;
 *   - install_from_gz() swaps a verified release into place, and on any refusal leaves the
 *     installed file untouched and no staged file behind;
 *   - unpacking refuses past its size cap;
 *   - update() ends with no network when the installed files are this month's releases, ends
 *     when they are the latest published ones, and fails when a page lists neither this
 *     month nor the previous one (the pages are a seam: _set_download_page_for_tests());
 *   - the databases are independent: one failing still lets the other finish, and the run
 *     then fails naming the failed one;
 *   - the "IP Geolocation" health row: INFO with no database, OK with current ones, WARN
 *     naming the database and its release once one is past Rsx_Geo_Ip::DATA_VALIDITY_DAYS,
 *     never FAIL (time fixed through Rsx_Geo_Ip::_set_now_for_tests()).
 *
 * Fixture MMDB files (Geo_Ip_Mmdb_Fixture) live in this class's own directory, which
 * Rsx_Geo_Ip::_set_database_files_for_tests() points the installed-database paths at.
 */
class Geo_Ip_Update_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Rsx_Geo_Ip::_set_database_files_for_tests(null, null);
        Rsx_Geo_Ip::_set_now_for_tests(null);
        Geo_Ip_Service::_reset_download_pages_for_tests();
        static::__remove_dir();
    }

    private static function __dir(): string
    {
        return Rsx_Project_Paths::tmp_path('test-geo-ip-update');
    }

    private static function __remove_dir(): void
    {
        foreach (glob(static::__dir() . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir(static::__dir())) {
            rmdir(static::__dir());
        }
    }

    /** A fresh directory; the installed-database paths are its city.mmdb and asn.mmdb (absent). Answers the city path. */
    private static function __fresh(): string
    {
        static::__remove_dir();
        mkdir(static::__dir(), 0775, true);
        $target = static::__dir() . '/city.mmdb';
        Rsx_Geo_Ip::_set_database_files_for_tests($target, static::__asn_target());
        Geo_Ip_Service::_reset_download_pages_for_tests();

        return $target;
    }

    private static function __asn_target(): string
    {
        return static::__dir() . '/asn.mmdb';
    }

    private const ASN_TYPE = 'DBIP-ASN-Lite (compat=GeoLite2-ASN)';

    private static function __asn_networks(int $asn = 15169): array
    {
        return [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::asn_record($asn, 'Google LLC'),
        ];
    }

    /** Midday on the 15th of a release month, UTC. */
    private static function __epoch(string $release): int
    {
        return strtotime($release . '-15 12:00:00 UTC');
    }

    private static function __networks(string $iso = 'US'): array
    {
        return [
            '8.8.8.0/24' => Geo_Ip_Mmdb_Fixture::city_record('Mountain View', 'California', 'United States', $iso),
        ];
    }

    /** The files in the fixture directory other than the installed database. */
    private static function __leftovers(): array
    {
        return array_values(array_filter(
            glob(static::__dir() . '/{,.}*', GLOB_BRACE) ?: [],
            fn ($path) => is_file($path) && !in_array(basename($path), ['city.mmdb', 'asn.mmdb'], true) && !str_ends_with($path, '.gz')
        ));
    }

    /** A download page with one MMDB card (and one CSV card that must be ignored); $stem names the database's files. */
    private static function __page(string $release, string $sha1, string $stem = 'dbip-city-lite'): string
    {
        return <<<HTML
            <div class="card"><dl class="card-body">
                <dt>Format</dt><dd>CSV</dd><dt>Release</dt><dd>Whatever</dd>
                <dt>MD5SUM</dt><dd class="small">a547aa29f27b25714b49b6786fa9ad82</dd>
                <dt>SHA1SUM</dt><dd class="small">254ef60b261cb4fb613362058b388a480d2a6b92</dd>
            </dl><a href='https://download.db-ip.com/free/{$stem}-{$release}.csv.gz'>CSV</a></div>
            <div class="card"><dl class="card-body">
                <dt>Format</dt>
                <dd>MMDB</dd>
                <dt>MD5SUM</dt>
                <dd class="small">11b738040abb45cf462e40d7d266cbe7</dd>
                <dt>SHA1SUM</dt>
                <dd class="small">{$sha1}</dd>
            </dl><div><a href='https://download.db-ip.com/free/{$stem}-{$release}.mmdb.gz' class='btn'>MMDB</a></div></div>
            HTML;
    }

    public static function test_the_download_page_parser_reads_each_mmdb_release()
    {
        $sha1 = str_repeat('ab', 20);
        static::__assert_equals(['2026-11' => $sha1], Geo_Ip_Service::parse_published_releases(static::__page('2026-11', $sha1), Rsx_Geo_Ip::DATABASE_CITY));
        static::__assert_equals(['2026-11' => $sha1], Geo_Ip_Service::parse_published_releases(static::__page('2026-11', $sha1, 'dbip-asn-lite'), Rsx_Geo_Ip::DATABASE_ASN));
        static::__assert_equals([], Geo_Ip_Service::parse_published_releases(static::__page('2026-11', $sha1), Rsx_Geo_Ip::DATABASE_ASN), "the city page is not the ASN database's");
        static::__assert_equals([], Geo_Ip_Service::parse_published_releases('<html>nothing here</html>', Rsx_Geo_Ip::DATABASE_CITY));
        static::__assert_equals(
            [],
            Geo_Ip_Service::parse_published_releases(str_replace('SHA1SUM', 'SHA256', static::__page('2026-11', $sha1)), Rsx_Geo_Ip::DATABASE_CITY),
            'an MMDB card without a SHA1 is not a release'
        );
    }

    public static function test_verification_accepts_the_expected_release()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-11'));

        Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_CITY, $file, '2026-11', sha1_file($file));
        static::__pass();
    }

    public static function test_verification_refuses_a_wrong_checksum()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_CITY, $file, '2026-11', str_repeat('0', 40)), 'SHA1');
    }

    public static function test_verification_refuses_a_wrong_type()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), 'GeoLite2-City', static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_CITY, $file, '2026-11', sha1_file($file)), "type 'GeoLite2-City'");
    }

    public static function test_verification_refuses_a_build_outside_the_release_month()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-09'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_CITY, $file, '2026-11', sha1_file($file)), 'outside its 2026-11 release month');
    }

    public static function test_verification_refuses_a_failed_sanity_lookup()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks('FR'), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_CITY, $file, '2026-11', sha1_file($file)), Geo_Ip_Service::SANITY_IP);
    }

    public static function test_install_swaps_a_verified_release_into_place()
    {
        $target = static::__fresh();
        $gz = static::__dir() . '/release.mmdb.gz';
        $sha1 = Geo_Ip_Mmdb_Fixture::write_gz($gz, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-11'));

        Geo_Ip_Service::install_from_gz(Rsx_Geo_Ip::DATABASE_CITY, $gz, '2026-11', $sha1);

        static::__assert_equals($sha1, sha1_file($target), 'the installed file is the unpacked release');
        static::__assert_equals([], static::__leftovers(), 'no staged file is left behind');
        static::__assert_equals(['2026-11' => '2026-11'], ['2026-11' => Rsx_Geo_Ip::installed_metadata(Rsx_Geo_Ip::DATABASE_CITY)['release']], 'the installed file reads as the release');
    }

    public static function test_a_refused_install_leaves_the_installed_file_untouched()
    {
        $target = static::__fresh();
        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-10'));
        $before = sha1_file($target);

        $gz = static::__dir() . '/release.mmdb.gz';
        Geo_Ip_Mmdb_Fixture::write_gz($gz, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::install_from_gz(Rsx_Geo_Ip::DATABASE_CITY, $gz, '2026-11', str_repeat('0', 40)), 'SHA1');
        static::__assert_equals($before, sha1_file($target), 'the installed file is unchanged');
        static::__assert_equals([], static::__leftovers(), 'the staged file is discarded');
    }

    public static function test_unpacking_refuses_past_its_size_cap()
    {
        static::__fresh();
        $gz = static::__dir() . '/big.gz';
        file_put_contents($gz, gzencode(str_repeat('x', 4096)));
        $out = static::__dir() . '/big.out';

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::unpack_gz($gz, $out, 1000), 'refused');
        static::__assert_equals(sha1(str_repeat('x', 4096)), Geo_Ip_Service::unpack_gz($gz, $out . '2', 4096), 'within the cap it unpacks');
    }

    public static function test_update_ends_without_the_network_when_this_month_is_installed()
    {
        $target = static::__fresh();
        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], time());
        Geo_Ip_Mmdb_Fixture::write(static::__asn_target(), static::__asn_networks(), self::ASN_TYPE, time());
        // Pages that would fail the run if they were read.
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_CITY, '<html></html>');
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_ASN, '<html></html>');

        $run = Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_contains('Current', (string) $run->summary());
    }

    public static function test_update_ends_when_the_installed_release_is_the_latest_published()
    {
        $target = static::__fresh();
        $previous = gmdate('Y-m', strtotime(gmdate('Y-m-01') . ' -1 month'));
        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch($previous));
        Geo_Ip_Mmdb_Fixture::write(static::__asn_target(), static::__asn_networks(), self::ASN_TYPE, static::__epoch($previous));
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_CITY, static::__page($previous, sha1_file($target)));
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_ASN, static::__page($previous, sha1_file(static::__asn_target()), 'dbip-asn-lite'));

        $run = Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_contains('is not published yet', (string) $run->summary());
    }

    public static function test_update_fails_when_the_page_lists_no_recent_release()
    {
        static::__fresh();
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_CITY, static::__page('2019-01', str_repeat('ab', 20)));
        Geo_Ip_Mmdb_Fixture::write(static::__asn_target(), static::__asn_networks(), self::ASN_TYPE, time());

        static::__assert_throws(
            Exception::class,
            fn () => Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD),
            'lists no MMDB release'
        );
    }

    public static function test_verification_of_the_asn_database()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';

        Geo_Ip_Mmdb_Fixture::write($file, static::__asn_networks(), self::ASN_TYPE, static::__epoch('2026-11'));
        Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_ASN, $file, '2026-11', sha1_file($file));

        static::__assert_throws(
            Exception::class,
            fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_ASN, $file, '2026-11', str_repeat('0', 40)),
            'SHA1'
        );

        Geo_Ip_Mmdb_Fixture::write($file, static::__asn_networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], static::__epoch('2026-11'));
        static::__assert_throws(
            Exception::class,
            fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_ASN, $file, '2026-11', sha1_file($file)),
            "type 'DBIP-City-Lite'"
        );

        Geo_Ip_Mmdb_Fixture::write($file, static::__asn_networks(), self::ASN_TYPE, static::__epoch('2026-09'));
        static::__assert_throws(
            Exception::class,
            fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_ASN, $file, '2026-11', sha1_file($file)),
            'outside its 2026-11 release month'
        );

        Geo_Ip_Mmdb_Fixture::write($file, static::__asn_networks(13335), self::ASN_TYPE, static::__epoch('2026-11'));
        static::__assert_throws(
            Exception::class,
            fn () => Geo_Ip_Service::verify_candidate(Rsx_Geo_Ip::DATABASE_ASN, $file, '2026-11', sha1_file($file)),
            'autonomous system 13335'
        );
    }

    public static function test_one_database_failing_does_not_block_the_other()
    {
        static::__fresh();
        $release = gmdate('Y-m');

        // The city page lists no release, so the city update fails; the ASN database is this
        // month's release (current, no network), so its update still completes after it.
        Geo_Ip_Mmdb_Fixture::write(static::__asn_target(), static::__asn_networks(), self::ASN_TYPE, time());
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_CITY, '<html>no releases</html>');
        Geo_Ip_Service::_set_download_page_for_tests(Rsx_Geo_Ip::DATABASE_ASN, '<html></html>');

        $error = null;
        try {
            Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        static::__assert_not_null($error, 'the run fails');
        static::__assert_contains('DB-IP City Lite:', (string) $error, 'naming the failed database');
        static::__assert_false(str_contains((string) $error, 'DB-IP ASN Lite:'), 'and only it');

        $run = Task_Run_Model::query()
            ->where('class', Task::resolve_task_class(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD))
            ->orderByDesc('id')
            ->first();
        static::__assert_contains("DB-IP ASN Lite: Current ({$release} release).", (string) $run->summary(), 'the ASN database was still checked');
    }

    public static function test_the_health_row_warns_on_an_expired_database_and_never_fails()
    {
        $target = static::__fresh();
        DB::table('_tasks')->where('class', Task::resolve_task_class(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD))->delete();
        $built = static::__epoch('2026-03');

        Rsx_Geo_Ip::_set_now_for_tests($built + 10 * 86400);
        $row = Geo_Ip_Health_Checks::geo_ip();
        static::__assert_equals('INFO', $row['status'], 'no database yet is INFO');
        static::__assert_contains('not acquired yet', $row['detail']);

        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASES[Rsx_Geo_Ip::DATABASE_CITY]['type'], $built);
        Geo_Ip_Mmdb_Fixture::write(static::__asn_target(), static::__asn_networks(), self::ASN_TYPE, $built);
        $row = Geo_Ip_Health_Checks::geo_ip();
        static::__assert_equals('OK', $row['status'], 'current databases are OK');
        static::__assert_contains('DB-IP City Lite, 2026-03 release', $row['detail']);

        Rsx_Geo_Ip::_set_now_for_tests(Rsx_Geo_Ip::validity_ends($built));
        $row = Geo_Ip_Health_Checks::geo_ip();
        static::__assert_equals('WARN', $row['status'], 'an expired database is WARN, never FAIL');
        static::__assert_contains('DB-IP City Lite, 2026-03 release - EXPIRED 2026-09-14', $row['detail'], 'naming the database, its release and when it expired');
        static::__assert_contains('DB-IP ASN Lite, 2026-03 release - EXPIRED 2026-09-14', $row['detail']);
        static::__assert_contains('rsx:task:run Geo_Ip_Service update', $row['remediation']);
    }
}
