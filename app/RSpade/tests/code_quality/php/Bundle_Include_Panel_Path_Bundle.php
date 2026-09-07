<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * CONV-BUNDLE-02 fixture: an application bundle naming the framework application tree by
 * PATH. The rule reads define(), so the include list has to be a real one.
 */
class Bundle_Include_Panel_Path_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                'app/RSpade/Sys/theme',
                __DIR__,
            ],
        ];
    }
}
