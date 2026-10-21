<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The user session engine (_user_sessions): browser x realm x account x site x impersonator.
 *
 * A web sign-in cannot be driven from the command line (the CLI branch of every setter only
 * declares an identity), so this class drives the browser-addressed primitives the web
 * paths are built from - the find-or-create of a user session, the pointer, and the one
 * sign-out - against fixture browser rows. The full web flow (one row, in-place token
 * rotation, A/B/A over a real cookie) is session/http/session_sign_in_rotation.sh.
 *
 * Runs in the default per-test transaction.
 */
class Session_User_Sessions_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** Session::__activate_user_session() - the find-or-create every sign-in runs. */
    private static function __activate(int $session_id, int $login_user_id, ?int $impersonator = null, int $site_id = self::SITE_ID): int
    {
        $method = new \ReflectionMethod(Session::class, '__activate_user_session');

        return $method->invoke(null, $session_id, Session::REALM_STAFF, [
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'impersonator_login_user_id' => $impersonator,
        ], null);
    }

    /** Session::__point() - the browser's realm pointer. */
    private static function __point(int $session_id, ?int $user_session_id): void
    {
        $method = new \ReflectionMethod(Session::class, '__point');
        $method->invoke(null, $session_id, Session::REALM_STAFF, $user_session_id);
    }

    private static function __user_session(int $id): object
    {
        return DB::table('_user_sessions')->where('id', $id)->first();
    }

    private static function __pointer(int $session_id, string $column = 'staff_user_session_id'): ?int
    {
        $value = DB::table('_sessions')->where('id', $session_id)->value($column);

        return $value === null ? null : (int) $value;
    }

    /**
     * A signs in, B signs in (A replaced), A signs in again: one browser, two user sessions,
     * A's reused with its count at 2.
     */
    public static function test_the_same_account_reuses_its_user_session_on_a_browser()
    {
        $browser = static::__insert_session_fixture()['id'];

        $a = static::__activate($browser, 890001);
        static::__point($browser, $a);

        $b = static::__activate($browser, 890002);
        static::__point($browser, $b);
        Session::_sign_out_user_sessions([$a], Session::SIGN_OUT_REPLACED, false);

        static::__assert_not_equals($a, $b, 'another account is another user session');
        static::__assert_equals(Session::SIGN_OUT_REPLACED, (int) static::__user_session($a)->sign_out_reason_id);

        $a_again = static::__activate($browser, 890001);
        static::__point($browser, $a_again);
        Session::_sign_out_user_sessions([$b], Session::SIGN_OUT_REPLACED, false);

        static::__assert_equals($a, $a_again, 'the same account on the same browser and site reuses its user session');

        $row = static::__user_session($a);
        static::__assert_equals(2, (int) $row->sign_in_count, 'counted twice');
        static::__assert_null($row->signed_out_at, 'signed in again');
        static::__assert_null($row->sign_out_reason_id);
        static::__assert_equals($a, static::__pointer($browser));
        static::__assert_equals(2, DB::table('_user_sessions')->where('session_id', $browser)->count(), 'two user sessions on the one browser');
    }

    /**
     * A different site, a different impersonator or a different browser is a different user
     * session; the identity of one never changes.
     */
    public static function test_site_impersonator_and_browser_each_make_a_new_user_session()
    {
        $browser = static::__insert_session_fixture()['id'];
        $other_browser = static::__insert_session_fixture()['id'];

        $plain = static::__activate($browser, 890003);
        $ids = [
            $plain,
            static::__activate($browser, 890003, null, self::SITE_ID + 1),
            static::__activate($browser, 890003, 890004),
            static::__activate($other_browser, 890003),
        ];

        static::__assert_count(4, array_unique($ids), 'four tuples, four user sessions');
        static::__assert_equals($plain, static::__activate($browser, 890003), 'and the first tuple still answers its own');
    }

    /**
     * The migration that created the table computes the tuple hash in SQL; the two must agree
     * or a migrated sign-in would never be reused.
     */
    public static function test_the_tuple_hash_matches_the_migrations_sql()
    {
        $php_staff = Session::_user_session_tuple_hash(77, Session::REALM_STAFF, [
            'login_user_id' => 5, 'site_id' => 0, 'impersonator_login_user_id' => null,
        ]);
        $sql_staff = DB::selectOne("SELECT SHA2(CONCAT_WS('|', 1, 77, 5, IFNULL(0, 0), '', '', IFNULL(NULL, ''), ''), 256) AS h")->h;

        $php_portal = Session::_user_session_tuple_hash(77, Session::REALM_PORTAL, [
            'portal_user_id' => 9, 'portal_site_id' => 1, 'impersonator_user_id' => 12,
        ]);
        $sql_portal = DB::selectOne("SELECT SHA2(CONCAT_WS('|', 2, 77, '', '', 9, IFNULL(1, ''), '', IFNULL(12, '')), 256) AS h")->h;

        static::__assert_equals($sql_staff, $php_staff, 'staff');
        static::__assert_equals($sql_portal, $php_portal, 'portal');
    }

    /**
     * The browser's FIRST sign-in, in any realm, stamps _sessions.first_signed_in_at - what
     * makes it no longer a guest the cleanup may delete - and a later sign-in never moves it.
     * Driven through the row-addressed portal activation, which runs the same user-session
     * activation every sign-in does.
     */
    public static function test_the_first_sign_in_stamps_the_browser_once()
    {
        $portal_user = new \App\RSpade\Core\Models\Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'first_sign_in_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->status_id = \App\RSpade\Core\Models\Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        $guest = static::__insert_session_fixture();
        $stamp = fn () => DB::table('_sessions')->where('id', $guest['id'])->value('first_signed_in_at');

        static::__assert_null($stamp(), 'a guest has never signed in');

        Session::_apply_portal_impersonation($guest['id'], (int) $portal_user->id, self::SITE_ID, 1);
        $first = $stamp();

        static::__assert_not_null($first, 'the first sign-in stamps the browser');

        DB::table('_sessions')->where('id', $guest['id'])->update(['first_signed_in_at' => '2020-01-01 00:00:00.000']);
        Session::_apply_portal_impersonation($guest['id'], (int) $portal_user->id, self::SITE_ID, 2);

        static::__assert_equals('2020-01-01 00:00:00.000', (string) $stamp(), 'a later sign-in leaves the first one alone');
    }

    /**
     * A realm's sign-out clears THAT realm's values: the other realm's and the guest values
     * stay, and the browser row survives.
     */
    public static function test_a_realm_sign_out_clears_only_that_realms_values()
    {
        $portal_user = new \App\RSpade\Core\Models\Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'user_sessions_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->status_id = \App\RSpade\Core\Models\Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        $fixture = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 890005, 'site_id' => self::SITE_ID],
            'portal' => ['portal_user_id' => (int) $portal_user->id, 'portal_site_id' => self::SITE_ID],
        ]);

        foreach ([Session::REALM_STAFF, Session::REALM_PORTAL, Session::REALM_GUEST] as $realm_id) {
            DB::table('_session_values')->insert(['session_id' => $fixture['id'], 'realm_id' => $realm_id, 'value_key' => 'k', 'value' => '1']);
        }

        $realms_left = fn () => DB::table('_session_values')->where('session_id', $fixture['id'])->orderBy('realm_id')->pluck('realm_id')->map(fn ($id) => (int) $id)->all();

        Session::_sign_out_user_sessions([$fixture['staff_user_session_id']], Session::SIGN_OUT_SIGNED_OUT, false);

        static::__assert_null(static::__pointer($fixture['id']), 'the staff pointer is cleared');
        static::__assert_equals([Session::REALM_PORTAL, Session::REALM_GUEST], $realms_left(), 'the staff sign-out took the staff value only');

        Session::_sign_out_user_sessions([$fixture['portal_user_session_id']], Session::SIGN_OUT_SIGNED_OUT, false);

        static::__assert_null(static::__pointer($fixture['id'], 'portal_user_session_id'));
        static::__assert_equals([Session::REALM_GUEST], $realms_left(), 'the portal sign-out took the portal value; the guest value survives both');
        static::__assert_equals(1, (int) DB::table('_sessions')->where('id', $fixture['id'])->value('active'), 'the browser row survives');
    }

    /**
     * Another account signing in over a realm (SIGN_OUT_REPLACED) clears that realm's values
     * although the pointer is not left empty; a pointer that moves to the same person (a site
     * switch) keeps them.
     */
    public static function test_a_replaced_sign_in_clears_the_realm_and_a_site_switch_does_not()
    {
        $fixture = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 890006, 'site_id' => self::SITE_ID],
            // The same account on another site: where a site switch moves the pointer.
            'staff_own' => ['login_user_id' => 890006, 'site_id' => 0],
        ]);

        DB::table('_session_values')->insert(['session_id' => $fixture['id'], 'realm_id' => Session::REALM_STAFF, 'value_key' => 'k', 'value' => '1']);
        $staff_values = fn () => DB::table('_session_values')->where('session_id', $fixture['id'])->where('realm_id', Session::REALM_STAFF)->count();

        // The pointer moves to the second user session first (as __sign_in() does), then the
        // first is signed out - SITE_SWITCHED keeps the values, REPLACED clears them.
        DB::table('_sessions')->where('id', $fixture['id'])->update(['staff_user_session_id' => $fixture['staff_own_user_session_id']]);
        Session::_sign_out_user_sessions([$fixture['staff_user_session_id']], Session::SIGN_OUT_SITE_SWITCHED, false);

        static::__assert_equals(1, $staff_values(), 'a site switch keeps the realm\'s values');

        $replacement = static::__insert_session_fixture(['staff' => ['login_user_id' => 890008, 'site_id' => self::SITE_ID]]);
        DB::table('_user_sessions')->where('id', $replacement['staff_user_session_id'])->update(['session_id' => $fixture['id']]);
        DB::table('_sessions')->where('id', $fixture['id'])->update(['staff_user_session_id' => $replacement['staff_user_session_id']]);
        Session::_sign_out_user_sessions([$fixture['staff_own_user_session_id']], Session::SIGN_OUT_REPLACED, false);

        static::__assert_equals(0, $staff_values(), 'another account signing in clears the realm\'s values');
    }

    /**
     * Ending an impersonation puts the browser back on the impersonator's own sign-in, which
     * was suspended behind it.
     */
    public static function test_ending_an_impersonation_returns_to_the_suspended_sign_in()
    {
        $fixture = static::__insert_session_fixture([
            'staff_own' => ['login_user_id' => 890006, 'site_id' => self::SITE_ID],
            'staff' => ['login_user_id' => 890007, 'site_id' => self::SITE_ID, 'impersonator_login_user_id' => 890006],
        ]);

        Session::_sign_out_user_sessions([$fixture['staff_user_session_id']], Session::SIGN_OUT_IMPERSONATION_ENDED, false);

        static::__assert_equals($fixture['staff_own_user_session_id'], static::__pointer($fixture['id']), 'back on the impersonator\'s own sign-in');
        static::__assert_null(static::__user_session($fixture['staff_own_user_session_id'])->signed_out_at, 'which is still signed in');
    }

    /**
     * Ending the impersonator's own sign-in ends the impersonation riding on it, on that
     * browser: an impersonation is the impersonator's authority.
     */
    public static function test_ending_the_impersonators_sign_in_ends_the_impersonation()
    {
        $fixture = static::__insert_session_fixture([
            'staff_own' => ['login_user_id' => 890008, 'site_id' => self::SITE_ID],
            'staff' => ['login_user_id' => 890009, 'site_id' => self::SITE_ID, 'impersonator_login_user_id' => 890008],
        ]);

        $ended = Session::_sign_out_user_sessions([$fixture['staff_own_user_session_id']], Session::SIGN_OUT_ENDED_BY_ADMIN, false);

        static::__assert_count(2, $ended, 'both user sessions ended');
        static::__assert_not_null(static::__user_session($fixture['staff_user_session_id'])->signed_out_at, 'the impersonation is signed out');
        static::__assert_null(static::__pointer($fixture['id']), 'the browser is signed out');
    }

    /**
     * Signing out an already signed-out user session changes nothing and answers nothing.
     */
    public static function test_signing_out_twice_is_a_no_op()
    {
        $fixture = static::__insert_session_fixture(['staff' => ['login_user_id' => 890010, 'site_id' => self::SITE_ID]]);

        static::__assert_count(1, Session::_sign_out_user_sessions([$fixture['staff_user_session_id']], Session::SIGN_OUT_SIGNED_OUT, false));
        $stamp = static::__user_session($fixture['staff_user_session_id'])->signed_out_at;

        static::__assert_count(0, Session::_sign_out_user_sessions([$fixture['staff_user_session_id']], Session::SIGN_OUT_ENDED_BY_ADMIN, false));
        static::__assert_equals($stamp, static::__user_session($fixture['staff_user_session_id'])->signed_out_at, 'the first stamp stands');
        static::__assert_equals(Session::SIGN_OUT_SIGNED_OUT, (int) static::__user_session($fixture['staff_user_session_id'])->sign_out_reason_id);
    }

    /**
     * A command has no browser and no user session: the dispatch binding reads null.
     */
    public static function test_a_command_has_no_user_session()
    {
        static::__acting_as_user(1);

        static::__assert_null(Session::get_user_session_id());
        static::__assert_null(Session::get_current_session_info());

        static::__reset_session();
    }
}
