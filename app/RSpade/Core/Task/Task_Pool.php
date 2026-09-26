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
 * The task worker pool, as accounted by rsx-lockd (the `pool.*` ops, system/bin/rsx-lockd
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
 * The client-side bookkeeping (holds_lock(), member_id()) follows the daemon's answers and
 * nothing else, so a call site can assert THE RULE's preconditions cheaply.
 */
class Task_Pool
{
    /** Prefix of the pool name; the rest is the per-environment scope token. */
    private const POOL_PREFIX = 'tasks:';

    private static ?Lockd_Connection $connection = null;

    /** The pool whose lock this process holds, per the daemon's last answer. */
    private static ?string $locked_pool = null;

    /** This process's membership, per the daemon's last answer: ['pool' => ..., 'member_id' => ...]. */
    private static ?array $membership = null;

    /**
     * The pool's name: one pool per (database, host) scope - the scope RsxLocks and every
     * other box-wide coordinator uses (Rsx_Connection_Scope) - because the pool counts the
     * workers that operate on one `_tasks` table. Two environments sharing a daemon never
     * share a pool.
     */
    public static function pool_name(): string
    {
        return self::POOL_PREFIX . Rsx_Connection_Scope::token();
    }

    /** The pool's cap: rsx.tasks.global_max_workers, never below one. */
    public static function max_workers(): int
    {
        return max(1, (int) config('rsx.tasks.global_max_workers', 1));
    }

    /** Take the pool lock (FIFO). Returns once the daemon has granted it; waits forever. */
    public static function lock(): void
    {
        $pool = self::pool_name();
        self::__request('pool.lock', ['pool' => $pool], 'granted');
        self::$locked_pool = $pool;
    }

    /** Release the pool lock, handing it to the next waiter. */
    public static function unlock(): void
    {
        $pool = self::pool_name();
        self::__request('pool.unlock', ['pool' => $pool]);
        self::$locked_pool = null;
    }

    /**
     * Join the pool. Requires the lock. Returns the member id the daemon minted - store it
     * wherever another process must ask whether this worker is alive.
     */
    public static function join(): string
    {
        $pool = self::pool_name();
        $response = self::__request('pool.join', ['pool' => $pool]);

        $member_id = $response['member_id'] ?? null;
        if (!is_string($member_id) || $member_id === '') {
            throw new RuntimeException("rsx-lockd answered pool.join on {$pool} without a member_id");
        }

        self::$membership = ['pool' => $pool, 'member_id' => $member_id];

        return $member_id;
    }

    /** Leave the pool. Requires the lock. */
    public static function leave(): void
    {
        $pool = self::pool_name();
        self::__request('pool.leave', ['pool' => $pool]);
        self::$membership = null;
    }

    /** The number of members, EXCLUDING this process when it is one. Requires the lock. */
    public static function count(): int
    {
        $response = self::__request('pool.count', ['pool' => self::pool_name()]);

        return (int) $response['members'];
    }

    /** Whether that member id is still a member (false = it left or its connection is gone). Requires the lock. */
    public static function member_alive(string $member_id): bool
    {
        $response = self::__request('pool.member_alive', [
            'pool' => self::pool_name(),
            'member_id' => $member_id,
        ]);

        return (bool) $response['alive'];
    }

    /**
     * Read-only, unlocked snapshot of this pool, for health checks and dashboards.
     *
     * @return array{members: int, holder: bool, waiting: int}
     */
    public static function stats(): array
    {
        $response = self::__request('pool.stats', ['pool' => self::pool_name()]);

        return [
            'members' => (int) $response['members'],
            'holder' => (bool) $response['holder'],
            'waiting' => (int) $response['waiting'],
        ];
    }

    /** True when this process holds the pool lock, per the daemon's answers so far. */
    public static function holds_lock(): bool
    {
        return self::$locked_pool !== null && self::$locked_pool === self::pool_name();
    }

    /** This process's member id, or null when it is not a member. */
    public static function member_id(): ?string
    {
        if (self::$membership === null || self::$membership['pool'] !== self::pool_name()) {
            return null;
        }

        return self::$membership['member_id'];
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
