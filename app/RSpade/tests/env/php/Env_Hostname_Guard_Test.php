<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use App\RSpade\Core\Env\Rsx_Env_Hostname_Guard;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit coverage for Rsx_Env_Hostname_Guard (the dev-mode .env hostname tripwire).
 *
 * The guard declares the APP_URL host and, when PORTAL_URL names a host of its own,
 * the portal's host; a request passes when it matches ANY of them, EXACTLY (no
 * sub-host suffix rule, no port). The guard's per-request check() bails immediately under CLI -
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
        static::__assert_equals([['var' => 'APP_URL', 'host' => 'other.dev.hanson.xyz']], $mismatch['declared']);
        static::__assert_equals('app.dev.hanson.xyz', $mismatch['request_host']);

        $message = Rsx_Env_Hostname_Guard::mismatch_message($mismatch);
        static::__assert_contains('"app.dev.hanson.xyz" does not match APP_URL host "other.dev.hanson.xyz"', $message);
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
        static::__assert_equals('APP_URL', $mismatch['declared'][0]['var']);

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
        static::__assert_equals('APP_URL', $mismatch['declared'][0]['var']);
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

    // -------------------------------------------------------------------------
    // PORTAL_URL: a portal on a host of its own is a second declared host, and a
    // request matching EITHER passes.
    // -------------------------------------------------------------------------

    public static function test_a_separate_portal_host_is_a_second_declared_host()
    {
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://app.dev.hanson.xyz',
            'PORTAL_URL' => 'https://Portal.dev.hanson.xyz:8443/x',
        ]);

        static::__assert_equals([
            ['var' => 'APP_URL', 'host' => 'app.dev.hanson.xyz'],
            ['var' => 'PORTAL_URL', 'host' => 'portal.dev.hanson.xyz'],
        ], $declared);

        static::__assert_null(Rsx_Env_Hostname_Guard::find_mismatch('app.dev.hanson.xyz', $declared), 'the application host passes');
        static::__assert_null(Rsx_Env_Hostname_Guard::find_mismatch('portal.dev.hanson.xyz', $declared), 'the portal host passes');

        $mismatch = Rsx_Env_Hostname_Guard::find_mismatch('third.dev.hanson.xyz', $declared);
        static::__assert_not_null($mismatch, 'a third host is refused');
        static::__assert_equals('third.dev.hanson.xyz', $mismatch['request_host']);

        $message = Rsx_Env_Hostname_Guard::mismatch_message($mismatch);
        static::__assert_contains('matches neither APP_URL host "app.dev.hanson.xyz" nor PORTAL_URL host "portal.dev.hanson.xyz"', $message);
        static::__assert_contains('PORTAL_URL', $message);
    }

    public static function test_a_same_host_or_blank_portal_url_declares_nothing_more()
    {
        static::__assert_count(1, Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://app.dev.hanson.xyz',
            'PORTAL_URL' => 'https://APP.dev.hanson.xyz/clients',
        ]), 'a prefix on the application host adds no host');

        static::__assert_count(1, Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'https://app.dev.hanson.xyz',
            'PORTAL_URL' => '',
        ]), 'a blank PORTAL_URL is APP_URL + /_portal');

        static::__assert_count(0, Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => '',
            'PORTAL_URL' => 'https://portal.dev.hanson.xyz',
        ]), 'nothing is declared until APP_URL is');
    }

    public static function test_a_hostless_portal_url_fails_loud()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_Env_Hostname_Guard::build_declared(['APP_URL' => 'https://app.dev.hanson.xyz', 'PORTAL_URL' => '/clients']);
            },
            'PORTAL_URL'
        );
    }

    public static function test_the_portal_host_is_compared_without_its_port()
    {
        $declared = Rsx_Env_Hostname_Guard::build_declared([
            'APP_URL' => 'http://localhost:8080',
            'PORTAL_URL' => 'http://portal.local:8080',
        ]);

        static::__assert_null(
            Rsx_Env_Hostname_Guard::find_mismatch(Rsx_Env_Hostname_Guard::normalize_request_host('Portal.Local:9000'), $declared),
            'a port never decides a host'
        );
    }

    public static function test_nothing_declared_is_never_a_mismatch()
    {
        static::__assert_null(Rsx_Env_Hostname_Guard::find_mismatch('anything.example', []));
    }

    // -------------------------------------------------------------------------
    // The production host rule (Rsx::get_hostname()): the APP_URL host, a sub-host
    // of it, or the portal's own host.
    // -------------------------------------------------------------------------

    public static function test_production_serves_the_app_host_its_sub_hosts_and_the_portal_host()
    {
        static::__assert_true(Rsx::host_is_served('myapp.com', 'myapp.com', 'myapp.com'));
        static::__assert_true(Rsx::host_is_served('tenant.myapp.com', 'myapp.com', 'myapp.com'));
        static::__assert_true(Rsx::host_is_served('clients-of-myapp.com', 'myapp.com', 'clients-of-myapp.com'), 'a portal host that is not a sub-host');

        static::__assert_false(Rsx::host_is_served('other.com', 'myapp.com', 'clients-of-myapp.com'));
        static::__assert_false(Rsx::host_is_served('xmyapp.com', 'myapp.com', 'myapp.com'), 'a suffix that is not a label boundary');
        static::__assert_false(Rsx::host_is_served('sub.clients-of-myapp.com', 'myapp.com', 'clients-of-myapp.com'), 'the portal host is exact');
        static::__assert_false(Rsx::host_is_served('other.com', 'myapp.com', ''), 'an empty portal host matches nothing');
    }
}
