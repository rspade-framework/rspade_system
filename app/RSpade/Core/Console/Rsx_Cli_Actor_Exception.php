<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Console;

use RuntimeException;

/**
 * A command line's --user / --site names nothing, or --site is required and absent
 * (Rsx_Cli_Actor::resolve()). The message is the one to print; error_type is the stable
 * machine token rsx:ajax reports in its JSON error envelope (user_not_found, invalid_user,
 * invalid_site, site_not_found, site_required).
 */
#[Instantiatable]
class Rsx_Cli_Actor_Exception extends RuntimeException
{
    public readonly string $error_type;

    public function __construct(string $message, string $error_type)
    {
        parent::__construct($message);

        $this->error_type = $error_type;
    }
}
