<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Revision_Cleanup_Service
 *
 * Scheduled retention for the revision history. Mirrors Api_Cleanup_Service: deletes are
 * CHUNKED so a large backlog never issues one giant lock-holding DELETE, and the task is
 * #[Exclusive] so a slow backlog-recovery run never overlaps its own next tick.
 *
 * Only `_transactions` is deleted. `_revisions.transaction_id` is an FK with ON DELETE
 * CASCADE, so the revisions go with their transaction - one statement, no orphans, and no
 * second sweep that could be interrupted between the two halves.
 *
 * config('rsx.revisions.retention_days') = 0 means KEEP FOREVER, and it is the default. A
 * history is the kind of data whose value shows up years later, so the framework does not
 * decide to throw any of it away; an operator who wants a window sets one.
 */
class Revision_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Rows deleted per DELETE statement. Small enough to keep each statement's lock hold
     * trivial, large enough that a backlog clears in a few statements. Overridable per run
     * via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Prune _transactions rows (and, by FK cascade, their _revisions) older than the
     * configured retention window.
     *
     * @param Task_Instance $task Task instance for heartbeats
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing;
     *                      retention_days: override the config, testing)
     * @return null
     */
    #[Task('Prune revision history past its retention window')]
    #[Exclusive]
    #[Schedule('daily at 3am')]
    public static function cleanup_revisions(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $retention_days = (int) ($params['retention_days'] ?? config('rsx.revisions.retention_days', 0));

        if ($retention_days <= 0) {
            $task->stdout('rsx.revisions.retention_days is 0 - revision history is kept forever and nothing was deleted.');

            $task->state([
                'deleted' => 0,
                'retention_days' => $retention_days,
                'kept_forever' => true,
            ]);
            $task->summary('Revision history is kept forever; nothing was deleted.');

            return null;
        }

        $cutoff = now()->subDays($retention_days);

        // created_at is indexed, so the backlog is cheap to count up front.
        $backlog = DB::table('_transactions')->where('created_at', '<', $cutoff)->count();
        if ($backlog > 0) {
            $task->status("Deleting {$backlog} revision transaction(s) older than {$retention_days} days");
        }

        $total = 0;
        while (true) {
            if ($task->is_stop_requested()) {
                $task->stdout("Stop requested - stopped after deleting {$total} of {$backlog} revision transaction(s).");
                $task->summary("Stopped after deleting {$total} of {$backlog} revision transactions.");

                return null;
            }

            $deleted = DB::table('_transactions')
                ->where('created_at', '<', $cutoff)
                ->limit($chunk_size)
                ->delete();
            $total += $deleted;
            $task->progress_count($total, max($backlog, $total));

            if ($deleted < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        $task->stdout($total > 0
            ? "Deleted {$total} revision transaction(s) older than {$retention_days} days."
            : "No revision transactions are older than {$retention_days} days; nothing deleted.");

        $task->state([
            'deleted' => $total,
            'retention_days' => $retention_days,
            'kept_forever' => false,
        ]);
        $task->summary("Deleted {$total} revision transactions older than {$retention_days} days.");

        return null;
    }
}
