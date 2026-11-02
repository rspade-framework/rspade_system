<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Redis;
use RuntimeException;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Framework\Framework_Maintenance;

/**
 * Companion_Store - the Redis database companion applications live in (DB 4; the database map
 * is in the RsxCache header), read by PHP and owned by rsx-companiond.
 *
 * Every key is 'rsx_companion:<Rsx_Connection_Scope::token()>:...', so environments sharing a
 * Redis never mix. The daemon recomputes the token from .env, because it cannot call PHP; the
 * key shapes and the instance record's fields are the wire contract (rsx:man companion_apps),
 * pinned by Companion_Wire_Contract_Test.
 *
 * An instance record has no TTL and is not derived: PHP reads it to decide whether a request's
 * instance is alive, and only the daemon writes or removes it.
 */
class Companion_Store
{
    /**
     * The Redis database. The daemon names the same number.
     */
    public const REDIS_DB = 4;

    /** This class's own connection, selected to REDIS_DB. */
    private static ?Redis $__redis = null;

    /**
     * The prefix of every key of this database scope.
     */
    public static function prefix(): string
    {
        return 'rsx_companion:' . Rsx_Connection_Scope::token() . ':';
    }

    /**
     * The instance record's key.
     */
    public static function instance_key(string $instance_id): string
    {
        return static::prefix() . 'instance:' . $instance_id;
    }

    /**
     * The request counters of one companion for one UTC minute (YYYYmmddHHii).
     */
    public static function request_stats_key(string $name, string $minute): string
    {
        return static::prefix() . 'stats:' . $name . ':' . $minute;
    }

    /** The set of instance ids with a live record, every host. */
    public static function live_key(): string
    {
        return static::prefix() . 'live';
    }

    /** The set of hosts that have written a summary. */
    public static function hosts_key(): string
    {
        return static::prefix() . 'hosts';
    }

    /** One host's summaries: a field per companion, and SUMMARY_DAEMON_FIELD for the daemon. */
    public static function summary_key(string $host): string
    {
        return static::prefix() . 'summary:' . $host;
    }

    /** One companion's ended instances, newest first. */
    public static function history_key(string $name): string
    {
        return static::prefix() . 'history:' . $name;
    }

    /** One companion's capped output stream. */
    public static function output_key(string $name): string
    {
        return static::prefix() . 'output:' . $name;
    }

    /** The summary hash's field for the daemon itself. */
    public const SUMMARY_DAEMON_FIELD = '_daemon';

    /**
     * Every host's summaries: host => {daemon: array|null, companions: name => summary}.
     * Null in the maintenance window.
     *
     * @throws RuntimeException when Redis cannot be reached
     */
    public static function read_summaries(): ?array
    {
        $redis = static::connection();

        if ($redis === null) {
            return null;
        }

        $hosts = $redis->sMembers(static::hosts_key()) ?: [];
        sort($hosts, SORT_STRING);

        $answer = [];
        foreach ($hosts as $host) {
            $fields = $redis->hGetAll(static::summary_key($host)) ?: [];

            $daemon = null;
            $companions = [];
            foreach ($fields as $field => $json) {
                $decoded = json_decode((string) $json, true);
                if (!is_array($decoded)) {
                    continue;
                }
                if ($field === self::SUMMARY_DAEMON_FIELD) {
                    $daemon = $decoded;
                } else {
                    $companions[$field] = $decoded;
                }
            }

            $answer[$host] = ['daemon' => $daemon, 'companions' => $companions];
        }

        return $answer;
    }

    /**
     * The live instance records of one companion, every host. Empty in the maintenance window.
     *
     * @return array<int, array<string, string>>
     */
    public static function read_live_instances(string $name): array
    {
        $redis = static::connection();

        if ($redis === null) {
            return [];
        }

        $records = [];
        foreach ($redis->sMembers(static::live_key()) ?: [] as $instance_id) {
            $record = $redis->hGetAll(static::instance_key($instance_id));
            if (!empty($record) && ($record['name'] ?? null) === $name) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The newest $count ended instances of one companion, newest first. Evictable: the log
     * file is the authority.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function read_history(string $name, int $count): array
    {
        $redis = static::connection();

        if ($redis === null || $count < 1) {
            return [];
        }

        $rows = [];
        foreach ($redis->lRange(static::history_key($name), 0, $count - 1) ?: [] as $json) {
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * At most $count output entries after the stream id $after (exclusive; null = from the
     * oldest kept), with what the stream says about itself:
     *
     *   entries         [[id, stream, line, instance, at], ...] in order
     *   first_id        the oldest id the stream still holds, null when it is empty or absent
     *   dropped         entries the cap has trimmed away since the stream began (entries added
     *                   minus entries held)
     *
     * @throws RuntimeException when Redis cannot be reached
     */
    public static function read_output(string $name, ?string $after, int $count): ?array
    {
        $redis = static::connection();

        if ($redis === null) {
            return null;
        }

        $key = static::output_key($name);

        if (!$redis->exists($key)) {
            return ['entries' => [], 'first_id' => null, 'dropped' => 0];
        }

        $info = $redis->xInfo('STREAM', $key);
        $first = is_array($info['first-entry'] ?? null) ? array_key_first($info['first-entry']) : null;
        $dropped = max(0, (int) ($info['entries-added'] ?? 0) - (int) ($info['length'] ?? 0));

        $entries = [];
        foreach ($redis->xRange($key, $after === null ? '-' : '(' . $after, '+', $count) ?: [] as $id => $fields) {
            $entries[] = [
                'id' => (string) $id,
                'stream' => (string) ($fields['stream'] ?? 'stdout'),
                'line' => (string) ($fields['line'] ?? ''),
                'instance' => (string) ($fields['instance'] ?? ''),
                'at' => (string) ($fields['at'] ?? ''),
            ];
        }

        return ['entries' => $entries, 'first_id' => $first === null ? null : (string) $first, 'dropped' => $dropped];
    }

    /**
     * One companion's request counters for the last $minutes UTC minutes, summed per endpoint:
     * endpoint => {calls, errors, ms}. Counters are evictable; a lost minute is a gap.
     *
     * @return array<string, array{calls: int, errors: int, ms: int}>
     */
    public static function read_request_stats(string $name, int $minutes): array
    {
        $redis = static::connection();

        if ($redis === null || $minutes < 1) {
            return [];
        }

        $now = time();
        $pipe = $redis->pipeline();
        for ($i = 0; $i < $minutes; $i++) {
            $pipe->hGetAll(static::request_stats_key($name, gmdate('YmdHi', $now - $i * 60)));
        }
        $hashes = $pipe->exec() ?: [];

        $totals = [];
        foreach ($hashes as $hash) {
            foreach (is_array($hash) ? $hash : [] as $field => $value) {
                $split = strrpos($field, ':');
                if ($split === false) {
                    continue;
                }
                $endpoint = substr($field, 0, $split);
                $measure = substr($field, $split + 1);
                if (!in_array($measure, ['calls', 'errors', 'ms'], true)) {
                    continue;
                }
                $totals[$endpoint] ??= ['calls' => 0, 'errors' => 0, 'ms' => 0];
                $totals[$endpoint][$measure] += (int) $value;
            }
        }

        ksort($totals, SORT_STRING);

        return $totals;
    }

    /**
     * One instance's record, or null when Redis holds none.
     *
     * @throws RuntimeException when Redis cannot be reached: "no record" and "cannot tell" are
     *                          different answers, and only the first means the instance is dead.
     */
    public static function read_instance(string $instance_id): ?array
    {
        $redis = static::connection();

        if ($redis === null) {
            throw new RuntimeException('Companion instance records are unavailable: maintenance mode has stopped redis');
        }

        $record = $redis->hGetAll(static::instance_key($instance_id));

        return empty($record) ? null : $record;
    }

    /**
     * This class's own connection, selected to REDIS_DB - a second connection rather than a
     * select() on the cache's, so no cache key can be written into this database. The
     * connection rules are RsxCache's (REDIS_SOCKET, else REDIS_HOST/REDIS_PORT). Null in the
     * maintenance window, when Redis is stopped.
     */
    public static function connection(): ?Redis
    {
        if (Framework_Maintenance::is_active()) {
            return null;
        }

        if (self::$__redis !== null) {
            return self::$__redis;
        }

        $redis = new Redis();
        $socket = env('REDIS_SOCKET', null);
        $connected = ($socket && file_exists($socket))
            ? $redis->connect($socket)
            : $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));

        if (!$connected) {
            throw new RuntimeException('Failed to connect to Redis for companion applications');
        }

        $redis->select(self::REDIS_DB);
        self::$__redis = $redis;

        return $redis;
    }
}
