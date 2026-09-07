<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Settings\Php;

use App\RSpade\Core\Settings\Rsx_Settings;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the Rsx_Settings application-value store.
 *
 * define()/set() commit to _settings / _setting_values, so the class re-provisions
 * a clean DB (no transaction rollback). Each test uses a unique key.
 */
class Settings_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function test_define_and_get_returns_default()
    {
        Rsx_Settings::define(['key' => 'company_name', 'label' => 'Company Name', 'type' => Rsx_Settings::TYPE_TEXT, 'default' => 'Acme']);
        static::__assert_equals('Acme', Rsx_Settings::get('company_name'));
    }

    public static function test_set_overrides_default()
    {
        Rsx_Settings::define(['key' => 'company_tagline', 'label' => 'Tagline', 'type' => Rsx_Settings::TYPE_TEXT, 'default' => 'default tag']);
        Rsx_Settings::set('company_tagline', 'we do things');
        static::__assert_equals('we do things', Rsx_Settings::get('company_tagline'));
    }

    public static function test_forget_reverts_to_default()
    {
        Rsx_Settings::define(['key' => 'support_email', 'label' => 'Support', 'type' => Rsx_Settings::TYPE_TEXT, 'default' => 'help@example.com']);
        Rsx_Settings::set('support_email', 'override@example.com');
        static::__assert_equals('override@example.com', Rsx_Settings::get('support_email'));
        Rsx_Settings::forget('support_email');
        static::__assert_equals('help@example.com', Rsx_Settings::get('support_email'));
    }

    public static function test_integer_type_round_trips_as_int()
    {
        Rsx_Settings::define(['key' => 'billing_dow_cutoff', 'label' => 'Billing cutoff DOW', 'type' => Rsx_Settings::TYPE_INTEGER, 'default' => 1]);
        $v = Rsx_Settings::get('billing_dow_cutoff');
        static::__assert_true(is_int($v));
        static::__assert_equals(1, $v);
        Rsx_Settings::set('billing_dow_cutoff', 5);
        static::__assert_equals(5, Rsx_Settings::get('billing_dow_cutoff'));
    }

    public static function test_decimal_preserves_precision_as_string()
    {
        Rsx_Settings::define(['key' => 'tax_rate', 'label' => 'Tax rate', 'type' => Rsx_Settings::TYPE_DECIMAL, 'default' => '8.25', 'meta' => ['scale' => 2]]);
        $v = Rsx_Settings::get('tax_rate');
        static::__assert_true(is_string($v));
        static::__assert_equals('8.25', $v);
    }

    public static function test_float_type_round_trips_as_float()
    {
        Rsx_Settings::define(['key' => 'conversion_factor', 'label' => 'Factor', 'type' => Rsx_Settings::TYPE_FLOAT, 'default' => 1.5]);
        $v = Rsx_Settings::get('conversion_factor');
        static::__assert_true(is_float($v));
        static::__assert_equals_approx(1.5, $v);
    }

    public static function test_boolean_type_round_trips_as_bool()
    {
        Rsx_Settings::define(['key' => 'maintenance_mode', 'label' => 'Maintenance', 'type' => Rsx_Settings::TYPE_BOOLEAN, 'default' => false]);
        static::__assert_false(Rsx_Settings::get('maintenance_mode'));
        Rsx_Settings::set('maintenance_mode', true);
        static::__assert_true(Rsx_Settings::get('maintenance_mode'));
    }

    public static function test_date_type()
    {
        Rsx_Settings::define(['key' => 'launch_date', 'label' => 'Launch', 'type' => Rsx_Settings::TYPE_DATE, 'default' => '2026-01-01']);
        static::__assert_equals('2026-01-01', Rsx_Settings::get('launch_date'));
    }

    public static function test_datetime_type_normalizes_to_iso()
    {
        Rsx_Settings::define(['key' => 'cutover_at', 'label' => 'Cutover', 'type' => Rsx_Settings::TYPE_DATETIME, 'default' => '2026-01-01T00:00:00Z']);
        $v = Rsx_Settings::get('cutover_at');
        static::__assert_not_null($v);
        static::__assert_true(is_string($v));
    }

    public static function test_json_type_round_trips_as_array()
    {
        Rsx_Settings::define(['key' => 'feature_flags', 'label' => 'Flags', 'type' => Rsx_Settings::TYPE_JSON, 'default' => ['a' => 1]]);
        Rsx_Settings::set('feature_flags', ['x' => true, 'y' => [1, 2, 3]]);
        $v = Rsx_Settings::get('feature_flags');
        static::__assert_true(is_array($v));
        static::__assert_equals([1, 2, 3], $v['y']);
    }

    public static function test_site_scoped_values_are_per_site()
    {
        Rsx_Settings::define(['key' => 'site_theme', 'label' => 'Theme', 'type' => Rsx_Settings::TYPE_TEXT, 'scope' => Rsx_Settings::SCOPE_SITE, 'default' => 'light']);
        Rsx_Settings::set('site_theme', 'dark', 1);
        Rsx_Settings::set('site_theme', 'blue', 2);
        static::__assert_equals('dark', Rsx_Settings::get('site_theme', 1));
        static::__assert_equals('blue', Rsx_Settings::get('site_theme', 2));
        static::__assert_equals('light', Rsx_Settings::get('site_theme', 3)); // unset -> default
    }

    public static function test_site_scope_uses_session_site_when_omitted()
    {
        Rsx_Settings::define(['key' => 'site_locale', 'label' => 'Locale', 'type' => Rsx_Settings::TYPE_TEXT, 'scope' => Rsx_Settings::SCOPE_SITE, 'default' => 'en']);
        static::__acting_as_site(7);
        Rsx_Settings::set('site_locale', 'fr');                              // implicit site 7
        static::__assert_equals('fr', Rsx_Settings::get('site_locale'));     // implicit site 7
        static::__assert_equals('en', Rsx_Settings::get('site_locale', 9));  // other site -> default
        static::__reset_session();
    }

    public static function test_is_defined()
    {
        static::__assert_false(Rsx_Settings::is_defined('nope_not_a_setting'));
        Rsx_Settings::define(['key' => 'maybe_setting', 'label' => 'Maybe', 'type' => Rsx_Settings::TYPE_TEXT]);
        static::__assert_true(Rsx_Settings::is_defined('maybe_setting'));
    }

    public static function test_get_undefined_throws()
    {
        static::__assert_throws(\Throwable::class, function () {
            Rsx_Settings::get('definitely_undefined_key');
        });
    }

    public static function test_set_undefined_throws()
    {
        static::__assert_throws(\Throwable::class, function () {
            Rsx_Settings::set('also_undefined_key', 'x');
        });
    }

    public static function test_integer_validation_rejects_non_integer()
    {
        Rsx_Settings::define(['key' => 'max_items', 'label' => 'Max', 'type' => Rsx_Settings::TYPE_INTEGER, 'default' => 10]);
        static::__assert_throws(\Throwable::class, function () {
            Rsx_Settings::set('max_items', 'not a number');
        });
    }

    public static function test_define_is_idempotent_update()
    {
        Rsx_Settings::define(['key' => 'redefine_me', 'label' => 'First', 'type' => Rsx_Settings::TYPE_TEXT, 'default' => 'one']);
        Rsx_Settings::define(['key' => 'redefine_me', 'label' => 'Second', 'type' => Rsx_Settings::TYPE_TEXT, 'default' => 'two']);
        static::__assert_equals('two', Rsx_Settings::get('redefine_me'));
    }

    public static function test_all_lists_definitions_with_resolved_values()
    {
        Rsx_Settings::define(['key' => 'listed_a', 'label' => 'A', 'type' => Rsx_Settings::TYPE_TEXT, 'default' => 'aa']);
        Rsx_Settings::define(['key' => 'listed_b', 'label' => 'B', 'type' => Rsx_Settings::TYPE_INTEGER, 'default' => 2]);
        $all = Rsx_Settings::all();
        static::__assert_true(is_array($all));
        $keys = array_column($all, 'key');
        static::__assert_true(in_array('listed_a', $keys, true));
        static::__assert_true(in_array('listed_b', $keys, true));
    }
}
