<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The literal values Node mirrors. A change here is a change to the daemon and the SDK: see
 * docs.dev/companion_apps/WIRE_CONTRACT.md.
 */
class Companion_Wire_Contract_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_the_instance_secret_is_the_documented_hmac()
    {
        static::__assert_equals(
            hash_hmac('sha256', 'companion|hello|i-1|1700000000000', (string) env('APP_KEY')),
            Companion_Verifier::instance_secret('hello', 'i-1', '1700000000000')
        );
    }

    public static function test_the_daemon_key_is_the_documented_hmac()
    {
        static::__assert_equals(hash_hmac('sha256', 'companiond', (string) env('APP_KEY')), Companion_Verifier::daemon_key());
    }

    public static function test_the_two_keys_are_domain_separated()
    {
        static::__assert_false(Companion_Verifier::instance_secret('companiond', '-', '-') === Companion_Verifier::daemon_key());
    }

    public static function test_the_canonical_string_is_the_documented_join()
    {
        static::__assert_equals(
            'POST|/_companion/hello/hello|hello|i-1|1700000000000|abc|-|1700000000|n0123456789abcdef|' . hash('sha256', '{}'),
            Companion_Verifier::canonical_string('POST', '/_companion/hello/hello', 'hello', 'i-1', '1700000000000', 'abc', '-', '1700000000', 'n0123456789abcdef', '{}')
        );
    }

    public static function test_the_redis_keys_are_the_documented_shape()
    {
        $prefix = 'rsx_companion:' . Rsx_Connection_Scope::token() . ':';

        static::__assert_equals(4, Companion_Store::REDIS_DB);
        static::__assert_equals($prefix . 'instance:i-1', Companion_Store::instance_key('i-1'));
        static::__assert_equals($prefix . 'stats:hello:202611020615', Companion_Store::request_stats_key('hello', '202611020615'));
    }

    public static function test_the_window_and_the_nonce_ttl()
    {
        static::__assert_equals(60, Companion_Verifier::WINDOW_SECONDS);
        static::__assert_greater_than(2 * Companion_Verifier::WINDOW_SECONDS - 1, Companion_Verifier::NONCE_TTL_SECONDS, 'a nonce outlives the longest a request stays valid');
    }
}
