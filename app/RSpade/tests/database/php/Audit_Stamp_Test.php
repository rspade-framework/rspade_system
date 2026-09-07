<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Models\Country_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The audit authorship auto-stamp: Rsx_Model_Abstract::save() recording WHO wrote a record
 * into the created_by_id/created_by_type and updated_by_id/updated_by_type polymorphic pairs.
 *
 * The matrix under test (documented on Rsx_Model_Abstract::__apply_audit_stamp):
 *   portal actor + record in the actor's site  -> Portal_User_Model
 *   portal actor + anything else               -> nothing (no cross-site portal identity)
 *   staff actor  + record in the actor's site  -> User_Model
 *   staff actor  + anything else               -> Login_User_Model
 *   nobody signed in                           -> nothing
 *
 * Fixtures: Portal_Notification_Model (framework-core, site-scoped) and Country_Model
 * (framework-core, NOT site-scoped - the "no site" arm of the matrix). Both run in the
 * default per-test transaction.
 */
class Audit_Stamp_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const USER_ID = 1;

    public static function setup(): void
    {
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        Rsx_Portal::set_portal_request(false);
        Portal_Session::logout();
        static::__reset_session();
    }

    /**
     * A portal account in the test site. Notifications FK to one, so every site-scoped
     * fixture needs its own.
     */
    private static function __make_portal_user(): Portal_User_Model
    {
        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'audit_stamp_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        return $portal_user;
    }

    /**
     * A site-scoped fixture row. Nothing is stamped by the fixture itself - the point of
     * every test here is what save() does on its own.
     */
    private static function __make_notification(): Portal_Notification_Model
    {
        $notification = new Portal_Notification_Model();
        $notification->site_id = self::SITE_ID;
        $notification->portal_user_id = static::__make_portal_user()->id;
        $notification->type = 'audit_stamp_test';
        $notification->save();

        return $notification;
    }

    /**
     * A NON-site-scoped fixture row (countries is global reference data).
     */
    private static function __make_country(): Country_Model
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -5);

        $country = new Country_Model();
        $country->alpha2 = strtoupper(substr($suffix, 0, 2));
        $country->alpha3 = strtoupper(substr($suffix, 0, 3));
        $country->numeric = substr($suffix, 0, 3);
        $country->name = 'Audit Stamp Test ' . $suffix;
        $country->enabled = false;
        $country->save();

        return $country;
    }

    /**
     * INSERT by a staff actor whose session site is the record's site stamps BOTH pairs with
     * the site-scoped User_Model.
     */
    public static function test_insert_stamps_both_pairs_with_the_site_user()
    {
        $notification = static::__make_notification();

        static::__assert_equals('User_Model', $notification->created_by_type);
        static::__assert_equals(self::USER_ID, (int) $notification->created_by_id);
        static::__assert_equals('User_Model', $notification->updated_by_type);
        static::__assert_equals(self::USER_ID, (int) $notification->updated_by_id);

        // And it is what actually landed in the database, not just on the instance.
        $reloaded = Portal_Notification_Model::find($notification->id);
        static::__assert_equals('User_Model', $reloaded->created_by_type);
        static::__assert_equals(self::USER_ID, (int) $reloaded->created_by_id);
    }

    /**
     * The type column is a real type ref: the raw database value is the _type_refs integer,
     * and the cast is what presents it as a class name.
     */
    public static function test_type_column_stores_the_type_ref_integer()
    {
        $notification = static::__make_notification();

        // Raw SQL on purpose: the point is the value BEFORE the type-ref cast.
        $raw = \Illuminate\Support\Facades\DB::select(
            'SELECT created_by_type FROM portal_notifications WHERE id = ?',
            [$notification->id]
        )[0]->created_by_type;

        static::__assert_true(is_numeric($raw), 'created_by_type must be stored as an integer type ref');
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('User_Model'),
            (int) $raw
        );
    }

    /**
     * UPDATE moves only the updated_by pair; authorship of the creation is never rewritten.
     */
    public static function test_update_stamps_only_the_updated_pair()
    {
        $notification = new Portal_Notification_Model();
        $notification->site_id = self::SITE_ID;
        $notification->portal_user_id = static::__make_portal_user()->id;
        $notification->type = 'audit_stamp_test';
        $notification->created_by_type = 'Login_User_Model';
        $notification->created_by_id = 4242;
        $notification->save();

        $notification->type = 'audit_stamp_test_updated';
        $notification->save();

        static::__assert_equals('Login_User_Model', $notification->created_by_type);
        static::__assert_equals(4242, (int) $notification->created_by_id);
        static::__assert_equals('User_Model', $notification->updated_by_type);
        static::__assert_equals(self::USER_ID, (int) $notification->updated_by_id);
    }

    /**
     * An explicitly assigned pair is the application's, and the stamp never touches it.
     */
    public static function test_an_explicit_pair_wins_over_the_stamp()
    {
        $notification = new Portal_Notification_Model();
        $notification->site_id = self::SITE_ID;
        $notification->portal_user_id = static::__make_portal_user()->id;
        $notification->type = 'audit_stamp_test';
        $notification->created_by_type = 'Login_User_Model';
        $notification->created_by_id = 77;
        $notification->updated_by_type = 'Login_User_Model';
        $notification->updated_by_id = 78;
        $notification->save();

        static::__assert_equals('Login_User_Model', $notification->created_by_type);
        static::__assert_equals(77, (int) $notification->created_by_id);
        static::__assert_equals('Login_User_Model', $notification->updated_by_type);
        static::__assert_equals(78, (int) $notification->updated_by_id);
    }

    /**
     * A save() with nothing dirty must stay a no-op - the stamp must never manufacture a
     * write just to move updated_by.
     */
    public static function test_an_unchanged_save_is_not_stamped()
    {
        $notification = static::__make_notification();

        // Raw SQL on purpose: clearing the pair through the ORM would re-stamp it.
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE portal_notifications SET updated_by_id = NULL, updated_by_type = NULL WHERE id = ?',
            [$notification->id]
        );

        $reloaded = Portal_Notification_Model::find($notification->id);
        $reloaded->save();

        $after = \Illuminate\Support\Facades\DB::select(
            'SELECT updated_by_id, updated_by_type FROM portal_notifications WHERE id = ?',
            [$notification->id]
        )[0];

        static::__assert_null($after->updated_by_id, 'A clean save() must not write anything');
        static::__assert_null($after->updated_by_type, 'A clean save() must not write anything');
    }

    /**
     * A record with no site (a non-site-scoped model) cannot be attributed to a site-scoped
     * User_Model, so the cross-site identity is stamped instead.
     */
    public static function test_a_record_with_no_site_stamps_the_login_user()
    {
        $country = static::__make_country();

        static::__assert_equals('Login_User_Model', $country->created_by_type);
        static::__assert_equals(self::USER_ID, (int) $country->created_by_id);
    }

    /**
     * Site 0 is "no site selected", not a site - a staff actor there is attributed by its
     * cross-site identity.
     */
    public static function test_no_site_selected_stamps_the_login_user()
    {
        Session::impersonate(0, self::USER_ID, null);

        $country = static::__make_country();

        static::__assert_equals('Login_User_Model', $country->created_by_type);

        static::__acting_as_user(self::USER_ID);
    }

    /**
     * Nobody signed in leaves the pairs NULL. An unattributed record is the honest record.
     */
    public static function test_no_actor_leaves_the_pairs_null()
    {
        static::__reset_session();

        $country = static::__make_country();

        static::__assert_null($country->created_by_type);
        static::__assert_null($country->created_by_id);
        static::__assert_null($country->updated_by_type);
        static::__assert_null($country->updated_by_id);

        static::__acting_as_user(self::USER_ID);
    }

    /**
     * A portal actor writing into its own site is stamped as a Portal_User_Model - never as
     * whatever staff identity happens to share the process.
     */
    public static function test_a_portal_actor_stamps_the_portal_user()
    {
        $portal_user = static::__make_portal_user();

        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        $notification = static::__make_notification();

        Rsx_Portal::set_portal_request(false);

        static::__assert_equals('Portal_User_Model', $notification->created_by_type);
        static::__assert_equals((int) $portal_user->id, (int) $notification->created_by_id);
    }

    /**
     * A portal actor whose site does not match the record has NO truthful identity to write
     * (there is no cross-site portal account), so nothing is stamped.
     */
    public static function test_a_portal_actor_outside_its_site_stamps_nothing()
    {
        $portal_user = static::__make_portal_user();

        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        // countries has no site at all.
        $country = static::__make_country();

        Rsx_Portal::set_portal_request(false);

        static::__assert_null($country->created_by_type);
        static::__assert_null($country->created_by_id);
    }

    /**
     * The pair is readable as a stock morph relation: $record->created_by resolves to the
     * actor's model instance.
     */
    public static function test_the_created_by_relation_resolves_the_actor()
    {
        $notification = static::__make_notification();

        $actor = Portal_Notification_Model::find($notification->id)->created_by;

        static::__assert_instance_of(User_Model::class, $actor);
        static::__assert_equals(self::USER_ID, (int) $actor->id);

        $country = static::__make_country();
        $country_actor = Country_Model::find($country->id)->created_by;

        static::__assert_instance_of(Login_User_Model::class, $country_actor);
    }

    /**
     * An unattributed record's relation is null, not an exception.
     */
    public static function test_an_unattributed_relation_is_null()
    {
        static::__reset_session();
        $country = static::__make_country();
        static::__acting_as_user(self::USER_ID);

        static::__assert_null(Country_Model::find($country->id)->created_by);
    }

    /**
     * The audit type columns are type-ref cast on EVERY model, including one that declares
     * its own $type_ref_columns (a redeclared static property would otherwise shadow the
     * base's declaration and silently drop the cast).
     */
    public static function test_the_audit_type_columns_survive_a_model_declaring_its_own()
    {
        $declared = Portal_Notification_Model::_type_ref_columns();

        static::__assert_true(in_array('subject_type', $declared, true), 'model declaration kept');
        static::__assert_true(in_array('created_by_type', $declared, true), 'audit declaration merged');
        static::__assert_true(in_array('updated_by_type', $declared, true), 'audit declaration merged');

        $casts = (new Portal_Notification_Model())->getCasts();
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Rsx_Type_Ref_Cast::class,
            $casts['created_by_type'] ?? null
        );
    }

    /**
     * A WHERE on an audit type column converts the class name to its type-ref integer, on a
     * model that declares its own type-ref columns as well as one that does not.
     */
    public static function test_a_where_on_an_audit_type_column_converts()
    {
        $notification = static::__make_notification();

        $found = Portal_Notification_Model::where('created_by_type', 'User_Model')
            ->where('id', $notification->id)
            ->first();

        static::__assert_not_empty($found);

        $country = static::__make_country();
        $found_country = Country_Model::where('created_by_type', 'Login_User_Model')
            ->where('id', $country->id)
            ->first();

        static::__assert_not_empty($found_country);
    }
}
