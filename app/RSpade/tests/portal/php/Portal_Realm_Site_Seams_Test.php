<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Settings\Rsx_Settings;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Throttle\Rsx_Throttle;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * THE SITE SEAMS ARE REALM-AWARE (backlog B-76).
 *
 * "Which site is this?" used to be answered by the STAFF session facade everywhere,
 * including on portal requests - where it is the wrong question. A browser has ONE
 * session row carrying BOTH identities, and the staff tenant (site_id) and the portal
 * tenant (portal_site_id) are different columns with different meanings. So every seam
 * that asks now forks on the EXPERIENCE OF THE REQUEST (Rsx_Portal::is_portal_request()),
 * never on who is signed in.
 *
 * The load-bearing one is Rsx_Site_Model_Abstract::get_current_site_id(), the tenant
 * boundary for every site-scoped model; the rest (Rsx_Time, Rsx_Sms, Rsx_Settings,
 * Rsx_Throttle - and Rsx_Mail, converted earlier) resolve a site for a queue row, a
 * timezone or a rate-limit bucket.
 *
 * These tests deliberately declare the STAFF site and the PORTAL site as DIFFERENT
 * sites, then assert which one each seam picks. Under the old behavior every portal
 * assertion here returns the staff site - which is what made a portal query in prefix
 * mode read whatever tenant the co-resident staff cookie happened to be on.
 *
 * Rsx_Portal::set_portal_request() is the request-context seam the other portal tests
 * use; teardown always puts it back to false, since it is a process-wide static.
 */
class Portal_Realm_Site_Seams_Test extends Rsx_Test_Abstract
{
    private static int $_staff_site_id = 0;

    private static int $_portal_site_id = 0;

    public static function setup(): void
    {
        static::$_staff_site_id = static::__make_site('staff');
        static::$_portal_site_id = static::__make_site('portal');

        static::__acting_as_site(static::$_staff_site_id);
        Portal_Session::reset();
    }

    public static function teardown(): void
    {
        Rsx_Portal::set_portal_request(false);
        Portal_Session::reset();
        static::__reset_session();
    }

    /**
     * Site_Model is not site-scoped, so a site row is created independent of session.
     */
    private static function __make_site(string $tag): int
    {
        $site = new Site_Model();
        $site->slug = 'realm-seam-' . $tag . '-' . uniqid();
        $site->name = 'Realm Seam ' . $tag;
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    /**
     * Enter a portal request whose declared site is the portal site.
     */
    private static function __as_portal_request(): void
    {
        Portal_Session::reset();
        Portal_Session::set_site_id(static::$_portal_site_id);
        Rsx_Portal::set_portal_request(true);
    }

    private static function __as_staff_request(): void
    {
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_site(static::$_staff_site_id);
    }

    // =====================================================================
    // The tenant boundary (Rsx_Site_Model_Abstract::get_current_site_id)
    // =====================================================================

    public static function test_a_portal_request_scopes_models_to_the_portal_site()
    {
        static::__as_portal_request();

        static::__assert_equals(
            static::$_portal_site_id,
            Rsx_Site_Model_Abstract::get_current_site_id(),
            'the ORM tenant boundary answers with the DECLARED portal site, not the staff session site'
        );
    }

    public static function test_a_staff_request_still_scopes_models_to_the_staff_site()
    {
        static::__as_staff_request();

        static::__assert_equals(
            static::$_staff_site_id,
            Rsx_Site_Model_Abstract::get_current_site_id(),
            'the staff branch is untouched'
        );
    }

    /**
     * The behavior that matters: a real query on a site-scoped model. A row created on
     * a portal request belongs to the PORTAL's tenant and is invisible to the staff site.
     */
    public static function test_a_model_written_on_a_portal_request_belongs_to_the_portal_site()
    {
        static::__as_portal_request();

        $portal_user = new Portal_User_Model();
        $portal_user->email = 'seam_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        $id = (int) $portal_user->id;

        static::__assert_equals(
            static::$_portal_site_id,
            (int) $portal_user->site_id,
            'the creating hook stamped the portal tenant'
        );
        static::__assert_not_null(
            Portal_User_Model::find($id),
            'and the row is readable inside the portal request that created it'
        );

        static::__as_staff_request();

        static::__assert_null(
            Portal_User_Model::find($id),
            'the same row is invisible to a staff request on another tenant - which is the point'
        );
    }

    /**
     * A portal request that declared no site REFUSES rather than scoping to 0 (or to
     * whatever tenant a co-resident staff session is on). Silent site 0 is a cross-tenant
     * read primitive that reports success; the throw is the contract (ruling 12).
     */
    public static function test_a_portal_request_with_no_declared_site_throws()
    {
        Portal_Session::reset();
        Rsx_Portal::set_portal_request(true);

        static::__assert_throws(\RuntimeException::class, function () {
            Rsx_Site_Model_Abstract::get_current_site_id();
        }, 'portal site has not been declared');
    }

    // =====================================================================
    // Rsx_Time - the site timezone tier
    // =====================================================================

    public static function test_the_site_timezone_comes_from_the_portal_site_on_a_portal_request()
    {
        Site_Model::where('id', static::$_staff_site_id)
            ->raw_bulk()
            ->update(['timezone' => 'America/New_York']);
        Site_Model::where('id', static::$_portal_site_id)
            ->raw_bulk()
            ->update(['timezone' => 'Europe/Paris']);

        static::__as_portal_request();
        static::__assert_equals(
            'Europe/Paris',
            Rsx_Time::get_site_timezone(),
            'a portal page renders in the PORTAL tenant zone'
        );

        static::__as_staff_request();
        static::__assert_equals(
            'America/New_York',
            Rsx_Time::get_site_timezone(),
            'and a staff page in the staff tenant zone'
        );
    }

    /**
     * get_user_timezone() memoizes, so the realm has to be part of the cache key -
     * otherwise the first experience to ask wins for the rest of the process.
     */
    public static function test_the_user_timezone_cache_is_not_shared_across_experiences()
    {
        Site_Model::where('id', static::$_staff_site_id)
            ->raw_bulk()
            ->update(['timezone' => 'America/New_York']);
        Site_Model::where('id', static::$_portal_site_id)
            ->raw_bulk()
            ->update(['timezone' => 'Europe/Paris']);

        static::__as_staff_request();
        $staff_zone = Rsx_Time::get_user_timezone();

        static::__as_portal_request();
        $portal_zone = Rsx_Time::get_user_timezone();

        static::__as_staff_request();
        $staff_zone_again = Rsx_Time::get_user_timezone();

        static::__assert_equals('America/New_York', $staff_zone, 'staff: no user preference, so the staff site');
        static::__assert_equals('Europe/Paris', $portal_zone, 'portal: the portal site, never the staff one');
        static::__assert_equals(
            'America/New_York',
            $staff_zone_again,
            'flipping back is not served the portal memo'
        );
    }

    // =====================================================================
    // Rsx_Settings - the site-scoped value store
    // =====================================================================

    public static function test_a_site_scoped_setting_is_stored_under_the_portal_site()
    {
        $key = 'realm_seam_' . uniqid();

        Rsx_Settings::define([
            'key' => $key,
            'label' => 'Realm seam probe',
            'type' => Rsx_Settings::TYPE_TEXT,
            'scope' => Rsx_Settings::SCOPE_SITE,
        ]);

        static::__as_staff_request();
        Rsx_Settings::set($key, 'staff value');

        static::__as_portal_request();
        Rsx_Settings::set($key, 'portal value');

        static::__assert_equals(
            'portal value',
            Rsx_Settings::get($key),
            'the portal request reads its own tenant value'
        );

        static::__as_staff_request();
        static::__assert_equals(
            'staff value',
            Rsx_Settings::get($key),
            'and the staff value was never overwritten - two tenants, two rows'
        );
    }

    // =====================================================================
    // Rsx_Throttle - the rate-limit bucket
    // =====================================================================

    /**
     * The bucket key is (site_id, user_id, action_key). With the site half realm-blind,
     * every tenant's portal callers collapsed onto one bucket.
     */
    public static function test_the_throttle_bucket_is_keyed_on_the_portal_site()
    {
        $action = 'REALM_SEAM_' . strtoupper(substr(uniqid(), -8));
        $user_id = 4242;

        static::__as_staff_request();
        static::__assert_true(Rsx_Throttle::check($action, $user_id, 60), 'first staff call claims the bucket');
        static::__assert_false(Rsx_Throttle::check($action, $user_id, 60), 'and the staff bucket is now throttled');

        static::__as_portal_request();
        static::__assert_true(
            Rsx_Throttle::check($action, $user_id, 60),
            'the portal tenant has its OWN bucket - it is not throttled by the staff call'
        );

        static::__assert_equals(
            1,
            (int) DB::table('_throttle')
                ->where('action_key', $action)
                ->where('site_id', static::$_portal_site_id)
                ->count(),
            'and the row it claimed is filed under the portal site'
        );
    }
}
