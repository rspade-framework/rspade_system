<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use RuntimeException;

/**
 * A stage of the document worker ran past rsx.documents.stage_seconds.
 *
 * Thrown by Document_Stage_Bound from its SIGALRM handler, so it surfaces at whatever PHP
 * statement the stage had reached. The message is the recorded failure reason
 * ("render exceeded 20 seconds").
 */
class Document_Stage_Timeout_Exception extends RuntimeException
{
}
