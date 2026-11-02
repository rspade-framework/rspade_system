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
 * Companion_Changed_Topic - "this companion's status changed; go look". Filter: {name}, or {}
 * for every companion.
 *
 * Published by rsx-companiond itself (its lib/store.js, at most once per tick per companion)
 * when anything a summary shows moved: status, status line, state, progress, an instance
 * starting or ending, a hold. The frame carries the name and nothing else; the watcher refetches
 * through Rsx_Companion, which asks Companion_Gates again.
 *
 * @REALTIME-AUTH-01-EXCEPTION - can_subscribe() delegates to Companion_Gates, whose view gate
 * denies every viewer but a developer unless the application registers a handler.
 */
class Companion_Changed_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return Companion_Gates::can_subscribe($filter);
    }
}
