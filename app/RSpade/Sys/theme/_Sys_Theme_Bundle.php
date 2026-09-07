<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\Theme;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * _Sys_Theme_Bundle - the control panel's Bootstrap build and design tokens.
 *
 * THE INVARIANT (CONV-BUNDLE-04): this include list names no rsx/ path and no
 * app-defined class. The panel is framework code, and a framework feature does not
 * get to depend on the application it ships beside - an app is free to restyle its
 * theme or redefine its Bootstrap build, and the panel must be untouched by that.
 * So the panel compiles its OWN Bootstrap, from the framework's own npm dependency.
 *
 * Bootstrap arrives three ways, deliberately:
 *   - SCSS through vendor/bootstrap.scss, the one file in this tree allowed to
 *     @import (the bundle processor permits it only under a '/vendor/' path).
 *     It is named as an EXPLICIT FILE because 'vendor' is a never-recursed
 *     directory basename and so can never arrive from a directory include.
 *   - JS through the 'npm' key, esbuild-bundled from system/node_modules.
 *   - Icons through cdn_assets, mirrored into the git-tracked .cdn-cache store and
 *     served same-origin from /_vendor/ in every mode.
 *
 * @CONV-BUNDLE-02-EXCEPTION - this bundle names specific theme files, not the
 * whole theme directory: the vendor entry file must be explicit, and the component
 * SCSS beside it is included by _Sys_Bundle's directory include instead.
 */
class _Sys_Theme_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                // Bootstrap, built with the panel's variable overrides.
                'app/RSpade/Sys/theme/vendor/bootstrap.scss',
                // The --rsx-* custom properties and the reboot corrections, after
                // Bootstrap so they win.
                'app/RSpade/Sys/theme/theme.scss',
            ],
            'watch' => [
                // The overrides are consumed by an @import the bundler cannot see
                // through, so the compiled output must be invalidated by hand when
                // either of these changes.
                'app/RSpade/Sys/theme/variables.scss',
                'app/RSpade/Sys/theme/vendor/bootstrap.scss',
            ],
            'npm' => [
                'bootstrap' => "import * as bootstrap from 'bootstrap'",
            ],
            'cdn_assets' => [
                'css' => [
                    [
                        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css',
                    ],
                ],
            ],
        ];
    }
}
