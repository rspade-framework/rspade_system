<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for login_users.is_developer - the whole of what a developer is.
 *
 * Three properties, and each of them is a promise something else is written against:
 * the initial user carries the flag (so a fresh installation has exactly one developer,
 * the person who built it); toArray() carries the key only when the flag is true (so a
 * consumer reads it as a truthiness question and every ordinary identity ships no
 * developer key at all); and Session::is_developer() answers from the signed-in login
 * identity and from nothing else - no role, no hostname.
 */
class Developer_Flag_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Session::reset_impersonation();
    }

    public static function teardown()
    {
        Session::reset_impersonation();
    }

    /**
     * A second login identity, taking the column's default. Rolled back with the
     * per-test transaction.
     */
    private static function __ordinary_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'developer-flag-' . random_hash(8) . '@rspade.test';
        $login_user->password = Hash::make(random_hash(16));
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    // -------------------------------------------------------------------------
    // The initial user
    // -------------------------------------------------------------------------

    public static function test_the_baseline_identity_is_a_developer()
    {
        $login_user = Login_User_Model::find(1);

        static::__assert_not_null($login_user, 'the test baseline carries login identity 1');
        static::__assert_true((bool) $login_user->is_developer, 'the initial user is a developer');
    }

    public static function test_a_new_identity_is_not_a_developer()
    {
        $login_user = static::__ordinary_login_user();

        static::__assert_false((bool) $login_user->is_developer, 'the column defaults to 0');
    }

    // -------------------------------------------------------------------------
    // The payload
    // -------------------------------------------------------------------------

    public static function test_to_array_carries_the_flag_when_it_is_set()
    {
        $array = Login_User_Model::find(1)->toArray();

        static::__assert_true(isset($array['is_developer']), 'a developer ships the key');
        static::__assert_true((bool) $array['is_developer'], 'and ships it true');
    }

    public static function test_to_array_omits_the_flag_when_it_is_clear()
    {
        $array = static::__ordinary_login_user()->toArray();

        static::__assert_false(
            array_key_exists('is_developer', $array),
            'an ordinary identity ships no developer key, so absence reads as false'
        );
    }

    // -------------------------------------------------------------------------
    // Session::is_developer()
    // -------------------------------------------------------------------------

    public static function test_session_answers_true_for_a_developer()
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::find(1);
        });

        static::__assert_not_null($user, 'the test baseline carries user 1');

        Session::impersonate((int) $user->site_id, 1, (int) $user->id);

        static::__assert_true(Session::is_developer(), 'the signed-in identity holds the flag');
    }

    public static function test_session_answers_false_for_an_ordinary_identity()
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::find(1);
        });

        $login_user = static::__ordinary_login_user();

        Session::impersonate((int) $user->site_id, (int) $login_user->id, (int) $user->id);

        static::__assert_false(Session::is_developer(), 'the flag is a property of the login identity');
    }

    public static function test_session_answers_false_with_nobody_signed_in()
    {
        Session::reset_impersonation();

        static::__assert_false(Session::is_developer(), 'anonymous is never a developer');
    }
}
