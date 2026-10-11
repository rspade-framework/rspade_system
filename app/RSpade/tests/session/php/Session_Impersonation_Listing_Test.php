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
 * An impersonation is not one of the impersonated user's sessions.
 *
 * Impersonating puts the target's identity on the IMPERSONATOR's own browser row, so that
 * row answers to "the sessions carrying this identity" for as long as it lasts. Both
 * get_sessions_for_user() listings leave it out unless the caller asks for it, and say
 * which entries are impersonations when it does - the user's own security screen never
 * shows somebody else's address and device as a sign-in of theirs, and an administrator's
 * view can label one.
 */
class Session_Impersonation_Listing_Test extends Rsx_Test_Abstract
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

    /** A browser session row carrying the given identities. */
    private static function __make_session(array $fields): Session
    {
        $session = new Session();
        $session->site_id = self::SITE_ID;
        $session->type_id = Session::TYPE_WEB;
        $session->session_token = bin2hex(random_bytes(16));
        $session->csrf_token = bin2hex(random_bytes(16));
        $session->ip_address = '10.0.0.21';
        $session->user_agent = 'impersonation-listing-test';
        $session->last_active = now();
        $session->active = true;
        $session->version = 1;

        foreach ($fields as $name => $value) {
            $session->$name = $value;
        }

        $session->save();

        return $session;
    }

    /** SESSION-IMP-01 - the staff listing leaves an impersonation out by default */
    public static function test_staff_listing_excludes_an_impersonation_by_default()
    {
        $target = 880001;
        $own = static::__make_session(['login_user_id' => $target]);
        static::__make_session(['login_user_id' => $target, 'impersonator_login_user_id' => 880002]);

        $listed = Session::get_sessions_for_user($target);

        static::__assert_equals([(int) $own->id], array_map('intval', array_column($listed, 'id')), 'only the user\'s own session is listed');
        static::__assert_false($listed[0]['is_impersonation']);
    }

    /** SESSION-IMP-02 - asked for, the impersonation is answered and marked */
    public static function test_staff_listing_includes_and_marks_an_impersonation_on_request()
    {
        $target = 880003;
        $own = static::__make_session(['login_user_id' => $target]);
        $impersonation = static::__make_session(['login_user_id' => $target, 'impersonator_login_user_id' => 880004]);

        $listed = Session::get_sessions_for_user($target, null, true);
        $marks = array_column($listed, 'is_impersonation', 'id');

        static::__assert_count(2, $listed);
        static::__assert_false($marks[$own->id]);
        static::__assert_true($marks[$impersonation->id]);
    }

    /** SESSION-IMP-03 - the site filter and the impersonation filter apply together */
    public static function test_staff_listing_site_filter_still_excludes_an_impersonation()
    {
        $target = 880005;
        static::__make_session(['login_user_id' => $target, 'impersonator_login_user_id' => 880006]);

        static::__assert_count(0, Session::get_sessions_for_user($target, self::SITE_ID));
        static::__assert_count(1, Session::get_sessions_for_user($target, self::SITE_ID, true));
    }

    /** SESSION-IMP-04 - the portal listing leaves a View as Client session out by default */
    public static function test_portal_listing_excludes_an_impersonation_by_default()
    {
        $portal_user = (int) static::__make_portal_user()->id;
        $own = static::__make_session(['site_id' => 0, 'portal_user_id' => $portal_user, 'portal_site_id' => self::SITE_ID]);
        $viewed = static::__make_session([
            'login_user_id' => 880008,
            'portal_user_id' => $portal_user,
            'portal_site_id' => self::SITE_ID,
            'impersonator_user_id' => 880009,
        ]);

        $listed = Portal_Session::get_sessions_for_user($portal_user);
        static::__assert_equals([(int) $own->id], array_map('intval', array_column($listed, 'id')));
        static::__assert_false($listed[0]['is_impersonation']);

        $marks = array_column(Portal_Session::get_sessions_for_user($portal_user, true), 'is_impersonation', 'id');
        static::__assert_count(2, $marks);
        static::__assert_true($marks[$viewed->id]);
        static::__assert_false($marks[$own->id]);
    }
}
