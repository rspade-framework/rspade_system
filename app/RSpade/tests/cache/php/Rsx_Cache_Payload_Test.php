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
 *
 * PLAIN DATA ONLY: a value carrying an object is refused on the way in, and a payload naming a
 * class is refused on the way out without anything being instantiated - the read is the
 * security boundary against whoever can write to redis (PHP object injection).
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
        [$redis, $full_key] = static::_poke($key, 'not a serialize payload');

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

    /**
     * Write a raw string at the redis key get($key) reads, bypassing the public API. The full
     * key is derived by invoking RsxCache's own private key builders via reflection -
     * reproducing the scheme by hand is what broke this test when the test-run namespace
     * suffix landed (2026-08-18). The caller deletes the key and closes the connection.
     *
     * @return array{0: Redis, 1: string}
     */
    private static function _poke(string $key, string $raw): array
    {
        $transform_build = new \ReflectionMethod(RsxCache::class, '_transform_key_build');
        $make_persistent = new \ReflectionMethod(RsxCache::class, '_make_key_persistent');
        $full_key = $make_persistent->invoke(null, $transform_build->invoke(null, $key));

        $redis = new Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int)env('REDIS_PORT', 6379), 2.0);
        $redis->select(0);
        $redis->set($full_key, $raw);

        return [$redis, $full_key];
    }

    /**
     * A payload that names a class - written straight into redis, as an attacker with redis
     * access would - is refused loud, and is never materialised as that class: the error
     * names the class the payload claimed, which only an inert read can report.
     */
    public static function test_crafted_object_payload_is_refused_unread()
    {
        $payloads = [
            'stdClass' => serialize(new \stdClass()),
            'ArrayObject' => serialize(['ok' => 1, 'deep' => ['x' => new \ArrayObject([1])]]),
            RsxCache::class => 'O:' . strlen(RsxCache::class) . ':"' . RsxCache::class . '":0:{}',
        ];

        foreach ($payloads as $claimed_class => $raw) {
            $key = static::_fresh_key('object');
            [$redis, $full_key] = static::_poke($key, $raw);

            try {
                $e = static::__assert_throws(
                    RuntimeException::class,
                    function () use ($key) {
                        RsxCache::get($key);
                    },
                    'contains an object of class'
                );
                static::__assert_contains("'{$claimed_class}'", $e->getMessage(), 'the refusal names the class the payload claimed');
            } finally {
                $redis->del($full_key);
                $redis->close();
            }
        }
    }

    /**
     * A plain payload whose STRING contents merely look like an object token reads back
     * unchanged - the refusal is about decoded objects, not about bytes in a string.
     */
    public static function test_string_resembling_an_object_token_round_trips()
    {
        $key = static::_fresh_key('lookalike');
        $value = ['note' => 'O:8:"stdClass":0:{} and C: and E:', 'n' => 3];

        RsxCache::set($key, $value);
        static::__assert_equals($value, RsxCache::get($key));
    }

    /**
     * Writing an object is refused at the write, in both families, so a caller learns the rule
     * where the mistake is made rather than on some later read - and nothing is stored.
     */
    public static function test_writing_an_object_is_refused_and_stores_nothing()
    {
        $key = static::_fresh_key('write_object');

        static::__assert_throws(
            RuntimeException::class,
            function () use ($key) {
                RsxCache::set($key, ['rows' => [new \stdClass()]]);
            },
            'plain data only'
        );
        static::__assert_null(RsxCache::get($key), 'nothing was stored by the refused set()');

        $persistent_key = 'rsxtest_cache_payload_persistent_' . uniqid();

        try {
            static::__assert_throws(
                RuntimeException::class,
                function () use ($persistent_key) {
                    RsxCache::set_persistent($persistent_key, new \ArrayObject());
                },
                'plain data only'
            );
            static::__assert_null(RsxCache::get_persistent($persistent_key), 'nothing was stored by the refused set_persistent()');
        } finally {
            RsxCache::delete_persistent($persistent_key);
        }

        static::__assert_throws(
            RuntimeException::class,
            function () use ($key) {
                RsxCache::remember($key, fn () => new \stdClass());
            },
            'plain data only'
        );
    }
}
