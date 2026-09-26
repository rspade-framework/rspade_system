<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use RuntimeException;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Task_Worker_Registry - Redis-backed registry of live task workers.
 *
 * The task worker pool is capped at rsx.tasks.global_max_workers. This registry is
 * the authoritative admission gate: each worker atomically claims a slot on startup
 * (admit()) and self-declines if the pool is full. Slots are a Redis ZSET member per
 * live worker, scored by last-heartbeat epoch; a stale (crashed) slot is pruned once
 * its score falls below now - rsx.tasks.worker_heartbeat_ttl. This replaces the old
 * "count _tasks rows in status=running" gate, which mis-counted between tasks and
 * wedged on SIGKILLed workers until the 30-minute stuck sweep.
 *
 * SPAWNING IS ADMITTED TOO, BEFORE THE PROCESS EXISTS. A spawner (Task::spawn_worker())
 * first RESERVES a slot - reserve_spawn(), one Lua script counting live slots PLUS
 * outstanding reservations against the cap - and spawns only when that succeeded, handing the
 * reservation token to the child (--_task-reservation=<token>). The child's admit() CONVERTS
 * the reservation into its live slot, so a reserved spawn is never declined. N simultaneous
 * dispatches therefore start at most (cap - occupied) processes and the rest start nothing -
 * no PHP boot that exists only to discover the pool is full.
 *
 * A reservation carries NO TTL. It is released deterministically by exactly one of:
 *   - the child converting it in admit() (the ordinary end);
 *   - the spawner, when the spawn itself failed (release_reservation());
 *   - reclaim_orphaned_reservations(), run by every rsx:task:process tick, when the process
 *     it names is gone: a reservation names its owner as host:pid - the spawner while it
 *     spawns, the child once hand_off_reservation() has recorded the child's pid - and a
 *     reservation whose owner no longer runs on this host can never be converted.
 * A child whose reservation was reclaimed before it started simply admits the ordinary way.
 *
 * THE REGISTRY IS NOT THE ONLY COUNT. A Redis flush (FLUSHALL, FLUSHDB, eviction, a reset
 * script) empties the live set and the reservations while the workers they described keep
 * running; the registry then reads an empty pool and admits spawns the cap no longer
 * protects - a downstream field report (2026-09-26) measured 1,532 concurrent workers from
 * one bulk-writing script after exactly that. So the spawner also consults two counts that
 * live OUTSIDE Redis and cannot be flushed (Task::spawn_worker()): the workers its own
 * process spawned that are still running (is_worker_process()), and the workers running on
 * this host (host_worker_count(), one /proc scan). Each is a lower bound on the true pool
 * size; the pool is full when ANY of the three says so.
 *
 * Redis owns ONLY this ephemeral worker-slot state. The durable queue (_tasks), the
 * atomic dequeue lock, and per-identity run-locks stay in MySQL. Losing just this registry
 * key is safe: it self-heals (stale slots expire, admission recomputes from scratch). Redis
 * itself is a hard framework dependency (cache + manifest), so a full Redis outage fails the
 * process loudly at bootstrap; admit()/live_count() throwing is a fatal error.
 */
class Task_Worker_Registry
{
    /** Base of the Redis ZSET holding one member per live worker (score = last heartbeat
     *  epoch). The live key is per-(database,host)-scoped - see _zset_key(). */
    private const ZSET_KEY_BASE = 'rsx:tasks:workers';

    /**
     * Lock database - shared numbering with RsxLocks; keys are namespaced. The eviction
     * policy is per redis INSTANCE, not per database (allkeys-lru in the shipped conf), so
     * this database is as evictable as any other; see the RsxCache class header and B-105.
     */
    private static int $redis_db = 1;

    private static ?\Redis $redis = null;

    /** Suffix of the HASH of outstanding spawn reservations (token => owner host:pid), beside
     *  the live ZSET under the same scope. */
    private const RESERVATIONS_SUFFIX = ':reserved';

    /**
     * The internal flag a spawner hands its child the reservation token on. `--_` convention:
     * the only caller is Task::spawn_worker(), so it is lifted pre-boot and never an option.
     */
    public const RESERVATION_FLAG = '--_task-reservation';

    /** This process's worker id, set by admit(). Null until/unless this process is an admitted worker. */
    private static ?string $worker_id = null;

    /** Guard so the shutdown deregister handler is only registered once. */
    private static bool $shutdown_registered = false;

    /**
     * Atomically claim a worker slot. Prunes stale slots, then admits - all in one Lua script,
     * so N simultaneous starters admit EXACTLY the cap and the rest decline:
     *
     *   - with a $reservation this process was spawned under (reserve_spawn()) that is still
     *     outstanding, the reservation BECOMES the slot: no cap check, because the slot was
     *     counted when it was reserved;
     *   - otherwise (a hand-run worker, or a reservation already reclaimed) iff live slots
     *     plus outstanding reservations are under rsx.tasks.global_max_workers.
     *
     * On success the slot id is stored on this process and a shutdown handler is registered
     * to release it.
     *
     * @param string|null $reservation The token this worker was spawned with, if any
     * @return bool True if admitted (proceed to work), false if the pool is full (exit).
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function admit(?string $reservation = null): bool
    {
        $redis = self::_redis();

        $now = time();
        $ttl = self::_ttl();
        $worker_id = self::_new_worker_id();

        $lua = <<<'LUA'
            redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[2])
            local converted = 0
            if ARGV[6] ~= '' then
                converted = redis.call('HDEL', KEYS[2], ARGV[6])
            end
            if converted == 0 and redis.call('ZCARD', KEYS[1]) + redis.call('HLEN', KEYS[2]) >= tonumber(ARGV[3]) then
                return 0
            end
            redis.call('ZADD', KEYS[1], ARGV[1], ARGV[4])
            redis.call('EXPIRE', KEYS[1], ARGV[5])
            return 1
LUA;

        $admitted = (int) $redis->eval(
            $lua,
            [
                self::_zset_key(),
                self::_reservations_key(),
                $now,
                $now - $ttl,
                self::max_workers(),
                $worker_id,
                $ttl * 4,
                (string) $reservation,
            ],
            2
        );

        if ($admitted !== 1) {
            return false;
        }

        self::$worker_id = $worker_id;

        if (!self::$shutdown_registered) {
            register_shutdown_function([self::class, 'deregister']);
            self::$shutdown_registered = true;
        }

        return true;
    }

    /**
     * Reserve a slot for a worker about to be spawned. Atomic with every other admission: one
     * Lua script prunes stale slots and reserves iff live slots plus outstanding reservations
     * are under the cap. The reservation is owned by THIS process until
     * hand_off_reservation() names the child.
     *
     * @return string|null The reservation token to hand the child, or null when the pool is
     *                     full (spawn nothing).
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function reserve_spawn(): ?string
    {
        $token = random_hash(16);

        $lua = <<<'LUA'
            redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
            if redis.call('ZCARD', KEYS[1]) + redis.call('HLEN', KEYS[2]) >= tonumber(ARGV[2]) then
                return 0
            end
            redis.call('HSET', KEYS[2], ARGV[3], ARGV[4])
            return 1
LUA;

        $reserved = (int) self::_redis()->eval(
            $lua,
            [
                self::_zset_key(),
                self::_reservations_key(),
                time() - self::_ttl(),
                self::max_workers(),
                $token,
                self::_owner(getmypid()),
            ],
            2
        );

        return $reserved === 1 ? $token : null;
    }

    /**
     * Record the spawned child as the reservation's owner, so the reaper judges the
     * reservation by the CHILD's liveness from here on. A no-op when the child has already
     * converted it (a fast child can admit before its spawner gets here).
     *
     * @param string $token
     * @param int $child_pid
     * @return void
     */
    public static function hand_off_reservation(string $token, int $child_pid): void
    {
        $lua = <<<'LUA'
            if redis.call('HEXISTS', KEYS[1], ARGV[1]) == 1 then
                redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
            end
            return 1
LUA;

        self::_redis()->eval($lua, [self::_reservations_key(), $token, self::_owner($child_pid)], 1);
    }

    /**
     * Give a reservation back - the spawner's answer to a spawn that produced no process.
     *
     * @param string $token
     * @return void
     */
    public static function release_reservation(string $token): void
    {
        self::_redis()->hDel(self::_reservations_key(), $token);
    }

    /**
     * Release every reservation whose owner process is gone from THIS host - a spawner that
     * died between reserving and spawning, or a child that died before it converted. The
     * reaper half of the reservation lifecycle; rsx:task:process runs it every tick.
     *
     * Only this host's reservations are judged: a pid means nothing on another machine. Each
     * release is compare-and-delete, so a reservation handed off to a new owner between the
     * read and the release is left alone.
     *
     * @return int How many reservations were reclaimed.
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function reclaim_orphaned_reservations(): int
    {
        $redis = self::_redis();
        $host = (string) gethostname();

        $lua = <<<'LUA'
            if redis.call('HGET', KEYS[1], ARGV[1]) == ARGV[2] then
                return redis.call('HDEL', KEYS[1], ARGV[1])
            end
            return 0
LUA;

        $reclaimed = 0;
        foreach ($redis->hGetAll(self::_reservations_key()) as $token => $owner) {
            $separator = strrpos((string) $owner, ':');
            if ($separator === false) {
                shouldnt_happen("Task worker reservation {$token} has an owner that is not host:pid: {$owner}");
            }

            $owner_host = substr($owner, 0, $separator);
            $owner_pid = (int) substr($owner, $separator + 1);

            if ($owner_host !== $host || self::_pid_is_running($owner_pid)) {
                continue;
            }

            $reclaimed += (int) $redis->eval($lua, [self::_reservations_key(), $token, $owner], 1);
        }

        return $reclaimed;
    }

    /**
     * Outstanding spawn reservations (spawned or spawning workers that have not admitted yet).
     *
     * @return int
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function reserved_count(): int
    {
        return (int) self::_redis()->hLen(self::_reservations_key());
    }

    /**
     * Refresh this worker's slot (keep it alive) and return the current live count.
     *
     * Called at the top of each worker loop iteration and from Task_Instance::heartbeat()
     * so a long-running task keeps its slot alive across a run longer than the TTL. A
     * no-op (returns 0) when this process holds no slot or Redis is unreachable - losing
     * the slot is safe, so heartbeat never throws.
     *
     * @return int Live worker count, or 0 if not applicable.
     */
    public static function heartbeat(): int
    {
        if (self::$worker_id === null) {
            return 0;
        }

        try {
            $redis = self::_redis();
            $now = time();
            $ttl = self::_ttl();

            $lua = <<<'LUA'
                redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[2])
                redis.call('ZADD', KEYS[1], ARGV[1], ARGV[3])
                redis.call('EXPIRE', KEYS[1], ARGV[4])
                return redis.call('ZCARD', KEYS[1])
LUA;

            return (int) $redis->eval(
                $lua,
                [self::_zset_key(), $now, $now - $ttl, self::$worker_id, $ttl * 4],
                1
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Live worker count after pruning stale slots (outstanding spawn reservations are NOT
     * included - see reserved_count()).
     *
     * @return int
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function live_count(): int
    {
        $redis = self::_redis();

        $lua = <<<'LUA'
            redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
            return redis.call('ZCARD', KEYS[1])
LUA;

        return (int) $redis->eval($lua, [self::_zset_key(), time() - self::_ttl()], 1);
    }

    /**
     * Is this pid a running task worker of THIS project? Read from /proc/<pid>/cmdline, so it
     * needs no Redis and survives a flush of the registry.
     *
     * A worker is a process whose command line names both this project's artisan path and
     * the rsx:task:worker command - the command line Rsx_Artisan::dispatch_detached() builds.
     * The match is on the command-line TEXT, not on exact argv tokens, so a worker is
     * recognised from the moment it is forked: until its exec the child is a copy of the
     * spawning bash, whose single `-c` argument carries that same text. And the command line,
     * rather than bare pid existence, is what makes the answer safe to act on: a pid the
     * kernel reused for an unrelated process is not a worker, and an exited worker nobody has
     * reaped yet (a zombie) has an empty command line.
     *
     * @param int $pid
     * @return bool
     */
    public static function is_worker_process(int $pid): bool
    {
        $cmdline = self::_process_cmdline($pid);

        return $cmdline !== null && self::_is_this_projects_worker($cmdline);
    }

    /**
     * How many task workers of this pool are running on THIS host - one pass over /proc, no
     * shell, no Redis. The flush-proof floor under the registry: Task::spawn_worker() refuses
     * to spawn when this alone reaches the cap, so an emptied registry never means an
     * unlimited pool on the host doing the spawning.
     *
     * "This pool" is this project's workers (is_worker_process()) on the same side of the
     * test-suite boundary as the caller: a worker started under rsx:test carries
     * --_test-run and works against the test database, so it is counted by a test process
     * and not by the development box's own dispatches, and the reverse. A worker started by
     * hand from the project root (`php artisan rsx:task:worker`, a relative artisan path) is
     * not recognised; it is still admitted by the registry like any other. The count errs
     * high, never low: the brief handshake shell of a spawn in progress carries the same text
     * and is counted while it lives.
     *
     * @return int
     */
    public static function host_worker_count(): int
    {
        $under_test = Rsx_Test_Abstract::suite_is_running();
        $count = 0;

        foreach (scandir('/proc') as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }

            $cmdline = self::_process_cmdline((int) $entry);
            if ($cmdline === null || !self::_is_this_projects_worker($cmdline)) {
                continue;
            }

            if (str_contains($cmdline, Rsx_Test_Abstract::TEST_RUN_FLAG) !== $under_test) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * rsx.tasks.global_max_workers, never below one.
     *
     * @return int
     */
    public static function max_workers(): int
    {
        return max(1, (int) config('rsx.tasks.global_max_workers', 1));
    }

    /**
     * Release this process's worker slot. Called on graceful exit and as a shutdown
     * handler (covers a fatal that is not a SIGKILL); SIGKILL is covered by TTL expiry.
     * Best-effort and idempotent.
     */
    public static function deregister(): void
    {
        if (self::$worker_id === null) {
            return;
        }

        $worker_id = self::$worker_id;
        self::$worker_id = null;

        try {
            self::_redis()->zRem(self::_zset_key(), $worker_id);
        } catch (\Throwable $e) {
            // Slot will expire via TTL; nothing else to do.
        }
    }

    /**
     * This process's worker id, or null if it is not an admitted worker.
     */
    public static function worker_id(): ?string
    {
        return self::$worker_id;
    }

    /**
     * rsx:health probe: is Redis reachable? Redis backs locks, realtime, and the task
     * worker-slot registry - a hard framework dependency. A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class) that
     * exercises the same connection admit()/live_count() use.
     *
     * @return array
     */
    #[Health_Check('Redis Connectivity')]
    public static function redis_connectivity(): array
    {
        try {
            $live = self::live_count();
        } catch (\Throwable $e) {
            return [
                'status' => 'FAIL',
                'detail' => 'cannot reach Redis: ' . $e->getMessage(),
                'remediation' => 'check REDIS_* in .env and that redis-server is running',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'connected; ' . $live . ' live worker(s), ' . self::reserved_count() . ' spawn reservation(s), '
                . self::host_worker_count() . ' worker process(es) running on this host',
        ];
    }

    // =========================================================================
    // internals
    // =========================================================================

    /**
     * The live ZSET key, namespaced per (database, host).
     *
     * The worker pool counts workers that operate on ONE database; two environments sharing
     * this box's Redis (the parallel test runner's per-worker databases the motivating case)
     * must not admit into each other's pool or the exact-count admission gate collides. The
     * scope is the SAME token RsxLocks uses (Rsx_Connection_Scope), so a worker and the test
     * process that spawns it - both on the same database connection - compute the same key.
     * In production every cluster node shares one database, so the token is a constant and
     * the pool coordinates exactly as before.
     *
     * @return string
     */
    private static function _zset_key(): string
    {
        return self::ZSET_KEY_BASE . ':' . Rsx_Connection_Scope::token();
    }

    /**
     * The spawn-reservation HASH, under the same (database, host) scope as the live ZSET.
     *
     * @return string
     */
    private static function _reservations_key(): string
    {
        return self::_zset_key() . self::RESERVATIONS_SUFFIX;
    }

    /**
     * A reservation's owner value: this host plus a pid on it.
     *
     * @param int $pid
     * @return string
     */
    private static function _owner(int $pid): string
    {
        return gethostname() . ':' . $pid;
    }

    /**
     * Does a process with this pid exist on this host? /proc is the whole answer on the
     * Linux hosts the framework runs on, and it needs no permission over the process (a
     * posix_kill(0) probe of another user's worker would answer EPERM).
     *
     * @param int $pid
     * @return bool
     */
    private static function _pid_is_running(int $pid): bool
    {
        return $pid > 0 && file_exists('/proc/' . $pid);
    }

    /**
     * A process's command line from /proc/<pid>/cmdline (argv, NUL-separated), or null when
     * there is none to read.
     *
     * A process can exit between the /proc listing and this read, and that race IS the
     * answer "not running" - so a failed read is suppressed and means gone. An empty command
     * line (a zombie, a kernel thread) is null too.
     *
     * @param int $pid
     * @return string|null
     */
    private static function _process_cmdline(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }

        $raw = @file_get_contents('/proc/' . $pid . '/cmdline');

        return ($raw === false || $raw === '') ? null : $raw;
    }

    /**
     * Does this command line run the task worker through THIS project's artisan?
     *
     * @param string $cmdline
     * @return bool
     */
    private static function _is_this_projects_worker(string $cmdline): bool
    {
        return str_contains($cmdline, 'rsx:task:worker') && str_contains($cmdline, base_path('artisan'));
    }

    private static function _ttl(): int
    {
        return max(1, (int) config('rsx.tasks.worker_heartbeat_ttl', 90));
    }

    /**
     * A unique slot id for this worker process.
     */
    private static function _new_worker_id(): string
    {
        return random_hash(8) . ':' . gethostname() . ':' . getmypid();
    }

    /**
     * Connect to Redis (the no-eviction lock DB). Independent of the cache/manifest so
     * it is usable from bare CLI workers.
     *
     * @throws RuntimeException If phpredis is missing or the connection fails.
     */
    private static function _redis(): \Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        if (!class_exists('\Redis')) {
            throw new RuntimeException('phpredis extension not available for task worker registry');
        }

        $redis = new \Redis();

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $socket = env('REDIS_SOCKET', null);

        $connected = ($socket && file_exists($socket))
            ? $redis->connect($socket)
            : $redis->connect($host, $port, 2.0);

        if (!$connected) {
            throw new RuntimeException('Failed to connect to Redis for task worker registry');
        }

        $redis->select(self::$redis_db);
        self::$redis = $redis;

        return self::$redis;
    }
}
