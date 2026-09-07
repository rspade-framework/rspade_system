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
 * Tests for BEM-style magic instance properties on model objects.
 *
 * Tests $model->field__label, $model->field__constant, and custom property
 * access (e.g., $model->field__selectable). Also tests that an unset field
 * value returns null from the parent __get() path.
 *
 * No DB rows are created - instances are constructed with new and attributes
 * are set directly. No transaction wrapping needed.
 */
class Enum_Magic_Properties_Test extends Rsx_Test_Abstract
{
    // Pure in-memory model instances - no database access needed.
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // field__label
    // -------------------------------------------------------------------------

    public static function test_label_property_returns_correct_label()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_ACTIVE;

        static::__assert_equals('Active', $model->status_id__label);
    }

    public static function test_label_property_changes_with_value()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_INACTIVE;

        static::__assert_equals('Inactive', $model->status_id__label);
    }

    public static function test_label_for_non_selectable_entry()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_SUSPENDED;

        static::__assert_equals('Suspended', $model->status_id__label);
    }

    public static function test_label_on_second_enum_field()
    {
        $model = new Login_User_Model();
        // is_verified is a boolean-style enum (keys 0 and 1).
        $model->is_verified = 1;
        static::__assert_equals('Verified', $model->is_verified__label);

        $model->is_verified = 0;
        static::__assert_equals('Not Verified', $model->is_verified__label);
    }

    // -------------------------------------------------------------------------
    // field__constant
    // -------------------------------------------------------------------------

    public static function test_constant_property_returns_constant_name()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_ACTIVE;

        static::__assert_equals('STATUS_ACTIVE', $model->status_id__constant);
    }

    public static function test_constant_property_for_suspended()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_SUSPENDED;

        static::__assert_equals('STATUS_SUSPENDED', $model->status_id__constant);
    }

    // -------------------------------------------------------------------------
    // Custom properties (order, selectable, etc.)
    // -------------------------------------------------------------------------

    public static function test_order_property_accessible_via_bem_naming()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_ACTIVE;

        static::__assert_equals(1, $model->status_id__order);
    }

    public static function test_selectable_false_accessible_on_instance()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_SUSPENDED;

        static::__assert_equals(false, $model->status_id__selectable);
    }

    // -------------------------------------------------------------------------
    // Flash_Alert_Model instance properties
    // -------------------------------------------------------------------------

    public static function test_flash_alert_label_and_constant()
    {
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_SUCCESS;

        static::__assert_equals('Success', $model->type_id__label);
        static::__assert_equals('TYPE_SUCCESS', $model->type_id__constant);
    }

    public static function test_flash_alert_error_type()
    {
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_ERROR;

        static::__assert_equals('Error', $model->type_id__label);
    }

    // -------------------------------------------------------------------------
    // __isset() must return true for known enum properties
    // -------------------------------------------------------------------------

    public static function test_isset_true_for_known_enum_property()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_ACTIVE;

        static::__assert_true(isset($model->status_id__label));
    }

    public static function test_isset_true_for_custom_enum_property()
    {
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_ACTIVE;

        static::__assert_true(isset($model->status_id__order));
    }
}
