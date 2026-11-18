<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use RuntimeException;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * NO EAGER LOADING, from the STATIC and BUILDER entry points.
 *
 * Model_Refresh_Test (db-rf-06) covers the instance forms (load, loadMissing, loadCount).
 * This class covers the doors a query starts from: `Model::with()` (overridden on
 * Rsx_Model_Abstract), and every with*() aggregate reached statically through __callStatic
 * or on a builder - RestrictedEloquentBuilder refuses each one that names a relation, while
 * an empty list (which Laravel itself passes internally) is not a load. A collection of
 * results refuses load() as the record does.
 *
 * Fixture: User_Model and its `login_user` relation (the baseline user 1).
 */
class Model_Static_Eager_Load_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __assert_refused(callable $callable, string $expected, string $what): void
    {
        $e = static::__assert_throws(RuntimeException::class, $callable);
        static::__assert_contains($expected, $e->getMessage(), "{$what} is refused naming the refusal");
    }

    public static function test_static_with_is_refused()
    {
        static::__assert_refused(fn () => User_Model::with('login_user'), 'Eager loading is not allowed', 'Model::with(string)');
        static::__assert_refused(fn () => User_Model::with(['login_user']), 'Attempted to eager load: login_user', 'Model::with(array)');
    }

    public static function test_static_with_count_and_aggregates_are_refused()
    {
        static::__assert_refused(fn () => User_Model::withCount('login_user'), 'withCount() is not allowed', 'Model::withCount()');
        static::__assert_refused(fn () => User_Model::withMax('login_user', 'id'), 'withMax() is not allowed', 'Model::withMax()');
        static::__assert_refused(fn () => User_Model::withMin('login_user', 'id'), 'withMin() is not allowed', 'Model::withMin()');
        static::__assert_refused(fn () => User_Model::withSum('login_user', 'id'), 'withSum() is not allowed', 'Model::withSum()');
        static::__assert_refused(fn () => User_Model::withAvg('login_user', 'id'), 'withAvg() is not allowed', 'Model::withAvg()');
        static::__assert_refused(fn () => User_Model::withExists('login_user'), 'withExists() is not allowed', 'Model::withExists()');
        static::__assert_refused(fn () => User_Model::withAggregate('login_user', 'id', 'max'), 'withAggregate() is not allowed', 'Model::withAggregate()');
    }

    public static function test_builder_with_and_with_count_are_refused()
    {
        static::__assert_refused(fn () => User_Model::query()->with('login_user'), 'via with() is not allowed', 'query()->with()');
        static::__assert_refused(fn () => User_Model::where('id', self::USER_ID)->with('login_user'), 'via with() is not allowed', 'where()->with()');
        static::__assert_refused(fn () => User_Model::where('id', self::USER_ID)->withCount('login_user'), 'withCount() is not allowed', 'where()->withCount()');
    }

    public static function test_an_empty_builder_with_is_not_a_load()
    {
        $user = User_Model::query()->with([])->withCount([])->find(self::USER_ID);

        static::__assert_not_null($user, 'with([]) / withCount([]) load nothing and the query runs');
        static::__assert_false($user->relationLoaded('login_user'));
    }

    public static function test_a_result_collection_refuses_load()
    {
        $users = User_Model::where('id', self::USER_ID)->get();

        static::__assert_count(1, $users);
        static::__assert_refused(fn () => $users->load('login_user'), 'not allowed', 'Collection::load()');
        static::__assert_refused(fn () => $users->loadMissing('login_user'), 'not allowed', 'Collection::loadMissing()');
        static::__assert_refused(fn () => $users->loadCount('login_user'), 'not allowed', 'Collection::loadCount()');
    }
}
