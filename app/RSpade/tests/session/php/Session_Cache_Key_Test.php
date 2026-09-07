<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the Rsx_Storage scope key recipe (window.rsxapp.cache_key).
 *
 * cache_key is computed server-side by Rsx_Bundle_Abstract::_compute_cache_key and
 * shipped in window.rsxapp; Rsx.scope_key() just returns it. The recipe must match
 * the join Rsx.scope_key() used to perform client-side EXACTLY: append each
 * ingredient (session_hash, user id, site id, build_key) only when truthy, join the
 * survivors with '_', then md5. These tests lock that recipe and the distinctness
 * property that lets Rsx_Storage invalidate one user's scoped keys when a different
 * user loads a page on the same session row.
 *
 * Pure logic - no database.
 */
class Session_Cache_Key_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // Recipe: md5 of the '_'-joined truthy ingredients
    // -------------------------------------------------------------------------

    public static function test_all_ingredients_present_locks_exact_md5()
    {
        // md5('sesshash_42_1_buildkey123')
        $expected = 'c28cf397a7d06d5d54f2441edffc605c';
        $actual = Rsx_Bundle_Abstract::_compute_cache_key('sesshash', 42, 1, 'buildkey123');
        static::__assert_equals($expected, $actual, 'cache_key is md5 of session_hash_user_site_build');
    }

    public static function test_null_session_hash_is_skipped()
    {
        // md5('42_1_buildkey123') - the empty session_hash part is dropped from the join
        $expected = '98e0ff5c10a6c3c321740d708960055a';
        $actual = Rsx_Bundle_Abstract::_compute_cache_key(null, 42, 1, 'buildkey123');
        static::__assert_equals($expected, $actual, 'null session_hash is skipped, not joined as empty');
    }

    public static function test_recipe_matches_independent_join()
    {
        // Independently rebuild the expected join to prove the '_' + md5 recipe and
        // the empty-part skipping, without hardcoding a digest.
        $expected = md5(implode('_', ['abc123', '7', '3', 'v9']));
        $actual = Rsx_Bundle_Abstract::_compute_cache_key('abc123', 7, 3, 'v9');
        static::__assert_equals($expected, $actual, 'recipe equals md5 of underscore-joined truthy parts');
    }

    public static function test_anonymous_no_user_no_site_uses_hash_and_build()
    {
        // Anonymous visitor: only session_hash + build_key survive the truthiness gate.
        $expected = md5(implode('_', ['onlyhash', 'buildX']));
        $actual = Rsx_Bundle_Abstract::_compute_cache_key('onlyhash', null, null, 'buildX');
        static::__assert_equals($expected, $actual, 'empty user/site ingredients are skipped');
    }

    public static function test_returns_32_char_hex()
    {
        $key = Rsx_Bundle_Abstract::_compute_cache_key('h', 1, 1, 'b');
        static::__assert_equals(32, strlen($key), 'cache_key is a 32-char md5 hex string');
        static::__assert_true((bool) preg_match('/^[0-9a-f]{32}$/', $key), 'cache_key is lowercase hex');
    }

    // -------------------------------------------------------------------------
    // Distinctness: two different users on the same session/build -> different keys
    // -------------------------------------------------------------------------

    public static function test_different_users_same_session_produce_different_keys()
    {
        // The safety property the change must preserve: a second user loading a page
        // on the same session row must NOT collide on scope with the first user.
        $key_user_a = Rsx_Bundle_Abstract::_compute_cache_key('samehash', 100, 1, 'build');
        $key_user_b = Rsx_Bundle_Abstract::_compute_cache_key('samehash', 200, 1, 'build');
        static::__assert_true($key_user_a !== $key_user_b, 'different user ids yield different cache_keys');
    }

    public static function test_different_build_keys_produce_different_keys()
    {
        // A redeploy (build_key change) must invalidate scoped storage.
        $key_before = Rsx_Bundle_Abstract::_compute_cache_key('h', 5, 1, 'build_old');
        $key_after = Rsx_Bundle_Abstract::_compute_cache_key('h', 5, 1, 'build_new');
        static::__assert_true($key_before !== $key_after, 'different build_keys yield different cache_keys');
    }

    public static function test_different_sites_produce_different_keys()
    {
        $key_site_1 = Rsx_Bundle_Abstract::_compute_cache_key('h', 5, 1, 'build');
        $key_site_2 = Rsx_Bundle_Abstract::_compute_cache_key('h', 5, 2, 'build');
        static::__assert_true($key_site_1 !== $key_site_2, 'different site ids yield different cache_keys');
    }
}
