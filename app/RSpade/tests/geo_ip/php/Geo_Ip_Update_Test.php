<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\GeoIp\Php;

use Exception;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Geo\Ip\Geo_Ip_Service;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;
use App\RSpade\Tests\GeoIp\Php\Geo_Ip_Mmdb_Fixture;

/**
 * Geo_Ip_Service::update - what it installs and what it refuses, never touching the network:
 *
 *   - the download page parser reads each MMDB card's release month and SHA1;
 *   - verification refuses a wrong checksum, a wrong database type, a build outside the
 *     release month and a failed sanity lookup, and accepts a correct release;
 *   - install_from_gz() swaps a verified release into place, and on any refusal leaves the
 *     installed file untouched and no staged file behind;
 *   - unpacking refuses past its size cap;
 *   - update() ends with no network when the installed file is this month's release, ends
 *     when it is the latest published one, and fails when the page lists neither this month
 *     nor the previous one (the page is a seam: _set_download_page_for_tests()).
 *
 * Fixture MMDB files (Geo_Ip_Mmdb_Fixture) live in this class's own directory, which
 * Rsx_Geo_Ip::_set_database_file_for_tests() points the installed-database path at.
 */
class Geo_Ip_Update_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Rsx_Geo_Ip::_set_database_file_for_tests(null);
        Geo_Ip_Service::_set_download_page_for_tests(null);
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

    /** A fresh directory; the installed-database path is its city.mmdb (absent). */
    private static function __fresh(): string
    {
        static::__remove_dir();
        mkdir(static::__dir(), 0775, true);
        $target = static::__dir() . '/city.mmdb';
        Rsx_Geo_Ip::_set_database_file_for_tests($target);
        Geo_Ip_Service::_set_download_page_for_tests(null);

        return $target;
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
            fn ($path) => is_file($path) && basename($path) !== 'city.mmdb' && !str_ends_with($path, '.gz')
        ));
    }

    /** A download page with one MMDB card (and one CSV card that must be ignored). */
    private static function __page(string $release, string $sha1): string
    {
        return <<<HTML
            <div class="card"><dl class="card-body">
                <dt>Format</dt><dd>CSV</dd><dt>Release</dt><dd>Whatever</dd>
                <dt>MD5SUM</dt><dd class="small">a547aa29f27b25714b49b6786fa9ad82</dd>
                <dt>SHA1SUM</dt><dd class="small">254ef60b261cb4fb613362058b388a480d2a6b92</dd>
            </dl><a href='https://download.db-ip.com/free/dbip-city-lite-{$release}.csv.gz'>CSV</a></div>
            <div class="card"><dl class="card-body">
                <dt>Format</dt>
                <dd>MMDB</dd>
                <dt>MD5SUM</dt>
                <dd class="small">11b738040abb45cf462e40d7d266cbe7</dd>
                <dt>SHA1SUM</dt>
                <dd class="small">{$sha1}</dd>
            </dl><div><a href='https://download.db-ip.com/free/dbip-city-lite-{$release}.mmdb.gz' class='btn'>MMDB</a></div></div>
            HTML;
    }

    public static function test_the_download_page_parser_reads_each_mmdb_release()
    {
        $sha1 = str_repeat('ab', 20);
        static::__assert_equals(['2026-11' => $sha1], Geo_Ip_Service::parse_published_releases(static::__page('2026-11', $sha1)));
        static::__assert_equals([], Geo_Ip_Service::parse_published_releases('<html>nothing here</html>'));
        static::__assert_equals(
            [],
            Geo_Ip_Service::parse_published_releases(str_replace('SHA1SUM', 'SHA256', static::__page('2026-11', $sha1))),
            'an MMDB card without a SHA1 is not a release'
        );
    }

    public static function test_verification_accepts_the_expected_release()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-11'));

        Geo_Ip_Service::verify_candidate($file, '2026-11', sha1_file($file));
        static::__pass();
    }

    public static function test_verification_refuses_a_wrong_checksum()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate($file, '2026-11', str_repeat('0', 40)), 'SHA1');
    }

    public static function test_verification_refuses_a_wrong_type()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), 'GeoLite2-City', static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate($file, '2026-11', sha1_file($file)), "type 'GeoLite2-City'");
    }

    public static function test_verification_refuses_a_build_outside_the_release_month()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-09'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate($file, '2026-11', sha1_file($file)), 'outside its 2026-11 release month');
    }

    public static function test_verification_refuses_a_failed_sanity_lookup()
    {
        static::__fresh();
        $file = static::__dir() . '/candidate.mmdb';
        Geo_Ip_Mmdb_Fixture::write($file, static::__networks('FR'), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::verify_candidate($file, '2026-11', sha1_file($file)), Geo_Ip_Service::SANITY_IP);
    }

    public static function test_install_swaps_a_verified_release_into_place()
    {
        $target = static::__fresh();
        $gz = static::__dir() . '/release.mmdb.gz';
        $sha1 = Geo_Ip_Mmdb_Fixture::write_gz($gz, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-11'));

        Geo_Ip_Service::install_from_gz($gz, '2026-11', $sha1);

        static::__assert_equals($sha1, sha1_file($target), 'the installed file is the unpacked release');
        static::__assert_equals([], static::__leftovers(), 'no staged file is left behind');
        static::__assert_equals('Mountain View', Rsx_Geo_Ip::lookup('8.8.8.8')['city']);
    }

    public static function test_a_refused_install_leaves_the_installed_file_untouched()
    {
        $target = static::__fresh();
        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-10'));
        $before = sha1_file($target);

        $gz = static::__dir() . '/release.mmdb.gz';
        Geo_Ip_Mmdb_Fixture::write_gz($gz, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch('2026-11'));

        static::__assert_throws(Exception::class, fn () => Geo_Ip_Service::install_from_gz($gz, '2026-11', str_repeat('0', 40)), 'SHA1');
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
        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, time());
        // A page that would fail the run if it were read.
        Geo_Ip_Service::_set_download_page_for_tests('<html></html>');

        $run = Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_contains('Current', (string) $run->summary());
    }

    public static function test_update_ends_when_the_installed_release_is_the_latest_published()
    {
        $target = static::__fresh();
        $previous = gmdate('Y-m', strtotime(gmdate('Y-m-01') . ' -1 month'));
        Geo_Ip_Mmdb_Fixture::write($target, static::__networks(), Geo_Ip_Service::DATABASE_TYPE, static::__epoch($previous));
        Geo_Ip_Service::_set_download_page_for_tests(static::__page($previous, sha1_file($target)));

        $run = Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_contains('is not published yet', (string) $run->summary());
    }

    public static function test_update_fails_when_the_page_lists_no_recent_release()
    {
        static::__fresh();
        Geo_Ip_Service::_set_download_page_for_tests(static::__page('2019-01', str_repeat('ab', 20)));

        static::__assert_throws(
            Exception::class,
            fn () => Task::internal(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD),
            'lists no MMDB release'
        );
    }
}
