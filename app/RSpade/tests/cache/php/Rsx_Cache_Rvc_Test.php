<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Cache\Php;

use Redis;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The _RVC_ key convention: a cache key that must survive the cache resets.
 *
 * RsxCache::clear() runs on every database transaction rollback, so database 0 empties often
 * and for reasons that have nothing to do with any particular value. A key beginning with
 * _RVC_ is routed to the reduced-volatility database instead - still build-key scoped, still
 * LRU-evicted, but never flushed by clear(). It is an INTERNAL convention (nothing in PHP
 * uses it yet); these tests hold the routing so a future user inherits a working seam rather
 * than a comment.
 */
class Rsx_Cache_Rvc_Test extends Rsx_Test_Abstract
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

    private static function _fresh_key(string $prefix, string $suffix): string
    {
        $key = $prefix . 'rsxtest_rvc_' . $suffix . '_' . uniqid();
        static::$keys_used[] = $key;

        return $key;
    }

    /**
     * The redis key a cache value lives at, derived from RsxCache's own key builders so a
     * scheme change cannot silently strand this test on a stale shape.
     */
    private static function _full_key(string $key): string
    {
        $transform_build = new \ReflectionMethod(RsxCache::class, '_transform_key_build');
        $make_persistent = new \ReflectionMethod(RsxCache::class, '_make_key_persistent');

        return $make_persistent->invoke(null, $transform_build->invoke(null, $key));
    }

    private static function _redis(int $database): Redis
    {
        $redis = new Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
        $redis->select($database);

        return $redis;
    }

    /**
     * The database an _RVC_ key is routed to, read from RsxCache's own property so the test
     * cannot drift onto a database the code no longer uses.
     */
    private static function _rvc_db(): int
    {
        $property = new \ReflectionProperty(RsxCache::class, 'rvc_db');

        return (int) $property->getValue();
    }

    /**
     * THE WHOLE POINT: clear() empties the volatile database and nothing else.
     */
    public static function test_an_rvc_key_survives_a_cache_clear_and_a_plain_key_does_not()
    {
        $plain_key = static::_fresh_key('', 'plain');
        $rvc_key = static::_fresh_key(RsxCache::RVC_PREFIX, 'kept');

        RsxCache::set($plain_key, 'volatile');
        RsxCache::set($rvc_key, 'reduced volatility');

        static::__assert_equals('volatile', RsxCache::get($plain_key), 'sanity: the plain key is cached');
        static::__assert_equals('reduced volatility', RsxCache::get($rvc_key), 'sanity: the _RVC_ key is cached');
        static::__assert_true(RsxCache::exists($rvc_key), 'exists() follows the same routing as get()');

        RsxCache::clear();

        static::__assert_null(
            RsxCache::get($plain_key),
            'clear() must empty the volatile database - that is what it is for'
        );
        static::__assert_equals(
            'reduced volatility',
            RsxCache::get($rvc_key),
            'clear() must never reach the reduced-volatility database'
        );
    }

    /**
     * rsx:clean's half: clear_reduced_volatility() empties database 2 and nothing else - a
     * plain key on database 0 and a counter on database 3 both survive it.
     */
    public static function test_clear_reduced_volatility_empties_only_the_reduced_volatility_database()
    {
        $plain_key = static::_fresh_key('', 'plain_kept');
        $rvc_key = static::_fresh_key(RsxCache::RVC_PREFIX, 'dropped');
        $counter_key = 'rvc_clear_probe_' . random_hash(6);

        RsxCache::set($plain_key, 'volatile');
        RsxCache::set($rvc_key, 'reduced volatility');
        Rsx_Counter::increment($counter_key, 60);

        RsxCache::clear_reduced_volatility();

        static::__assert_null(RsxCache::get($rvc_key), 'clear_reduced_volatility() must empty database 2');
        static::__assert_equals('volatile', RsxCache::get($plain_key), 'the volatile database is not its business');
        static::__assert_equals(1, Rsx_Counter::get($counter_key), 'the counter database is not its business');

        Rsx_Counter::reset($counter_key);
    }

    /**
     * The routing is a DIFFERENT DATABASE, not a different key name: the value is physically
     * absent from database 0 and present in database 2.
     */
    public static function test_an_rvc_key_is_stored_on_the_reduced_volatility_database()
    {
        $rvc_key = static::_fresh_key(RsxCache::RVC_PREFIX, 'routed');

        RsxCache::set($rvc_key, 'routed');

        $full_key = static::_full_key($rvc_key);

        $volatile = static::_redis(0);
        $reduced = static::_redis(static::_rvc_db());

        try {
            static::__assert_equals(0, $volatile->exists($full_key), 'nothing was written to database 0');
            static::__assert_equals(1, $reduced->exists($full_key), 'the value lives on the RVC database');
        } finally {
            $volatile->close();
            $reduced->close();
        }
    }

    /**
     * An _RVC_ key is still BUILD-SCOPED: a code update misses it cleanly rather than serving
     * a value computed by code that no longer exists. The stored key literally contains the
     * current build key, so a different build key derives a different - absent - key.
     */
    public static function test_an_rvc_key_is_build_scoped()
    {
        $rvc_key = static::_fresh_key(RsxCache::RVC_PREFIX, 'buildscoped');

        RsxCache::set($rvc_key, 'this build');

        $transform_build = new \ReflectionMethod(RsxCache::class, '_transform_key_build');
        $namespaced = $transform_build->invoke(null, $rvc_key);

        static::__assert_contains(
            Manifest::get_build_key(),
            $namespaced,
            'an _RVC_ key carries the build key like every other cache key'
        );

        // What the NEXT build would look for: the same user key under a different build key.
        $make_persistent = new \ReflectionMethod(RsxCache::class, '_make_key_persistent');
        $next_build_key = $make_persistent->invoke(
            null,
            str_replace(Manifest::get_build_key(), 'rsxtest_next_build_key', $namespaced)
        );

        $reduced = static::_redis(static::_rvc_db());

        try {
            static::__assert_equals(
                1,
                $reduced->exists($make_persistent->invoke(null, $namespaced)),
                'sanity: this build sees its own value'
            );
            static::__assert_equals(
                0,
                $reduced->exists($next_build_key),
                'a rebuilt codebase misses cleanly - the RVC database is not a way out of build scoping'
            );
        } finally {
            $reduced->close();
        }
    }
}
