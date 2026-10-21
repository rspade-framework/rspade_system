<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * What the two get_sessions_for_user() listings answer: USER SESSIONS.
 *
 * An impersonation is not one of the impersonated user's sessions. It is a user session of
 * its own on the IMPERSONATOR's browser, carrying the target's identity and an impersonator;
 * both listings leave it out unless the caller asks, and mark it when it does - the user's
 * own security screen never shows somebody else's browser as a sign-in of theirs, and an
 * administrator's view can label one. A signed-out user session is still listed (marked)
 * within rsx.sessions.unused_session_days; a harness browser never is.
 */
class Session_Impersonation_Listing_Test extends Rsx_Test_Abstract
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

    /** A browser signed in as $login_user_id; answers the staff user session id. */
    private static function __staff(int $login_user_id, ?int $impersonator = null, array $extra = []): int
    {
        $options = array_merge([
            'staff' => ['login_user_id' => $login_user_id, 'site_id' => self::SITE_ID, 'impersonator_login_user_id' => $impersonator],
        ], $extra);

        if ($impersonator !== null) {
            $options['staff_own'] = ['login_user_id' => $impersonator, 'site_id' => self::SITE_ID];
        }

        return static::__insert_session_fixture($options)['staff_user_session_id'];
    }

    /** SESSION-IMP-01 - the staff listing leaves an impersonation out by default */
    public static function test_staff_listing_excludes_an_impersonation_by_default()
    {
        $target = 880001;
        $own = static::__staff($target);
        static::__staff($target, 880002);

        $listed = Session::get_sessions_for_user($target);

        static::__assert_equals([$own], array_column($listed, 'id'), 'only the user\'s own sign-in is listed');
        static::__assert_false($listed[0]['is_impersonation']);
        static::__assert_true($listed[0]['is_signed_in']);
    }

    /** SESSION-IMP-02 - asked for, the impersonation is answered and marked */
    public static function test_staff_listing_includes_and_marks_an_impersonation_on_request()
    {
        $target = 880003;
        $own = static::__staff($target);
        $impersonation = static::__staff($target, 880004);

        $listed = Session::get_sessions_for_user($target, null, true);
        $marks = array_column($listed, 'is_impersonation', 'id');

        static::__assert_count(2, $listed);
        static::__assert_false($marks[$own]);
        static::__assert_true($marks[$impersonation]);
        static::__assert_equals(880004, array_column($listed, 'impersonator_id', 'id')[$impersonation], 'the impersonator is named');
    }

    /** SESSION-IMP-03 - the site filter and the impersonation filter apply together */
    public static function test_staff_listing_site_filter_still_excludes_an_impersonation()
    {
        $target = 880005;
        static::__staff($target, 880006);

        static::__assert_count(0, Session::get_sessions_for_user($target, self::SITE_ID));
        static::__assert_count(1, Session::get_sessions_for_user($target, self::SITE_ID, true));
        static::__assert_count(0, Session::get_sessions_for_user($target, self::SITE_ID + 1, true), 'another site lists nothing');
    }

    /** SESSION-IMP-04 - the portal listing leaves a View as Client session out by default */
    public static function test_portal_listing_excludes_an_impersonation_by_default()
    {
        $portal_user = (int) static::__make_portal_user()->id;
        $own = static::__insert_session_fixture([
            'portal' => ['portal_user_id' => $portal_user, 'portal_site_id' => self::SITE_ID],
        ])['portal_user_session_id'];
        $viewed = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 880008, 'site_id' => self::SITE_ID],
            'portal' => ['portal_user_id' => $portal_user, 'portal_site_id' => self::SITE_ID, 'impersonator_user_id' => 880009],
        ])['portal_user_session_id'];

        $listed = Portal_Session::get_sessions_for_user($portal_user);
        static::__assert_equals([$own], array_column($listed, 'id'));
        static::__assert_false($listed[0]['is_impersonation']);

        $marks = array_column(Portal_Session::get_sessions_for_user($portal_user, true), 'is_impersonation', 'id');
        static::__assert_count(2, $marks);
        static::__assert_true($marks[$viewed]);
        static::__assert_false($marks[$own]);
    }

    /** SESSION-LIST-01 - a recently signed-out user session is listed, marked; an old one is not */
    public static function test_signed_out_user_sessions_are_listed_within_the_window()
    {
        $target = 880010;
        $recent = static::__staff($target, null, [
            'staff' => ['login_user_id' => $target, 'site_id' => self::SITE_ID, 'signed_out_at' => now()->subDays(2), 'sign_out_reason_id' => Session::SIGN_OUT_SIGNED_OUT, 'last_active_at' => now()->subDays(2)],
        ]);
        static::__staff($target, null, [
            'staff' => ['login_user_id' => $target, 'site_id' => self::SITE_ID, 'signed_out_at' => now()->subDays(60), 'sign_out_reason_id' => Session::SIGN_OUT_SIGNED_OUT, 'last_active_at' => now()->subDays(60)],
        ]);

        $listed = Session::get_sessions_for_user($target);

        static::__assert_equals([$recent], array_column($listed, 'id'), 'only the sign-out inside rsx.sessions.unused_session_days is listed');
        static::__assert_false($listed[0]['is_signed_in']);
        static::__assert_equals('Signed out', $listed[0]['sign_out_reason']);
    }

    /** SESSION-LIST-02 - a harness browser is never listed */
    public static function test_a_harness_browser_is_never_listed()
    {
        $target = 880011;
        static::__staff($target, null, ['type_id' => Session::TYPE_PLAYWRIGHT]);
        $real = static::__staff($target);

        static::__assert_equals([$real], array_column(Session::get_sessions_for_user($target), 'id'));
    }
}
