<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * A BUNDLE'S FIELD-LENGTH SCRIPT: the column max lengths of the models a compiled bundle
 * carries, as one line of JavaScript the page loads after the bundle.
 *
 *     Rsx.__field_length_data = {"Contact_Model": {"first_name": 100, ...}, ...};
 *
 * WHY IT IS A SCRIPT OF ITS OWN. A column's length is a fact about the DATABASE; everything
 * else a model's JS stub declares is a fact about the model's code. Kept apart, a compiled
 * bundle is a function of code alone - a build needs no database, and a migration changes
 * no bundle - while the lengths are read at run time, from the schema as it is now.
 * Rsx_Js_Model.field_length() reads the object this assigns and stays synchronous.
 *
 * WHICH MODELS. Exactly the ones whose stubs are in the bundle's compiled app script: the
 * model list the compiler wrote beside it (BundleCompiler::read_model_list()).
 *
 * WHICH COLUMNS. Every varchar/char column of the model, each with the PHP model's own
 * field_length() answer - ONE definition of a length, detail-table columns of a
 * class-table-inheritance base included - except single-underscore SYSTEM columns:
 * toArray() strips those from every payload, so the client never holds one and can never
 * need its length. A model whose table does not exist in the connected database gets an
 * empty entry, so field_length() answers null for it rather than reporting a missing script.
 *
 * THE URL NAMES THE BUNDLE'S OWN APP SCRIPT - /_field_lengths/<Bundle>__<hash>.js, where
 * <hash> is the one in <Bundle>__app.<hash>.js - so the script is exactly as reachable as
 * the bundle it belongs to: somebody who cannot name the compiled bundle cannot ask for its
 * models' column names. Any other name is not a script (resolve() answers null).
 *
 * CACHED under the state key (the build-scoped RsxCache family), so a migration or a code
 * change answers from the schema again with nothing to purge.
 */
class Field_Length_Script
{
    /** The path the script is served under, in both realms. */
    public const URL_PREFIX = '/_field_lengths/';

    /**
     * The URL a page loads a bundle's script from: realm-aware (a portal page asks the
     * portal's route), and carrying the state key as ?v= - the script is served immutable
     * for exactly that key, and the key moves when a migration runs.
     *
     * @param string $script_name "<Bundle>__<hash>.js" (BundleCompiler names it)
     */
    public static function url(string $script_name): string
    {
        return Rsx_Portal::internal_url(self::URL_PREFIX . $script_name) . '?v=' . Manifest::get_state_key();
    }

    /**
     * The bundle a script name belongs to, or null when the name is not the name of a
     * compiled bundle's script: malformed, a bundle with no model list, a hash that is not
     * the one in the bundle's compiled app script, or no such app script on disk.
     *
     * @return string|null The bundle name
     */
    public static function resolve(string $script_name): ?string
    {
        if (!preg_match('/^([A-Za-z0-9_]+)__([a-f0-9]{8,16})\.js$/', $script_name, $matches)) {
            return null;
        }

        [, $bundle_name, $hash] = $matches;

        $list = BundleCompiler::read_model_list($bundle_name);

        if ($list === null || !hash_equals($list['app_hash'], $hash)) {
            return null;
        }

        if (!is_file(Rsx_Project_Paths::bundles_dir() . '/' . $bundle_name . '__app.' . $hash . '.js')) {
            return null;
        }

        return $bundle_name;
    }

    /**
     * The script's source for a compiled bundle.
     *
     * @param string $bundle_name A bundle resolve() answered
     */
    public static function body(string $bundle_name): string
    {
        $list = BundleCompiler::read_model_list($bundle_name);

        if ($list === null) {
            shouldnt_happen("Field_Length_Script::body(): bundle {$bundle_name} has no model list");
        }

        $cache_key = 'field_length_script:' . $bundle_name . ':' . $list['app_hash'];
        $cached = RsxCache::get($cache_key);

        if (is_string($cached)) {
            return $cached;
        }

        $body = 'Rsx.__field_length_data = '
            . json_encode(self::lengths_for_models($list['models']), JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES)
            . ";\n";

        RsxCache::set($cache_key, $body);

        return $body;
    }

    /**
     * model class => column => max length, for the named models (see the class docblock for
     * which columns).
     *
     * @param string[] $model_classes Simple model class names
     * @return array<string, array<string, int>>
     */
    public static function lengths_for_models(array $model_classes): array
    {
        $data = [];

        foreach ($model_classes as $model_class) {
            $record = Manifest::php_model_record($model_class);

            if ($record === null) {
                shouldnt_happen("Field_Length_Script: {$model_class} is in a bundle's model list and is not a model in the manifest");
            }

            $data[$model_class] = [];

            // No table in the connected database (not migrated yet): an empty entry.
            foreach (array_keys(Rsx_Schema::model_columns($model_class) ?? []) as $column) {
                if (str_starts_with($column, '_') && !str_starts_with($column, '__')) {
                    continue;
                }

                $length = $record['fqcn']::field_length($column);

                if ($length !== null) {
                    $data[$model_class][$column] = $length;
                }
            }
        }

        return $data;
    }
}
