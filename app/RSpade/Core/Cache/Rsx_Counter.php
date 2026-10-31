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
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Rsx;

// Ensure helpers are loaded since we may run early in bootstrap
require_once __DIR__ . '/../../helpers.php';

/**
 * Rsx_Counter - the ONE transient counter store (Redis database 3)
 *
 * WHY IT IS NOT THE CACHE. A counter is not a cached answer: it is the only record that
 * something happened N times in the last M seconds, and there is nowhere to recompute it
 * from. RsxCache is flushed on every database transaction rollback (see the RsxCache header
 * for the full database map and the reason), which would silently zero a login-failure
 * budget every time a request rolled a transaction back. So counters live in their own
 * database, on their own connection, and the cache flush cannot reach them.
 *
 * KEYS ARE NOT BUILD-SCOPED. A counter measures REAL TIME, so it must survive a manifest
 * rebuild - a build-scoped failure counter would reset to zero on every file change, which
 * is a throttle an attacker can clear by waiting for a deploy. It IS DATABASE-SCOPED, under
 * the same Rsx_Connection_Scope::token() prefix RsxCache applies, so a test run - or any
 * process pointed at another database - can never spend, clear or inherit the developer's
 * counters.
 *
 * VALUE ENCODING. A counter or flag key holds a raw numeric string maintained by redis itself
 * (INCRBY, or a plain SET for a flag). Nothing in this database is ever serialize()d, so
 * there is no encoding to confuse and no payload sniffing to do. The one other kind of entry
 * is an OPAQUE VALUE (add_value() / value()): a string the caller encoded, with an absolute
 * expiry, for short-lived state that belongs beside a counter - the second-factor engine keeps
 * an identity's issued sign-in code here, next to the failure budget that governs it. Only
 * value() reads one; a numeric read of it fails loud, exactly as before. The third is an
 * EVENT LOG (record_event() / event_times()): a sorted set of timestamps for a sliding-window
 * rate limit - Login_Throttle keeps its attempt history here.
 *
 * MAINTENANCE MODE. Redis is deliberately stopped inside the maintenance window, so every
 * entry point here returns 0 / null / no-ops without touching it - exactly as RsxCache does.
 * A throttle built on this therefore fails OPEN for the duration, which costs nothing
 * because the web tier is answering 503. Login_Throttle relies on "0 means nothing was
 * counted, so there is nothing to act on". The one exception is add_value(), which THROWS:
 * its caller is about to hand out something it expects to read back, and a value silently
 * not stored would be a secret that can never be redeemed.
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
     * Store an opaque string value with an ABSOLUTE expiry, unless the key already holds one.
     *
     * SET NX PXAT: the write and the existence check are one redis command, so two callers
     * racing to create the same value cannot both succeed - the loser gets false and reads
     * the winner's value with value(). The expiry is a point in time, not a duration, so the
     * value disappears exactly when the caller said it stops being valid and no later write
     * can extend it.
     *
     * Unlike every other writer here, an unavailable store (maintenance mode) THROWS - see
     * the class header.
     *
     * @param string $key Value key
     * @param string $value The caller's encoded value (stored as given)
     * @param int $expires_at_ms Unix time in milliseconds the value expires at (must be future)
     * @return bool True when this call stored it; false when the key already held a value
     */
    public static function add_value(string $key, string $value, int $expires_at_ms): bool
    {
        if ($expires_at_ms <= (int) (microtime(true) * 1000)) {
            throw new InvalidArgumentException(
                "Rsx_Counter::add_value() requires a future expires_at_ms for key '{$key}',"
                . " got {$expires_at_ms} - a value that is already expired is not transient state"
            );
        }

        $redis = self::_init();

        if ($redis === null) {
            throw new RuntimeException(
                "Rsx_Counter::add_value() cannot store key '{$key}': the counter store is unavailable"
                . ' (maintenance mode has stopped redis)'
            );
        }

        return $redis->set(self::_make_key($key), $value, ['nx', 'pxat' => $expires_at_ms]) === true;
    }

    /**
     * Overwrite a value add_value() stored, with a new absolute expiry - ONLY while it still
     * exists (SET XX). False when it had expired or was removed, so a caller restarting a
     * live value's clock never resurrects one that already ended. Throws like add_value() when
     * the store is unavailable.
     *
     * @param string $key Value key
     * @param string $value The caller's encoded value (stored as given)
     * @param int $expires_at_ms Unix time in milliseconds the value expires at (must be future)
     * @return bool True when the value existed and was replaced
     */
    public static function replace_value(string $key, string $value, int $expires_at_ms): bool
    {
        if ($expires_at_ms <= (int) (microtime(true) * 1000)) {
            throw new InvalidArgumentException(
                "Rsx_Counter::replace_value() requires a future expires_at_ms for key '{$key}', got {$expires_at_ms}"
            );
        }

        $redis = self::_init();

        if ($redis === null) {
            throw new RuntimeException(
                "Rsx_Counter::replace_value() cannot store key '{$key}': the counter store is unavailable"
                . ' (maintenance mode has stopped redis)'
            );
        }

        return $redis->set(self::_make_key($key), $value, ['xx', 'pxat' => $expires_at_ms]) === true;
    }

    /**
     * Read a value written by add_value(). Null when it is absent, expired, or the store is
     * unavailable.
     *
     * @param string $key Value key
     * @return string|null
     */
    public static function value(string $key): ?string
    {
        $redis = self::_init();

        if ($redis === null) {
            return null;
        }

        $value = $redis->get(self::_make_key($key));

        return $value === false ? null : (string) $value;
    }

    /**
     * Drop a counter, flag or value before its window runs out.
     *
     * For a test, for an operator releasing a customer who locked themselves out, and for a
     * value whose owner is done with it.
     *
     * @param string $key Counter, flag or value key
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
     * The times of the events recorded under $key within the last $window_seconds, oldest
     * first, in seconds (float).
     *
     * An EVENT LOG is the third kind of entry here: a redis sorted set of timestamps, for a
     * caller that has to know WHEN things happened inside a window rather than how many fit
     * in a fixed one (a sliding-window rate limit). Events older than the window are pruned
     * on read, so a log never holds more than one window of history.
     *
     * An unavailable store (maintenance mode) answers no events, so a limit built on this
     * fails OPEN, like every counter here.
     *
     * @param string $key Event log key
     * @param int $window_seconds How far back to look (must be > 0)
     * @return float[]
     */
    public static function event_times(string $key, int $window_seconds): array
    {
        if ($window_seconds <= 0) {
            throw new InvalidArgumentException(
                "Rsx_Counter::event_times() requires a positive window_seconds for key '{$key}', got {$window_seconds}"
            );
        }

        $redis = self::_init();

        if ($redis === null) {
            return [];
        }

        $full_key = self::_make_key($key);
        $since = microtime(true) - $window_seconds;

        $redis->zRemRangeByScore($full_key, '-inf', '(' . $since);

        $scores = $redis->zRangeByScore($full_key, (string) $since, '+inf', ['withscores' => true]);

        if ($scores === false) {
            throw new RuntimeException("Rsx_Counter::event_times() failed for key '{$key}' - the key is not an event log");
        }

        $times = array_map('floatval', array_values($scores));
        sort($times);

        return $times;
    }

    /**
     * Record one event, now, in the event log under $key.
     *
     * The log expires $retain_seconds after its LAST event, so an idle log cleans itself up;
     * event_times() prunes anything older than the window it is asked about.
     *
     * @param string $key Event log key
     * @param int $retain_seconds How long the log outlives its newest event (must be > 0)
     * @return void
     */
    public static function record_event(string $key, int $retain_seconds): void
    {
        if ($retain_seconds <= 0) {
            throw new InvalidArgumentException(
                "Rsx_Counter::record_event() requires a positive retain_seconds for key '{$key}', got {$retain_seconds}"
            );
        }

        $redis = self::_init();

        if ($redis === null) {
            return;
        }

        $full_key = self::_make_key($key);
        $now = microtime(true);

        // The member only has to be unique: two events in the same microtime are two events.
        $redis->zAdd($full_key, $now, sprintf('%.6f:%s', $now, bin2hex(random_bytes(4))));
        $redis->expire($full_key, $retain_seconds);
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
     * The redis key for a counter: the (database, host) scope + hash of the caller's key.
     *
     * Deliberately NOT build-scoped (see the class header). Hashed for the same reason
     * RsxCache hashes: a caller's key may embed an email address or an IP, and a raw one
     * would sit readable in redis for the life of the window.
     *
     * The scope is Rsx_Connection_Scope::token(), read LIVE and never memoized - exactly as
     * RsxCache::_scope_prefix() derives it, and for the same reason: a process pointed at
     * another database must never spend, clear or inherit this one's counters, and the
     * connection can be swapped mid-process. It replaces an older boolean namespace
     * (config('database.default') === 'test') that an artisan child launched with
     * DB_DATABASE at the test database answered NO to while reading the test database.
     */
    private static function _make_key(string $key): string
    {
        return 'counter:' . Rsx_Connection_Scope::token() . ':' . sha1($key);
    }
}
