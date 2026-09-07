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
 * Tests for automatic enum property inclusion in toArray() / JSON output.
 *
 * The man page states that when a model is converted to an array, all enum
 * properties for the current field value are added automatically using
 * BEM-style naming (field__label, field__constant, field__<custom>).
 *
 * We use in-memory model instances (no DB save needed) and call toArray()
 * directly. The models extend Eloquent, but toArray() on an unsaved instance
 * still includes the attributes that have been set.
 *
 * No DB transactions needed - purely in-memory.
 */
class Enum_To_Array_Export_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // toArray() includes BEM enum properties automatically
    // -------------------------------------------------------------------------

    public static function test_to_array_includes_label()
    {
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_SUCCESS;

        $arr = $model->toArray();

        static::__assert_array_has_key('type_id__label', $arr);
        static::__assert_equals('Success', $arr['type_id__label']);
    }

    public static function test_to_array_includes_constant()
    {
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_ERROR;

        $arr = $model->toArray();

        static::__assert_array_has_key('type_id__constant', $arr);
        static::__assert_equals('TYPE_ERROR', $arr['type_id__constant']);
    }

    public static function test_to_array_includes_original_field_value()
    {
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_WARNING;

        $arr = $model->toArray();

        static::__assert_array_has_key('type_id', $arr);
        static::__assert_equals(Flash_Alert_Model::TYPE_WARNING, $arr['type_id']);
    }

    public static function test_to_array_includes_model_identifier()
    {
        // toArray() always adds __MODEL with the short class name.
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_INFO;

        $arr = $model->toArray();

        static::__assert_array_has_key('__MODEL', $arr);
        static::__assert_equals('Flash_Alert_Model', $arr['__MODEL']);
    }

    public static function test_to_array_includes_custom_enum_properties()
    {
        // Login_User_Model status_id has 'order' as a custom property.
        // toArray() must include status_id__order.
        $model = new Login_User_Model();
        $model->status_id = Login_User_Model::STATUS_ACTIVE;

        $arr = $model->toArray();

        static::__assert_array_has_key('status_id__order', $arr);
        static::__assert_equals(1, $arr['status_id__order']);
    }

    public static function test_to_array_reflects_current_field_value()
    {
        // The exported label must match the field value at the time toArray() is called.
        $model = new Flash_Alert_Model();
        $model->type_id = Flash_Alert_Model::TYPE_INFO;

        $arr = $model->toArray();
        static::__assert_equals('Info', $arr['type_id__label']);
        static::__assert_equals('TYPE_INFO', $arr['type_id__constant']);
    }
}
