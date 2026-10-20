<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Session\Php\Never_Export_Fixture_Base_Model_Abstract;
use App\RSpade\Tests\Session\Php\Never_Export_Fixture_Model;

/**
 * $neverExport is the one export control, and its effective list is the UNION of the chain.
 *
 * Three promises are pinned here. A subclass declaring its own list ADDS to its parent's
 * (the shape of a class override of a core model) rather than replacing it. Laravel's
 * makeVisible() cannot put a never-exported column back, because the strip runs after
 * Laravel has built the array. And the identity secrets - both password hashes, the staff
 * remember token, the portal user's open metadata bag - are on that list.
 *
 * Models are built unsaved (attribute-only). The chain under test is the fixture pair
 * Never_Export_Fixture_Base_Model_Abstract -> Never_Export_Fixture_Model beside this file.
 */
class Model_Never_Export_Union_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_subclass_list_unions_with_the_base_list()
    {
        $columns = Never_Export_Fixture_Model::never_exported_columns();

        static::__assert_true(in_array('leaf_secret_column', $columns, true), 'the leaf adds its own column');
        static::__assert_true(in_array('base_secret_column', $columns, true), 'the parent column survives the leaf declaration');

        $record = new Never_Export_Fixture_Model();
        $record->id = 1;
        $record->label = 'visible';
        $record->base_secret_column = 'probe-base-secret';
        $record->leaf_secret_column = 'probe-leaf-secret';

        $payload = $record->toArray();

        static::__assert_false(array_key_exists('leaf_secret_column', $payload), 'the leaf column is stripped');
        static::__assert_false(array_key_exists('base_secret_column', $payload), 'the parent column is still stripped');
        static::__assert_true(array_key_exists('label', $payload), 'the rest of the record is serialized');
    }

    public static function test_a_parent_list_is_unaffected_by_a_subclass_list()
    {
        static::__assert_equals(
            ['base_secret_column'],
            Never_Export_Fixture_Base_Model_Abstract::never_exported_columns(),
            'a subclass declaration does not leak upward into its parent'
        );
    }

    public static function test_the_identity_models_never_export_their_secrets()
    {
        // Membership, not equality: an application override may add columns of its own.
        foreach (['password', 'remember_token'] as $column) {
            static::__assert_true(in_array($column, Login_User_Model::never_exported_columns(), true), "staff identity: {$column}");
        }

        foreach (['password', 'metadata'] as $column) {
            static::__assert_true(in_array($column, Portal_User_Model::never_exported_columns(), true), "portal user: {$column}");
        }
    }

    public static function test_make_visible_cannot_reveal_a_staff_identity_secret()
    {
        $identity = new Login_User_Model();
        $identity->email = 'make-visible@example.com';
        $identity->password = 'probe-staff-password-hash';
        $identity->remember_token = 'probe-remember-token';

        $payload = $identity->makeVisible(['password', 'remember_token'])->toArray();

        static::__assert_false(array_key_exists('password', $payload), 'password stays out after makeVisible()');
        static::__assert_false(array_key_exists('remember_token', $payload), 'remember_token stays out after makeVisible()');
        static::__assert_false(str_contains(json_encode($identity), 'probe-staff-password-hash'), 'no hash in the JSON');
    }

    public static function test_make_visible_cannot_reveal_a_portal_password()
    {
        $user = new Portal_User_Model();
        $user->email = 'make-visible@example.com';
        $user->password = 'probe-portal-password-hash';

        $payload = $user->makeVisible('password')->toArray();

        static::__assert_false(array_key_exists('password', $payload), 'password stays out after makeVisible()');
    }

    public static function test_a_portal_user_payload_has_no_metadata()
    {
        $user = new Portal_User_Model();
        $user->email = 'metadata@example.com';
        $user->set_metadata('probe_key', 'probe-metadata-value');

        static::__assert_false(array_key_exists('metadata', $user->toArray()), 'no metadata key');
        static::__assert_false(str_contains(json_encode($user), 'probe-metadata-value'), 'no metadata value in the JSON');
        static::__assert_equals('probe-metadata-value', $user->get_metadata('probe_key'), 'server code still reads it');
    }
}
