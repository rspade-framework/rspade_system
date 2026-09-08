<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for extracting jqhtml component metadata
 * This runs after the primary manifest is built to add jqhtml component metadata
 */
class Jqhtml_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Update the component registry from the CHANGED and REMOVED sets.
     *
     * INCREMENTAL. A component entry is owned by up to two files - its `.jqhtml` template
     * and the optional JS class of the same name - and both are named on the entry, so the
     * diff is exact: drop the entries whose template or JS file is dirty, then re-derive
     * from the dirty files' own records.
     *
     * The reverse (file -> component) maps are built from the REGISTRY, which is proportional
     * to the number of components (a few hundred), never to the tree.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data, array $changed_files, array $removed_files): void
    {
        if (!isset($manifest_data['data']['jqhtml']['components'])) {
            $manifest_data['data']['jqhtml']['components'] = [];
        }

        $components = $manifest_data['data']['jqhtml']['components'];
        $dirty = static::dirty_set($changed_files, $removed_files);
        $files = $manifest_data['data']['files'];

        // Drop every entry either of whose source files is dirty. The names are remembered so
        // a component whose JS class changed but whose template did not is re-derived below.
        $touched_names = [];

        foreach ($components as $name => $entry) {
            if (isset($dirty[$entry['file'] ?? '']) || isset($dirty[$entry['js_file'] ?? ''])) {
                $touched_names[$name] = $entry['file'] ?? null;
                unset($components[$name]);
            }
        }

        // A dirty file that IS a template declares (or re-declares) its component.
        foreach (array_keys($dirty) as $file) {
            $file_data = $files[$file] ?? null;

            if ($file_data === null || ($file_data['type'] ?? null) !== 'jqhtml_template') {
                continue;
            }

            if (isset($file_data['template_name'])) {
                $touched_names[$file_data['template_name']] = $file;
            }
        }

        // JS classes extending Component, by simple name - the index answers this without a
        // scan, and a component's JS class is the class of the same name.
        $component_js_classes = array_flip($manifest_data['data']['js_subclass_index']['Component'] ?? []);
        $js_classes = $manifest_data['data']['js_classes'] ?? [];

        foreach ($touched_names as $name => $template_file) {
            if ($template_file === null) {
                continue;
            }

            $file_data = $files[$template_file] ?? null;

            if ($file_data === null
                || ($file_data['type'] ?? null) !== 'jqhtml_template'
                || ($file_data['template_name'] ?? null) !== $name) {
                // The template is gone, is no longer a template, or renamed its component.
                continue;
            }

            // A REFERENCE, not a record: the component name is the key, so it is not
            // repeated as a value, and everything else about the template is in the file
            // record `file` points at.
            $entry = ['file' => $template_file];

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
