<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Session::has_api_access() - the one predicate every API seam asks.
 *
 * Two properties matter and both are covered here: it answers from
 * users.is_api_access_enabled, and asking it CREATES NOTHING. The second is the reason
 * the method exists in this shape rather than reading get_session()->... : a question
 * about an identity must never mint a session row for a caller who has none.
 */
class Session_Api_Access_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Session::reset_impersonation();
    }

    public static function teardown()
    {
        Session::_reset_api_identity();
        Session::reset_impersonation();
    }

    /**
     * A user in the baseline, with the API flag set as asked. Every write here rolls
     * back with the per-test transaction.
     */
    private static function __user_with_flag(bool $enabled): User_Model
    {
        return User_Model::without_site_scope(function () use ($enabled) {
            $user = User_Model::find(1);

            static::__assert_not_null($user, 'baseline user 1 exists');

            $user->is_api_access_enabled = $enabled;
            $user->save();

            return $user;
        });
    }

    /**
     * Declare user 1's identity as the CLI context (no session row is demanded).
     */
    private static function __become_user(User_Model $user): void
    {
        Session::set_site_id((int) $user->site_id);
        Session::set_login_user_id((int) $user->login_user_id);
    }

    // -------------------------------------------------------------------------
    // It reads the column
    // -------------------------------------------------------------------------

    public static function test_true_when_the_column_is_set()
    {
        $user = static::__user_with_flag(true);
        static::__become_user($user);

        static::__assert_true(Session::has_api_access(), 'is_api_access_enabled = 1 grants access');
    }

    public static function test_false_when_the_column_is_clear()
    {
        $user = static::__user_with_flag(false);
        static::__become_user($user);

        static::__assert_false(Session::has_api_access(), 'is_api_access_enabled = 0 refuses access');
    }

    public static function test_false_with_no_identity_at_all()
    {
        Session::reset_impersonation();

        static::__assert_false(Session::has_api_access(), 'nobody is signed in, so nobody has access');
    }

    // -------------------------------------------------------------------------
    // The headless Bearer tier - backed by no session row
    // -------------------------------------------------------------------------

    public static function test_reads_the_api_identity_tier()
    {
        $user = static::__user_with_flag(true);

        Session::_set_api_identity((int) $user->login_user_id, (int) $user->site_id, (int) $user->id);

        static::__assert_false(Session::has_session(), 'an API identity is backed by no session row');
        static::__assert_true(Session::has_api_access(), 'and is still answered from its own user');

        Session::_reset_api_identity();
    }

    // -------------------------------------------------------------------------
    // It creates nothing - the whole point of the method's ordering
    // -------------------------------------------------------------------------

    public static function test_asking_mints_no_session_row()
    {
        Session::reset_impersonation();

        $before = DB::table('_sessions')->count();

        Session::has_api_access();

        static::__assert_equals($before, DB::table('_sessions')->count(), 'asking with no session creates none');
        static::__assert_false(Session::has_session(), 'and leaves the process without one');
    }

    public static function test_asking_with_a_declared_identity_mints_no_session_row()
    {
        $user = static::__user_with_flag(true);
        static::__become_user($user);

        $before = DB::table('_sessions')->count();

        static::__assert_true(Session::has_api_access());

        static::__assert_equals($before, DB::table('_sessions')->count(), 'a declared identity demands no row');
    }
}
