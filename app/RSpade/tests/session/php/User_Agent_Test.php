<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Session\User_Agent;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for User_Agent parser.
 *
 * All logic is pure string parsing - no database or session state involved.
 * Disable per-test transactions entirely (no DB access at all).
 */
class User_Agent_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // parse() - structure
    // -------------------------------------------------------------------------

    public static function test_parse_returns_required_keys()
    {
        $result = User_Agent::parse('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0');
        static::__assert_array_has_key('browser', $result);
        static::__assert_array_has_key('os', $result);
        static::__assert_array_has_key('device', $result);
        static::__assert_array_has_key('summary', $result);
    }

    public static function test_parse_null_returns_unknown()
    {
        $result = User_Agent::parse(null);
        static::__assert_equals('Unknown', $result['browser']);
        static::__assert_equals('Unknown', $result['os']);
        static::__assert_equals('Unknown', $result['device']);
        static::__assert_equals('Unknown', $result['summary']);
    }

    public static function test_parse_empty_string_returns_unknown()
    {
        $result = User_Agent::parse('');
        static::__assert_equals('Unknown', $result['summary']);
    }

    // -------------------------------------------------------------------------
    // Browser detection
    // -------------------------------------------------------------------------

    public static function test_detects_chrome()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        static::__assert_equals('Chrome', User_Agent::parse($ua)['browser']);
    }

    public static function test_detects_firefox()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/109.0';
        static::__assert_equals('Firefox', User_Agent::parse($ua)['browser']);
    }

    public static function test_detects_edge()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
        static::__assert_equals('Edge', User_Agent::parse($ua)['browser']);
    }

    public static function test_detects_safari_not_chrome()
    {
        // Real Safari UA - does NOT contain Chrome
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        static::__assert_equals('Safari', User_Agent::parse($ua)['browser']);
    }

    public static function test_chrome_not_detected_as_safari()
    {
        // Chrome contains "Safari" substring but should not resolve to Safari
        $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
        static::__assert_not_equals('Safari', User_Agent::parse($ua)['browser']);
    }

    // -------------------------------------------------------------------------
    // OS detection
    // -------------------------------------------------------------------------

    public static function test_detects_windows()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';
        static::__assert_equals('Windows', User_Agent::parse($ua)['os']);
    }

    public static function test_detects_macos()
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15';
        static::__assert_equals('macOS', User_Agent::parse($ua)['os']);
    }

    public static function test_detects_ios()
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
        static::__assert_equals('iOS', User_Agent::parse($ua)['os']);
    }

    public static function test_detects_android()
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Mobile Safari/537.36';
        static::__assert_equals('Android', User_Agent::parse($ua)['os']);
    }

    // -------------------------------------------------------------------------
    // Device detection
    // -------------------------------------------------------------------------

    public static function test_detects_desktop()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';
        static::__assert_equals('Desktop', User_Agent::parse($ua)['device']);
    }

    public static function test_detects_mobile_iphone()
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';
        static::__assert_equals('Mobile', User_Agent::parse($ua)['device']);
    }

    public static function test_detects_tablet_ipad()
    {
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
        static::__assert_equals('Tablet', User_Agent::parse($ua)['device']);
    }

    // -------------------------------------------------------------------------
    // summary format
    // -------------------------------------------------------------------------

    public static function test_summary_format_is_browser_on_os()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
        $result = User_Agent::parse($ua);
        $expected_summary = $result['browser'] . ' on ' . $result['os'];
        static::__assert_equals($expected_summary, $result['summary']);
    }

    // -------------------------------------------------------------------------
    // get_summary() convenience method
    // -------------------------------------------------------------------------

    public static function test_get_summary_matches_parse_summary()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/109.0';
        $from_parse = User_Agent::parse($ua)['summary'];
        $from_get_summary = User_Agent::get_summary($ua);
        static::__assert_equals($from_parse, $from_get_summary);
    }

    public static function test_get_summary_null_returns_unknown()
    {
        static::__assert_equals('Unknown', User_Agent::get_summary(null));
    }

    // -------------------------------------------------------------------------
    // is_automated()
    // -------------------------------------------------------------------------

    public static function test_is_automated_headless_chrome()
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0 Safari/537.36';
        static::__assert_true(User_Agent::is_automated($ua));
    }

    public static function test_is_automated_playwright()
    {
        $ua = 'Mozilla/5.0 Playwright/1.40 Chrome/120.0';
        static::__assert_true(User_Agent::is_automated($ua));
    }

    public static function test_is_automated_normal_chrome_is_not_automated()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36';
        static::__assert_false(User_Agent::is_automated($ua));
    }

    public static function test_is_automated_null_returns_false()
    {
        static::__assert_false(User_Agent::is_automated(null));
    }

    public static function test_is_automated_empty_returns_false()
    {
        static::__assert_false(User_Agent::is_automated(''));
    }
}
