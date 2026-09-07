<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Polymorphic transparency: STOCK Eloquent morph relations over a BIGINT type-ref
 * discriminator column.
 *
 * Type_Ref_Registry::register_morph_map() registers every type ref under TWO morph-map
 * aliases pointing at the same FQCN - the simple class name AND the integer type-ref id.
 * That is the whole mechanism: morphTo() reads the RAW attribute (an integer, because it
 * bypasses the cast) and resolves it through the morph map, while a WRITE goes through
 * getMorphClass() - which must answer with the CLASS-NAME alias so Rsx_Type_Ref_Cast can
 * convert it back to the integer. Alias ORDER is therefore load-bearing and is pinned
 * here.
 *
 * Fixtures use Portal_Notification_Model (subject_type/subject_id) as the child and
 * Site_Model as the polymorphic target - both framework-core, both already in the
 * type-ref registry. Runs in the default per-test transaction.
 */
class Polymorphic_Morph_Relations_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'poly_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * A notification row pointing at $subject_type/$subject_id (either may be null).
     */
    private static function __make_notification(?string $subject_type, ?int $subject_id): Portal_Notification_Model
    {
        $user = static::__make_portal_user();

        $row = new Portal_Notification_Model();
        $row->site_id = self::SITE_ID;
        $row->portal_user_id = $user->id;
        $row->type = 'poly_test';
        $row->subject_type = $subject_type;
        $row->subject_id = $subject_id;
        $row->save();

        return $row;
    }

    /**
     * The RAW stored row for a notification, straight from SQL.
     *
     * Deliberately NOT the ORM: the whole point of these assertions is the value ON DISK,
     * and Rsx_Type_Ref_Cast would convert the integer back to a class name before a model
     * read could see it. DB::select() with a prepared parameter is the sanctioned form
     * (PHP-DB-01 names it for exactly this case; DB::table() is what the rule forbids).
     */
    private static function __raw_row(int $id): object
    {
        return DB::select(
            'SELECT subject_type, subject_id FROM portal_notifications WHERE id = ?',
            [$id]
        )[0];
    }

    // =====================================================================
    // The morph map - the mechanism itself
    // =====================================================================

    public static function test_morph_map_registers_both_the_class_name_and_the_integer_alias()
    {
        $id = Type_Ref_Registry::class_to_id('Site_Model');

        static::__assert_equals(
            Site_Model::class,
            Relation::getMorphedModel('Site_Model'),
            'the class-name alias resolves to the FQCN'
        );
        static::__assert_equals(
            Site_Model::class,
            Relation::getMorphedModel($id),
            'the integer type-ref id resolves to the same FQCN - this is what makes morphTo() work'
        );
        static::__assert_equals(
            Site_Model::class,
            Relation::getMorphedModel((string) $id),
            'a raw attribute arriving as a numeric STRING resolves identically (PHP coerces the key)'
        );
    }

    /**
     * ORDER IS LOAD-BEARING. getMorphClass() answers with the FIRST morph-map key that
     * maps to the class, and that value is what a WRITE puts in the type column. It must
     * be the class-name alias: the integer alias would make the cast try to register a
     * model class literally named "14".
     */
    public static function test_get_morph_class_answers_with_the_class_name_alias()
    {
        static::__assert_equals(
            'Site_Model',
            (new Site_Model())->getMorphClass(),
            'the class-name alias must precede the integer alias in the morph map'
        );
    }

    // =====================================================================
    // Read - lazy morphTo over an integer discriminator
    // =====================================================================

    public static function test_morph_to_resolves_the_integer_discriminator()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $raw = static::__raw_row($row->id);
        static::__assert_equals(
            Type_Ref_Registry::class_to_id('Site_Model'),
            (int) $raw->subject_type,
            'the discriminator is stored as the BIGINT type-ref id, not a class name'
        );

        $fresh = Portal_Notification_Model::find($row->id);
        $subject = $fresh->subject;

        static::__assert_instance_of(Site_Model::class, $subject, 'morphTo() resolved the raw integer');
        static::__assert_equals(self::SITE_ID, (int) $subject->id);
    }

    public static function test_morph_to_relation_object_queries_the_right_table()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $result = Portal_Notification_Model::find($row->id)->subject()->first();

        static::__assert_instance_of(Site_Model::class, $result);
    }

    public static function test_morph_to_caches_the_loaded_relation()
    {
        $fresh = Portal_Notification_Model::find(static::__make_notification('Site_Model', self::SITE_ID)->id);

        $first = $fresh->subject;
        $second = $fresh->subject;

        static::__assert_true($first === $second, 'a second property read returns the cached relation, not a new query');
    }

    public static function test_morph_to_is_null_when_the_pair_is_empty()
    {
        $fresh = Portal_Notification_Model::find(static::__make_notification(null, null)->id);

        static::__assert_null($fresh->subject, 'an unset polymorphic reference resolves to null');
    }

    public static function test_morph_to_is_null_when_the_target_row_is_gone()
    {
        $fresh = Portal_Notification_Model::find(static::__make_notification('Site_Model', 999999999)->id);

        static::__assert_null($fresh->subject, 'a dangling id resolves to null, not an error');
    }

    // =====================================================================
    // Write - associate() / dissociate() round-trip through the cast
    // =====================================================================

    public static function test_associate_persists_the_integer_discriminator()
    {
        $user = static::__make_portal_user();
        $site = Site_Model::find(self::SITE_ID);

        $row = new Portal_Notification_Model();
        $row->site_id = self::SITE_ID;
        $row->portal_user_id = $user->id;
        $row->type = 'poly_associate';
        $row->subject()->associate($site);

        static::__assert_equals('Site_Model', $row->subject_type, 'the accessor reads back the class name');
        $row->save();

        $raw = static::__raw_row($row->id);
        static::__assert_equals(
            Type_Ref_Registry::class_to_id('Site_Model'),
            (int) $raw->subject_type,
            'associate() wrote the class-name alias, which the cast converted to the type-ref id'
        );
        static::__assert_equals(self::SITE_ID, (int) $raw->subject_id);

        static::__assert_instance_of(Site_Model::class, Portal_Notification_Model::find($row->id)->subject);
    }

    public static function test_dissociate_clears_both_columns()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $row->subject()->dissociate();
        $row->save();

        $raw = static::__raw_row($row->id);
        static::__assert_null($raw->subject_type);
        static::__assert_null($raw->subject_id);
    }

    // =====================================================================
    // The forward side - morphMany / morphOne constraints
    // =====================================================================

    public static function test_morph_many_constrains_on_the_integer_discriminator()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);
        $site = Site_Model::find(self::SITE_ID);

        $relation = $site->morphMany(Portal_Notification_Model::class, 'subject');
        $bindings = $relation->getBindings();

        static::__assert_true(
            in_array(Type_Ref_Registry::class_to_id('Site_Model'), $bindings, true),
            'the relation binds the type-ref INTEGER; binding the class-name string would silently match nothing'
        );

        $ids = $relation->get()->pluck('id')->all();
        static::__assert_true(in_array($row->id, $ids), 'morphMany finds the child row');
    }

    public static function test_morph_one_resolves_over_a_type_ref_column()
    {
        static::__make_notification('Site_Model', self::SITE_ID);
        $site = Site_Model::find(self::SITE_ID);

        static::__assert_instance_of(
            Portal_Notification_Model::class,
            $site->morphOne(Portal_Notification_Model::class, 'subject')->first()
        );
    }

    public static function test_morph_many_save_writes_the_pair()
    {
        $user = static::__make_portal_user();
        $site = Site_Model::find(self::SITE_ID);

        $child = new Portal_Notification_Model();
        $child->site_id = self::SITE_ID;
        $child->portal_user_id = $user->id;
        $child->type = 'poly_morph_many_save';
        $site->morphMany(Portal_Notification_Model::class, 'subject')->save($child);

        $raw = static::__raw_row($child->id);
        static::__assert_equals(Type_Ref_Registry::class_to_id('Site_Model'), (int) $raw->subject_type);
        static::__assert_equals(self::SITE_ID, (int) $raw->subject_id);
    }

    // =====================================================================
    // Query helpers
    // =====================================================================

    public static function test_where_morphed_to_matches_the_type_ref_rows()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $ids = Portal_Notification_Model::whereMorphedTo('subject', Site_Model::find(self::SITE_ID))
            ->pluck('id')
            ->all();

        static::__assert_true(in_array($row->id, $ids));
    }

    public static function test_where_has_morph_matches_the_type_ref_rows()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $ids = Portal_Notification_Model::whereHasMorph('subject', [Site_Model::class])
            ->pluck('id')
            ->all();

        static::__assert_true(in_array($row->id, $ids), 'whereHasMorph qualifies the type column; the conversion must still apply');
    }

    /**
     * The qualified spelling is what Eloquent's own relations produce
     * (HasRelationships::morphMany passes "$table.$type"), so the builder's type-ref
     * conversion must accept it - unconverted, a class-name string against a BIGINT
     * column is coerced to 0 by MySQL and matches nothing.
     */
    public static function test_table_qualified_type_ref_column_is_converted_in_a_where()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $ids = Portal_Notification_Model::where('portal_notifications.subject_type', 'Site_Model')
            ->pluck('id')
            ->all();

        static::__assert_true(in_array($row->id, $ids));
    }

    public static function test_unqualified_type_ref_column_is_converted_in_a_where()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);

        $ids = Portal_Notification_Model::where('subject_type', 'Site_Model')->pluck('id')->all();

        static::__assert_true(in_array($row->id, $ids));
    }
}
