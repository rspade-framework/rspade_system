<?php

namespace App\RSpade\Integrations\Scss;

use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;

/**
 * Assigns a stylesheet its `id`: the name of the component or view it styles.
 *
 * A stylesheet wrapped in one class (`scss_wrapper_class`, recorded by Scss_ManifestModule
 * from the file alone) takes that class as its `id` when the name is DECLARED elsewhere in
 * the tree - a jqhtml template's or Blade view's `id`, or a JS class extending Component.
 *
 * FULL, and run after the scan, because the answer depends on OTHER files. Decided during the
 * per-file scan it depended on which files the scan had reached and on what the previous
 * build left in the index, so a cold build and a warm one disagreed. Here it is a function of
 * the complete file map alone, recomputed every build, and an id that no longer applies is
 * removed from the record that carried it forward.
 *
 * One id per name. When several stylesheets wrap the same declared class, the PRIMARY one
 * wins - its filename matches the declaring file's (the same notion ScssClassScope uses to
 * admit supplemental stylesheets) - and otherwise the first by path. Never scan order.
 */
class Scss_Id_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function rebuild(array &$manifest_data): void
    {
        $files = &$manifest_data['data']['files'];

        // name => the stems of the files that declare it
        $declared = [];
        foreach ($files as $path => $record) {
            $extension = $record['extension'] ?? '';
            if (($extension === 'jqhtml' || $extension === 'blade.php') && !empty($record['id'])) {
                $declared[$record['id']][] = static::__stem($path);
            }
        }
        foreach ($manifest_data['data']['js_subclass_index']['Component'] ?? [] as $class_name) {
            $file = $manifest_data['data']['js_classes'][$class_name]['file'] ?? null;
            if ($file !== null) {
                $declared[$class_name][] = static::__stem($file);
            }
        }

        // wrapper class => the stylesheets wrapped in it, by path
        $wrapped = [];
        foreach ($files as $path => $record) {
            if (($record['extension'] ?? '') !== 'scss') {
                continue;
            }
            unset($files[$path]['id']);
            if (!empty($record['scss_wrapper_class'])) {
                $wrapped[$record['scss_wrapper_class']][] = $path;
            }
        }

        foreach ($wrapped as $class_name => $paths) {
            if (!isset($declared[$class_name])) {
                continue;
            }

            sort($paths, SORT_STRING);
            $winner = $paths[0];
            foreach ($paths as $path) {
                if (in_array(static::__stem($path), $declared[$class_name], true)) {
                    $winner = $path;
                    break;
                }
            }

            $files[$winner]['id'] = $class_name;
        }
    }

    /** A file's name without directory or extension, lowercased (`Foo_Bar.blade.php` -> `foo_bar`). */
    private static function __stem(string $path): string
    {
        return strtolower(explode('.', basename($path), 2)[0]);
    }

    /**
     * @return string
     */
    public static function get_name(): string
    {
        return 'Stylesheet Ids';
    }
}
