<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A site-scoped READ needs a DECLARED site, and a declared site must exist.
 *
 * With no site declared the scope used to read site 0, so a plain task's find() answered null
 * and every query matched nothing, silently. It now throws. Site 0 is a real site and may be
 * declared by name. The setters only record a site; the first site-scoped query checks it
 * exists. User_Model (site-scoped, framework) and the baseline user 1 on site 1 are the subject.
 */
class Site_Declaration_Test extends Rsx_Test_Abstract
{
    private const NO_SUCH_SITE = 987654321;

    public static function teardown()
    {
        Session::clear_temporary_site_id();
    }

    public static function test_a_read_with_no_site_declared_throws()
    {
        static::__reset_session();
        Session::clear_temporary_site_id();

        static::__assert_false(Session::has_declared_site(), 'nothing declared after the reset');
        static::__assert_throws(
            \RuntimeException::class,
            fn () => User_Model::find(1),
            'Site-scoped read of User_Model with no site declared'
        );
    }

    public static function test_naming_the_site_in_the_query_is_not_a_declaration()
    {
        static::__reset_session();
        Session::clear_temporary_site_id();

        static::__assert_throws(
            \RuntimeException::class,
            fn () => User_Model::where('site_id', 1)->first(),
            'no site declared'
        );
    }

    public static function test_a_declared_site_reads_and_without_site_scope_needs_none()
    {
        static::__reset_session();

        Session::set_temporary_site_id(1);
        static::__assert_true(Session::has_declared_site(), 'a temporary declaration counts');
        static::__assert_equals(1, (int) User_Model::find(1)?->id, 'user 1 is read on site 1');
        Session::clear_temporary_site_id();

        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_equals(1, (int) $user?->id, 'without_site_scope() reads with no site declared');
    }

    public static function test_site_zero_declared_by_name_is_a_declaration()
    {
        static::__reset_session();
        Session::set_site_id(0);

        static::__assert_true(Session::has_declared_site(), 'site 0 named is declared');
        static::__assert_null(User_Model::find(1), 'the read runs, scoped to site 0');

        static::__reset_session();
    }

    public static function test_a_site_with_no_row_is_refused_at_first_use_not_when_set()
    {
        static::__reset_session();

        Session::set_temporary_site_id(self::NO_SUCH_SITE);   // recorded, nothing queried
        static::__assert_equals(self::NO_SUCH_SITE, Session::get_site_id(), 'the setter records the site');

        static::__assert_throws(
            \RuntimeException::class,
            fn () => User_Model::find(1),
            'there is no site ' . self::NO_SUCH_SITE
        );

        Session::clear_temporary_site_id();
    }
}
