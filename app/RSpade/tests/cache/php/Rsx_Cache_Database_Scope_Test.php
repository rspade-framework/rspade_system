<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Cache\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Cache and counter keys are scoped on the DATABASE as well as the build.
 *
 * Every key RsxCache writes is 'cache:<Rsx_Connection_Scope::token()>:<sha1>', and every key
 * Rsx_Counter writes is 'counter:<token>:<sha1>' - the same (database, host) token RsxLocks
 * and Task_Worker_Registry namespace their state under. Two environments sharing one redis
 * (the developer's database and the test database being the everyday pair) therefore share
 * no cache entry, and clear() empties only the calling scope's keys.
 *
 * THE DEFECT THIS HOLDS SHUT. The namespace used to be the boolean
 * config('database.default') === 'test'. An artisan child spawned with DB_DATABASE at the
 * test database answered NO to it while READING the test database, so it wrote that
 * database's near-empty _type_refs map into the developer's 'type_refs_map' key and the dev
 * site 500'd with "Type ref ID 1 not found in registry" until the key expired. The token is
 * read from the LIVE connection, so no environment override can desynchronize it from the
 * data it describes.
 *
 * Each test drives the scope by swapping the default connection between 'mysql' and 'test'
 * (the runner refuses a run where those name the same database, so the swap always moves the
 * token) and restores it in a finally.
 */
class Rsx_Cache_Database_Scope_Test extends Rsx_Test_Abstract
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

    private static function _fresh_key(string $suffix): string
    {
        $key = 'rsxtest_cache_scope_' . $suffix . '_' . uniqid();
        static::$keys_used[] = $key;

        return $key;
    }

    /**
     * The connection name that is NOT the current default: the other half of the
     * (mysql, test) pair the runner guarantees point at different databases.
     */
    private static function _other_connection(): string
    {
        return config('database.default') === 'test' ? 'mysql' : 'test';
    }

    /**
     * Run $callback with the default connection swapped to the other database, restoring the
     * original in a finally whatever happens. Both halves of the swap are performed exactly
     * as Rsx_Test_Command does it: the config value AND the resolved default connection.
     */
    private static function _under_other_scope(callable $callback)
    {
        $original = (string) config('database.default');
        $other = static::_other_connection();

        config(['database.default' => $other]);
        DB::setDefaultConnection($other);

        try {
            return $callback();
        } finally {
            config(['database.default' => $original]);
            DB::setDefaultConnection($original);
        }
    }

    /**
     * The prefix is the token, literally - not a coincidence of two hashes agreeing.
     */
    public static function test_every_cache_key_is_prefixed_with_the_connection_scope_token()
    {
        $key = static::_fresh_key('prefix');

        $make_persistent = new \ReflectionMethod(RsxCache::class, '_make_key_persistent');
        $transform_build = new \ReflectionMethod(RsxCache::class, '_transform_key_build');

        $expected_prefix = 'cache:' . Rsx_Connection_Scope::token() . ':';

        static::__assert_contains(
            $expected_prefix,
            $make_persistent->invoke(null, $key),
            'the persistent namespace carries the (database, host) token'
        );
        static::__assert_contains(
            $expected_prefix,
            $make_persistent->invoke(null, $transform_build->invoke(null, $key)),
            'so does the build-scoped namespace, through the same one builder'
        );

        // The build key is still folded into the hashed body - database scoping ADDS a
        // dimension, it does not replace build scoping.
        static::__assert_contains(
            Manifest::get_build_key(),
            $transform_build->invoke(null, $key),
            'a build-scoped key still carries the build key'
        );

        // And the token genuinely moves with the connection.
        $other_prefix = static::_under_other_scope(fn () => 'cache:' . Rsx_Connection_Scope::token() . ':');

        static::__assert_not_equals(
            $expected_prefix,
            $other_prefix,
            'the two configured databases must produce different tokens - otherwise nothing here proves anything'
        );
    }

    /**
     * THE WHOLE POINT: a value cached under one database is not readable under another, and
     * is readable again the moment the connection comes back.
     */
    public static function test_a_cached_value_is_invisible_under_another_database_scope()
    {
        $key = static::_fresh_key('invisible');

        RsxCache::set($key, 'this database');
        static::__assert_equals('this database', RsxCache::get($key), 'sanity: cached in this scope');

        $seen_elsewhere = static::_under_other_scope(function () use ($key) {
            $value = RsxCache::get($key);

            // Poison the other scope with the SAME user key, exactly as the type-ref defect
            // did - it must not be able to reach back across the boundary either.
            RsxCache::set($key, 'other database');

            return $value;
        });

        static::__assert_null(
            $seen_elsewhere,
            'another database must not read this database\'s cache - that is the type_refs_map defect'
        );
        static::__assert_equals(
            'this database',
            RsxCache::get($key),
            'and the other scope\'s write must not overwrite this one'
        );

        static::_under_other_scope(fn () => RsxCache::delete($key));
    }

    /**
     * clear() runs on every transaction rollback, so an unscoped one emptied the other
     * environment's cache thousands of times a day.
     */
    public static function test_clear_under_another_database_scope_leaves_this_scopes_keys_intact()
    {
        $key = static::_fresh_key('survives_clear');

        RsxCache::set($key, 'kept');

        static::_under_other_scope(function () {
            RsxCache::clear();
        });

        static::__assert_equals(
            'kept',
            RsxCache::get($key),
            'a cache reset in another database scope must not touch this one'
        );

        // The converse, so the test cannot pass because clear() stopped working: clearing
        // HERE does drop it.
        RsxCache::clear();

        static::__assert_null(RsxCache::get($key), 'sanity: clear() still empties its own scope');
    }

    /**
     * Counters carry the same token for the same reason - a test run must never spend, clear
     * or inherit the developer's login-failure budget.
     */
    public static function test_a_counter_does_not_cross_database_scopes()
    {
        $key = 'rsxtest_counter_scope_' . random_hash(6);

        static::__assert_equals(3, Rsx_Counter::increment($key, 300, 3), 'sanity: counted in this scope');

        $elsewhere = static::_under_other_scope(function () use ($key) {
            $read = Rsx_Counter::get($key);
            Rsx_Counter::increment($key, 300, 50);
            Rsx_Counter::reset($key);

            return $read;
        });

        static::__assert_equals(0, $elsewhere, 'another database reads its own, empty counter');
        static::__assert_equals(
            3,
            Rsx_Counter::get($key),
            'and neither its increment nor its reset reached this scope\'s counter'
        );

        Rsx_Counter::reset($key);
        static::__assert_equals(0, Rsx_Counter::get($key), 'teardown: the counter is gone');
    }
}
