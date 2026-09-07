<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use App\RSpade\Core\Env\Rsx_Env_Hostname_Guard;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit coverage for Rsx_Env_Hostname_Guard (the dev-mode .env hostname tripwire).
 *
 * The guard now consults a SINGLE declared host - the APP_URL host, matched
 * EXACTLY (the old REALTIME_PUBLIC_URL and RSX_HOSTNAME entries, and the suffix
 * rule, are gone). The guard's per-request check() bails immediately under CLI -
 * and the PHP test runner IS CLI - so the web path can never be exercised here.
 * Instead the guard is deliberately factored into a pure core (build_declared +
 * find_mismatch + is_loopback_host + normalize_request_host) that takes plain
 * inputs and touches neither env nor $_SERVER; these tests drive that core.
 *
 * Pure logic, no DB.
 */
class Env_Hostname_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // find_mismatch - exact match passes
    // -------------------------------------------------------------------------

    public static function test_exact_match_passes()
    {
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://app.dev.hanson.xyz',
        ]);

        static::__assert_null(
            Rsx_Env_Hostname_Guard::find_mismatch('app.dev.hanson.xyz', $declared),
            'matching request host produces no mismatch'
        );
    }

    // -------------------------------------------------------------------------
    // find_mismatch - a mismatch is caught
    // -------------------------------------------------------------------------

    public static function test_app_url_mismatch_caught()
    {
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://other.dev.hanson.xyz',
        ]);

        $mismatch = Rsx_Env_Hostname_Guard::find_mismatch('app.dev.hanson.xyz', $declared);

        static::__assert_not_null($mismatch, 'wrong APP_URL host is caught');
        static::__assert_equals('APP_URL', $mismatch['var']);
        static::__assert_equals('other.dev.hanson.xyz', $mismatch['env_host']);
        static::__assert_equals('app.dev.hanson.xyz', $mismatch['request_host']);
    }

    // -------------------------------------------------------------------------
    // The old RSX_HOSTNAME suffix rule NO LONGER applies: a sub-host of the
    // APP_URL host does NOT satisfy it (match is exact).
    // -------------------------------------------------------------------------

    public static function test_suffix_no_longer_applies()
    {
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://hanson.xyz',
        ]);

        // Under the old RSX_HOSTNAME suffix rule this PASSED; now it is a mismatch.
        $mismatch = Rsx_Env_Hostname_Guard::find_mismatch('app.dev.hanson.xyz', $declared);

        static::__assert_not_null($mismatch, 'sub-host of APP_URL host no longer matches (exact only)');
        static::__assert_equals('APP_URL', $mismatch['var']);

        // The exact host still matches.
        static::__assert_null(
            Rsx_Env_Hostname_Guard::find_mismatch('hanson.xyz', $declared),
            'exact APP_URL host matches'
        );
    }

    // -------------------------------------------------------------------------
    // A loopback-VALUED APP_URL is NOT skipped now: APP_URL=https://localhost
    // browsed under a real host is exactly the misconfiguration the guard catches.
    // -------------------------------------------------------------------------

    public static function test_loopback_valued_app_url_not_skipped()
    {
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://localhost',
        ]);

        static::__assert_count(1, $declared, 'a loopback-valued APP_URL still declares an entry');
        static::__assert_equals('localhost', $declared[0]['host']);

        $mismatch = Rsx_Env_Hostname_Guard::find_mismatch('app.dev.hanson.xyz', $declared);
        static::__assert_not_null($mismatch, 'real request host vs APP_URL=localhost is a mismatch');
        static::__assert_equals('APP_URL', $mismatch['var']);
    }

    // -------------------------------------------------------------------------
    // Empty / absent APP_URL declares nothing.
    // -------------------------------------------------------------------------

    public static function test_empty_app_url_yields_no_entries()
    {
        static::__assert_count(0, Rsx_Env_Hostname_Guard::build_declared(['APP_URL' => '']));
        static::__assert_count(0, Rsx_Env_Hostname_Guard::build_declared([]));
    }

    // -------------------------------------------------------------------------
    // A non-empty but hostless APP_URL fails loud.
    // -------------------------------------------------------------------------

    public static function test_malformed_app_url_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_Env_Hostname_Guard::build_declared(['APP_URL' => '/ws']);
            },
            'APP_URL'
        );
    }

    // -------------------------------------------------------------------------
    // loopback request hosts are recognized (is_loopback_host) - backs the
    // request-exempt path in check() (proven E2E by curl; check() bails on CLI).
    // -------------------------------------------------------------------------

    public static function test_loopback_hosts_recognized()
    {
        static::__assert_true(Rsx_Env_Hostname_Guard::is_loopback_host('localhost'));
        static::__assert_true(Rsx_Env_Hostname_Guard::is_loopback_host('LOCALHOST'));
        static::__assert_true(Rsx_Env_Hostname_Guard::is_loopback_host('127.0.0.1'));
        static::__assert_true(Rsx_Env_Hostname_Guard::is_loopback_host('127.5.5.5'));
        static::__assert_true(Rsx_Env_Hostname_Guard::is_loopback_host('::1'));

        static::__assert_false(Rsx_Env_Hostname_Guard::is_loopback_host('app.dev.hanson.xyz'));
        static::__assert_false(Rsx_Env_Hostname_Guard::is_loopback_host('128.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // request-host normalization: port stripping + case + IPv6 bracket form
    // -------------------------------------------------------------------------

    public static function test_normalize_strips_port_and_lowercases()
    {
        static::__assert_equals('app.dev.hanson.xyz', Rsx_Env_Hostname_Guard::normalize_request_host('APP.dev.hanson.xyz:8080'));
        static::__assert_equals('app.dev.hanson.xyz', Rsx_Env_Hostname_Guard::normalize_request_host('app.dev.hanson.xyz'));
        static::__assert_equals('::1', Rsx_Env_Hostname_Guard::normalize_request_host('[::1]:6200'));
        static::__assert_equals('::1', Rsx_Env_Hostname_Guard::normalize_request_host('[::1]'));
    }

    public static function test_case_insensitive_match()
    {
        // Declared host lowercased by build_declared; request host lowercased by
        // normalize; the two meet case-insensitively.
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://APP.DEV.HANSON.XYZ',
        ]);
        $request_host = Rsx_Env_Hostname_Guard::normalize_request_host('App.Dev.Hanson.Xyz:443');

        static::__assert_equals('app.dev.hanson.xyz', $declared[0]['host']);
        static::__assert_null(
            Rsx_Env_Hostname_Guard::find_mismatch($request_host, $declared),
            'case differences do not cause a mismatch'
        );
    }
}
