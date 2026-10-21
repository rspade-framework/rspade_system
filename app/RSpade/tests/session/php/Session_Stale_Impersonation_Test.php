<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Session_Cleanup_Service::end_stale_impersonations - the daily task that ends an
 * impersonation older than rsx.sessions.impersonation_max_days (measured from the
 * impersonation user session's started_at).
 *
 * A staff impersonation's user session is signed out together with the impersonator's own
 * sign-in behind it, both keeping their record. A portal View as Client's user session is
 * signed out alone: the staff sign-in it rode on carries on. A younger impersonation, and a
 * sign-in that is not one, are left alone; 0 switches the limit off.
 */
class Session_Stale_Impersonation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A portal user for a user session to carry (the column is a foreign key). */
    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'impersonation_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /** A browser on which $impersonator is impersonating $target since $started. */
    private static function __impersonation(int $target, int $impersonator, $started): array
    {
        return static::__insert_session_fixture([
            'staff_own' => ['login_user_id' => $impersonator, 'site_id' => self::SITE_ID],
            'staff' => ['login_user_id' => $target, 'site_id' => self::SITE_ID, 'impersonator_login_user_id' => $impersonator, 'started_at' => $started],
        ]);
    }

    private static function __user_session(int $id): object
    {
        return DB::table('_user_sessions')->where('id', $id)->first();
    }

    private static function __run(): void
    {
        $run = Task::internal('Session_Cleanup_Service', 'end_stale_impersonations');
        static::__assert_equals(0, (int) $run->return_code, 'the task succeeded: ' . (string) $run->error);
    }

    /** SESSION-IMP-05 - a staff impersonation past the limit is ended with its impersonator's sign-in */
    public static function test_an_old_staff_impersonation_is_ended_and_keeps_its_record()
    {
        config(['rsx.sessions.impersonation_max_days' => 7]);

        $old = static::__impersonation(881001, 881002, now()->subDays(8));
        $young = static::__impersonation(881001, 881003, now()->subDays(6));
        $own = static::__insert_session_fixture(['staff' => ['login_user_id' => 881001, 'site_id' => self::SITE_ID]]);

        static::__run();

        $ended = static::__user_session($old['staff_user_session_id']);
        static::__assert_not_null($ended->signed_out_at, 'the old impersonation is signed out');
        static::__assert_equals(Session::SIGN_OUT_IMPERSONATION_ENDED, (int) $ended->sign_out_reason_id);
        static::__assert_equals(881001, (int) $ended->login_user_id, 'it still records whose identity it carried');
        static::__assert_equals(881002, (int) $ended->impersonator_login_user_id, 'and who was acting as them');
        static::__assert_not_null(static::__user_session($old['staff_own_user_session_id'])->signed_out_at, "the impersonator's own sign-in behind it ends too");
        static::__assert_null(DB::table('_sessions')->where('id', $old['id'])->value('staff_user_session_id'), 'that browser is signed out');

        static::__assert_null(static::__user_session($young['staff_user_session_id'])->signed_out_at, 'a younger impersonation is left running');
        static::__assert_null(static::__user_session($own['staff_user_session_id'])->signed_out_at, 'the user\'s own sign-in is untouched');

        $listed = array_filter(Session::get_sessions_for_user(881001, null, true), fn ($session) => $session['is_signed_in']);
        static::__assert_count(2, $listed, 'the administrator\'s listing shows the ended one as signed out');
    }

    /** SESSION-IMP-06 - an old View as Client is ended; the staff sign-in carries on */
    public static function test_an_old_portal_view_as_client_ends_alone()
    {
        config(['rsx.sessions.impersonation_max_days' => 7]);

        $viewed = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 881004, 'site_id' => self::SITE_ID],
            'portal' => [
                'portal_user_id' => (int) static::__make_portal_user()->id,
                'portal_site_id' => self::SITE_ID,
                'impersonator_user_id' => 881006,
                'started_at' => now()->subDays(9),
            ],
        ]);

        static::__run();

        $browser = DB::table('_sessions')->where('id', $viewed['id'])->first();
        static::__assert_equals(1, (int) $browser->active, 'the browser carries on');
        static::__assert_equals($viewed['staff_user_session_id'], (int) $browser->staff_user_session_id, 'still signed into the staff app');
        static::__assert_null($browser->portal_user_session_id, 'the portal view is gone');
        static::__assert_equals(Session::SIGN_OUT_IMPERSONATION_ENDED, (int) static::__user_session($viewed['portal_user_session_id'])->sign_out_reason_id);
    }

    /** SESSION-IMP-07 - 0 switches the age limit off */
    public static function test_zero_days_ends_nothing()
    {
        config(['rsx.sessions.impersonation_max_days' => 0]);

        $old = static::__impersonation(881007, 881008, now()->subDays(400));

        static::__run();

        static::__assert_null(static::__user_session($old['staff_user_session_id'])->signed_out_at);
    }
}
