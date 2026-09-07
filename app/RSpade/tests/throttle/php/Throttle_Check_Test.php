<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Throttle\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Throttle\Rsx_Throttle;

/**
 * Rsx_Throttle::check() claims a per (site, user, action) interval with ONE atomic upsert
 * serialized by uk_throttle_site_user_action.
 *
 * Per-test transactions are disabled: the transaction-safety test needs a REAL transaction it
 * can roll back (a savepoint inside the harness transaction would not prove the property), so
 * each test cleans up its own _throttle rows instead.
 */
class Throttle_Check_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Distinct user id per test so concurrent-looking rows never collide. */
    private const USER_BASE = 990000;

    private static function __cleanup(int $user_id): void
    {
        DB::table('_throttle')->where('user_id', $user_id)->delete();
    }

    private static function __row(int $user_id, string $action_key)
    {
        return DB::table('_throttle')
            ->where('site_id', Session::get_site_id())
            ->where('user_id', $user_id)
            ->where('action_key', $action_key)
            ->first();
    }

    /**
     * Move a claim backwards on the DB clock (the same clock check() compares against), so an
     * elapsed interval can be tested without sleeping.
     */
    private static function __backdate(int $user_id, string $action_key, int $minutes): void
    {
        DB::update(
            'UPDATE _throttle SET last_executed_at = NOW(3) - INTERVAL ? MINUTE
             WHERE site_id = ? AND user_id = ? AND action_key = ?',
            [$minutes, Session::get_site_id(), $user_id, $action_key]
        );
    }

    public static function test_first_call_is_allowed_and_creates_the_row()
    {
        $user_id = self::USER_BASE + 1;
        self::__cleanup($user_id);

        try {
            static::__assert_true(
                Rsx_Throttle::check('TEST_THROTTLE_FIRST', $user_id, 30),
                'a never-claimed action must run'
            );

            $row = self::__row($user_id, 'TEST_THROTTLE_FIRST');
            static::__assert_not_null($row, 'the claim row must exist');
            static::__assert_not_null($row->created_at, 'created_at must be populated by the insert');
            static::__assert_not_null($row->last_executed_at);
        } finally {
            self::__cleanup($user_id);
        }
    }

    public static function test_second_call_within_interval_is_throttled()
    {
        $user_id = self::USER_BASE + 2;
        self::__cleanup($user_id);

        try {
            static::__assert_true(Rsx_Throttle::check('TEST_THROTTLE_REPEAT', $user_id, 30));

            $claimed_at = self::__row($user_id, 'TEST_THROTTLE_REPEAT')->last_executed_at;

            static::__assert_false(
                Rsx_Throttle::check('TEST_THROTTLE_REPEAT', $user_id, 30),
                'an immediate second call must be throttled'
            );

            static::__assert_equals(
                $claimed_at,
                self::__row($user_id, 'TEST_THROTTLE_REPEAT')->last_executed_at,
                'a throttled call must not advance the claim'
            );
        } finally {
            self::__cleanup($user_id);
        }
    }

    public static function test_call_after_interval_elapsed_is_allowed()
    {
        $user_id = self::USER_BASE + 3;
        self::__cleanup($user_id);

        try {
            static::__assert_true(Rsx_Throttle::check('TEST_THROTTLE_ELAPSED', $user_id, 30));

            $stale_at = self::__row($user_id, 'TEST_THROTTLE_ELAPSED')->last_executed_at;
            self::__backdate($user_id, 'TEST_THROTTLE_ELAPSED', 31);

            static::__assert_true(
                Rsx_Throttle::check('TEST_THROTTLE_ELAPSED', $user_id, 30),
                'a claim older than the interval must run again'
            );

            static::__assert_not_equals(
                $stale_at,
                self::__row($user_id, 'TEST_THROTTLE_ELAPSED')->last_executed_at,
                'an allowed call must advance the claim'
            );
        } finally {
            self::__cleanup($user_id);
        }
    }

    /**
     * The interval is per call, not a stored property of the row: the same row that throttles a
     * 30-minute caller allows a 1-minute caller once 1 minute has passed.
     */
    public static function test_interval_is_evaluated_per_call()
    {
        $user_id = self::USER_BASE + 4;
        self::__cleanup($user_id);

        try {
            static::__assert_true(Rsx_Throttle::check('TEST_THROTTLE_INTERVAL', $user_id, 30));
            self::__backdate($user_id, 'TEST_THROTTLE_INTERVAL', 5);

            static::__assert_false(
                Rsx_Throttle::check('TEST_THROTTLE_INTERVAL', $user_id, 30),
                '5 minutes in, a 30-minute interval is still throttled'
            );
            static::__assert_true(
                Rsx_Throttle::check('TEST_THROTTLE_INTERVAL', $user_id, 1),
                '5 minutes in, a 1-minute interval must run'
            );
        } finally {
            self::__cleanup($user_id);
        }
    }

    /**
     * The check must participate in the caller's transaction. The retired implementation wrapped
     * itself in LOCK TABLES / UNLOCK TABLES, each of which implicitly COMMITs the enclosing
     * transaction - the row would have SURVIVED this rollback.
     */
    public static function test_claim_participates_in_an_enclosing_transaction()
    {
        $user_id = self::USER_BASE + 5;
        self::__cleanup($user_id);

        try {
            DB::beginTransaction();
            static::__assert_true(Rsx_Throttle::check('TEST_THROTTLE_TXN', $user_id, 30));
            static::__assert_not_null(
                self::__row($user_id, 'TEST_THROTTLE_TXN'),
                'the claim is visible inside the transaction'
            );
            DB::rollBack();

            static::__assert_null(
                self::__row($user_id, 'TEST_THROTTLE_TXN'),
                'the rolled-back claim must be gone'
            );
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            self::__cleanup($user_id);
        }
    }

    /**
     * reset() drops the claim, so the next check() takes the fresh-insert path again.
     */
    public static function test_reset_clears_the_claim()
    {
        $user_id = self::USER_BASE + 6;
        self::__cleanup($user_id);

        try {
            static::__assert_true(Rsx_Throttle::check('TEST_THROTTLE_RESET', $user_id, 30));
            static::__assert_false(Rsx_Throttle::check('TEST_THROTTLE_RESET', $user_id, 30));

            Rsx_Throttle::reset('TEST_THROTTLE_RESET', $user_id);
            static::__assert_null(self::__row($user_id, 'TEST_THROTTLE_RESET'), 'reset deletes the row');

            static::__assert_true(
                Rsx_Throttle::check('TEST_THROTTLE_RESET', $user_id, 30),
                'after a reset the action runs immediately'
            );
        } finally {
            self::__cleanup($user_id);
        }
    }
}
