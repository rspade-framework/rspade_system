<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx_absolute_url() DURING A REQUEST - where the origin of a URL that leaves the browser
 * comes from. The no-request (CLI / task) answer is mail's Absolute_Url_Test.
 *
 * A password-reset or invitation link is mailed to somebody else, so the Host header of
 * the request that built it must never be able to reach it: a poisoned host delivers the
 * victim's token to the attacker's server. The request authority is therefore admitted
 * only when the request host is SERVED (the APP_URL host, a sub-host of it, the
 * PORTAL_URL host); everything else gets APP_URL's configured origin.
 *
 * The function reads the AMBIENT request, so each case rebinds app('request') to a
 * synthetic one and restores the console's own request afterwards.
 *
 * Pure request logic, no DB.
 */
class Absolute_Url_Request_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static $saved_request;

    private static $saved_portal_url;

    public static function setup()
    {
        self::$saved_request = app('request');
        self::$saved_portal_url = config('rsx.portal.url');
    }

    public static function teardown()
    {
        app()->instance('request', self::$saved_request);
        config(['rsx.portal.url' => self::$saved_portal_url]);
    }

    /**
     * This box's APP_URL host.
     */
    private static function __app_host(): string
    {
        return strtolower((string) parse_url(Rsx::app_url_origin(), PHP_URL_HOST));
    }

    /**
     * rsx_absolute_url($path) as built during a request to $url, with optional raw headers.
     */
    private static function __absolute_during(string $url, string $path, array $server = []): string
    {
        app()->instance('request', Request::create($url, 'GET', [], [], [], $server));

        return rsx_absolute_url($path);
    }

    /**
     * AURL-01 - a served host keeps the authority the browser used, port and scheme included.
     */
    public static function test_a_served_host_keeps_the_browsed_authority()
    {
        $app_host = static::__app_host();

        static::__assert_equals(
            "https://{$app_host}/reset/abc",
            static::__absolute_during("https://{$app_host}/login", '/reset/abc'),
            'the APP_URL host'
        );

        static::__assert_equals(
            "https://tenant.{$app_host}/reset/abc",
            static::__absolute_during("https://tenant.{$app_host}/login", '/reset/abc'),
            'a sub-host of the APP_URL host is served'
        );

        static::__assert_equals(
            "http://{$app_host}:8080/reset/abc",
            static::__absolute_during("http://{$app_host}:8080/login", 'reset/abc'),
            'a non-default port and the http scheme travel with a served host; a bare path gains its slash'
        );
    }

    /**
     * AURL-02 - an unserved Host header never reaches the URL, however it is spelled.
     */
    public static function test_an_unserved_host_gets_the_app_url_origin()
    {
        $expected = Rsx::app_url_origin() . '/_portal/password/reset/abc';

        foreach (['evil.example', '127.attacker.example', 'x' . static::__app_host()] as $host) {
            static::__assert_equals(
                $expected,
                static::__absolute_during("https://{$host}/_portal/password/reset", '/_portal/password/reset/abc'),
                "Host: {$host} is not served"
            );
        }
    }

    /**
     * AURL-03 - loopback is a testing channel, not a served host: unless APP_URL names
     * it, a loopback request builds its links on APP_URL.
     */
    public static function test_a_loopback_host_is_not_served_unless_app_url_names_it()
    {
        if (in_array(static::__app_host(), ['localhost', '127.0.0.1'], true)) {
            static::__skip('APP_URL names a loopback host on this box, so loopback IS its served host');
        }

        foreach (['http://localhost/login', 'http://127.0.0.1/login', 'http://localhost:8080/login'] as $url) {
            static::__assert_equals(
                Rsx::app_url_origin() . '/invite/abc',
                static::__absolute_during($url, '/invite/abc'),
                "{$url} builds on APP_URL"
            );
        }
    }

    /**
     * AURL-04 - a portal on a host of its own is served; an absolute URL passes through.
     */
    public static function test_the_portal_host_is_served_and_absolute_urls_pass_through()
    {
        config(['rsx.portal.url' => 'https://portal-host.example.test/']);

        static::__assert_equals(
            'https://portal-host.example.test/register/abc',
            static::__absolute_during('https://portal-host.example.test/request-access', '/register/abc'),
            'the PORTAL_URL host is served'
        );

        static::__assert_equals(
            'https://elsewhere.example/x',
            static::__absolute_during('https://evil.example/', 'https://elsewhere.example/x'),
            'an already-absolute URL is returned unchanged'
        );
    }
}
