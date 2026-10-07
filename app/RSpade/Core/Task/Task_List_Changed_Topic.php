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
 * Task_List_Changed_Topic - "a run entered, left or moved between lifecycle states; reread
 * your list". The frame's data names the run's task: {class (simple service name), method}.
 *
 * Published (by Task_Notify) when a run is queued, starts, settles, is cancelled or is killed.
 * Filters match shallowly: {} hears every run - a list of runs (the developer console's grid,
 * an application's "my tasks" panel) - while {class} hears one service and {class, method}
 * one task, which is how a page watches whether a task is running (Rsx_Task.watch_task()).
 * The watcher refetches through a gated search endpoint, which decides what the viewer sees.
 *
 * @REALTIME-AUTH-01-EXCEPTION - can_subscribe() delegates to Task_Gates, which admits a
 * developer, or a signed-in viewer in a realm whose view scope has a handler.
 *
 * Subscribing needs a realm in which some runs can be visible at all (Task_Gates).
 */
class Task_List_Changed_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return Task_Gates::can_subscribe_to_list();
    }
}
