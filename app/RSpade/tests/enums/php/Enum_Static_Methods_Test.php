<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Enums\Php;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Flash\Flash_Alert_Model;

/**
 * Tests for the static enum helper methods on Rsx_Model_Abstract.
 *
 * All enum metadata is derived from static $enums arrays - no rows are read or
 * written, so database transactions are not needed.
 *
 * Test models:
 *   Login_User_Model  - status_id (3 entries: Active/Inactive/Suspended, Suspended
 *                       has selectable:false and order values), is_verified (boolean 0/1)
 *   Flash_Alert_Model - type_id  (4 simple entries, no order/selectable overrides)
 */
class Enum_Static_Methods_Test extends Rsx_Test_Abstract
{
    // Pure static metadata - no database access needed.
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // field__enum() - returns all definitions for the field
    // -------------------------------------------------------------------------

    public static function test_enum_returns_all_definitions()
    {
        $result = Login_User_Model::status_id__enum();

        static::__assert_true(is_array($result), 'field__enum() must return an array');
        static::__assert_count(3, $result);
        static::__assert_array_has_key(1, $result);
        static::__assert_array_has_key(2, $result);
        static::__assert_array_has_key(3, $result);
    }

    public static function test_enum_entry_contains_full_metadata()
    {
        $all = Login_User_Model::status_id__enum();

        static::__assert_equals('STATUS_ACTIVE', $all[1]['constant']);
        static::__assert_equals('Active', $all[1]['label']);
        static::__assert_equals(1, $all[1]['order']);
    }

    public static function test_enum_includes_selectable_false_entries()
    {
        // field__enum() returns ALL entries, including non-selectable ones.
        $all = Login_User_Model::status_id__enum();

        static::__assert_array_has_key(3, $all);
        static::__assert_equals('STATUS_SUSPENDED', $all[3]['constant']);
        static::__assert_equals(false, $all[3]['selectable']);
    }

    public static function test_enum_second_field_on_same_model()
    {
        $result = Login_User_Model::is_verified__enum();

        static::__assert_true(is_array($result));
        static::__assert_count(2, $result);
        static::__assert_array_has_key(0, $result);
        static::__assert_array_has_key(1, $result);
        static::__assert_equals('Not Verified', $result[0]['label']);
        static::__assert_equals('Verified', $result[1]['label']);
    }

    // -------------------------------------------------------------------------
    // field__enum() - sorting by 'order' property
    // -------------------------------------------------------------------------

    public static function test_enum_sorted_by_order_property()
    {
        // Login_User_Model::status_id has order 1,2,3 - result keys should appear
        // in that ascending order.
        $result = Login_User_Model::status_id__enum();
        $keys = array_keys($result);

        static::__assert_equals([1, 2, 3], $keys);
    }

    // -------------------------------------------------------------------------
    // field__enum_select() - only selectable entries, in order
    // -------------------------------------------------------------------------

    public static function test_enum_select_excludes_non_selectable()
    {
        // status_id 3 (Suspended) has selectable:false - must be absent.
        $options = Login_User_Model::status_id__enum_select();

        $values = array_column($options, 'value');
        static::__assert_false(in_array(3, $values, true),
            'enum_select() must exclude entries with selectable:false');
    }

    public static function test_enum_select_returns_value_label_pairs()
    {
        $options = Login_User_Model::status_id__enum_select();

        static::__assert_count(2, $options);
        static::__assert_array_has_key('value', $options[0]);
        static::__assert_array_has_key('label', $options[0]);
        static::__assert_equals(1, $options[0]['value']);
        static::__assert_equals('Active', $options[0]['label']);
        static::__assert_equals(2, $options[1]['value']);
        static::__assert_equals('Inactive', $options[1]['label']);
    }

    public static function test_enum_select_order_respected()
    {
        // All Flash_Alert_Model type_id entries have no explicit 'order' (defaults to 0).
        // Tie-break falls back to numeric key order: 1, 2, 3, 4.
        $options = Flash_Alert_Model::type_id__enum_select();

        static::__assert_count(4, $options);
        static::__assert_equals(1, $options[0]['value']);
        static::__assert_equals(2, $options[1]['value']);
        static::__assert_equals(3, $options[2]['value']);
        static::__assert_equals(4, $options[3]['value']);
    }

    public static function test_enum_select_includes_all_when_all_selectable()
    {
        $options = Flash_Alert_Model::type_id__enum_select();

        static::__assert_count(4, $options);
    }

    // -------------------------------------------------------------------------
    // field__enum_labels() - id => label map, all entries including non-selectable
    // -------------------------------------------------------------------------

    public static function test_enum_labels_returns_id_to_label_map()
    {
        $labels = Login_User_Model::status_id__enum_labels();

        static::__assert_true(is_array($labels));
        static::__assert_equals('Active', $labels[1]);
        static::__assert_equals('Inactive', $labels[2]);
        static::__assert_equals('Suspended', $labels[3]);
    }

    public static function test_enum_labels_includes_non_selectable_entries()
    {
        // enum_labels() ignores selectable flag - returns all entries.
        $labels = Login_User_Model::status_id__enum_labels();

        static::__assert_array_has_key(3, $labels);
        static::__assert_equals('Suspended', $labels[3]);
    }

    public static function test_enum_labels_returns_all_four_flash_types()
    {
        $labels = Flash_Alert_Model::type_id__enum_labels();

        static::__assert_count(4, $labels);
        static::__assert_equals('Success', $labels[1]);
        static::__assert_equals('Error', $labels[2]);
        static::__assert_equals('Info', $labels[3]);
        static::__assert_equals('Warning', $labels[4]);
    }

    // -------------------------------------------------------------------------
    // field__enum_ids() - array of all valid IDs
    // -------------------------------------------------------------------------

    public static function test_enum_ids_returns_all_keys()
    {
        $ids = Login_User_Model::status_id__enum_ids();

        static::__assert_true(is_array($ids));
        static::__assert_count(3, $ids);
        static::__assert_true(in_array(1, $ids, true));
        static::__assert_true(in_array(2, $ids, true));
        static::__assert_true(in_array(3, $ids, true));
    }

    public static function test_enum_ids_includes_non_selectable()
    {
        // enum_ids() returns all IDs including non-selectable ones.
        $ids = Login_User_Model::status_id__enum_ids();

        static::__assert_true(in_array(3, $ids, true));
    }

    public static function test_enum_ids_second_model()
    {
        $ids = Flash_Alert_Model::type_id__enum_ids();

        static::__assert_count(4, $ids);
        static::__assert_true(in_array(1, $ids, true));
        static::__assert_true(in_array(4, $ids, true));
    }

    // -------------------------------------------------------------------------
    // PHP constants - generated on models
    // -------------------------------------------------------------------------

    public static function test_php_constants_match_enum_keys()
    {
        static::__assert_equals(1, Login_User_Model::STATUS_ACTIVE);
        static::__assert_equals(2, Login_User_Model::STATUS_INACTIVE);
        static::__assert_equals(3, Login_User_Model::STATUS_SUSPENDED);
    }

    public static function test_flash_alert_model_constants()
    {
        static::__assert_equals(1, Flash_Alert_Model::TYPE_SUCCESS);
        static::__assert_equals(2, Flash_Alert_Model::TYPE_ERROR);
        static::__assert_equals(3, Flash_Alert_Model::TYPE_INFO);
        static::__assert_equals(4, Flash_Alert_Model::TYPE_WARNING);
    }
}
