<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * CONV-BUNDLE-02 fixture: include_routes is the second list, and it is the same rule. Route
 * extraction pulls no assets, but it still reaches into the framework application tree - and
 * the published entry route is what makes reaching in unnecessary.
 */
class Bundle_Include_Panel_Routes_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                __DIR__,
            ],
            'include_routes' => [
                'app/RSpade/Sys/app/sys',
            ],
        ];
    }
}
