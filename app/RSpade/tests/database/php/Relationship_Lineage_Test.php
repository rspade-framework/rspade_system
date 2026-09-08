<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Model_Abstract::get_relationships() unions the lineage.
 *
 * The manifest indexes methods per FILE, so a model's own entry never lists an inherited
 * method. get_relationships() therefore climbs extends_fqcn and collects each ancestor's
 * own #[Relationship] methods.
 *
 * A core model is a THREE-DEEP case, and deliberately: the concrete class an application
 * overrides declares nothing at all, its abstract base declares the model's own relations,
 * and Rsx_Model_Abstract declares the audit relations (created_by / updated_by /
 * deleted_by). So every name a core model reports comes from an ANCESTOR file, and a walk
 * that stopped one level short would drop the model's own relations rather than only the
 * framework's.
 */
class Relationship_Lineage_Test extends Rsx_Test_Abstract
{
    // Manifest reads only - no database access.
    protected static $use_database_transactions = false;

    private const AUDIT_RELATIONS = ['created_by', 'updated_by', 'deleted_by'];

    public static function test_audit_relations_reach_a_core_model()
    {
        $relationships = File_Attachment_Model::get_relationships();

        foreach (self::AUDIT_RELATIONS as $relation) {
            static::__assert_true(
                in_array($relation, $relationships, true),
                "File_Attachment_Model reports the inherited {$relation}"
            );
        }
    }

    public static function test_own_relations_survive_the_union()
    {
        $relationships = File_Attachment_Model::get_relationships();

        foreach (['file_storage', 'site', 'fileable'] as $relation) {
            static::__assert_true(
                in_array($relation, $relationships, true),
                "File_Attachment_Model still reports its own {$relation}"
            );
        }
    }

    public static function test_the_reported_relations_are_absent_from_the_models_own_manifest_entry()
    {
        // The premise of the fix: without the walk, NONE of these names are there. The
        // concrete class a core model ships is empty - it exists to be overridden - so its
        // own method index lists neither the audit relations nor the model's own.
        $metadata = Manifest::php_get_metadata_by_class('File_Attachment_Model');
        $own_methods = $metadata['public_instance_methods'] ?? [];

        foreach (array_merge(self::AUDIT_RELATIONS, ['file_storage']) as $relation) {
            static::__assert_false(
                isset($own_methods[$relation]),
                "{$relation} is not in File_Attachment_Model's own method index"
            );
        }

        // One level up is where the model's own relations are declared - and the audit
        // relations are still not there, because they belong to Rsx_Model_Abstract.
        $base_metadata = Manifest::php_get_metadata_by_class('File_Attachment_Model_Abstract');
        $base_methods = $base_metadata['public_instance_methods'] ?? [];

        static::__assert_true(
            isset($base_methods['file_storage']),
            'file_storage is declared on the abstract base'
        );

        foreach (self::AUDIT_RELATIONS as $relation) {
            static::__assert_false(
                isset($base_methods[$relation]),
                "{$relation} is not declared on the abstract base either"
            );
        }
    }

    public static function test_the_union_is_de_duplicated()
    {
        $relationships = File_Attachment_Model::get_relationships();

        static::__assert_equals(
            count($relationships),
            count(array_unique($relationships)),
            'no relationship name appears twice'
        );
    }

    public static function test_a_second_core_model_resolves_its_own_set()
    {
        // Two models sharing the same audit ancestor legitimately resolve to different
        // sets - the memo is keyed by called class, so this also proves the cache is not
        // shared.
        $user_relationships = User_Model::get_relationships();

        static::__assert_true(
            in_array('login_user', $user_relationships, true),
            'User_Model reports its own login_user relation'
        );
        static::__assert_true(
            in_array('created_by', $user_relationships, true),
            'User_Model reports the inherited created_by'
        );
        static::__assert_false(
            in_array('file_storage', $user_relationships, true),
            "User_Model does not report File_Attachment_Model's relations"
        );
    }

    public static function test_the_walk_terminates_outside_the_manifest()
    {
        // Rsx_Model_Abstract's parent is Eloquent's Model, which the manifest never scans -
        // that absence is what ends the climb.
        $entry = Manifest::php_get_metadata_by_class('File_Attachment_Model');
        $lineage_depth = 0;

        while (isset($entry['extends_fqcn'])) {
            $simple_name = Manifest::_normalize_class_name($entry['extends_fqcn']);

            if (!isset(Manifest::$data['data']['php_classes'][$simple_name])) {
                break;
            }

            $entry = Manifest::get_file(Manifest::php_find_class($simple_name));
            $lineage_depth++;
        }

        static::__assert_greater_than(
            1,
            $lineage_depth,
            'a core model is at least two manifest-visible ancestors deep (its abstract base, then the framework model base)'
        );
        static::__assert_equals(
            'Illuminate\\Database\\Eloquent\\Model',
            $entry['extends_fqcn'] ?? null,
            'the last manifest-visible ancestor is the one whose parent is Eloquent'
        );
    }
}
