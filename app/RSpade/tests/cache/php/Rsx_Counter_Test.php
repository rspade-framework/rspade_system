<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Cache\Php;

use InvalidArgumentException;
use Redis;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Counter - the ONE transient counter store, on its own redis database.
 *
 * The contract under test: a FIXED window opened by the increment that CREATES the key and by
 * no other (a sliding window would let a slow attacker hold a counter alive forever); a
 * self-expiring numeric flag beside it; and - the reason the class exists at all - counters
 * that live somewhere RsxCache::clear() cannot reach, because clear() runs on every database
 * transaction rollback and a rolled-back request must not zero a login-failure budget.
 *
 * TTLs are read straight from redis: the class exposes no handle, so the key scheme is derived
 * by invoking Rsx_Counter's own private key builder through reflection. If that scheme ever
 * changes these tests fail loudly (the probed key is simply not the one the counter lives at).
 */
class Rsx_Counter_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const WINDOW_SECONDS = 300;

    private static array $keys_used = [];

    public static function teardown()
    {
        foreach (static::$keys_used as $key) {
            Rsx_Counter::reset($key);
        }

        static::$keys_used = [];
    }

    /**
     * A unique key per test, registered for teardown.
     */
    private static function _fresh_key(string $suffix): string
    {
        $key = 'rsxtest_counter_' . $suffix . '_' . uniqid();
        static::$keys_used[] = $key;

        return $key;
    }

    /**
     * The redis key a counter actually lives at, derived from Rsx_Counter's own builder.
     */
    private static function _full_key(string $key): string
    {
        $make_key = new \ReflectionMethod(Rsx_Counter::class, '_make_key');

        return $make_key->invoke(null, $key);
    }

    /**
     * A connection to the COUNTER database (3), read from the class's own constant so the
     * test cannot drift onto a database the code no longer uses.
     */
    private static function _counter_redis(): Redis
    {
        $db = new \ReflectionClassConstant(Rsx_Counter::class, 'COUNTER_DB');

        $redis = new Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
        $redis->select((int) $db->getValue());

        return $redis;
    }

    /**
     * Seconds left on a counter's window (-1 = no expiry set, -2 = no such key).
     */
    private static function _ttl(string $key): int
    {
        $redis = static::_counter_redis();
        $ttl = (int) $redis->ttl(static::_full_key($key));
        $redis->close();

        return $ttl;
    }

    public static function test_first_increment_opens_the_window()
    {
        $key = static::_fresh_key('open');

        static::__assert_equals(1, Rsx_Counter::increment($key, self::WINDOW_SECONDS));

        $ttl = static::_ttl($key);
        static::__assert_greater_than(0, $ttl, 'the creating increment sets an expiry');
        static::__assert_true(
            $ttl <= self::WINDOW_SECONDS,
            "the expiry is the requested window, got {$ttl}"
        );
    }

    public static function test_later_increments_never_extend_the_window()
    {
        $key = static::_fresh_key('fixed');

        Rsx_Counter::increment($key, self::WINDOW_SECONDS);
        $ttl_after_first = static::_ttl($key);

        static::__assert_equals(2, Rsx_Counter::increment($key, self::WINDOW_SECONDS));

        $ttl_after_second = static::_ttl($key);
        static::__assert_greater_than(0, $ttl_after_second, 'the counter still expires');
        static::__assert_true(
            $ttl_after_second <= $ttl_after_first,
            "a later increment must not push the window out ({$ttl_after_first} -> {$ttl_after_second})"
        );

        // Stronger proof: shrink the window behind the API's back, then increment again. A
        // sliding implementation would restore it to the full window.
        $redis = static::_counter_redis();
        $redis->expire(static::_full_key($key), 5);
        $redis->close();

        static::__assert_equals(3, Rsx_Counter::increment($key, self::WINDOW_SECONDS));
        static::__assert_true(
            static::_ttl($key) <= 5,
            'the window belongs to the key, not to the latest increment'
        );
    }

    public static function test_amount_seeds_the_counter_and_still_opens_the_window()
    {
        $key = static::_fresh_key('amount');

        static::__assert_equals(
            5,
            Rsx_Counter::increment($key, self::WINDOW_SECONDS, 5),
            'a creating increment of n seeds the counter at n'
        );
        static::__assert_greater_than(0, static::_ttl($key), 'and still opens the window');

        static::__assert_equals(10, Rsx_Counter::increment($key, self::WINDOW_SECONDS, 5));
    }

    public static function test_non_positive_window_throws()
    {
        $key = static::_fresh_key('badwindow');

        static::__assert_throws(
            InvalidArgumentException::class,
            function () use ($key) {
                Rsx_Counter::increment($key, 0);
            },
            'requires a positive window_seconds'
        );

        static::__assert_throws(
            InvalidArgumentException::class,
            function () use ($key) {
                Rsx_Counter::increment($key, -60);
            },
            'requires a positive window_seconds'
        );

        static::__assert_equals(-2, static::_ttl($key), 'a rejected call creates nothing');
    }

    public static function test_get_reads_the_counter_and_defaults_to_zero()
    {
        $key = static::_fresh_key('read');

        static::__assert_equals(0, Rsx_Counter::get($key), 'an absent counter is 0');

        Rsx_Counter::increment($key, self::WINDOW_SECONDS);
        Rsx_Counter::increment($key, self::WINDOW_SECONDS);

        $value = Rsx_Counter::get($key);
        static::__assert_true(is_int($value), 'get() answers an int');
        static::__assert_equals(2, $value);
    }

    /**
     * The lockout half of a throttle: one integer that expires on its own, so reading it is a
     * single get rather than a TTL introspection.
     */
    public static function test_a_flag_carries_its_value_and_expires_on_its_own()
    {
        $key = static::_fresh_key('flag');

        static::__assert_equals(0, Rsx_Counter::flag_value($key), 'an unset flag reads 0');

        $expires_at = time() + 900;
        Rsx_Counter::set_flag($key, 900, $expires_at);

        static::__assert_equals($expires_at, Rsx_Counter::flag_value($key));

        $ttl = static::_ttl($key);
        static::__assert_greater_than(0, $ttl, 'a flag carries an expiry so it cleans itself up');
        static::__assert_true($ttl <= 900, "the expiry is the requested ttl, got {$ttl}");
    }

    public static function test_non_positive_flag_ttl_throws()
    {
        $key = static::_fresh_key('badflag');

        static::__assert_throws(
            InvalidArgumentException::class,
            function () use ($key) {
                Rsx_Counter::set_flag($key, 0, 1);
            },
            'requires a positive ttl_seconds'
        );

        static::__assert_equals(-2, static::_ttl($key), 'a rejected call creates nothing');
    }

    public static function test_reset_drops_a_counter_before_its_window_runs_out()
    {
        $key = static::_fresh_key('reset');

        Rsx_Counter::increment($key, self::WINDOW_SECONDS, 4);
        static::__assert_equals(4, Rsx_Counter::get($key));

        Rsx_Counter::reset($key);

        static::__assert_equals(0, Rsx_Counter::get($key), 'a reset counter reads 0');
        static::__assert_equals(-2, static::_ttl($key), 'and the key is gone, not merely zeroed');

        // And the next increment opens a NEW window rather than resuming the old one.
        static::__assert_equals(1, Rsx_Counter::increment($key, self::WINDOW_SECONDS));
    }

    /**
     * THE REASON THE CLASS EXISTS: counters are not in the cache database, so nothing that
     * empties the cache can empty them. RsxCache::clear() runs on every database transaction
     * rollback - if a counter lived in database 0 a rolled-back request would silently zero a
     * throttle budget.
     */
    public static function test_a_counter_is_invisible_to_the_cache_and_survives_a_cache_clear()
    {
        $key = static::_fresh_key('isolated');

        Rsx_Counter::increment($key, self::WINDOW_SECONDS, 3);

        static::__assert_null(
            RsxCache::get($key),
            'a counter must not be reachable through the cache - it is on another database'
        );
        static::__assert_false(RsxCache::exists($key));

        RsxCache::clear();

        static::__assert_equals(
            3,
            Rsx_Counter::get($key),
            'a cache flush must never reach the counter database'
        );
    }

    /**
     * Maintenance mode stops redis, so a counter answers 0 without reaching it - which is what
     * makes a throttle built on this primitive fail OPEN.
     */
    public static function test_maintenance_mode_returns_zero_and_writes_nothing()
    {
        $key = static::_fresh_key('maint');

        Framework_Maintenance::$force_active_for_tests = true;

        try {
            static::__assert_equals(0, Rsx_Counter::increment($key, self::WINDOW_SECONDS));
            static::__assert_equals(0, Rsx_Counter::get($key));
            static::__assert_equals(0, Rsx_Counter::flag_value($key));
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_equals(-2, static::_ttl($key), 'nothing reached redis');
        static::__assert_equals(0, Rsx_Counter::get($key));
    }
}
