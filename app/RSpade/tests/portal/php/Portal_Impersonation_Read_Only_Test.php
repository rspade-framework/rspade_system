<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Portal\Php\Portal_Impersonation_Fixture_Controller;

/**
 * "View as Client" is read-only BY DEFAULT, at the framework seam (Ajax::execute).
 *
 * While Portal_Session::is_impersonating(), a portal-realm Ajax endpoint runs only when it
 * carries #[Portal_Impersonation_Readable]; every other one is refused as unauthorized,
 * before its code runs. Without impersonation both run. The mark is baked into the surface
 * index at manifest build.
 */
class Portal_Impersonation_Read_Only_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * setup()/teardown() run once per CLASS; each test starts from zero counts itself.
     */
    private static function __fresh(bool $impersonating): void
    {
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_impersonator_user_id($impersonating ? 1 : null);
        Portal_Impersonation_Fixture_Controller::$reads = 0;
        Portal_Impersonation_Fixture_Controller::$writes = 0;
    }

    public static function teardown()
    {
        Portal_Session::cli_set_impersonator_user_id(null);
        Rsx_Portal::set_portal_request(false);
        Portal_Impersonation_Fixture_Controller::$reads = 0;
        Portal_Impersonation_Fixture_Controller::$writes = 0;
    }

    public static function test_the_mark_is_in_the_surface_index()
    {
        $surfaces = Auth_Gates::get_surfaces();

        static::__assert_true(!empty($surfaces['Portal_Impersonation_Fixture_Controller::read']['impersonation_readable']));
        static::__assert_true(empty($surfaces['Portal_Impersonation_Fixture_Controller::write']['impersonation_readable']));
    }

    public static function test_an_unmarked_endpoint_is_refused_while_impersonating()
    {
        static::__fresh(true);

        static::__assert_throws(AjaxUnauthorizedException::class, function () {
            Ajax::internal('Portal_Impersonation_Fixture_Controller', 'write');
        }, 'read-only session');
        static::__assert_equals(0, Portal_Impersonation_Fixture_Controller::$writes, 'the endpoint never ran');
    }

    public static function test_a_marked_endpoint_runs_while_impersonating()
    {
        static::__fresh(true);

        static::__assert_equals(['ok' => true], Ajax::internal('Portal_Impersonation_Fixture_Controller', 'read'));
        static::__assert_equals(1, Portal_Impersonation_Fixture_Controller::$reads);
    }

    public static function test_both_run_without_impersonation()
    {
        static::__fresh(false);

        Ajax::internal('Portal_Impersonation_Fixture_Controller', 'read');
        Ajax::internal('Portal_Impersonation_Fixture_Controller', 'write');

        static::__assert_equals(1, Portal_Impersonation_Fixture_Controller::$reads);
        static::__assert_equals(1, Portal_Impersonation_Fixture_Controller::$writes);
    }

    public static function test_the_framework_read_endpoints_a_portal_page_needs_are_marked()
    {
        $surfaces = Auth_Gates::get_surfaces();

        foreach ([
            'Orm_Controller::fetch',
            'Orm_Controller::fetch_relationship',
            'Spa_Session_Controller::get_state',
            'Realtime_Controller::get_connection_token',
            'Realtime_Controller::get_subscribe_token',
            'File_Preview_Controller::get_preview_info',
        ] as $target) {
            static::__assert_true(!empty($surfaces[$target]['impersonation_readable']), "{$target} is readable during View as Client");
        }
    }
}
