<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Env\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The pre-boot APP_URL screen's double-submit token (system/bootstrap/rsx_first_run.php).
 *
 * The screen renders on EVERY request while APP_URL is blank - the page itself and the
 * favicon request a browser fires beside it - and it used to mint a fresh token per
 * render, so the favicon response rotated the cookie underneath the open form and the
 * submit failed with "Setup token mismatch" (a downstream field report, 2026-09-14).
 * rsx_first_run_token() now answers with the token the browser already holds when it is
 * well-formed. These tests pin that seam.
 *
 * The bootstrap file is required here; its main closure returns at once under the CLI
 * SAPI, which is what makes the pure functions at its top reachable from a test.
 */
class First_Run_Token_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const COOKIE = 'rsx_first_run';

    public static function setup()
    {
        require_once base_path('bootstrap/rsx_first_run.php');
    }

    public static function test_a_well_formed_cookie_is_reused()
    {
        $held = str_repeat('ab', 16);

        static::__assert_equals($held, rsx_first_run_token([self::COOKIE => $held], self::COOKIE), 'the token the browser holds is the token the form gets');
    }

    public static function test_parallel_renders_agree_on_one_token()
    {
        // The page render mints; the favicon render, carrying that cookie, must answer
        // with the same value - this is the defect.
        $first = rsx_first_run_token([], self::COOKIE);
        $second = rsx_first_run_token([self::COOKIE => $first], self::COOKIE);

        static::__assert_equals($first, $second, 'a second render does not rotate the token');
    }

    public static function test_no_cookie_mints_a_32_hex_token()
    {
        $token = rsx_first_run_token([], self::COOKIE);

        static::__assert_true(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'minted token is 32 lowercase hex: ' . $token);
    }

    public static function test_two_mints_differ()
    {
        static::__assert_true(rsx_first_run_token([], self::COOKIE) !== rsx_first_run_token([], self::COOKIE), 'minting is random');
    }

    public static function test_malformed_cookies_are_replaced_not_reused()
    {
        foreach (['', 'short', str_repeat('AB', 16), str_repeat('zz', 16), str_repeat('ab', 16) . 'a', "\n" . str_repeat('ab', 16)] as $bad) {
            $token = rsx_first_run_token([self::COOKIE => $bad], self::COOKIE);

            static::__assert_true($token !== $bad, 'a malformed cookie is never accepted as the token: ' . json_encode($bad));
            static::__assert_true(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'and a fresh well-formed one replaces it');
        }
    }

    public static function test_only_the_named_cookie_is_consulted()
    {
        $held = str_repeat('cd', 16);
        $token = rsx_first_run_token(['rsx_first_user' => $held], self::COOKIE);

        static::__assert_true($token !== $held, 'another screen\'s cookie is not this screen\'s token');
    }
}
