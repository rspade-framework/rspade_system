<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use App\RSpade\Core\Companion\Companion_Gates;
use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;

/**
 * Companion_Output_Topic - "this companion wrote output; go read it". Filter: {name}.
 *
 * Published by rsx-companiond at most once per tick per companion that wrote lines. Separate
 * from Companion_Changed_Topic because output is the high-volume feed and only a console needs
 * it. The watcher reads the lines after the cursor it holds (Rsx_Companion::output_after()).
 *
 * @REALTIME-AUTH-01-EXCEPTION - can_subscribe() delegates to Companion_Gates, whose view gate
 * denies every viewer but a developer unless the application registers a handler.
 */
class Companion_Output_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return isset($filter['name']) && Companion_Gates::can_subscribe($filter);
    }
}
