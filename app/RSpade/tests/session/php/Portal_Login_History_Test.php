<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Session\Session_Cleanup_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Portal_Login_History - the client portal's sign-in history store, and its place in the
 * session concern's retention sweeps.
 *
 * The store is Login_History_Abstract bound to `_portal_login_history`; the shared engine is
 * pinned for staff by Login_History_Test and Login_History_List_Page_Test. What is pinned HERE
 * is the PORTAL binding:
 *  - a row is keyed on the portal user and carries its site, read from the portal user
 *    itself, so a recording made outside a portal request (the hourly sweep) is still right;
 *  - record_refusal() is a row and no failure count; the readers answer the portal user's
 *    rows only, labelled, with no location; list_page() takes 'portal_user_id' and refuses
 *    the staff key;
 *  - a staff identity and a portal user with the same numeric id never see each other's rows;
 *  - Session_Cleanup_Service prunes the portal table on the same retention window and keeps a
 *    user-agent string a portal row names.
 */
class Portal_Login_History_Test extends Rsx_Test_Abstract
{
    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        static::__reset_session();
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $site = new Site_Model();
        $site->slug = 'portal-history-' . uniqid();
        $site->name = 'Portal History';
        $site->is_enabled = true;
        $site->save();

        Portal_Session::_testing_reset();
        Portal_Session::set_site_id((int) $site->id);
        Rsx_Portal::set_portal_request(true);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = (int) $site->id;
        $portal_user->email = 'portal_history_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        // The recording below happens outside any portal request, as a sweep's would.
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();

        return $portal_user;
    }

    /**
     * plh-01: rows carry the portal user and its site; the reader answers them newest first,
     * labelled, with no location; a refusal is a row and never a failure count.
     */
    public static function test_rows_are_recorded_and_read_back()
    {
        $portal_user = static::__make_portal_user();
        $id = (int) $portal_user->id;

        Portal_Login_History::record_success($id, $portal_user->email);
        Portal_Login_History::record_refusal($id, $portal_user->email, Portal_Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED);

        $row = DB::table('_portal_login_history')->where('portal_user_id', $id)->orderBy('id')->first();
        static::__assert_equals((int) $portal_user->site_id, (int) $row->site_id, 'the site is read from the portal user');

        $history = Portal_Login_History::get_history_for_user($id);

        static::__assert_count(2, $history);
        static::__assert_equals(Portal_Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED, $history[0]['status'], 'newest first');
        static::__assert_equals('Refused - Password Reset Required', $history[0]['status_label']);
        static::__assert_equals('Success', $history[1]['status_label']);
        static::__assert_null($history[0]['location'], 'the portal table records no location');
        static::__assert_equals(0, Portal_Login_History::get_failed_attempts_count($portal_user->email), 'a refusal is never a failure count');
    }

    /**
     * plh-02: the two realms' histories are separate stores - a numeric id shared by a staff
     * identity and a portal user reads only its own realm's rows.
     */
    public static function test_the_realms_never_read_each_others_rows()
    {
        $portal_user = static::__make_portal_user();
        $id = (int) $portal_user->id;

        Portal_Login_History::record_success($id, $portal_user->email);
        Login_History::record_success($id, 'staff_same_id_' . uniqid() . '@example.com');

        static::__assert_equals([$portal_user->email], array_column(Portal_Login_History::get_history_for_user($id), 'email'));
        static::__assert_false(
            in_array($portal_user->email, array_column(Login_History::get_history_for_user($id, 50), 'email'), true),
            'the staff reader never answers a portal row'
        );
    }

    /**
     * plh-03: list_page() pages a portal user's history under the 'portal_user_id' key, and
     * refuses the staff key by name.
     */
    public static function test_list_page_takes_the_portal_scope_key()
    {
        $portal_user = static::__make_portal_user();
        $id = (int) $portal_user->id;

        for ($i = 0; $i < 3; $i++) {
            Portal_Login_History::record_success($id, $portal_user->email);
        }

        $page = Portal_Login_History::list_page(['portal_user_id' => $id, 'limit' => 2]);

        static::__assert_equals(3, $page['total']);
        static::__assert_count(2, $page['rows']);
        static::__assert_equals('Success', $page['rows'][0]['status_label']);

        static::__assert_throws(
            InvalidArgumentException::class,
            fn () => Portal_Login_History::list_page(['login_user_id' => $id]),
            'Portal_Login_History::list_page'
        );
    }

    /**
     * plh-04: the retention sweep prunes the portal table on the same window.
     */
    public static function test_the_retention_sweep_prunes_portal_rows()
    {
        $portal_user = static::__make_portal_user();
        $id = (int) $portal_user->id;

        Portal_Login_History::record_success($id, $portal_user->email);
        Portal_Login_History::record_success($id, $portal_user->email);

        $aged = (int) DB::table('_portal_login_history')->where('portal_user_id', $id)->min('id');
        DB::table('_portal_login_history')->where('id', $aged)->update(['created_at' => now()->subDays(400)]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_login_history');

        static::__assert_greater_than(0, $result['total_deleted']);
        static::__assert_equals(0, DB::table('_portal_login_history')->where('id', $aged)->count(), 'the aged row is gone');
        static::__assert_equals(1, DB::table('_portal_login_history')->where('portal_user_id', $id)->count(), 'the recent one stays');
    }

    /**
     * plh-05: a user-agent string a portal history row names is not an orphan.
     */
    public static function test_the_user_agent_sweep_keeps_a_string_a_portal_row_names()
    {
        $portal_user = static::__make_portal_user();
        $id = (int) $portal_user->id;

        Portal_Login_History::record_passkey_enrollment(
            $id,
            $portal_user->email,
            Portal_Login_History::STATUS_PASSKEY_ENROLL_BEGUN,
            null,
            ['ip_address' => '10.0.0.12', 'user_agent' => 'portal-history-sweep-' . uniqid()]
        );

        $user_agent_id = (int) DB::table('_portal_login_history')->where('portal_user_id', $id)->value('user_agent_id');
        DB::table('_user_agents')->where('id', $user_agent_id)->update([
            'first_seen_at' => now()->subDays(10),
            'last_seen_at' => now()->subDays(10),
        ]);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_user_agents');

        static::__assert_equals(1, DB::table('_user_agents')->where('id', $user_agent_id)->count(), 'named by a portal row: kept');
        static::__assert_not_null(Audit_Session::user_agent_string($user_agent_id));
    }
}
