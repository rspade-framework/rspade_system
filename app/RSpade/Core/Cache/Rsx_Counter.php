<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Cache;

use InvalidArgumentException;
use Redis;
use RuntimeException;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Rsx;

// Ensure helpers are loaded since we may run early in bootstrap
require_once __DIR__ . '/../../helpers.php';

/**
 * Rsx_Counter - the ONE transient counter store (Redis database 3)
 *
 * WHY IT IS NOT THE CACHE. A counter is not a cached answer: it is the only record that
 * something happened N times in the last M seconds, and there is nowhere to recompute it
 * from. RsxCache is flushed wholesale on every database transaction rollback (see the
 * RsxCache header for the full database map and the reason), which would silently zero a
 * login-failure budget every time a request rolled a transaction back. So counters live in
 * their own database, on their own connection, and the cache flush cannot reach them.
 *
 * KEYS ARE NOT BUILD-SCOPED. A counter measures REAL TIME, so it must survive a manifest
 * rebuild - a build-scoped failure counter would reset to zero on every file change, which
 * is a throttle an attacker can clear by waiting for a deploy. It DOES carry the same
 * test-run namespace suffix RsxCache applies, so a test run can never spend, clear or
 * inherit the developer's counters.
 *
 * VALUE ENCODING. Every key here holds a raw numeric string maintained by redis itself
 * (INCRBY, or a plain SET for a flag). Nothing in this database is ever serialize()d, so
 * there is no encoding to confuse and no payload sniffing to do.
 *
 * MAINTENANCE MODE. Redis is deliberately stopped inside the maintenance window, so every
 * entry point here returns 0 / no-ops without touching it - exactly as RsxCache does.
 * A throttle built on this therefore fails OPEN for the duration, which costs nothing
 * because the web tier is answering 503. Login_Throttle relies on "0 means nothing was
 * counted, so there is nothing to act on".
 *
 * EVICTION. Database 3 lives on the same redis instance as everything else, and the
 * eviction policy is per INSTANCE (allkeys-lru in the shipped conf), not per database - a
 * counter is as evictable as a cache entry. See backlog B-105.
 */
class Rsx_Counter
{
    /**
     * Redis database holding transient counters. See the RsxCache class header for the
     * authoritative four-database map.
     */
    private const COUNTER_DB = 3;

    private static ?Redis $_redis = null;

    // Sticky maintenance bypass set when a connect FAILED with the maintenance flag on disk
    // (a process that booted before maintenance went up - mirrors RsxCache::_init()).
    private static bool $maintenance_bypass_forced = false;

    /**
     * Connect (once per process) to the counter database.
     *
     * @return Redis|null Null when counters are unavailable (IDE bypass / maintenance mode)
     */
    private static function _init(): ?Redis
    {
        // The bypasses are checked BEFORE the memo, not after: a process that connected
        // before maintenance went up must still degrade, and a memo hit would sail past the
        // check and write to a server that is about to stop.
        if (self::_redis_bypass()) {
            return null;
        }

        if (self::_maintenance_bypass()) {
            return null;
        }

        if (self::$_redis) {
            return self::$_redis;
        }

        self::$_redis = new Redis();

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = env('REDIS_PORT', 6379);
        $socket = env('REDIS_SOCKET', null);

        if ($socket && file_exists($socket)) {
            $connected = self::$_redis->connect($socket);
        } else {
            $connected = self::$_redis->connect($host, $port, 2.0);
        }

        if (!$connected) {
            // STRAGGLER PATH: a process that booted BEFORE maintenance was enabled carries a
            // "no maintenance" snapshot but meets a stopped redis. With the flag on disk NOW,
            // degrade to the maintenance bypass; with no flag this stays as loud as ever.
            self::$_redis = null;
            if (Framework_Maintenance::is_active_on_disk()) {
                self::$maintenance_bypass_forced = true;

                return null;
            }

            shouldnt_happen('Failed to connect to Redis for transient counters');
        }

        self::$_redis->select(self::COUNTER_DB);

        return self::$_redis;
    }

    /**
     * Check if we can skip redis due to special circumstance (mirrors RsxCache).
     */
    private static function _redis_bypass(): bool
    {
        if (is_ide() && !class_exists('\Redis') && Rsx::is_development()) {
            return true;
        }

        return false;
    }

    /**
     * Is the counter store running WITHOUT a redis server because maintenance mode
     * stopped it? (mirrors RsxCache).
     */
    private static function _maintenance_bypass(): bool
    {
        return self::$maintenance_bypass_forced || Framework_Maintenance::is_active();
    }

    /**
     * Count one event in a window that starts at the FIRST event.
     *
     * FIXED WINDOW, NOT A SLIDING ONE: the expiry is applied only on the increment that
     * CREATES the key (the reply equals $amount), and no later increment touches it. The
     * window therefore runs from the first event to first-event + $window_seconds, then the
     * counter disappears entirely and the next event opens a new window. A sliding window
     * would let a slow attacker hold a counter alive forever.
     *
     * @param string $key Counter key
     * @param int $window_seconds Window length, applied when the key is created (must be > 0)
     * @param int $amount Amount to add
     * @return int New value, or 0 when the counter store is unavailable
     */
    public static function increment(string $key, int $window_seconds, int $amount = 1): int
    {
        if ($window_seconds <= 0) {
            throw new InvalidArgumentException(
                "Rsx_Counter::increment() requires a positive window_seconds for key '{$key}',"
                . " got {$window_seconds} - a counter with no window is not a transient counter"
            );
        }

        $redis = self::_init();

        if ($redis === null) {
            return 0;
        }

        $full_key = self::_make_key($key);

        $result = ($amount === 1)
            ? $redis->incr($full_key)
            : $redis->incrBy($full_key, $amount);

        $value = self::_counter_result($result, 'increment', $key);

        // The key was created by THIS call, so this is where the window opens. Any other
        // reply means the key already existed and already carries its own window.
        if ($value === $amount) {
            $redis->expire($full_key, $window_seconds);
        }

        return $value;
    }

    /**
     * Read a counter.
     *
     * A missing key - never incremented, or expired with its window - is 0. So is an
     * unavailable store (maintenance mode), which is what makes a throttle fail OPEN.
     *
     * @param string $key Counter key
     * @return int
     */
    public static function get(string $key): int
    {
        $redis = self::_init();

        if ($redis === null) {
            return 0;
        }

        $value = $redis->get(self::_make_key($key));

        if ($value === false) {
            return 0;
        }

        return self::_numeric_value($value, 'get', $key);
    }

    /**
     * Write a self-expiring numeric marker.
     *
     * The lockout half of a throttle: one integer with a TTL, so the flag cleans itself up
     * and reading it is one GET rather than a TTL introspection. The framework's own use
     * stores the UNIX TIME the lockout expires.
     *
     * @param string $key Flag key
     * @param int $ttl_seconds How long the flag lives (must be > 0)
     * @param int $value The integer the flag carries
     * @return void
     */
    public static function set_flag(string $key, int $ttl_seconds, int $value): void
    {
        if ($ttl_seconds <= 0) {
            throw new InvalidArgumentException(
                "Rsx_Counter::set_flag() requires a positive ttl_seconds for key '{$key}',"
                . " got {$ttl_seconds} - a flag that never expires is not transient state"
            );
        }

        $redis = self::_init();

        if ($redis === null) {
            return;
        }

        $redis->setex(self::_make_key($key), $ttl_seconds, (string) $value);
    }

    /**
     * Read a marker written by set_flag(). 0 when it is absent, expired, or unreadable.
     *
     * @param string $key Flag key
     * @return int
     */
    public static function flag_value(string $key): int
    {
        return self::get($key);
    }

    /**
     * Drop a counter or flag before its window runs out.
     *
     * For a test, and for an operator releasing a customer who locked themselves out.
     *
     * @param string $key Counter or flag key
     * @return void
     */
    public static function reset(string $key): void
    {
        $redis = self::_init();

        if ($redis === null) {
            return;
        }

        $redis->del(self::_make_key($key));
    }

    /**
     * Convert a redis INCR/INCRBY reply into an int, or fail loud.
     *
     * phpredis answers false when the key does not hold an integer. Coercing that through
     * the ': int' return type would produce a 0 indistinguishable from a genuine count.
     */
    private static function _counter_result($result, string $operation, string $key): int
    {
        if ($result === false) {
            $redis_error = self::$_redis->getLastError();
            self::$_redis->clearLastError();

            throw new RuntimeException(
                "Rsx_Counter::{$operation}() failed for key '{$key}'"
                . ($redis_error ? " (redis: {$redis_error})" : '')
                . ' - every key in the counter database holds a raw numeric value'
            );
        }

        return (int) $result;
    }

    /**
     * Interpret a stored value, or fail loud. Nothing in this database is ever serialized,
     * so anything non-numeric is corruption written by something that does not own the key.
     */
    private static function _numeric_value(string $value, string $operation, string $key): int
    {
        $digits = (isset($value[0]) && $value[0] === '-') ? substr($value, 1) : $value;

        if ($digits === '' || !ctype_digit($digits)) {
            throw new RuntimeException(
                "Rsx_Counter::{$operation}() found a non-numeric value at key '{$key}'"
                . ' - every key in the counter database holds a raw numeric value'
            );
        }

        return (int) $value;
    }

    /**
     * The redis key for a counter: test-run namespace + hash of the caller's key.
     *
     * Deliberately NOT build-scoped (see the class header). Hashed for the same reason
     * RsxCache hashes: a caller's key may embed an email address or an IP, and a raw one
     * would sit readable in redis for the life of the window.
     */
    private static function _make_key(string $key): string
    {
        return 'counter:' . self::_test_run_suffix() . sha1($key);
    }

    /**
     * Namespace suffix separating a TEST RUN's counters from the developer's - the same
     * rule, for the same reason, as RsxCache::_test_run_suffix().
     */
    private static function _test_run_suffix(): string
    {
        return config('database.default') === 'test' ? '_test' : '';
    }
}
