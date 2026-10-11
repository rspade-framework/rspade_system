<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Session_Cleanup_Service::end_stale_impersonations - the daily task that ends an
 * impersonation older than rsx.sessions.impersonation_max_days.
 *
 * A staff impersonation's row is retired: inactive, still recording the identity it carried
 * and who was acting as them. A portal View as Client loses its portal properties and the
 * staff session it rode on carries on. A younger impersonation, and a session that is not
 * one, are left alone; 0 switches the limit off.
 */
class Session_Stale_Impersonation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A portal user for a session row to carry (the column is a foreign key). */
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

    private static function __make_session(array $fields): Session
    {
        $session = new Session();
        $session->site_id = self::SITE_ID;
        $session->type_id = Session::TYPE_WEB;
        $session->session_token = bin2hex(random_bytes(16));
        $session->csrf_token = bin2hex(random_bytes(16));
        $session->ip_address = '10.0.0.22';
        $session->user_agent = 'stale-impersonation-test';
        $session->last_active = now();
        $session->active = true;
        $session->version = 1;

        foreach ($fields as $name => $value) {
            $session->$name = $value;
        }

        $session->save();

        return $session;
    }

    private static function __run(): void
    {
        $run = Task::internal('Session_Cleanup_Service', 'end_stale_impersonations');
        static::__assert_equals(0, (int) $run->return_code, 'the task succeeded: ' . (string) $run->error);
    }

    /** SESSION-IMP-05 - a staff impersonation past the limit is retired, keeping its record */
    public static function test_an_old_staff_impersonation_is_retired_and_keeps_its_record()
    {
        config(['rsx.sessions.impersonation_max_days' => 7]);

        $old = static::__make_session(['login_user_id' => 881001, 'impersonator_login_user_id' => 881002, 'impersonation_started_at' => now()->subDays(8)]);
        $young = static::__make_session(['login_user_id' => 881001, 'impersonator_login_user_id' => 881003, 'impersonation_started_at' => now()->subDays(6)]);
        $own = static::__make_session(['login_user_id' => 881001]);

        static::__run();

        $old = Session::find($old->id);
        static::__assert_false((bool) $old->active, 'the old impersonation is inactive');
        static::__assert_equals(881001, (int) $old->login_user_id, 'it still records whose identity it carried');
        static::__assert_equals(881002, (int) $old->impersonator_login_user_id, 'and who was acting as them');

        static::__assert_true((bool) Session::find($young->id)->active, 'a younger impersonation is left running');
        static::__assert_true((bool) Session::find($own->id)->active, 'the user\'s own session is untouched');

        static::__assert_count(2, Session::get_sessions_for_user(881001, null, true), 'the administrator\'s listing no longer shows the retired one');
    }

    /** SESSION-IMP-06 - an old View as Client loses its portal identity; the staff session carries on */
    public static function test_an_old_portal_view_as_client_loses_its_portal_identity_only()
    {
        config(['rsx.sessions.impersonation_max_days' => 7]);

        $viewed = static::__make_session([
            'login_user_id' => 881004,
            'portal_user_id' => (int) static::__make_portal_user()->id,
            'portal_site_id' => self::SITE_ID,
            'impersonator_user_id' => 881006,
            'impersonation_started_at' => now()->subDays(9),
        ]);

        static::__run();

        $viewed = Session::find($viewed->id);
        static::__assert_true((bool) $viewed->active, 'the staff session carries on');
        static::__assert_equals(881004, (int) $viewed->login_user_id);
        static::__assert_null($viewed->portal_user_id, 'the portal identity is gone');
        static::__assert_null($viewed->impersonator_user_id);
        static::__assert_null($viewed->impersonation_started_at);
    }

    /** SESSION-IMP-07 - 0 switches the age limit off */
    public static function test_zero_days_ends_nothing()
    {
        config(['rsx.sessions.impersonation_max_days' => 0]);

        $old = static::__make_session(['login_user_id' => 881007, 'impersonator_login_user_id' => 881008, 'impersonation_started_at' => now()->subDays(400)]);

        static::__run();

        static::__assert_true((bool) Session::find($old->id)->active);
    }
}
