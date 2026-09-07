<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * CONV-BUNDLE-02 fixture: the same dependency spelled as a BUNDLE CLASS instead of a path.
 * Naming a class rather than a directory does not make it the application's to include.
 */
class Bundle_Include_Panel_Class_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                '_Sys_Theme_Bundle',
                __DIR__,
            ],
        ];
    }
}
