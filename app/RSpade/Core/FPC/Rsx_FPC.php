<?php

namespace App\RSpade\Core\FPC;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Full Page Cache management utility
 *
 * Provides methods to clear FPC entries from Redis.
 * The FPC is a Node.js reverse proxy that caches responses
 * marked with #[FPC] attribute in Redis.
 *
 * THE FPC IS ALWAYS AVAILABLE. There is no master switch and nothing to enable: a route
 * is cached because a developer wrote #[FPC] on it, and a route without the attribute is
 * never cached. Caching is an application behaviour, decided in the code that knows
 * whether a page is safe to serve twice - not a deployment setting somebody has to
 * remember to turn on for the feature to work, or off for it to stop.
 *
 * Cache key format: fpc:{build_key}:{sha1(url)}
 * Redis DB: 2, the reduced-volatility cache. The authority on the database map is the
 * RsxCache class header; the Node proxy (system/bin/fpc-proxy.js) names the same number
 * in its own constant. Database 0 is flushed on every database transaction rollback, which
 * would throw away a page cache for reasons that have nothing to do with the page.
 *
 * WHAT CLEARS AN ENTRY, and there is nothing else:
 *   - php artisan rsx:fpc:clear [--url=/path]  (this class's clear()/clear_url())
 *   - php artisan rsx:clean, which empties Redis DB 2 whole through
 *     RsxCache::clear_reduced_volatility() - so does cache:clear, which delegates to it
 *   - a build-key rotation, which orphans every fpc:{old_key}:* entry at once
 *   - the entry's own TTL, when its #[FPC(ttl: N)] declared one
 */
class Rsx_FPC
{
    /**
     * Redis database holding FPC entries - the reduced-volatility cache. Must match
     * FPC_REDIS_DB in system/bin/fpc-proxy.js; the RsxCache class header is the authority
     * on the database map.
     */
    private const REDIS_DB = 2;

    /**
     * The response header the PHP side marks a cacheable response with, and which the
     * Node proxy keys its Redis write on. ONE channel: its VALUE carries the TTL, so a
     * per-route lifetime needs no second header and no environment value.
     */
    public const MARKER_HEADER = 'X-RSpade-FPC';

    /** The marker value meaning "cache this until something clears it". */
    public const MARKER_NO_EXPIRY = 'none';

    /**
     * The marker value for a route whose #[FPC] declared $ttl_minutes.
     *
     * SECONDS on the wire, because that is what the proxy hands Redis; MINUTES in the
     * attribute, because that is the unit a developer thinks in. Zero (the default, and
     * what a bare #[FPC] means) is the word 'none' rather than the number 0 - a number
     * that means "forever" is the kind of thing that reads as "immediately" to whoever
     * meets it next, on the wire or in a log.
     */
    public static function marker_value(int $ttl_minutes): string
    {
        return $ttl_minutes > 0 ? (string) ($ttl_minutes * 60) : self::MARKER_NO_EXPIRY;
    }

    /**
     * Clear all FPC cache entries for the current build key
     *
     * @return int Number of deleted entries
     */
    public static function clear(): int
    {
        $redis = self::_get_redis();

        $build_key = Manifest::get_build_key();
        $pattern = "fpc:{$build_key}:*";
        $count = 0;

        // Use SCAN to avoid blocking Redis with KEYS
        $iterator = null;
        do {
            $keys = $redis->scan($iterator, $pattern, 100);
            if ($keys !== false && count($keys) > 0) {
                $count += $redis->del($keys);
            }
        } while ($iterator > 0);

        return $count;
    }

    /**
     * Clear FPC cache for a specific URL
     *
     * @param string $url The URL path with optional query string (e.g., '/about' or '/search?q=test')
     * @return bool True if an entry was deleted
     */
    public static function clear_url(string $url): bool
    {
        $redis = self::_get_redis();

        $build_key = Manifest::get_build_key();

        // Parse and sort query params to match the proxy's key generation
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;

        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            ksort($params);
            $full_url = $path . '?' . http_build_query($params);
        } else {
            $full_url = $path;
        }

        $hash = sha1($full_url);
        $key = "fpc:{$build_key}:{$hash}";

        return $redis->del($key) > 0;
    }

    /**
     * rsx:health probe: FPC subsystem status. A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class).
     *
     * The subsystem is always available, so there is no disabled branch to report: this
     * verifies Redis reachability + auth (via the fail-loud _get_redis path, whose throw
     * is caught here and reported as a FAIL row rather than crashing the runner) and a
     * PING, plus an advisory liveness probe of the Node proxy port.
     *
     * @return array
     */
    #[Health_Check('FPC')]
    public static function fpc_health(): array
    {
        $rows = [];

        // Redis reachability + auth. _get_redis() fails loud on any connect/auth
        // failure; catch it here so a broken Redis reports FAIL, not a crash.
        try {
            $redis = self::_get_redis();
            $pong = $redis->ping();
            $ok = ($pong === true || $pong === 'PONG' || $pong === '+PONG');
            $rows[] = $ok
                ? ['status' => 'OK', 'detail' => 'Redis reachable (DB ' . self::REDIS_DB . '), auth OK']
                : ['status' => 'FAIL', 'detail' => 'Redis PING returned an unexpected value', 'remediation' => 'check Redis health and REDIS_PASSWORD'];
        } catch (\Throwable $e) {
            $rows[] = [
                'status' => 'FAIL',
                'detail' => 'Redis unreachable: ' . $e->getMessage(),
                'remediation' => 'verify REDIS_HOST/REDIS_PORT/REDIS_PASSWORD and that redis-server is running',
            ];
        }

        // Advisory: is the Node proxy listening? A down proxy is a WARN (nginx
        // falls back to PHP), so it never fails the health run on its own.
        $port = (int) config('rsx.fpc.proxy_port', 3200);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);

        if ($socket === false) {
            $rows[] = [
                'label' => 'FPC Proxy',
                'status' => 'WARN',
                'detail' => 'proxy not listening on 127.0.0.1:' . $port . ' (' . trim($errstr) . ')',
                'remediation' => 'start the FPC proxy - check supervisor [program:fpc-proxy] (node system/bin/fpc-proxy.js)',
            ];
        } else {
            fclose($socket);
            $rows[] = ['label' => 'FPC Proxy', 'status' => 'OK', 'detail' => 'proxy listening on 127.0.0.1:' . $port];
        }

        return $rows;
    }

    /**
     * Get Redis connection for FPC operations (DB 2 - see REDIS_DB).
     *
     * Fails loud on any connect/auth/select failure - a developer-invoked purge
     * that cannot reach Redis MUST surface, never silently report "nothing to
     * clear" (which would let stale pages serve forever). A failed handle is never
     * cached; only a healthy connection is memoized.
     */
    private static function _get_redis(): \Redis
    {
        static $redis = null;

        if ($redis instanceof \Redis) {
            return $redis;
        }

        $connection = new \Redis();
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD');

        try {
            if (!$connection->connect($host, $port, 2.0)) {
                throw new \RuntimeException('connect() returned false');
            }

            // Authenticate only when a real password is configured. The literal
            // string 'null' means "no password" (mirrors the Node proxy's
            // REDIS_PASSWORD handling in system/bin/fpc-proxy.js).
            if ($password && $password !== 'null') {
                $connection->auth($password);
            }

            $connection->select(self::REDIS_DB);
        } catch (\Throwable $e) {
            shouldnt_happen("FPC: cannot reach Redis for cache purge at {$host}:{$port} ({$e->getMessage()})");
        }

        $redis = $connection;

        return $redis;
    }
}
