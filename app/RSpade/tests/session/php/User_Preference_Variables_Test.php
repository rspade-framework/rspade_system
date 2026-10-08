<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * User preference variables - get_variable() / set_variable() / forget_variable() /
 * has_variable() on the three user records.
 *
 * The properties under test are the ones the store exists for: a value round-trips by type
 * and null is a value; the three records keep three separate stores; a value never rides
 * the record's payload; a soft delete keeps the values and a hard delete of the row takes
 * them with it.
 */
class User_Preference_Variables_Test extends Rsx_Test_Abstract
{
    private static function __login_user(): Login_User_Model
    {
        $id = (int) DB::table('login_users')->insertGetId([
            'email' => 'pref_' . uniqid() . '@example.com',
            'password' => 'not-a-hash',
            'status_id' => Login_User_Model::STATUS_ACTIVE,
            'is_verified' => 1,
            'is_activated' => 1,
        ]);

        return Login_User_Model::find($id);
    }

    private static function __user(Login_User_Model $login_user): User_Model
    {
        $id = (int) DB::table('users')->insertGetId([
            'login_user_id' => $login_user->id,
            'site_id' => 1,
            'first_name' => 'Pref',
            'last_name' => 'Variables',
            'role_id' => static::most_privileged_role_id(),
            'is_enabled' => 1,
        ]);

        return User_Model::find($id);
    }

    private static function __portal_user(): Portal_User_Model
    {
        $portal_user = new Portal_User_Model();
        $portal_user->site_id = 1;
        $portal_user->email = 'pref_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        return $portal_user;
    }

    /**
     * pref-var-01: an unset key answers the default and is not "had"; a set key answers its
     * value from a FRESH instance, so the answer came from the table and not the memo.
     */
    public static function test_set_then_read_from_a_fresh_instance()
    {
        $login_user = static::__login_user();

        static::__assert_null($login_user->get_variable('passkey_prompt'), 'unset reads as null');
        static::__assert_equals('later', $login_user->get_variable('passkey_prompt', 'later'), 'unset reads as the default');
        static::__assert_false($login_user->has_variable('passkey_prompt'), 'unset is not had');

        $login_user->set_variable('passkey_prompt', 'declined');

        static::__assert_equals('declined', $login_user->get_variable('passkey_prompt', 'later'), 'the same instance sees its own write');

        $fresh = Login_User_Model::find($login_user->id);
        static::__assert_equals('declined', $fresh->get_variable('passkey_prompt'), 'a fresh instance reads the stored value');
        static::__assert_true($fresh->has_variable('passkey_prompt'), 'and has it');
    }

    /**
     * pref-var-02: values round-trip by type, a second write replaces the first (one row,
     * not two), and null is a VALUE - had, and answered instead of the default.
     */
    public static function test_values_round_trip_and_null_is_a_value()
    {
        $login_user = static::__login_user();

        foreach ([
            'bool' => false,
            'int' => 0,
            'string' => '',
            'list' => ['a', 'b'],
            'map' => ['skipped_at' => '2026-10-08', 'count' => 2],
        ] as $key => $value) {
            $login_user->set_variable($key, $value);
            static::__assert_equals($value, Login_User_Model::find($login_user->id)->get_variable($key, 'DEFAULT'), "{$key} round-trips");
        }

        $login_user->set_variable('string', 'second');
        static::__assert_equals(
            1,
            DB::table('_login_user_variables')->where('login_user_id', $login_user->id)->where('variable_key', 'string')->count(),
            'a second write to a key is an update'
        );
        static::__assert_equals('second', Login_User_Model::find($login_user->id)->get_variable('string'), 'and the later value wins');

        $login_user->set_variable('nothing', null);
        $fresh = Login_User_Model::find($login_user->id);
        static::__assert_true($fresh->has_variable('nothing'), 'a key set to null is had');
        static::__assert_null($fresh->get_variable('nothing', 'DEFAULT'), 'and answers null, not the default');
    }

    /**
     * pref-var-03: forgetting removes the key; forgetting an absent key is not an error.
     */
    public static function test_forget()
    {
        $login_user = static::__login_user();

        $login_user->forget_variable('never_set');

        $login_user->set_variable('tour_seen', true);
        $login_user->forget_variable('tour_seen');

        static::__assert_false($login_user->has_variable('tour_seen'), 'forgotten on this instance');
        static::__assert_equals('DEFAULT', Login_User_Model::find($login_user->id)->get_variable('tour_seen', 'DEFAULT'), 'and in the table');
    }

    /**
     * pref-var-04: the identity, its site membership and a portal user are three stores. The
     * same key holds three values, and another record of the same kind sees none of them.
     */
    public static function test_each_record_has_its_own_store()
    {
        $login_user = static::__login_user();
        $user = static::__user($login_user);
        $portal_user = static::__portal_user();

        $login_user->set_variable('step', 'identity');
        $user->set_variable('step', 'membership');
        $portal_user->set_variable('step', 'portal');

        static::__assert_equals('identity', Login_User_Model::find($login_user->id)->get_variable('step'), 'the identity keeps its own');
        static::__assert_equals('membership', User_Model::find($user->id)->get_variable('step'), 'the membership keeps its own');
        static::__assert_equals('portal', Portal_User_Model::find($portal_user->id)->get_variable('step'), 'the portal user keeps its own');

        static::__assert_false(static::__login_user()->has_variable('step'), 'another identity has none of it');
    }

    /**
     * pref-var-05: a value never travels with the record.
     */
    public static function test_values_are_not_in_the_payload()
    {
        $login_user = static::__login_user();
        $user = static::__user($login_user);

        $login_user->set_variable('payload_probe_key', 'payload_probe_value');
        $user->set_variable('payload_probe_key', 'payload_probe_value');

        foreach ([$login_user, $user, Login_User_Model::find($login_user->id), User_Model::find($user->id)] as $record) {
            $payload = json_encode($record->toArray());

            static::__assert_false(str_contains($payload, 'payload_probe'), class_basename($record) . '::toArray() carries no variable');
        }
    }

    /**
     * pref-var-06: a soft delete keeps the values (the row is still there); removing the row
     * itself takes them with it by cascade.
     */
    public static function test_soft_delete_keeps_and_row_removal_cascades()
    {
        $login_user = static::__login_user();
        $user = static::__user($login_user);
        $user->set_variable('storage_step', 'skipped');

        $user->delete();

        static::__assert_equals(
            'skipped',
            User_Model::withTrashed()->find($user->id)->get_variable('storage_step'),
            'a soft-deleted membership still has its variables'
        );

        DB::statement('DELETE FROM users WHERE id = ?', [$user->id]);

        static::__assert_equals(
            0,
            DB::table('_user_variables')->where('user_id', $user->id)->count(),
            'removing the row removes its variables'
        );
    }

    /**
     * pref-var-07: a record that was never saved has no store to write to.
     */
    public static function test_an_unsaved_record_is_refused()
    {
        $thrown = null;

        try {
            (new Login_User_Model())->set_variable('key', 'value');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        static::__assert_not_null($thrown, 'an unsaved record refuses a write');
        static::__assert_true(str_contains($thrown->getMessage(), 'saved record'), 'and says why');
    }
}
