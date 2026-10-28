<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A command, task or test that declares a login identity and a site gets the SAME site user a
 * web request derives from them: the users row for (login_user_id, site_id).
 *
 * The virtual session used to answer get_user_id() only from a value impersonate() stored, so
 * set_login_user_id() + set_site_id() answered null and a save stamped the LOGIN identity as its
 * author where a browser stamps the site user. Subject: the baseline user 1 (login user 1, site 1).
 */
class Session_Cli_User_Derivation_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Session::clear_temporary_site_id();
    }

    public static function test_login_identity_plus_site_derives_the_site_user()
    {
        static::__reset_session();
        Session::set_login_user_id(1);
        Session::set_site_id(1);

        static::__assert_equals(1, Session::get_user_id(), 'the users row for login user 1 on site 1');
        static::__assert_equals(1, (int) Session::get_user()?->id, 'get_user() agrees');
    }

    public static function test_a_save_is_stamped_with_the_site_user()
    {
        static::__reset_session();
        Session::set_login_user_id(1);
        Session::set_site_id(1);

        $user = User_Model::find(1);
        $user->phone = '5550007777';
        $user->save();
        $user->refresh();

        static::__assert_equals('User_Model', $user->updated_by_type, 'authored by a User_Model, not the login identity');
        static::__assert_equals(1, (int) $user->updated_by_id, 'authored by user 1');
    }

    public static function test_another_site_answers_that_sites_user_not_the_previous_one()
    {
        static::__reset_session();
        Session::set_login_user_id(1);
        Session::set_site_id(1);
        static::__assert_equals(1, Session::get_user_id(), 'site 1 resolves first');

        $site = new Site_Model();
        $site->slug = 'cli-derivation-' . uniqid();
        $site->name = 'CLI Derivation';
        $site->is_enabled = true;
        $site->save();

        Session::set_temporary_site_id((int) $site->id);
        static::__assert_null(Session::get_user_id(), 'no membership on the declared site, and no stale answer from site 1');
        Session::clear_temporary_site_id();

        static::__assert_equals(1, Session::get_user_id(), 'back on site 1');
    }

    public static function test_an_impersonated_user_id_still_wins()
    {
        static::__reset_session();
        Session::impersonate(1, 1, 55);

        static::__assert_equals(55, Session::get_user_id(), 'impersonate() names the user outright');
    }
}
