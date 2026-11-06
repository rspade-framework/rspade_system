<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Tests\Locks\Php\Site_Lock_Cli_Fixture_Command;

/**
 * Site_Lock_Cli_Fixture_Command declared exempt: the same writes, outside every window.
 */
class Site_Lock_Cli_Exempt_Fixture_Command extends Site_Lock_Cli_Fixture_Command
{
    /** Site lock exemption (Site_Lock_Cli): this command provisions the database. */
    public const SITE_LOCK_EXEMPT = 'site lock cli test fixture';

    protected $signature = 'rsx_test:site_lock_cli_exempt_fixture {mode} {email}';
}
