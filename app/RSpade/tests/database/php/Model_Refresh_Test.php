<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * refresh() re-reads a record in place, and the eager-loading refusal still stands.
 *
 * A write that does not go through the instance - raw SQL, DB::table(), ->raw_bulk(), a
 * second instance of the same row - leaves the instance holding stale attributes, and
 * refresh() is the call that brings it up to date without handing the caller a new object.
 * Eloquent implements it as "re-read the row, then load() the relations the instance
 * holds", and load() is refused here, so two things are fenced: an empty relation list is
 * not a load, and a record that HAS read a relation refreshes by dropping it (the next read
 * queries again) rather than by throwing with its attributes half replaced.
 *
 * Every write rolls back with the test's transaction.
 */
class Model_Refresh_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    private static function __write_out_of_band(string $table, int $id, array $values): void
    {
        DB::table($table)->where('id', $id)->update($values);
    }

    public static function test_refresh_rereads_a_row_changed_out_of_band()
    {
        $user = User_Model::find(self::USER_ID);
        static::__write_out_of_band('users', self::USER_ID, ['first_name' => 'Refreshed', 'phone' => '555-0100']);

        static::__assert_not_equals('Refreshed', $user->first_name, 'the instance is stale before refresh()');

        $returned = $user->refresh();

        static::__assert_true($returned === $user, 'refresh() returns the same instance');
        static::__assert_equals('Refreshed', $user->first_name);
        static::__assert_equals('555-0100', $user->phone);
        static::__assert_false($user->isDirty(), 'a refreshed record has nothing to save');
    }

    public static function test_refresh_discards_unsaved_changes()
    {
        $user = User_Model::find(self::USER_ID);
        $stored = $user->first_name;
        $user->first_name = 'Never Saved';

        $user->refresh();

        static::__assert_equals($stored, $user->first_name);
        static::__assert_false($user->isDirty());
    }

    public static function test_refresh_drops_a_relation_the_record_had_read()
    {
        $user = User_Model::find(self::USER_ID);
        $login_user = $user->login_user;
        static::__assert_instance_of(Login_User_Model::class, $login_user);
        static::__assert_true($user->relationLoaded('login_user'), 'reading the relation holds it');

        static::__write_out_of_band('users', self::USER_ID, ['first_name' => 'Refreshed']);
        static::__write_out_of_band('login_users', (int) $login_user->id, ['timezone' => 'Pacific/Auckland']);

        $user->refresh();

        static::__assert_equals('Refreshed', $user->first_name);
        static::__assert_false($user->relationLoaded('login_user'), 'the held relation is dropped, not reloaded');
        static::__assert_equals('Pacific/Auckland', $user->login_user->timezone, 'the next read queries again');
        static::__assert_false($user->isDirty());
    }

    public static function test_refresh_on_an_unsaved_record_is_a_no_op()
    {
        $user = new User_Model();
        $user->first_name = 'Unsaved';

        static::__assert_true($user->refresh() === $user);
        static::__assert_equals('Unsaved', $user->first_name);
    }

    public static function test_an_empty_relation_list_is_not_a_load()
    {
        $user = User_Model::find(self::USER_ID);

        static::__assert_true($user->load([]) === $user);
        static::__assert_true($user->loadMissing([]) === $user);
        static::__assert_true($user->loadCount([]) === $user);
    }

    public static function test_loading_a_relation_is_still_refused()
    {
        $user = User_Model::find(self::USER_ID);

        static::__assert_throws(RuntimeException::class, fn () => $user->load('login_user'), 'Lazy eager loading is not allowed');
        static::__assert_throws(RuntimeException::class, fn () => $user->load(['login_user']), 'login_user');
        static::__assert_throws(RuntimeException::class, fn () => $user->loadMissing('login_user'), 'Conditional eager loading is not allowed');
        static::__assert_throws(RuntimeException::class, fn () => $user->loadCount('supplementary_permissions'), 'Eager loading counts is not allowed');
        static::__assert_throws(RuntimeException::class, fn () => User_Model::with('login_user'), 'Eager loading is not allowed');
    }
}
