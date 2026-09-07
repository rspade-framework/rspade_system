<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Model_Abstract::get_relationships() unions the lineage.
 *
 * The manifest indexes methods per FILE, so a model's own entry never lists an inherited
 * method. get_relationships() therefore climbs extends_fqcn and collects each ancestor's
 * own #[Relationship] methods; the framework audit relations (created_by / updated_by /
 * deleted_by), declared on Rsx_Model_Abstract, are the parent-declared case every model
 * exercises.
 */
class Relationship_Lineage_Test extends Rsx_Test_Abstract
{
    // Manifest reads only - no database access.
    protected static $use_database_transactions = false;

    private const AUDIT_RELATIONS = ['created_by', 'updated_by', 'deleted_by'];

    public static function test_audit_relations_reach_a_template_model()
    {
        $relationships = Client_Model::get_relationships();

        foreach (self::AUDIT_RELATIONS as $relation) {
            static::__assert_true(
                in_array($relation, $relationships, true),
                "Client_Model reports the inherited {$relation}"
            );
        }
    }

    public static function test_own_relations_survive_the_union()
    {
        $relationships = Client_Model::get_relationships();

        foreach (['billing_contact', 'contacts', 'owner'] as $relation) {
            static::__assert_true(
                in_array($relation, $relationships, true),
                "Client_Model still reports its own {$relation}"
            );
        }
    }

    public static function test_the_inherited_relations_are_absent_from_the_models_own_manifest_entry()
    {
        // The premise of the fix: without the walk, these names are simply not there.
        $metadata = Manifest::php_get_metadata_by_class('Client_Model');
        $own_methods = $metadata['public_instance_methods'] ?? [];

        foreach (self::AUDIT_RELATIONS as $relation) {
            static::__assert_false(
                isset($own_methods[$relation]),
                "{$relation} is not in Client_Model's own method index"
            );
        }
    }

    public static function test_the_union_is_de_duplicated()
    {
        $relationships = Client_Model::get_relationships();

        static::__assert_equals(
            count($relationships),
            count(array_unique($relationships)),
            'no relationship name appears twice'
        );
    }

    public static function test_a_second_template_model_resolves_its_own_set()
    {
        // Two subclasses of the same parent legitimately resolve to different sets - the
        // memo is keyed by called class, so this also proves the cache is not shared.
        $contact_relationships = Contact_Model::get_relationships();

        static::__assert_true(
            in_array('client', $contact_relationships, true),
            'Contact_Model reports its own client relation'
        );
        static::__assert_true(
            in_array('created_by', $contact_relationships, true),
            'Contact_Model reports the inherited created_by'
        );
        static::__assert_false(
            in_array('billing_contact', $contact_relationships, true),
            "Contact_Model does not report Client_Model's relations"
        );
    }

    public static function test_a_framework_core_model_inherits_them_too()
    {
        $relationships = File_Attachment_Model::get_relationships();

        static::__assert_true(
            in_array('file_storage', $relationships, true),
            'the model reports its own file_storage relation'
        );

        foreach (self::AUDIT_RELATIONS as $relation) {
            static::__assert_true(
                in_array($relation, $relationships, true),
                "File_Attachment_Model reports the inherited {$relation}"
            );
        }
    }

    public static function test_the_walk_terminates_outside_the_manifest()
    {
        // Rsx_Model_Abstract's parent is Eloquent's Model, which the manifest never scans -
        // that absence is what ends the climb.
        $entry = Manifest::php_get_metadata_by_class('Client_Model');
        $lineage_depth = 0;

        while (isset($entry['extends_fqcn'])) {
            $simple_name = Manifest::_normalize_class_name($entry['extends_fqcn']);

            if (!isset(Manifest::$data['data']['php_classes'][$simple_name])) {
                break;
            }

            $entry = Manifest::get_file(Manifest::php_find_class($simple_name));
            $lineage_depth++;
        }

        static::__assert_greater_than(0, $lineage_depth, 'Client_Model has manifest-visible ancestors');
        static::__assert_equals(
            'Illuminate\\Database\\Eloquent\\Model',
            $entry['extends_fqcn'] ?? null,
            'the last manifest-visible ancestor is the one whose parent is Eloquent'
        );
    }
}
