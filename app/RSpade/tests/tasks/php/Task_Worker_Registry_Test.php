<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Task\Task_Worker_Registry;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Task_Worker_Registry_Test - the Redis-backed worker admission gate.
 *
 * Exercises admit()/heartbeat()/live_count()/deregister()/worker_id() against a real
 * Redis (ZSET key rsx:tasks:workers on DB 1). The registry keeps this process's slot id
 * in a process-static, and every test in a class runs in ONE PHP process, so state leaks
 * across methods; each test therefore starts from a known-clean state via _reset() (drop
 * this process's slot + clear the ZSET). Other live/stale workers are simulated by writing
 * ZSET members directly, so the cap can be tested without spawning processes.
 */
class Task_Worker_Registry_Test extends Rsx_Test_Abstract
{
    /** Pure Redis logic - no database involved. */
    protected static $use_database_transactions = false;

    private const ZSET_BASE = 'rsx:tasks:workers';

    /** The registry's live key is per-(database,host)-scoped; the test must target the
     *  SAME namespace its in-process registry uses, which is also what isolates this
     *  test from sibling parallel workers sharing one Redis. */
    private static function _zset_key(): string
    {
        return self::ZSET_BASE . ':' . Rsx_Connection_Scope::token();
    }

    // =========================================================================
    // tests
    // =========================================================================

    public static function test_admit_succeeds_when_pool_under_cap()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        static::__assert_equals(true, Task_Worker_Registry::admit());
        static::__assert_equals(1, Task_Worker_Registry::live_count());
        static::__assert_not_null(Task_Worker_Registry::worker_id());

        Task_Worker_Registry::deregister();
    }

    public static function test_admit_declines_when_pool_at_cap()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        self::_seed_fresh_members(2);

        static::__assert_equals(false, Task_Worker_Registry::admit());
        static::__assert_null(Task_Worker_Registry::worker_id());
        static::__assert_equals(2, Task_Worker_Registry::live_count());
    }

    public static function test_admit_fills_last_slot()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        self::_seed_fresh_members(1);

        static::__assert_equals(true, Task_Worker_Registry::admit());
        static::__assert_equals(2, Task_Worker_Registry::live_count());

        Task_Worker_Registry::deregister();
    }

    public static function test_live_count_prunes_stale_slots()
    {
        self::_reset();
        config(['rsx.tasks.worker_heartbeat_ttl' => 90]);

        // A single stale member (score older than the ttl) is pruned to zero.
        self::_redis()->zAdd(self::_zset_key(), time() - 200, 'stale:1');
        static::__assert_equals(0, Task_Worker_Registry::live_count());

        // One fresh + one stale leaves exactly the fresh one.
        self::_redis()->zAdd(self::_zset_key(), time(), 'fresh:1');
        self::_redis()->zAdd(self::_zset_key(), time() - 200, 'stale:2');
        static::__assert_equals(1, Task_Worker_Registry::live_count());

        self::_reset();
    }

    public static function test_deregister_removes_slot()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 3, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        Task_Worker_Registry::admit();
        static::__assert_equals(1, Task_Worker_Registry::live_count());

        Task_Worker_Registry::deregister();
        static::__assert_equals(0, Task_Worker_Registry::live_count());
        static::__assert_null(Task_Worker_Registry::worker_id());
    }

    public static function test_heartbeat_refreshes_slot_and_returns_count()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 3, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        Task_Worker_Registry::admit();
        $worker_id = Task_Worker_Registry::worker_id();

        $count = Task_Worker_Registry::heartbeat();
        static::__assert_greater_than(0, $count);

        // The member's score is refreshed to ~now.
        $score = (int) self::_redis()->zScore(self::_zset_key(), $worker_id);
        static::__assert_greater_than(time() - 2, $score);

        static::__assert_greater_than(0, Task_Worker_Registry::live_count());

        Task_Worker_Registry::deregister();
    }

    public static function test_heartbeat_noop_when_not_admitted()
    {
        self::_reset();

        // This process holds no slot after _reset(), so heartbeat is a no-op.
        static::__assert_equals(0, Task_Worker_Registry::heartbeat());
    }

    public static function test_admit_is_reaping_stale_before_cap_check()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        // Two stale members: they are pruned before the cap check, so the pool is under
        // cap and this process is admitted.
        self::_redis()->zAdd(self::_zset_key(), time() - 200, 'stale:a');
        self::_redis()->zAdd(self::_zset_key(), time() - 200, 'stale:b');

        static::__assert_equals(true, Task_Worker_Registry::admit());
        static::__assert_equals(1, Task_Worker_Registry::live_count());

        Task_Worker_Registry::deregister();
    }

    // =========================================================================
    // teardown / helpers
    // =========================================================================

    /**
     * teardown() runs once after all test_* methods in this class. Leave no slot or
     * ZSET behind for other test classes.
     */
    public static function teardown()
    {
        self::_reset();
    }

    /**
     * Connect to the worker-registry Redis (DB 1), the same instance the registry uses.
     */
    private static function _redis(): \Redis
    {
        $r = new \Redis();
        $r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
        $r->select(1);
        return $r;
    }

    /**
     * Return this process and Redis to a known-empty state: drop this process's slot (so
     * worker_id() is null) and clear every registry member.
     */
    private static function _reset(): void
    {
        Task_Worker_Registry::deregister();
        self::_redis()->del(self::_zset_key());
    }

    /**
     * Simulate $count OTHER live workers occupying slots with fresh (now) scores.
     */
    private static function _seed_fresh_members(int $count): void
    {
        $redis = self::_redis();
        for ($i = 0; $i < $count; $i++) {
            $redis->zAdd(self::_zset_key(), time(), 'fake:' . $i);
        }
    }
}
