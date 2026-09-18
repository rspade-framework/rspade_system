<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ProdMode\Cli;

use App\RSpade\Tests\ProdMode\Cli\Prod_Script_Cli_Test_Abstract;

/**
 * The recommended production posture, applied to the real trees - cli/prod_readonly.sh
 * under the runner.
 *
 * WHAT THE SCRIPT PROVES. With build/, system/ and rsx/ stripped of every write bit, a
 * sealed box still serves the page and its compiled bundle, runs migrate, runs a framework
 * task and reports health - and then that none of it WROTE into those trees, permissions
 * or no permissions. Run as an unprivileged user it additionally exercises the OS refusal
 * on every command; run as root (this framework's development container is root) the
 * refusal is proven by an unprivileged probe instead, and the script says which path it
 * took. Every recorded file mode is re-applied afterwards.
 *
 * THE BOX ENDS IN DEVELOPMENT MODE, with the tree modes restored: the script's EXIT trap
 * restores the modes and runs rsx:prod:disable on a failed assertion, on an interrupt, on
 * anything.
 *
 * INVOCATION:
 *
 *     php artisan rsx:test --framework --group=prod_mode --sequential
 *
 * --sequential because this class and Prod_Lifecycle_Cli_Test both switch the mode of the
 * SAME box: run concurrently one would be chmod'ing the trees the other is building into.
 * It is also why they are $explicit_group_only - a bare suite run must not walk into a
 * full build and a chmod of the source trees.
 */
class Prod_Readonly_Cli_Test extends Prod_Script_Cli_Test_Abstract
{
    protected static $explicit_group_only = true;

    public static function test_read_only_production_posture()
    {
        static::__run_script('prod_readonly.sh', '/tmp/rsx_prod_readonly_*.log');
    }
}
