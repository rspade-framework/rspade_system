<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * WHERE-clause conversion of type-ref columns in RestrictedEloquentBuilder.
 *
 * The failure mode this whole seam exists to prevent is SILENT: an unconverted class-name
 * string compared against a BIGINT type-ref column is coerced to 0 by MySQL, so the clause
 * looks correct, raises nothing, and matches nothing.
 *
 * Three spellings, three answers:
 *   - bare ('subject_type')                            -> converted
 *   - self-qualified ('portal_notifications.subject_type') -> converted, qualifier preserved
 *   - foreign-qualified ('activities.subject_type')     -> NOT converted (that column belongs
 *     to a joined table whose type-ref declaration is not this builder's to read)
 *
 * And the defense: a value that is neither null nor an integer and does NOT resolve in the
 * registry THROWS, naming the model, the column and the value - never binds the string.
 *
 * Fixtures use Portal_Notification_Model (subject_type/subject_id), framework-core and
 * already in the type-ref registry. Runs in the default per-test transaction.
 */
class Polymorphic_Where_Conversion_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_notification(?string $subject_type, ?int $subject_id): Portal_Notification_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'polywhere_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        $row = new Portal_Notification_Model();
        $row->site_id = self::SITE_ID;
        $row->portal_user_id = $user->id;
        $row->type = 'poly_where_test';
        $row->subject_type = $subject_type;
        $row->subject_id = $subject_id;
        $row->save();

        return $row;
    }

    // =====================================================================
    // Which spellings convert
    // =====================================================================

    public static function test_bare_column_converts_and_matches_the_row()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);
        $type_ref_id = Type_Ref_Registry::class_to_id('Site_Model');

        $query = Portal_Notification_Model::where('subject_type', 'Site_Model');

        static::__assert_true(in_array($type_ref_id, $query->getBindings(), true), 'the integer id is bound, not the class name');
        static::__assert_true(in_array($row->id, $query->pluck('id')->all()));
    }

    public static function test_self_table_qualified_column_converts_and_keeps_the_qualifier_in_the_sql()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);
        $type_ref_id = Type_Ref_Registry::class_to_id('Site_Model');

        $query = Portal_Notification_Model::where('portal_notifications.subject_type', 'Site_Model');

        static::__assert_true(in_array($type_ref_id, $query->getBindings(), true));
        static::__assert_contains(
            '`portal_notifications`.`subject_type`',
            $query->toSql(),
            'the conversion lookup uses the bare name; the emitted clause stays table-qualified'
        );
        static::__assert_true(in_array($row->id, $query->pluck('id')->all()));
    }

    public static function test_a_foreign_table_qualifier_is_left_alone()
    {
        // 'activities' is not this model's table: that column belongs to a joined table whose
        // type-ref declaration this builder cannot read, so the value passes through untouched.
        $query = Portal_Notification_Model::where('activities.subject_type', 'Site_Model');

        static::__assert_true(in_array('Site_Model', $query->getBindings(), true), 'a foreign qualifier is not converted');
    }

    // =====================================================================
    // The or/not siblings - they delegate to where() with a fixed arity, so the
    // short form has to be converted before the delegation loses it
    // =====================================================================

    public static function test_or_where_short_form_converts()
    {
        $type_ref_id = Type_Ref_Registry::class_to_id('Site_Model');

        static::__assert_true(in_array(
            $type_ref_id,
            Portal_Notification_Model::query()->orWhere('subject_type', 'Site_Model')->getBindings(),
            true
        ));
        static::__assert_true(in_array(
            $type_ref_id,
            Portal_Notification_Model::query()->orWhere('portal_notifications.subject_type', 'Site_Model')->getBindings(),
            true
        ));
    }

    public static function test_where_not_and_or_where_not_short_forms_convert()
    {
        $type_ref_id = Type_Ref_Registry::class_to_id('Site_Model');

        static::__assert_true(in_array(
            $type_ref_id,
            Portal_Notification_Model::query()->whereNot('subject_type', 'Site_Model')->getBindings(),
            true
        ));
        static::__assert_true(in_array(
            $type_ref_id,
            Portal_Notification_Model::query()->orWhereNot('portal_notifications.subject_type', 'Site_Model')->getBindings(),
            true
        ));
    }

    public static function test_where_in_converts_both_spellings_and_leaves_other_values_alone()
    {
        $row = static::__make_notification('Site_Model', self::SITE_ID);
        $type_ref_id = Type_Ref_Registry::class_to_id('Site_Model');

        $query = Portal_Notification_Model::whereIn(
            'portal_notifications.subject_type',
            ['Site_Model', 999999, null]
        );

        $bindings = $query->getBindings();
        static::__assert_true(in_array($type_ref_id, $bindings, true), 'the class name in the array is converted');
        static::__assert_true(in_array(999999, $bindings, true), 'an integer in the array passes through');
        static::__assert_true(in_array($row->id, $query->pluck('id')->all()));
    }

    // =====================================================================
    // Integers and nulls are never touched
    // =====================================================================

    public static function test_integer_and_null_values_pass_through_untouched()
    {
        $type_ref_id = Type_Ref_Registry::class_to_id('Site_Model');
        $row = static::__make_notification('Site_Model', self::SITE_ID);
        $unset = static::__make_notification(null, null);

        $by_id = Portal_Notification_Model::where('subject_type', $type_ref_id)->pluck('id')->all();
        static::__assert_true(in_array($row->id, $by_id), 'an integer id still matches');

        $null_ids = Portal_Notification_Model::whereNull('subject_type')->pluck('id')->all();
        static::__assert_true(in_array($unset->id, $null_ids), 'a null pair is untouched');

        static::__assert_true(in_array(
            $type_ref_id,
            Portal_Notification_Model::where('portal_notifications.subject_type', '=', $type_ref_id)->getBindings(),
            true
        ));
    }

    // =====================================================================
    // The silent-zero defense
    // =====================================================================

    public static function test_an_unresolvable_value_on_a_bare_type_ref_column_throws()
    {
        $error = static::__assert_throws(
            \RuntimeException::class,
            fn() => Portal_Notification_Model::where('subject_type', 'Not_A_Real_Model_Xyz')->exists()
        );

        static::__assert_contains('Not_A_Real_Model_Xyz', $error->getMessage(), 'the value is named');
        static::__assert_contains('subject_type', $error->getMessage(), 'the column is named');
        static::__assert_contains('Portal_Notification_Model', $error->getMessage(), 'the model is named');
    }

    public static function test_an_unresolvable_value_on_a_qualified_type_ref_column_throws()
    {
        $error = static::__assert_throws(
            \RuntimeException::class,
            fn() => Portal_Notification_Model::where('portal_notifications.subject_type', 'Not_A_Real_Model_Xyz')->exists()
        );

        static::__assert_contains('portal_notifications.subject_type', $error->getMessage());
    }

    public static function test_an_unresolvable_value_inside_a_where_in_array_throws()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => Portal_Notification_Model::whereIn('subject_type', ['Site_Model', 'Not_A_Real_Model_Xyz'])->exists(),
            'Not_A_Real_Model_Xyz'
        );
    }
}
