<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use RuntimeException;

/**
 * Companion_Control_Exception - an operator verb could not be carried out, said plainly: no
 * rsx-companiond is listening on this box, or the daemon refused the verb (pause on a companion
 * that is not running, or one that does not use the SDK). Its message is fit to show the
 * operator. A refusal by Companion_Gates is never this: that throws AjaxUnauthorizedException.
 */
class Companion_Control_Exception extends RuntimeException
{
}
