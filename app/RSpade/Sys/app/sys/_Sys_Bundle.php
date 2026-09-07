<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * _Sys_Bundle - every asset the control panel serves.
 *
 * THE INVARIANT (CONV-BUNDLE-04, critical): THIS INCLUDE LIST NAMES NO rsx/ PATH
 * AND NO APP-DEFINED CLASS. The panel is a framework application that happens to
 * run beside somebody else's; it borrows nothing from rsx/theme, rsx/lib or
 * rsx/models, because any of those may be restyled, replaced or deleted by the
 * application that owns them, and the panel must still come up. Everything the
 * panel needs ships in app/RSpade/Sys/.
 *
 * jQuery and Lodash are named explicitly for the same reason the template's
 * bundles do: the required-bundle list is a framework default, and a bundle that
 * depends on them says so.
 */
class _Sys_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                'lodash',
                '_Sys_Theme_Bundle',            // Bootstrap + the panel's design tokens
                'app/RSpade/Sys/theme',         // the panel's own components
                'app/RSpade/Sys/lib',           // shared panel code (classes only)
                __DIR__,                         // the panel module itself
            ],
        ];
    }
}
