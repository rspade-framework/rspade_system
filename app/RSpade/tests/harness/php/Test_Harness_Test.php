<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Harness\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Framework test for the test harness itself: assertions, __assert_throws,
 * session impersonation, and per-test transaction isolation.
 */
class Test_Harness_Test extends Rsx_Test_Abstract
{
    // setup() runs OUTSIDE the per-test transaction, so this fixture persists
    // across the test methods.
    public static function setup()
    {
        DB::statement('CREATE TABLE IF NOT EXISTS _harness_txn_test (id BIGINT AUTO_INCREMENT PRIMARY KEY, v INT)');
        DB::statement('DELETE FROM _harness_txn_test');
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS _harness_txn_test');
    }

    public static function test_assertions()
    {
        static::__assert_true(true);
        static::__assert_false(false);
        static::__assert_equals(3, 1 + 2);
        static::__assert_not_equals(1, 2);
        static::__assert_null(null);
        static::__assert_not_null('x');
        static::__assert_contains('ell', 'hello');
        static::__assert_array_has_key('k', ['k' => 1]);
        static::__assert_count(2, [1, 2]);
        static::__assert_greater_than(1, 2);
        static::__assert_less_than(2, 1);
        static::__assert_instance_of(\Exception::class, new \RuntimeException('x'));
        static::__assert_empty('');
        static::__assert_not_empty('x');
        static::__assert_equals_approx(0.1 + 0.2, 0.3);
    }

    public static function test_assert_throws_returns_caught_exception()
    {
        $e = static::__assert_throws(
            \RuntimeException::class,
            function () { throw new \RuntimeException('boom: bad input'); },
            'bad input'
        );
        static::__assert_equals('boom: bad input', $e->getMessage());
    }

    public static function test_assert_throws_fails_when_nothing_throws()
    {
        // __assert_throws must itself throw when the callable does not throw.
        static::__assert_throws(\Throwable::class, function () {
            static::__assert_throws(\RuntimeException::class, function () {
                // intentionally does not throw
            });
        });
    }

    public static function test_assert_throws_fails_on_wrong_class()
    {
        static::__assert_throws(\Throwable::class, function () {
            static::__assert_throws(\LogicException::class, function () {
                throw new \RuntimeException('not a logic exception');
            });
        });
    }

    public static function test_session_impersonation()
    {
        static::__acting_as_site(123);
        static::__assert_equals(123, Session::get_site_id());

        static::__reset_session();
        static::__assert_equals(0, Session::get_site_id());
    }

    // Pair below proves per-test transaction rollback: the insert in _a is not
    // visible in _b (declaration order = execution order).
    public static function test_transaction_a_inserts()
    {
        static::__assert_equals(0, DB::table('_harness_txn_test')->count());
        DB::table('_harness_txn_test')->insert(['v' => 1]);
        static::__assert_equals(1, DB::table('_harness_txn_test')->count());
    }

    public static function test_transaction_b_is_isolated()
    {
        static::__assert_equals(0, DB::table('_harness_txn_test')->count());
    }
}
