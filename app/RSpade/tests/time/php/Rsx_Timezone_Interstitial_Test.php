<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Time::ensure_timezone() - the first-visit interstitial a browser's first page view gets
 * so it can be rendered in its own zone, and every request it must leave alone.
 *
 * PINNED: false for anything but a GET document navigation wanting HTML (Ajax, the external
 * API, a POST, a non-HTML Accept, a sub-resource), for a user with an explicit zone, for the
 * cookie's error value, and for the reload marker with still no cookie (a browser refusing
 * cookies renders without one - no loop); true with a valid cookie; the interstitial
 * otherwise, which writes the cookie with the same spellings Rsx_Timezone_Cookie.js uses.
 *
 * The browser is the test seam Rsx_Time::$browser_timezone_cookie_for_tests ('' = a browser
 * with no cookie; left null, the command is no browser and is never asked); a command's own
 * request is classified PAGE, and the Request handed in carries the headers under test.
 */
class Rsx_Timezone_Interstitial_Test extends Rsx_Test_Abstract
{
    private static function __page_request(string $method = 'GET', string $accept = 'text/html,application/xhtml+xml', string $uri = '/dashboard', ?string $dest = null): Request
    {
        $server = ['HTTP_ACCEPT' => $accept];
        if ($dest !== null) {
            $server['HTTP_SEC_FETCH_DEST'] = $dest;
        }

        return Request::create($uri, $method, [], [], [], $server);
    }

    /**
     * Signed out, in a browser that has sent no rsx_tz cookie yet (the seam's '').
     */
    private static function __signed_out(): void
    {
        static::__reset_session();
        Rsx_Time::$browser_timezone_cookie_for_tests = '';
        Rsx_Time::_clear_user_timezone_cache();
    }

    public static function test_a_first_visit_gets_the_interstitial()
    {
        static::__signed_out();

        $answer = Rsx_Time::ensure_timezone(static::__page_request('GET', 'text/html', '/dashboard?tab=2'));

        static::__assert_true($answer instanceof Response, 'no cookie: the interstitial');
        static::__assert_equals(200, $answer->getStatusCode());
        static::__assert_contains('no-store', (string) $answer->headers->get('Cache-Control'));

        $html = (string) $answer->getContent();
        static::__assert_contains('nonce="' . htmlspecialchars(Rsx_Csp::nonce(), ENT_QUOTES) . '"', $html, 'the script carries this request\'s CSP nonce');
        static::__assert_contains('"name":"' . Rsx_Time::TIMEZONE_COOKIE . '"', $html);
        static::__assert_contains('"error":"' . Rsx_Time::TIMEZONE_COOKIE_ERROR . '"', $html);
        // JSON_HEX_* escapes the URL's own & so nothing in it can close the script.
        static::__assert_contains('"url":"/dashboard?tab=2\\u0026_rsx_tz=1"', $html, 'reloads the same URL, marked');
        static::__assert_contains('http-equiv="refresh" content="0;url=/dashboard?tab=2&amp;_rsx_tz=1"', $html, 'and a client with no script follows the noscript refresh');
    }

    public static function test_a_valid_cookie_answers_true()
    {
        static::__signed_out();
        Rsx_Time::$browser_timezone_cookie_for_tests = 'America/Chicago';

        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request()) === true);
    }

    public static function test_the_error_value_and_the_marker_render_without_a_cookie()
    {
        static::__signed_out();

        Rsx_Time::$browser_timezone_cookie_for_tests = Rsx_Time::TIMEZONE_COOKIE_ERROR;
        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request()) === false, 'the browser answered: no zone');

        Rsx_Time::$browser_timezone_cookie_for_tests = '';
        static::__assert_true(
            Rsx_Time::ensure_timezone(static::__page_request('GET', 'text/html', '/dashboard?_rsx_tz=1')) === false,
            'the reload arrived with no cookie: cookies are refused, so no second interstitial'
        );
    }

    public static function test_a_command_is_never_asked()
    {
        static::__reset_session();
        Rsx_Time::$browser_timezone_cookie_for_tests = null;

        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request()) === false, 'no browser to send the page to');
    }

    public static function test_an_explicit_zone_user_is_never_asked()
    {
        static::__acting_as_user(1);
        Rsx_Time::$browser_timezone_cookie_for_tests = '';
        Session::get_login_user()->timezone = 'Europe/Berlin';
        Rsx_Time::_clear_user_timezone_cache();

        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request()) === false);
    }

    public static function test_only_a_get_document_navigation_wanting_html_is_concerned()
    {
        static::__signed_out();

        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request('POST')) === false, 'a POST would be resubmitted by the reload');
        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request('GET', '*/*')) === false, 'curl, a download, a JSON route, a webhook');
        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request('GET', 'application/json')) === false);
        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request('GET', 'text/html', '/dashboard', 'image')) === false, 'a sub-resource');
        static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request('GET', 'text/html', '/dashboard', 'iframe')) === false);
    }

    public static function test_ajax_and_the_api_are_never_concerned()
    {
        static::__signed_out();

        Rsx_Request_Channel::classify(Request::create('/_ajax/_batch', 'POST'));
        try {
            static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request()) === false, 'an Ajax call');
        } finally {
            Rsx_Request_Channel::reset();
        }

        Session::_set_api_identity(1, 1, 1);
        try {
            static::__assert_true(Rsx_Time::ensure_timezone(static::__page_request()) === false, 'an external API request');
        } finally {
            Session::_reset_api_identity();
        }
    }

    /**
     * The browser half writes the cookie with the same spellings as the server half (the
     * interstitial and the reader): one rename on one side would silently strand the other.
     */
    public static function test_the_javascript_spellings_match_the_php_ones()
    {
        $js = (string) file_get_contents(base_path('app/RSpade/Core/Js/Rsx_Timezone_Cookie.js'));

        foreach ([
            'COOKIE_NAME' => "'" . Rsx_Time::TIMEZONE_COOKIE . "'",
            'ERROR_VALUE' => "'" . Rsx_Time::TIMEZONE_COOKIE_ERROR . "'",
            'MAX_AGE' => (string) Rsx_Time::TIMEZONE_COOKIE_MAX_AGE,
            'ERROR_MAX_AGE' => (string) Rsx_Time::TIMEZONE_COOKIE_ERROR_MAX_AGE,
            'RELOAD_MARKER' => "'" . Rsx_Time::TIMEZONE_RELOAD_MARKER . "'",
        ] as $name => $value) {
            static::__assert_contains("static {$name} = {$value};", $js, "Rsx_Timezone_Cookie.{$name}");
        }
    }
}
