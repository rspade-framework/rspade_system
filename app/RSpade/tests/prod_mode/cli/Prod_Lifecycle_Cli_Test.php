<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ProdMode\Cli;

use App\RSpade\Tests\ProdMode\Cli\Prod_Script_Cli_Test_Abstract;

/**
 * The production lifecycle, end to end, on this box - cli/prod_lifecycle.sh under the
 * runner.
 *
 * WHAT THE SCRIPT PROVES. rsx:prod:enable produces a SEALED build and the site serves
 * from it (the page and the compiled bundle it names both answer 200); a sealed box
 * writes nothing under build/, system/ or rsx/ while it serves, runs rsx:health and runs
 * migrate; rsx:build and rsx:clean both refuse without --force; removing the seal STOPS
 * the box (500, and rsx:health exits non-zero naming the remedy) rather than letting it
 * quietly rebuild itself; rsx:build --force is the repair; and rsx:prod:disable returns a
 * working development box.
 *
 * THE BOX ENDS IN DEVELOPMENT MODE. The script's EXIT trap reads RSX_MODE and runs
 * rsx:prod:disable if anything left the box in a production mode - on a failed assertion,
 * on an interrupt, on anything - and it says so loudly if that restoration itself fails.
 *
 * INVOCATION:
 *
 *     php artisan rsx:test --framework --group=prod_mode --sequential
 *
 * --sequential because this class and Prod_Readonly_Cli_Test both switch the mode of the
 * SAME box: run concurrently they would seal and unseal the build tree underneath each
 * other, and neither one's verdict would mean anything. It is also why they are
 * $explicit_group_only - a bare suite run must not walk into three full builds.
 *
 * Roughly four minutes. That is the lifecycle, not the harness.
 */
class Prod_Lifecycle_Cli_Test extends Prod_Script_Cli_Test_Abstract
{
    protected static $explicit_group_only = true;

    public static function test_production_lifecycle_round_trip()
    {
        static::__run_script('prod_lifecycle.sh', '/tmp/rsx_prod_lifecycle_*.log');
    }
}
