<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Bundle;

use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Bundle_Alias_ManifestSupport - bake config('rsx.bundle_aliases') into the manifest.
 *
 * A bundle alias ('jquery', 'lodash', ...) is application config, read by BundleCompiler
 * through config() at compile time. The IDE bridge (Ide/Services/handler.php) runs BEFORE
 * Laravel boots and cannot call config() - the one consumer that tried (include of
 * config/rsx.php) fataled on the first env() call, so bundle-alias go-to-definition had
 * never once worked. The manifest is the pre-boot world's only view of the application,
 * so the alias map is recorded here as data['bundle_aliases'] = [alias => bundle FQCN]
 * and the bridge reads that.
 */
class Bundle_Alias_ManifestSupport extends ManifestSupport_Abstract
{
    public static function get_name(): string
    {
        return 'Bundle Aliases';
    }

    public static function process(array &$manifest_data): void
    {
        $aliases = [];

        foreach ((array) config('rsx.bundle_aliases', []) as $alias => $bundle_class) {
            $aliases[(string) $alias] = (string) $bundle_class;
        }

        $manifest_data['data']['bundle_aliases'] = $aliases;
    }
}
