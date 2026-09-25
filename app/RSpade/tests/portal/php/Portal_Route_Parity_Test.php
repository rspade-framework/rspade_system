<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Portal::Route() builds its URL with Rsx::Route()'s own routines, so a portal URL is
 * the staff URL for the same pattern and params with the portal base applied: whole-token
 * replacement, the query string, the reserved `at` key riding the #at= anchor
 * (rsx:man anchors) and the hash argument all behave identically in the two realms.
 *
 * The JS twin (Rsx_Portal.Route) is pinned by playwright/portal_route_parity.js.
 */
class Portal_Route_Parity_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const PORTAL_TARGET = 'Portal_Route_Parity_Fixture_Controller::item';

    private const STAFF_TARGET = 'Portal_Route_Parity_Staff_Fixture_Controller::item';

    private const PARAMS = ['id' => 5, 'id_type' => 7, 'at' => 'a b', 'x' => 1];

    public static function test_staff_route_carries_the_anchor_after_the_query_string()
    {
        static::__assert_equals(
            '/test-route-parity/5/7?x=1#at=a%20b',
            Rsx::Route(self::STAFF_TARGET, self::PARAMS),
            'the staff URL replaces :id and :id_type whole, and at rides the hash'
        );
    }

    public static function test_portal_route_carries_the_anchor_and_the_portal_base()
    {
        static::__assert_equals(
            Rsx_Portal::portal_path('/test-route-parity/5/7?x=1#at=a%20b'),
            Rsx_Portal::Route(self::PORTAL_TARGET, self::PARAMS),
            'the portal URL is built the same way and carries the portal base'
        );
    }

    public static function test_portal_url_is_the_staff_url_with_the_portal_base()
    {
        static::__assert_equals(
            Rsx_Portal::portal_path(Rsx::Route(self::STAFF_TARGET, self::PARAMS)),
            Rsx_Portal::Route(self::PORTAL_TARGET, self::PARAMS),
            'one generator: the two realms differ only by the base'
        );
    }

    public static function test_portal_route_without_an_anchor_has_no_fragment()
    {
        static::__assert_equals(
            Rsx_Portal::portal_path('/test-route-parity/5/7'),
            Rsx_Portal::Route(self::PORTAL_TARGET, ['id' => 5, 'id_type' => 7, 'at' => '']),
            'an empty anchor appends nothing'
        );
    }

    public static function test_portal_route_carries_the_hash_state_before_the_anchor()
    {
        static::__assert_equals(
            Rsx_Portal::portal_path('/test-route-parity/5/7?x=1#tab=a%20b&at=a%20b'),
            Rsx_Portal::Route(self::PORTAL_TARGET, self::PARAMS, ['tab' => 'a b', 'gone' => null]),
            'the hash argument is Rsx::Route()\'s, with the portal base'
        );
        static::__assert_equals(
            Rsx_Portal::portal_path(Rsx::Route(self::STAFF_TARGET, self::PARAMS, ['tab' => 'a b'])),
            Rsx_Portal::Route(self::PORTAL_TARGET, self::PARAMS, ['tab' => 'a b']),
            'one generator for the fragment too'
        );
    }
}
