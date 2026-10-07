<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;
use App\RSpade\Core\Task\Task_Gates;

/**
 * Task_Output_Topic - "this run wrote output; go read it". Filter: {id}.
 *
 * Published (by Task_Notify) whenever lines are added to a run's output - stdout, stderr,
 * operator lines. Separate from Task_Changed_Topic because output is the high-volume feed and
 * only a console needs it; a progress widget never pays for a chatty task's every line. The
 * watcher reads the lines after the last id it holds.
 *
 * @REALTIME-AUTH-01-EXCEPTION - can_subscribe() delegates to Task_Gates, whose view gates
 * deny every viewer but a developer unless the application registers a handler.
 *
 * Subscribing is decided by the run's view gate (Task_Gates::can_view()).
 */
class Task_Output_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return Task_Gates::can_subscribe_to_task((int) ($filter['id'] ?? 0));
    }
}
