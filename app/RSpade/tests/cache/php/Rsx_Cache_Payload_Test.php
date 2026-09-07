<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Cache\Php;

use Redis;
use RuntimeException;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The RsxCache value-encoding contract.
 *
 * ONE encoding lives in this keyspace: a serialize() payload, written by set() /
 * set_persistent() / remember() and read by get() / get_persistent(). Anything else at a
 * cache key was not written by this class, and reading it fails loud rather than handing the
 * caller a bogus false. (Counters are not cache and are not here - they are Rsx_Counter, on
 * their own redis database; see Rsx_Counter_Test.)
 */
class Rsx_Cache_Payload_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static array $keys_used = [];

    public static function teardown()
    {
        foreach (static::$keys_used as $key) {
            RsxCache::delete($key);
        }

        static::$keys_used = [];
    }

    /**
     * A unique key per test, registered for teardown.
     */
    private static function _fresh_key(string $suffix): string
    {
        $key = 'rsxtest_cache_payload_' . $suffix . '_' . uniqid();
        static::$keys_used[] = $key;

        return $key;
    }

    public static function test_get_returns_the_default_for_a_missing_key()
    {
        $key = static::_fresh_key('missing');

        static::__assert_null(RsxCache::get($key));
        static::__assert_equals('none', RsxCache::get($key, 'none'));
    }

    /**
     * set()/get() round trips, including the serialized boolean false - the one value
     * unserialize() legitimately returns false for, which the corruption check must not
     * mistake for a failed read.
     */
    public static function test_set_get_round_trip_is_unchanged()
    {
        $key = static::_fresh_key('roundtrip');

        RsxCache::set($key, ['a' => 1, 'b' => [2, 3]]);
        static::__assert_equals(['a' => 1, 'b' => [2, 3]], RsxCache::get($key));

        RsxCache::set($key, false);
        static::__assert_false(RsxCache::get($key), 'serialized false round trips as false, not a corruption throw');

        RsxCache::set($key, 100);
        static::__assert_equals(100, RsxCache::get($key), 'an int set() through the serialize path still reads back');

        RsxCache::set($key, '250');
        static::__assert_equals('250', RsxCache::get($key), 'a numeric STRING keeps its type - it was serialized');
    }

    /**
     * A stored value that is not a serialize() payload is corruption, and reading it fails
     * loud rather than handing the caller a bogus false.
     *
     * Writing one requires bypassing the public API, so the full key is derived by invoking
     * RsxCache's own private key builders via reflection - reproducing the scheme by hand is
     * what broke this test when the test-run namespace suffix landed (2026-08-18).
     */
    public static function test_corrupt_payload_fails_loud()
    {
        $key = static::_fresh_key('corrupt');

        $transform_build = new \ReflectionMethod(RsxCache::class, '_transform_key_build');
        $make_persistent = new \ReflectionMethod(RsxCache::class, '_make_key_persistent');
        $full_key = $make_persistent->invoke(null, $transform_build->invoke(null, $key));

        $redis = new Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int)env('REDIS_PORT', 6379), 2.0);
        $redis->select(0);
        $redis->set($full_key, 'not a serialize payload');

        try {
            static::__assert_throws(
                RuntimeException::class,
                function () use ($key) {
                    RsxCache::get($key);
                },
                'corrupt cache payload'
            );
        } finally {
            $redis->del($full_key);
            $redis->close();
        }
    }
}
