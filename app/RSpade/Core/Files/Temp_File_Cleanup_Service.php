<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Rsx_Temp_Files;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Temp_File_Cleanup_Service - the temp file store's expiry (Rsx_Temp_Files).
 *
 * Deletes every temp file whose expires_at has passed - only the ones THIS database has rows
 * for, so an uploads mount shared with another environment never loses that environment's
 * files. #[Exclusive]: a slow backlog never overlaps its own next tick.
 */
class Temp_File_Cleanup_Service extends Rsx_Service_Abstract
{
    #[Task('Delete expired temp files (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function delete_expired(Task_Instance $task, array $params = [])
    {
        $task->status('Deleting expired temp files');
        $deleted = Rsx_Temp_Files::delete_expired($task);

        $task->state(['deleted' => $deleted]);

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after deleting {$deleted} expired temp file(s).");
            $task->summary("Stopped after deleting {$deleted} expired temp file(s).");

            return null;
        }

        $task->stdout($deleted > 0 ? "Deleted {$deleted} expired temp file(s)." : 'No temp files have expired; nothing deleted.');

        $task->summary("Deleted {$deleted} expired temp file(s).");

        return null;
    }
}
