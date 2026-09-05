<?php

namespace App\RSpade\Core\Cache;

use Redis;
use RuntimeException;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;

// Ensure helpers are loaded since we may run early in bootstrap
require_once __DIR__ . '/../../helpers.php';

/**
 * Redis-based caching system with LRU eviction
 *
 * Uses Redis database 0 for general caching. Every key is prefixed with the current manifest
 * build key, so a code change invalidates the cache without anything being cleared.
 *
 * THE REDIS DATABASE MAP (one instance, four databases). This class is the authority; every
 * other subsystem that opens a redis connection points its comment here.
 *
 *   DB 0  VOLATILE CACHE. This class (including its persistent namespace) and the realtime
 *         emitter hashes (rsx_rt:em:*). FLUSHED WHOLESALE on every database transaction
 *         rollback (see below) and by clear().
 *   DB 1  LOCKS and the task worker registry (RsxLocks, Task_Worker_Registry).
 *   DB 2  REDUCED VOLATILITY CACHE ("RVC"). Reserved. The full page cache writes here
 *         directly (Rsx_FPC and system/bin/fpc-proxy.js), and this class routes any key
 *         beginning with _RVC_ here. NEVER flushed by clear().
 *   DB 3  TRANSIENT COUNTERS (Rsx_Counter) - login throttling and friends.
 *
 * EVICTION IS PER INSTANCE, NOT PER DATABASE. The shipped conf
 * (system/app/RSpade/resource/docker/redis/redis.conf) sets allkeys-lru over the whole
 * instance, so a lock, a counter and a cache entry are equally evictable. See backlog B-105.
 *
 * clear() RUNS ON EVERY TRANSACTION ROLLBACK. A rollback can undo a database write that this
 * cache already memoized - the defect that motivated the map above was Type_Ref_Registry
 * caching a _type_refs row that a rolled-back transaction then removed, after which
 * id_to_class() answered a confidently WRONG class. Owner ruling: if the cache cannot be
 * reset at any moment, the cache is improperly implemented. So the flush is unconditional
 * (Transaction_Rollback_Cache_Reset), and anything that must NOT be flushed is not cache and
 * does not live in database 0.
 *
 * THE _RVC_ KEY CONVENTION is INTERNAL ONLY - it is a cache-key convention, not a second API.
 * A key beginning with _RVC_ is stored in database 2 under the ordinary build-key and
 * test-run prefix, so a code update still misses it cleanly and it is LRU-evicted like
 * everything else; it simply survives the otherwise frequent cache resets that transaction
 * rollback causes. As of the time of writing nothing in PHP uses this feature. It is kept
 * here for reference and future implementation, and to reserve database 2, which is written
 * to directly by FPC.
 */
class RsxCache
{
    // Redis configuration
    private static ?Redis $_redis = null;

    private static int $cache_db = 0;  // Database 0 for cache (with LRU eviction)

    // Second connection, selected to the reduced-volatility database (see the class header).
    private static ?Redis $_redis_rvc = null;

    private static int $rvc_db = 2;  // Database 2 - reduced volatility cache, never flushed

    /**
     * Key prefix routing a value to the reduced-volatility database. INTERNAL convention -
     * see the class header.
     */
    public const RVC_PREFIX = '_RVC_';

    private static bool $initialized = false;

    // Sticky maintenance bypass set when a connect FAILED with the maintenance flag on disk
    // (a process that booted before maintenance went up - see _init()).
    private static bool $maintenance_bypass_forced = false;

    // One "write skipped" warning per process (see _note_maintenance_write_skipped()).
    private static bool $maintenance_write_warned = false;

    // Request-scoped cache (static property storage)
    private static array $_once_cache = [];

    // Default expiration times
    public const NO_EXPIRATION = 0;

    public const HOUR = 3600;

    public const DAY = 86400;

    public const WEEK = 604800;

    /**
     * Initialize the cache system
     * Must be called after manifest is loaded
     */
    public static function _init()
    {
        if (self::$_redis) {
            return self::$_redis;
        }

        // Skip Redis in IDE context if extension not available
        if (self::_redis_bypass()) {
            return null;
        }

        // Maintenance mode stops the redis server. Return BEFORE connecting: the manifest
        // auto-rebuild and Type_Ref_Registry hit the cache on the first artisan boot after
        // files change, so without this even the update window's first step would fatal in
        // shouldnt_happen() below.
        if (self::_maintenance_bypass()) {
            return null;
        }

        self::$_redis = new Redis();

        // Connect to Redis (will be configured via environment)
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = env('REDIS_PORT', 6379);
        $socket = env('REDIS_SOCKET', null);

        if ($socket && file_exists($socket)) {
            $connected = self::$_redis->connect($socket);
        } else {
            $connected = self::$_redis->connect($host, $port, 2.0);
        }

        if (!$connected) {
            // STRAGGLER PATH (failure path only, one extra stat): a process that booted BEFORE
            // maintenance was enabled carries a "no maintenance" snapshot but meets a stopped
            // redis. If the flag is on disk NOW, degrade to the maintenance bypass instead of
            // fatalling. With no flag on disk this stays exactly as loud as it has always been.
            self::$_redis = null;
            if (Framework_Maintenance::is_active_on_disk()) {
                self::$maintenance_bypass_forced = true;

                return null;
            }

            shouldnt_happen('Failed to connect to Redis for caching');
        }

        // Select the cache database (with LRU eviction)
        self::$_redis->select(self::$cache_db);

        return self::$_redis;
    }

    /**
     * Connect (once per process) to the reduced-volatility database.
     *
     * Deliberately a SECOND connection rather than a select() per operation: a shared
     * connection would have to be re-selected on every call, and one missed select would
     * write a cache key into the wrong database silently.
     */
    private static function _init_rvc(): ?Redis
    {
        if (self::$_redis_rvc) {
            return self::$_redis_rvc;
        }

        // The ordinary connection carries every bypass and straggler rule; when it declines,
        // so does this one.
        if (self::_init() === null) {
            return null;
        }

        self::$_redis_rvc = new Redis();

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = env('REDIS_PORT', 6379);
        $socket = env('REDIS_SOCKET', null);

        if ($socket && file_exists($socket)) {
            $connected = self::$_redis_rvc->connect($socket);
        } else {
            $connected = self::$_redis_rvc->connect($host, $port, 2.0);
        }

        if (!$connected) {
            self::$_redis_rvc = null;
            shouldnt_happen('Failed to connect to Redis for the reduced-volatility cache');
        }

        self::$_redis_rvc->select(self::$rvc_db);

        return self::$_redis_rvc;
    }

    /**
     * Does this caller-supplied key route to the reduced-volatility database?
     */
    private static function _is_rvc(string $key): bool
    {
        return str_starts_with($key, self::RVC_PREFIX);
    }

    /**
     * The connection a caller-supplied key belongs on. Every entry point resolves it here,
     * so the routing rule exists in exactly one place.
     */
    private static function _connection_for(string $key): ?Redis
    {
        return self::_is_rvc($key) ? self::_init_rvc() : self::_init();
    }

    /**
     * Check if we can skip redis due to special circumstance
     */
    private static function _redis_bypass()
    {
        if (is_ide() && !class_exists('\Redis') && Rsx::is_development()) {
            return true;
        }

        return false;
    }

    /**
     * Is the cache running WITHOUT a redis server because maintenance mode stopped it?
     *
     * Reads the per-process RSPADE_MAINT_MODE snapshot; the sticky flag covers the straggler
     * (booted-before-enable) case detected on a connect failure in _init().
     */
    private static function _maintenance_bypass(): bool
    {
        return self::$maintenance_bypass_forced || Framework_Maintenance::is_active();
    }

    /**
     * Record that a cache WRITE was dropped because maintenance mode is up.
     *
     * Owner ruling: a write under the flag leaves ONE warning line in the Laravel log and
     * returns without error; reads are silent misses. Logged once per process - a rebuild
     * inside the window performs thousands of identical writes and repeating the same line
     * for each adds no information.
     */
    private static function _note_maintenance_write_skipped(): void
    {
        if (self::$maintenance_write_warned) {
            return;
        }

        self::$maintenance_write_warned = true;
        \Illuminate\Support\Facades\Log::warning(
            'maintenance mode: redis write skipped (RsxCache; further skipped writes in this process are not logged)'
        );
    }

    /**
     * Get a value from cache
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or default
     */
    public static function get(string $key, $default = null)
    {
        $redis = self::_connection_for($key);

        if (self::_redis_bypass()) {
            return null;
        }

        // Maintenance mode: silent cache miss (reads are never logged).
        if (self::_maintenance_bypass()) {
            return $default;
        }

        return self::_read($redis, self::_make_key_persistent(self::_transform_key_build($key)), $key, $default);
    }

    /**
     * Same as ::get but survives changes in the development environment
     *
     * The developer must call this function during manifest build phase, because
     * the build key to determine if the build has been updated is not available
     * until the manifest rescan is complete.  Calling get() during manifest rescan
     * will throw an exception.
     *
     * DATABASE 0 ONLY: the persistent namespace is not routed, so an _RVC_ prefix means
     * nothing here (see the class header - the convention applies to the build-scoped
     * entry points).
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or default
     */
    public static function get_persistent(string $key, $default = null)
    {
        self::_init();

        if (self::_redis_bypass()) {
            return null;
        }

        // Maintenance mode: silent cache miss (reads are never logged).
        if (self::_maintenance_bypass()) {
            return $default;
        }

        return self::_read(self::$_redis, self::_make_key_persistent($key), $key, $default);
    }

    /**
     * Read and decode one already-namespaced key from a chosen connection.
     *
     * @param Redis $redis The connection the key lives on
     * @param string $full_key The namespaced redis key
     * @param string $key The caller's key, for the error message only
     * @param mixed $default
     * @return mixed
     */
    private static function _read(Redis $redis, string $full_key, string $key, $default)
    {
        $value = $redis->get($full_key);

        if ($value === false) {
            return $default;
        }

        // Anything that is not a serialize() payload was not written by this class. Reject it
        // BEFORE unserialize() so the caller gets a message naming the key, rather than a bare
        // "unserialize(): Error at offset 0" - and never a bogus false that reads as a value.
        if (!self::_is_serialized_payload($value)) {
            throw new RuntimeException(
                "RsxCache: corrupt cache payload for key '{$key}' - the stored value is not a "
                . 'serialize() payload'
            );
        }

        return unserialize($value);
    }

    /**
     * Does this raw redis string carry a serialize() payload?
     *
     * Every serialize() output is 'N;' (null) or a type letter followed by ':'.
     */
    private static function _is_serialized_payload(string $value): bool
    {
        if ($value === 'N;') {
            return true;
        }

        return isset($value[1])
            && $value[1] === ':'
            && strpos('bidsaOECR', $value[0]) !== false;
    }

    /**
     * Set a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Expiration time in seconds (0 = never expire)
     * @return bool Success
     */
    public static function set(string $key, $value, int $expiration = self::NO_EXPIRATION): bool
    {
        $redis = self::_connection_for($key);

        if (self::_redis_bypass()) {
            return false;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return false;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));
        $payload = serialize($value);

        if ($expiration > 0) {
            return $redis->setex($full_key, $expiration, $payload);
        }

        return $redis->set($full_key, $payload);
    }

    /**
     * Set a ::set but survives a manifest rescan
     * See ::get_persistent
     *
     * DATABASE 0 ONLY - the persistent namespace is not _RVC_-routed.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Expiration time in seconds (0 = never expire)
     * @return bool Success
     */
    public static function set_persistent(string $key, $value, int $expiration = self::NO_EXPIRATION): bool
    {
        self::_init();

        if (self::_redis_bypass()) {
            return false;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return false;
        }

        $full_key = self::_make_key_persistent($key);

        $value = serialize($value);

        if ($expiration > 0) {
            return self::$_redis->setex($full_key, $expiration, $value);
        }

        return self::$_redis->set($full_key, $value);
    }

    /**
     * Delete a key from cache
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete(string $key): bool
    {
        $redis = self::_connection_for($key);

        if (self::_redis_bypass()) {
            return false;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return false;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));

        return $redis->del($full_key) > 0;
    }

    /**
     * Delete a key from the PERSISTENT namespace
     *
     * The counterpart of get_persistent()/set_persistent(): delete() looks under the
     * build-prefixed key and can never find one of those, so a persistent key needs its own
     * delete.
     *
     * @param string $key Cache key (persistent namespace)
     * @return bool True when a key was removed
     */
    public static function delete_persistent(string $key): bool
    {
        self::_init();

        if (self::_redis_bypass()) {
            return false;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return false;
        }

        return self::$_redis->del(self::_make_key_persistent($key)) > 0;
    }

    /**
     * Check if a key exists in cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public static function exists(string $key): bool
    {
        $redis = self::_connection_for($key);

        if (self::_redis_bypass()) {
            return false;
        }

        // Maintenance mode: a read - silent, and nothing can be present.
        if (self::_maintenance_bypass()) {
            return false;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));

        return $redis->exists($full_key) > 0;
    }

    /**
     * Clear the entire volatile cache - database 0, and ONLY database 0.
     *
     * Called on every database transaction rollback (see the class header), so it must never
     * reach the reduced-volatility database, the locks database or the counter database.
     *
     * SCAN + DEL, NOT FLUSHDB. The shipped redis.conf disables FLUSHDB and FLUSHALL outright
     * (rename-command ... ""), so the flushDb() this method used to call answered false and
     * cleared NOTHING, silently, on every install carrying that conf - found 2026-09-05 when
     * the rollback flush made the behavior observable. SCAN needs no privileged command,
     * touches only the database the connection is selected on, and is the same pattern
     * Rsx_FPC::clear() already uses. A failed delete FAILS LOUD: a flush that silently does
     * not happen is precisely the defect this method now exists to prevent.
     */
    public static function clear(): void
    {
        self::_init();

        if (self::_redis_bypass()) {
            return;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return;
        }

        // Database 0 only: the connection this class selects it on. The RVC connection
        // (database 2) is deliberately untouched, as are databases 1 and 3.
        self::_delete_every_key(self::$_redis, 'clear');
    }

    /**
     * Clear the reduced-volatility cache - database 2, and ONLY database 2.
     *
     * The rollback flush never calls this; that is the whole point of the RVC database. Its
     * ONE caller is rsx:clean: discarding build state rotates the build key, which orphans
     * every _RVC_ key and every FPC entry (fpc:{build_key}:*) at once, and "clean" means the
     * orphans go now rather than whenever LRU gets round to them. Databases 0, 1 and 3 are
     * untouched - rsx:clean clears database 0 through clear() beside this call.
     */
    public static function clear_reduced_volatility(): void
    {
        $redis = self::_init_rvc();

        if ($redis === null || self::_redis_bypass()) {
            return;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return;
        }

        self::_delete_every_key($redis, 'clear_reduced_volatility');
    }

    /**
     * SCAN + DEL every key on the database the given connection is selected on. Fails loud
     * on a delete redis refused - see clear() for why this is not FLUSHDB.
     */
    private static function _delete_every_key(Redis $redis, string $caller): void
    {
        $iterator = null;

        do {
            $keys = $redis->scan($iterator, '*', 500);

            if ($keys === false || count($keys) === 0) {
                continue;
            }

            if ($redis->del($keys) === false) {
                $redis_error = $redis->getLastError();
                $redis->clearLastError();

                throw new RuntimeException(
                    "RsxCache::{$caller}() could not delete cache keys"
                    . ($redis_error ? " (redis: {$redis_error})" : '')
                );
            }
        } while ($iterator > 0);
    }

    // Build-scoped key: manifest build key + test-run namespace + user key.
    private static function _transform_key_build(string $key): string
    {
        return Manifest::get_build_key() . static::_test_run_suffix() . '_' . $key;
    }

    /**
     * Create a full cache key with build prefix
     *
     * @param string $key User-provided key
     * @return string Full cache key
     */
    private static function _make_key_persistent(string $key): string
    {
        return 'cache:' . static::_test_run_suffix() . sha1($key);
    }

    /**
     * Namespace suffix separating a TEST RUN's cache from the developer's.
     *
     * rsx:test swaps the default DB connection to 'test' for the whole run, and the file
     * subsystem is redirected to a test-scoped root - but Redis used to be SHARED, so any
     * cache write during a test persisted test-database state into the key the next dev
     * request reads (found 2026-08-18: a test run left _type_refs ids from rspade_test in
     * 'type_refs_map', and rsx:health then reported the developer database's registry as a
     * single entry). Keying off the default connection makes the split self-detecting for
     * every build-scoped AND persistent key, in-process and in any subprocess that swaps
     * the connection the same way.
     */
    private static function _test_run_suffix(): string
    {
        return config('database.default') === 'test' ? '_test' : '';
    }

    /**
     * Request-scoped cache - stores value in static property for request duration
     *
     * Simplest caching - no Redis, no locks, just memory. Perfect for expensive
     * calculations that might be called multiple times in a single request.
     *
     * @param string $key Cache key (request-scoped)
     * @param callable $callback Callback to generate value if not cached
     * @return mixed Cached or generated value
     */
    public static function once(string $key, callable $callback)
    {
        if (array_key_exists($key, self::$_once_cache)) {
            return self::$_once_cache[$key];
        }

        $value = $callback();
        self::$_once_cache[$key] = $value;

        return $value;
    }

    /**
     * Build-scoped cache with advisory locking
     *
     * Caches value in Redis with build key prefix. Uses advisory write lock
     * during cache building to prevent stampede (multiple processes building
     * same cache simultaneously). Cache survives until manifest rebuild.
     *
     * @param string $key Cache key (build-scoped)
     * @param callable $callback Callback to generate value if not cached
     * @param int|null $seconds Expiration in seconds (null = never expire)
     * @return mixed Cached or generated value
     */
    public static function remember(string $key, callable $callback, ?int $seconds = null)
    {
        self::_init();

        // Check cache first (fast path - no lock needed)
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        // Cache miss - acquire write lock to build cache
        // This prevents multiple processes from building the same cache
        // SYSTEM lock: the cache is per-box, so another server building the same value is
        // duplicated work rather than corruption. Waits forever - the callback owns the
        // duration, and waiting for it is exactly what prevents the stampede.
        $lock_token = RsxLocks::system_lock('cache_build:' . $key);

        try {
            // Check cache again after acquiring lock
            // Another process may have built it while we were waiting
            $value = self::get($key);
            if ($value !== null) {
                return $value;
            }

            // Build the cache
            $value = $callback();

            // Store in cache
            $expiration = $seconds ?? self::NO_EXPIRATION;
            self::set($key, $value, $expiration);

            return $value;
        } finally {
            RsxLocks::release_lock($lock_token);
        }
    }
}
