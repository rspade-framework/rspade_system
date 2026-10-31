<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Time::get_user_timezone() - the zone a request displays in, resolved per request:
 * a signed-in staff user's EXPLICIT zone -> the browser's (the rsx_tz cookie) -> the site's
 * -> the server's -> UTC, each tier skipped when absent or not a zone.
 *
 * The baseline user (login identity 1, site 1) stands in for "a signed-in staff user". Its
 * zone and its site's are set on the in-memory records Session resolved, never saved, so
 * nothing here outlives the method (the harness resets Session, and the cookie seam, at
 * every method boundary); the cookie is the test seam
 * Rsx_Time::$browser_timezone_cookie_for_tests (a command has no browser of its own).
 */
class Rsx_Timezone_Resolution_Test extends Rsx_Test_Abstract
{
    const EXPLICIT = 'Europe/Berlin';
    const BROWSER = 'Asia/Tokyo';
    const SITE = 'America/Denver';
    const SERVER = 'Australia/Sydney';

    private static $server_timezone = null;

    public static function setup(): void
    {
        static::$server_timezone = config('rsx.datetime.server_timezone');
    }

    public static function teardown(): void
    {
        config(['rsx.datetime.server_timezone' => static::$server_timezone]);
        static::__reset_session();
    }

    /**
     * Each method's starting point: the baseline user signed in, automatic, on a site in
     * SITE, a server in SERVER, no cookie.
     */
    private static function __fresh(): void
    {
        config(['rsx.datetime.server_timezone' => self::SERVER]);
        static::__acting_as_user(1);
        Session::get_login_user()->timezone = null;
        Session::get_site()->timezone = self::SITE;
        Rsx_Time::$browser_timezone_cookie_for_tests = null;
        Rsx_Time::_clear_user_timezone_cache();
    }

    private static function __resolve(): string
    {
        Rsx_Time::_clear_user_timezone_cache();

        return Rsx_Time::get_user_timezone();
    }

    // -------------------------------------------------------------------------
    // The order
    // -------------------------------------------------------------------------

    public static function test_an_explicit_user_zone_beats_the_browser()
    {
        static::__fresh();
        Session::get_login_user()->timezone = self::EXPLICIT;
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;

        static::__assert_equals(self::EXPLICIT, static::__resolve());
    }

    public static function test_an_automatic_user_follows_the_browser()
    {
        static::__fresh();
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;

        static::__assert_equals(self::BROWSER, static::__resolve());
    }

    public static function test_the_browser_beats_the_site_and_the_site_beats_the_server()
    {
        static::__fresh();
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;
        static::__assert_equals(self::BROWSER, static::__resolve(), 'the cookie beats the site');

        Rsx_Time::$browser_timezone_cookie_for_tests = null;
        static::__assert_equals(self::SITE, static::__resolve(), 'no cookie: the site');

        Session::get_site()->timezone = null;
        static::__assert_equals(self::SERVER, static::__resolve(), 'no site zone: the server');

        config(['rsx.datetime.server_timezone' => '']);
        static::__assert_equals('UTC', static::__resolve(), 'no server zone: UTC');
    }

    // -------------------------------------------------------------------------
    // An invalid value at each tier falls through
    // -------------------------------------------------------------------------

    public static function test_an_invalid_value_at_each_tier_falls_through()
    {
        static::__fresh();
        Session::get_login_user()->timezone = 'Mars/Olympus_Mons';
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;
        static::__assert_equals(self::BROWSER, static::__resolve(), 'a stored zone that is not one is skipped');

        Rsx_Time::$browser_timezone_cookie_for_tests = 'not-a-zone';
        static::__assert_equals(self::SITE, static::__resolve(), 'a cookie that is not a zone is skipped');

        Rsx_Time::$browser_timezone_cookie_for_tests = Rsx_Time::TIMEZONE_COOKIE_ERROR;
        static::__assert_equals(self::SITE, static::__resolve(), 'the error value is not a zone');

        Session::get_site()->timezone = 'Atlantis/Capital';
        static::__assert_equals(self::SERVER, static::__resolve(), 'a site zone that is not one is skipped');
    }

    public static function test_an_older_browser_spelling_resolves_to_its_equivalent()
    {
        static::__fresh();
        // Chromium reports ICU's Asia/Calcutta; PHP's zone database may know only the
        // equivalent Asia/Kolkata, which is what the request then renders in.
        Rsx_Time::$browser_timezone_cookie_for_tests = 'Asia/Calcutta';

        static::__assert_equals('Asia/Kolkata', static::__resolve());
    }

    // -------------------------------------------------------------------------
    // Realms and channels
    // -------------------------------------------------------------------------

    public static function test_a_portal_request_starts_at_the_browser()
    {
        static::__fresh();
        Session::get_login_user()->timezone = self::EXPLICIT;
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;
        \App\RSpade\Core\Portal\Portal_Session::set_site_id(1);
        Rsx_Portal::set_portal_request(true);

        try {
            static::__assert_equals(self::BROWSER, static::__resolve(), 'a staff sign-in lends the portal no zone');
            static::__assert_null(Rsx_Time::explicit_user_timezone());
        } finally {
            Rsx_Portal::set_portal_request(false);
        }
    }

    public static function test_an_api_request_never_reads_a_cookie()
    {
        static::__fresh();
        static::__reset_session();
        Session::_set_api_identity(1, 1, 1);
        config(['rsx.datetime.server_timezone' => self::SERVER]);
        Session::get_login_user()->timezone = null;
        Session::get_site()->timezone = self::SITE;
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;

        static::__assert_null(Rsx_Time::browser_timezone(), 'a client-sent cookie is never the key holder\'s zone');
        static::__assert_equals(self::SITE, static::__resolve(), 'an automatic key holder renders in the site zone');

        Session::get_login_user()->timezone = self::EXPLICIT;
        static::__assert_equals(self::EXPLICIT, static::__resolve(), 'an explicit key holder in their own');
    }

    // -------------------------------------------------------------------------
    // The cache
    // -------------------------------------------------------------------------

    public static function test_the_cache_follows_the_cookie_and_the_site()
    {
        static::__fresh();
        Rsx_Time::$browser_timezone_cookie_for_tests = self::BROWSER;
        static::__assert_equals(self::BROWSER, static::__resolve());

        // No clear: the cached answer is keyed on the cookie.
        Rsx_Time::$browser_timezone_cookie_for_tests = 'Europe/Lisbon';
        static::__assert_equals('Europe/Lisbon', Rsx_Time::get_user_timezone(), 'a different cookie is a different answer');

        Rsx_Time::$browser_timezone_cookie_for_tests = null;
        static::__assert_equals(self::SITE, Rsx_Time::get_user_timezone());

        // A site change (the site id is part of the key) is resolved afresh.
        Session::set_site_id(0);
        $site_zero = Rsx_Time::get_user_timezone();
        static::__assert_true(Rsx_Time::is_timezone($site_zero), 'another site resolves its own zone: ' . $site_zero);
    }
}
