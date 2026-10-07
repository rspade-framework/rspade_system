<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use RuntimeException;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Locks\Lockd_Connection;

/**
 * The task worker pools, as accounted by rsx-lockd (the `pool.*` ops, system/bin/rsx-lockd
 * README "Worker pools"). The daemon is the ONE party that knows how many workers exist:
 * membership belongs to a connection, so a worker that exits, crashes or is `kill -9`'d
 * stops being a member the moment its socket closes, with no heartbeat, lease or reaper.
 *
 * ONE POOL CONNECTION PER PROCESS, independent of RsxLocks in every respect:
 *
 *   - its own socket (a Lockd_Connection of its own), opened on the first call and held for
 *     the life of the process - the worker's liveness IS that socket;
 *   - its hello names NO lock group, so nothing a process tree shares through
 *     `--_lock-group` reaches the pool, and nothing about the pool reaches RsxLocks;
 *   - it is NEVER sent `release_all` and registers no shutdown hook: the socket closing at
 *     process exit is what ends the membership and frees the pool lock.
 *
 * EVERY CALL IS ACKNOWLEDGED. Each method sends one request and returns only once the daemon
 * has answered it, so a process never sends a pool op and moves on (or exits) before the
 * accountant has applied it. An `error` answer throws with the daemon's message; a lost
 * connection throws, and the process must treat its membership and pool lock as GONE - the
 * daemon has already released both.
 *
 * THE RULE - while holding the pool lock, a process runs only pool ops and reads/writes of
 * the `_tasks` rows the pool coordinates. It takes no other blocking lock (a non-blocking try
 * is fine), waits on no subprocess and makes no outbound call. Pool waits are invisible to
 * the daemon's deadlock detector, and this rule is what makes that safe.
 *
 * NO TIMEOUT anywhere: lock() waits for as long as the holder ahead of it holds.
 *
 * A MEMBER IS NAMED BY (wid, generation): the integer worker id the daemon assigned at join,
 * and the daemon's generation, a random integer it picks once at startup. A restarted daemon
 * is a new generation, and it answers a question about an older generation's wid with
 * `known: false` - it cannot know whether that worker still runs. The caller settles those with
 * its own evidence: the worker's pid, on the worker's own host (host()).
 *
 * The client-side bookkeeping (holds_lock(), wid(), generation()) follows the daemon's answers
 * and nothing else, so a call site can assert THE RULE's preconditions cheaply.
 *
 * THREE POOLS, each counted and capped on its own (rsx.tasks.pools.<pool>.max_workers):
 *
 *   - on_demand: workers that run dispatched work;
 *   - scheduled: workers that run #[Schedule] work, taking queued on-demand work FIRST;
 *   - kill:      the kill workers (Task_Kill_Worker) that carry out force stops and kills.
 *
 * A process is a member of at most one pool and holds at most one pool's lock at a time.
 */
class Task_Pool
{
    /** The pool that runs dispatched work. */
    const ON_DEMAND = 'on_demand';

    /** The pool that runs #[Schedule] work, and queued on-demand work first. */
    const SCHEDULED = 'scheduled';

    /** The pool of kill workers. */
    const KILL = 'kill';

    /** Every pool, in the order a status screen lists them. */
    const POOLS = [self::ON_DEMAND, self::SCHEDULED, self::KILL];

    /** Prefix of every pool name; the pool and the per-environment scope token follow. */
    private const POOL_PREFIX = 'tasks:';

    private static ?Lockd_Connection $connection = null;

    /** The pool whose lock this process holds, per the daemon's last answer. */
    private static ?string $locked_pool = null;

    /** This process's membership, per the daemon's last answer: ['pool' => ..., 'wid' => int, 'generation' => int]. */
    private static ?array $membership = null;

    /**
     * A pool's daemon-side name: one pool per (pool, database, host) scope - the scope RsxLocks
     * and every other box-wide coordinator uses (Rsx_Connection_Scope) - because a pool counts
     * the workers that operate on one `_tasks` table. Two environments sharing a daemon never
     * share a pool.
     */
    public static function pool_name(string $pool): string
    {
        static::__assert_pool($pool);

        return self::POOL_PREFIX . $pool . ':' . Rsx_Connection_Scope::token();
    }

    /**
     * A pool's cap: rsx.tasks.pools.<pool>.max_workers. Below one is a configuration error and
     * throws - a pool that can hold no worker would leave its work queued forever.
     */
    public static function max_workers(string $pool): int
    {
        static::__assert_pool($pool);

        $value = config("rsx.tasks.pools.{$pool}.max_workers");
        if (!is_numeric($value) || (int) $value != $value || (int) $value < 1) {
            throw new RuntimeException("rsx.tasks.pools.{$pool}.max_workers must be an integer of at least 1, got " . var_export($value, true));
        }

        return (int) $value;
    }

    /** Take a pool's lock (FIFO). Returns once the daemon has granted it; waits forever. */
    public static function lock(string $pool): void
    {
        if (self::$locked_pool !== null) {
            shouldnt_happen("Task_Pool::lock({$pool}) while this process holds the " . self::$locked_pool . ' lock');
        }

        $name = self::pool_name($pool);
        self::__request('pool.lock', ['pool' => $name], 'granted');
        self::$locked_pool = $name;
    }

    /** Release a pool's lock, handing it to the next waiter. */
    public static function unlock(string $pool): void
    {
        self::__request('pool.unlock', ['pool' => self::pool_name($pool)]);
        self::$locked_pool = null;
    }

    /**
     * Join a pool. Requires its lock. Returns the member's identity as the daemon assigned it -
     * store BOTH wherever another process must ask whether this worker is alive; neither is
     * meaningful alone.
     *
     * @return array{wid: int, generation: int}
     */
    public static function join(string $pool): array
    {
        $name = self::pool_name($pool);
        $response = self::__request('pool.join', ['pool' => $name]);

        $wid = $response['wid'] ?? null;
        $generation = $response['generation'] ?? null;
        if (!is_int($wid) || !is_int($generation)) {
            throw new RuntimeException("rsx-lockd answered pool.join on {$name} without an integer wid and generation: " . json_encode($response));
        }

        self::$membership = ['pool' => $name, 'wid' => $wid, 'generation' => $generation];

        return ['wid' => $wid, 'generation' => $generation];
    }

    /** Leave a pool. Requires its lock. */
    public static function leave(string $pool): void
    {
        self::__request('pool.leave', ['pool' => self::pool_name($pool)]);
        self::$membership = null;
    }

    /** A pool's member count, EXCLUDING this process when it is one. Requires the lock. */
    public static function count(string $pool): int
    {
        $response = self::__request('pool.count', ['pool' => self::pool_name($pool)]);

        return (int) $response['members'];
    }

    /**
     * Whether the worker (wid, generation) is still a member of $pool. Requires the lock.
     *
     * `known` is false when the generation is not the daemon's own (it restarted since that
     * worker joined): `alive` is then false and means nothing - settle the question with the
     * worker's pid on its own host. With `known` true, `alive` false means the worker left or
     * its connection is gone.
     *
     * @return array{alive: bool, known: bool}
     */
    public static function member_alive(string $pool, int $wid, int $generation): array
    {
        $response = self::__request('pool.member_alive', [
            'pool' => self::pool_name($pool),
            'wid' => $wid,
            'generation' => $generation,
        ]);

        return ['alive' => (bool) $response['alive'], 'known' => (bool) $response['known']];
    }

    /**
     * member_alive() for many workers of one pool in one round trip. Requires the lock.
     *
     * @param array<int, array{wid: int, generation: int}> $items
     * @return array<int, array{wid: int, generation: int, alive: bool, known: bool}> One result
     *         per item, in the same order and under the same keys.
     */
    public static function members_alive(string $pool, array $items): array
    {
        $keys = array_keys($items);
        $wire = [];
        foreach ($items as $item) {
            $wire[] = ['wid' => (int) $item['wid'], 'generation' => (int) $item['generation']];
        }

        $response = self::__request('pool.members_alive', [
            'pool' => self::pool_name($pool),
            'items' => $wire,
        ]);

        $results = $response['results'] ?? null;
        if (!is_array($results) || count($results) !== count($wire)) {
            throw new RuntimeException('rsx-lockd answered pool.members_alive with ' . json_encode($results) . ' for ' . count($wire) . ' item(s)');
        }

        $answer = [];
        foreach ($results as $i => $result) {
            $answer[$keys[$i]] = [
                'wid' => (int) $result['wid'],
                'generation' => (int) $result['generation'],
                'alive' => (bool) $result['alive'],
                'known' => (bool) $result['known'],
            ];
        }

        return $answer;
    }

    /**
     * Read-only, unlocked snapshot of one pool, for health checks and dashboards.
     *
     * `generation` is the daemon's current generation - what a RUNNING row's worker_generation
     * is compared with to find rows a previous daemon lifetime's workers claimed.
     *
     * @return array{generation: int, members: int, holder: bool, waiting: int}
     */
    public static function stats(string $pool): array
    {
        $response = self::__request('pool.stats', ['pool' => self::pool_name($pool)]);

        return [
            'generation' => (int) $response['generation'],
            'members' => (int) $response['members'],
            'holder' => (bool) $response['holder'],
            'waiting' => (int) $response['waiting'],
        ];
    }

    /**
     * True when this process holds $pool's lock - or, with no argument, ANY pool's lock - per
     * the daemon's answers so far.
     */
    public static function holds_lock(?string $pool = null): bool
    {
        if (self::$locked_pool === null) {
            return false;
        }

        return $pool === null || self::$locked_pool === self::pool_name($pool);
    }

    /** The pool this process is a member of, or null. */
    public static function member_pool(): ?string
    {
        if (self::$membership === null) {
            return null;
        }

        foreach (self::POOLS as $pool) {
            if (self::$membership['pool'] === self::pool_name($pool)) {
                return $pool;
            }
        }

        return null;
    }

    /** This process's worker id, or null when it is not a member of any pool. */
    public static function wid(): ?int
    {
        return self::$membership['wid'] ?? null;
    }

    /** The generation of the daemon that issued this process's wid, or null when it is not a member. */
    public static function generation(): ?int
    {
        return self::$membership['generation'] ?? null;
    }

    /**
     * This machine's name as a RUNNING row records it (`_tasks.worker_host`): the host whose
     * pid evidence may settle that row, and the only one whose may.
     */
    public static function host(): string
    {
        return (string) gethostname();
    }

    /**
     * Close the pool connection. Sends nothing: the daemon ends the membership and frees the
     * pool lock when it sees the close. The next call opens a fresh connection that holds
     * neither.
     */
    public static function disconnect(): void
    {
        self::$connection?->disconnect();
        self::__forget();
    }

    // ---------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------

    private static function __assert_pool(string $pool): void
    {
        if (!in_array($pool, self::POOLS, true)) {
            throw new RuntimeException("Unknown task pool '{$pool}'; expected one of " . implode(', ', self::POOLS) . '.');
        }
    }

    /**
     * One acknowledged round trip. The answer must carry $expected_status; anything else -
     * an `error` frame above all - throws with the daemon's own message.
     */
    private static function __request(string $op, array $fields, string $expected_status = 'ok'): array
    {
        if (self::$connection === null) {
            self::$connection = new Lockd_Connection(
                null,
                "this process's task pool membership and pool lock have been released by the daemon"
            );
        }

        // A connection that is not open is a fresh one: whatever an earlier one held is gone.
        if (!self::$connection->is_connected()) {
            self::__forget();
        }

        try {
            $response = self::$connection->request(['op' => $op] + $fields);
        } catch (RuntimeException $e) {
            // The socket is gone, and with it everything the daemon held for it. Bookkeeping
            // follows the daemon; the exception goes on to the caller untouched.
            self::__forget();

            throw $e;
        }

        $status = $response['status'] ?? null;
        if ($status !== $expected_status) {
            $message = $response['message'] ?? json_encode($response);

            throw new RuntimeException("rsx-lockd refused {$op} on pool {$fields['pool']}: {$message}");
        }

        return $response;
    }

    private static function __forget(): void
    {
        self::$locked_pool = null;
        self::$membership = null;
    }
}
