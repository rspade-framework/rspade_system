<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Support module for extracting jqhtml component metadata
 * This runs after the primary manifest is built to add jqhtml component metadata
 */
class Jqhtml_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Rebuild the component registry from every template in the tree.
     *
     * FULL. A component entry is owned by up to two files - its `.jqhtml` template and the
     * optional JS class of the same name - and keeping that two-file ownership correct
     * across a diff was most of this method. Deriving it instead is a loop over in-memory
     * records reading two keys, so the diff bought nothing and cost correctness: a
     * carried-forward registry that is lost can only be repaired by files CHANGING, and an
     * unchanged tree has none. An empty registry is not an error to the caller either - it
     * is a compiler that silently renders every `<Component>` tag as literal HTML.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function rebuild(array &$manifest_data): void
    {
        $files = $manifest_data['data']['files'];

        // JS classes extending Component, by simple name - the index answers this without a
        // scan, and a component's JS class is the class of the same name.
        $component_js_classes = array_flip($manifest_data['data']['js_subclass_index']['Component'] ?? []);
        $js_classes = $manifest_data['data']['js_classes'] ?? [];

        // Every template in the tree declares its component. Derived outright rather than
        // diffed: this is a loop over in-memory records reading two keys, and a
        // carried-forward map that is lost can only be repaired by files CHANGING.
        $components = [];

        foreach ($files as $file => $file_data) {
            if (($file_data['type'] ?? null) !== 'jqhtml_template') {
                continue;
            }

            $name = $file_data['template_name'] ?? null;

            if ($name === null) {
                continue;
            }

            // A REFERENCE, not a record: the component name is the key, so it is not
            // repeated as a value, and everything else about the template is in the file
            // record `file` points at.
            $entry = ['file' => $file];

            if (isset($component_js_classes[$name], $js_classes[$name]['file'])) {
                $entry['js_file'] = $js_classes[$name]['file'];
            }

            $components[$name] = $entry;
        }

        // Store component map (associative array: component_name => reference)
        ksort($components);
        $manifest_data['data']['jqhtml']['components'] = $components;
    }

    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Jqhtml Component Metadata';
    }

    /**
     * Static method to get jqhtml components from cached manifest
     * This is called by JqhtmlBladeCompiler to get the list of components
     *
     * @return array List of jqhtml component names
     */
    public static function get_jqhtml_components(): array
    {
        // NO CATCH. This used to swallow every Throwable as "manifest not ready" and answer
        // with an EMPTY component list - and an empty list is not an error to the caller,
        // it is a compiler that silently renders every `<Component>` tag as literal HTML.
        // Manifest::get_full_manifest() builds the manifest if it has to; if it cannot, that
        // is the failure to report, at the file that caused it.
        $manifest = Manifest::get_full_manifest();

        return $manifest['data']['jqhtml']['components'] ?? [];
    }
}
