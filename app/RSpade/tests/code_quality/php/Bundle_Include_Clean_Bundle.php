<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

// @CONV-BUNDLE-04-EXCEPTION This is a FIXTURE standing in for an APPLICATION bundle: it is
// handed to CONV-BUNDLE-02 under a synthetic rsx/ path, and its whole job is to carry the
// include list a real Frontend_Bundle carries (rsx/ paths included) so the rule can prove it
// does not flag one. It is never compiled and never rendered. CONV-BUNDLE-04 judges by the
// file's own location, which here is the test tree rather than a framework bundle.

/**
 * CONV-BUNDLE-02 fixture: the shape of the template app's own Frontend_Bundle - rsx/ paths,
 * an app bundle class, npm-backed aliases and its own directory. None of it is the framework
 * application tree, and none of it may be flagged.
 */
class Bundle_Include_Clean_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'rsx/theme/variables.scss',
                'Bootstrap5_Src_Bundle',
                'jquery',
                'rsx/models',
                __DIR__,
            ],
            'include_routes' => [
                'rsx/app/login',
            ],
        ];
    }
}
