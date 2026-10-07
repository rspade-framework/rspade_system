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
 * Task_Changed_Topic - "this run changed; go look". Filter: {id}.
 *
 * Published (by Task_Notify) when a run's lifecycle moves - queued, started, settled, stopped,
 * killed, cancelled - and when the running task writes a report, a message or an attachment.
 * Its output has its own topic (Task_Output_Topic). The frame carries the id only, and the
 * watcher refetches through a gated endpoint.
 *
 * @REALTIME-AUTH-01-EXCEPTION - can_subscribe() delegates to Task_Gates, whose view gates
 * deny every viewer but a developer unless the application registers a handler.
 *
 * Subscribing is decided by the run's view gate (Task_Gates::can_view()).
 */
class Task_Changed_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return Task_Gates::can_subscribe_to_task((int) ($filter['id'] ?? 0));
    }
}
