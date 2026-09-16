<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Console;

/**
 * IS THIS PROCESS AN EXTERNAL SCRIPT?
 *
 * system/script.php - the single include that boots the shell for a PHP script living
 * outside the application tree - defines RSX_SCRIPT_MODE before anything reads argv.
 * This is the booted-world reader for it.
 *
 * WHAT IT IS FOR. Several framework seams decide what to do by looking at
 * $_SERVER['argv'][1], because under artisan that token IS the command name: the
 * manifest's boot-skip list, the build-context sniff, the PHP-requirements exemption,
 * the container gate's echoed invocation. A script's first argument is the SCRIPT'S,
 * and reading it as a command name produced the silent failure this mode ends -
 * `php my_tool.php help` booting no manifest, every site-scoped model answering zero
 * rows with no error. Each of those seams asks this class first.
 *
 * It is a constant rather than an internal flag because it must be true before the
 * pre-boot flag strip runs, and because a script declares it by INCLUDING the entry
 * point, never by passing a token.
 *
 * Details: rsx:man scripting.
 */
class Rsx_Script
{
    /**
     * Was this process started by including system/script.php?
     */
    public static function is_active(): bool
    {
        return defined('RSX_SCRIPT_MODE');
    }
}
