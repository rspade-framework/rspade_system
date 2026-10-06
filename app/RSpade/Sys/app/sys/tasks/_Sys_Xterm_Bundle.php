<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Tasks;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * _Sys_Xterm_Bundle - xterm.js for the task detail's live console (_Sys_Task_Console).
 *
 * Auto-discovered by _Sys_Bundle's directory include, so it reaches the panel only - the
 * API console, which shares the theme, does not carry a terminal. Both packages are
 * FRAMEWORK npm dependencies (system/node_modules/@xterm). The stylesheet is an explicit
 * file include because vendor/ is never recursed by a directory include.
 */
class _Sys_Xterm_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'app/RSpade/Sys/app/sys/tasks/vendor/xterm.scss',
            ],
            'npm' => [
                '_Sys_Xterm_Terminal' => "import { Terminal } from '@xterm/xterm'",
                '_Sys_Xterm_Fit_Addon' => "import { FitAddon } from '@xterm/addon-fit'",
            ],
        ];
    }
}
