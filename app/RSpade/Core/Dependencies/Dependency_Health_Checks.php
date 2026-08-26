<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dependencies;

use App\RSpade\Core\Dependencies\Dependency_Manager;

/**
 * Dependency_Health_Checks - the app dependency layer's probe and its healer, together.
 *
 * The layer is two manifests at the project root, composer.json and package.json, each
 * INDEPENDENTLY OPTIONAL: an app may carry either, both, or neither. Neither is required
 * for the site to run - nothing at request time reads them, and the bundle compiler
 * resolves node modules through Node's own upward walk (system/node_modules first, then
 * the project root), so an app with no npm layer serves pages exactly as one with it.
 *
 * WHY THIS IS A WARN AND NOT A FAIL. A missing manifest costs nothing today; it costs
 * something the first time the developer wants a package of their own, because
 * `rsx:npm install` writes to a file that is not there. That is a "you will want this
 * later" finding, which is what WARN is for. rsx:health exits non-zero only on FAIL, so
 * this can never break a deploy gate or a container healthcheck.
 *
 * AND WHY THE FRAMEWORK UPDATE STAYS SILENT ABOUT IT. rsx:framework:post_update reports
 * on the UPDATE. Whether this app ought to have adopted an app dependency layer is not a
 * property of the update and must never color its outcome - so the post-update
 * reconciler guards each half on its own file and says nothing when one is absent. This
 * check is where the question belongs, on a command the operator ran deliberately.
 */
class Dependency_Health_Checks
{
    /**
     * The manifest a fresh provision ships. Regenerated here rather than copied from
     * anywhere, because there is no source to copy from on a box that never had one.
     */
    private const PACKAGE_JSON_TEMPLATE = [
        'name' => 'rsx-app',
        'description' => "RSX application dependency layer - managed via 'php artisan rsx:npm'",
        'private' => true,
        'dependencies' => [],
        'rsx' => ['provided' => []],
    ];

    /**
     * Report the presence of each half of the app dependency layer.
     *
     * @return array
     */
    #[Health_Check('App Dependency Layer')]
    public static function app_dependency_layer(): array
    {
        $composer = file_exists(Dependency_Manager::root_composer_json_path());
        $package = file_exists(Dependency_Manager::root_package_json_path());

        if ($composer && $package) {
            return [
                'status' => 'OK',
                'detail' => 'Root composer.json and package.json both present.',
                'remediation' => null,
            ];
        }

        if (!$composer && !$package) {
            // No layer at all is a coherent configuration - a framework-only install,
            // or an app that has never wanted a dependency of its own.
            return [
                'status' => 'INFO',
                'detail' => 'No app dependency layer (no root composer.json or package.json).'
                    . ' Valid - nothing at runtime needs one.',
                'remediation' => null,
            ];
        }

        if (!$package) {
            return [
                'status' => 'WARN',
                'detail' => 'Root package.json is missing, so this app has no npm layer.'
                    . ' Nothing is broken - but `rsx:npm install` has nowhere to record a'
                    . ' package, and app-layer npm dependencies cannot be added until it exists.',
                'remediation' => 'php artisan rsx:heal app-npm-layer',
            ];
        }

        return [
            'status' => 'WARN',
            'detail' => 'Root composer.json is missing, so this app has no composer layer.'
                . ' Nothing is broken - but `rsx:composer require` has nowhere to record a'
                . ' package, and the framework replace map cannot be maintained.',
            'remediation' => 'Restore the root composer.json from the starter project'
                . ' (rspade-framework/rspade); it carries the framework replace map and'
                . ' cannot be regenerated from nothing.',
        ];
    }

    /**
     * Create the root package.json a fresh provision ships, when the app has none.
     *
     * Creation-only, per the Heal_Runner boundary: a file that already exists is never
     * touched, whatever it contains. A corrupt manifest is a broken install for a human
     * to look at, not something to overwrite - overwriting would destroy the `rsx.provided`
     * ledger, which is the one thing in this file that cannot be reconstructed.
     *
     * @return array
     */
    #[Health_Heal('app-npm-layer')]
    public static function heal_app_npm_layer(): array
    {
        $path = Dependency_Manager::root_package_json_path();

        if (file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (!is_array($decoded)) {
                return [
                    'status' => 'REFUSED',
                    'detail' => "{$path} exists but is not valid JSON. That is a broken file,"
                        . ' not a missing one - repair or delete it by hand. Refusing to'
                        . ' overwrite it, because doing so would discard the rsx.provided ledger.',
                ];
            }

            return [
                'status' => 'ALREADY_OK',
                'detail' => "Root package.json already present at {$path}.",
            ];
        }

        Dependency_Manager::write_root_package_json(self::PACKAGE_JSON_TEMPLATE);

        return [
            'status' => 'HEALED',
            'detail' => "Created {$path} (empty app npm layer)."
                . ' Commit it - it is tracked in the starter project, and an untracked'
                . ' manifest is absent from a fresh clone and from every deploy.',
        ];
    }
}
