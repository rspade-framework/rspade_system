<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRedirect\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Login\Login_Notice;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Login_Notice: a declared code round-trips through the URL to {code, level, text}, an
 * undeclared code reads as nothing (no free text crosses the URL), {name} is the application's
 * name, and naming an undeclared code, or declaring a malformed one, fails loud.
 */
class Login_Notice_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static $notices = null;

    private static $name = null;

    public static function setup()
    {
        static::$notices = config('rsx.login_notices');
        static::$name = config('rsx.name');
        config([
            'rsx.name' => 'Northwind',
            'rsx.login_notices' => [
                'signed_out' => ['level' => 'notice', 'text' => 'You have signed out of {name}.'],
                'link_dead' => ['level' => 'error', 'text' => 'That link is dead.'],
                'malformed' => ['level' => 'shout', 'text' => 'Nope.'],
            ],
        ]);
    }

    public static function teardown()
    {
        config(['rsx.login_notices' => static::$notices, 'rsx.name' => static::$name]);
        app()->instance('request', Request::create('/'));
    }

    private static function __on(string $query): ?array
    {
        app()->instance('request', Request::create('/login' . $query));

        return Login_Notice::current();
    }

    /**
     * LR-NOTICE-01: params() carries a declared code, and current() answers it.
     */
    public static function test_a_declared_code_round_trips()
    {
        $query = '?' . http_build_query(Login_Notice::params('link_dead'));

        static::__assert_equals(['code' => 'link_dead', 'level' => 'error', 'text' => 'That link is dead.'], static::__on($query));
        static::__assert_equals('You have signed out of Northwind.', static::__on('?notice=signed_out')['text'], '{name} is filled in');
    }

    /**
     * LR-NOTICE-02: nothing but a declared code reaches the page.
     */
    public static function test_anything_else_reads_as_no_notice()
    {
        static::__assert_null(static::__on(''));
        static::__assert_null(static::__on('?notice='));
        static::__assert_null(static::__on('?notice=' . urlencode('<script>alert(1)</script>')));
        static::__assert_null(static::__on('?notice[]=link_dead'));
    }

    /**
     * LR-NOTICE-03: the developer's mistakes fail loud.
     */
    public static function test_an_undeclared_or_malformed_notice_fails_loud()
    {
        static::__assert_throws(\RuntimeException::class, fn () => Login_Notice::params('never_declared'), 'no notice');
        static::__assert_throws(\RuntimeException::class, fn () => Login_Notice::params('malformed'), 'must be');
    }
}
