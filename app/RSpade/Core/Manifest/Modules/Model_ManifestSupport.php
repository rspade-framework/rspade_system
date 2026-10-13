<?php

namespace App\RSpade\Core\Manifest\Modules;

use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * The model registry: every concrete Rsx_Model_Abstract subclass the tree declares.
 */
class Model_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Derive the model registry: class name => ['fqcn', 'file', 'table', 'class'].
     *
     * CODE FACTS ONLY. `table` is the model's own declaration (its $table, or the name
     * Eloquent derives from the class), read off the class with no connection. Nothing here
     * asks the database anything, so a build needs none and a model whose table does not
     * exist yet is still a model. What columns a table has is a fact about the database,
     * read at run time by Rsx_Schema.
     *
     * A FULL module although it loads each model class to ask for its table: there is no
     * per-row input left to diff on, and a registry derived whole from the class map cannot
     * be lost the way a carried-forward section can. The cost is one instantiation per
     * model, of classes the stub generator loads in the same pass anyway.
     *
     * The row REFERENCES the model's file; it does not copy the file's method map into
     * itself (Orm_Controller and get_relationships() read the FILE record).
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function rebuild(array &$manifest_data): void
    {
        $models = [];

        // Concrete classes only - php_get_extending() filters the abstract ones.
        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $model_entry) {
            if (!isset($model_entry['fqcn'])) {
                continue;
            }

            $fqcn = $model_entry['fqcn'];
            $class_name = $model_entry['class'] ?? '';

            Manifest::_load_class_hierarchy($fqcn, $manifest_data);

            $models[$class_name] = [
                'fqcn' => $fqcn,
                'file' => $model_entry['file'] ?? '',
                'table' => $fqcn::get_table_static(),
                'class' => $class_name,
            ];
        }

        ksort($models);

        $manifest_data['data']['models'] = $models;
    }

    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Model Registry';
    }
}
