<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\InitialUser\Php;

use App\RSpade\Core\Env\Rsx_First_User_Setup;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The first-user screen's double-submit token (Rsx_First_User_Setup::token_for()).
 *
 * The screen renders on EVERY request while login_users is empty - the page and the
 * favicon request a browser fires beside it - and it used to mint a fresh token per
 * render, so the favicon response rotated the cookie underneath the open form and the
 * submit failed with "Your session expired" (a downstream field report, 2026-09-14).
 * token_for() now answers with the token the browser already holds when it is
 * well-formed. Pure seam, no request, no database.
 */
class First_User_Setup_Token_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const COOKIE = 'rsx_first_user';

    public static function test_a_well_formed_cookie_is_reused()
    {
        $held = str_repeat('ab', 16);

        static::__assert_equals($held, Rsx_First_User_Setup::token_for([self::COOKIE => $held]), 'the token the browser holds is the token the form gets');
    }

    public static function test_parallel_renders_agree_on_one_token()
    {
        $first = Rsx_First_User_Setup::token_for([]);
        $second = Rsx_First_User_Setup::token_for([self::COOKIE => $first]);

        static::__assert_equals($first, $second, 'a second render does not rotate the token');
    }

    public static function test_no_cookie_mints_a_32_hex_token()
    {
        $token = Rsx_First_User_Setup::token_for([]);

        static::__assert_true(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'minted token is 32 lowercase hex: ' . $token);
    }

    public static function test_two_mints_differ()
    {
        static::__assert_true(Rsx_First_User_Setup::token_for([]) !== Rsx_First_User_Setup::token_for([]), 'minting is random');
    }

    public static function test_malformed_cookies_are_replaced_not_reused()
    {
        foreach (['', 'short', str_repeat('AB', 16), str_repeat('zz', 16), str_repeat('ab', 16) . 'a', "\n" . str_repeat('ab', 16)] as $bad) {
            $token = Rsx_First_User_Setup::token_for([self::COOKIE => $bad]);

            static::__assert_true($token !== $bad, 'a malformed cookie is never accepted as the token: ' . json_encode($bad));
            static::__assert_true(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'and a fresh well-formed one replaces it');
        }
    }

    public static function test_only_this_screens_cookie_is_consulted()
    {
        $held = str_repeat('cd', 16);

        static::__assert_true(Rsx_First_User_Setup::token_for(['rsx_first_run' => $held]) !== $held, 'the APP_URL screen\'s cookie is not this screen\'s token');
    }
}
