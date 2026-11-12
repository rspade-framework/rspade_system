<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Revisions;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Time;

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
 *
 * prune_request_payloads() is the writer request history's shorter window: it nulls a
 * transaction's request_payload / response_status / response_body after
 * config('rsx.request_history.payload_retention_days') (default 7) and keeps the row.
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
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
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

    /**
     * Null the writer request history's short-lived columns - request_payload,
     * response_status, response_body - on _transactions rows older than
     * config('rsx.request_history.payload_retention_days') (default 7; 0 = never prune).
     * The row, its reference, fingerprint, timing and revisions are kept; a retry of a
     * pruned request answers 410 request_response_expired (Request_History).
     *
     * Chunked UPDATEs over the request_history_held index (a virtual column true while any
     * of the three columns holds a value), so each run touches exactly the rows it still
     * owes work on, never the rows earlier runs already nulled. _transactions is framework
     * bookkeeping, not a site-scoped model, and the writes are raw: nothing here is a site
     * write, so the task needs no site lock under the task enforcement window.
     *
     * @param Task_Instance $task Task instance for heartbeats
     * @param array $params Task parameters (chunk_size: rows per UPDATE, testing;
     *                      retention_days: override the config, testing)
     * @return null
     */
    #[Task('Prune request history payloads past their retention window')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function prune_request_payloads(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $retention_days = (int) ($params['retention_days'] ?? config('rsx.request_history.payload_retention_days', 7));

        if ($retention_days <= 0) {
            $task->stdout('rsx.request_history.payload_retention_days is 0 - request payloads and responses are kept forever and nothing was pruned.');

            $task->state([
                'pruned' => 0,
                'retention_days' => $retention_days,
                'kept_forever' => true,
            ]);
            $task->summary('Request payloads and responses are kept forever; nothing was pruned.');

            return null;
        }

        $cutoff = Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), $retention_days * 86400));
        $owed = fn () => DB::table('_transactions')->where('request_history_held', 1)->where('created_at', '<', $cutoff);

        $backlog = $owed()->count();
        if ($backlog > 0) {
            $task->status("Pruning the request payload and response of {$backlog} transaction(s) older than {$retention_days} days");
        }

        $total = 0;
        while (true) {
            if ($task->is_stop_requested()) {
                $task->stdout("Stop requested - stopped after pruning {$total} of {$backlog} transaction(s).");
                $task->summary("Stopped after pruning {$total} of {$backlog} request payloads.");

                return null;
            }

            $pruned = $owed()
                ->limit($chunk_size)
                ->update([
                    'request_payload' => null,
                    'response_status' => null,
                    'response_body' => null,
                ]);
            $total += $pruned;
            $task->progress_count($total, max($backlog, $total));

            if ($pruned < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        $task->stdout($total > 0
            ? "Pruned the request payload and response of {$total} transaction(s) older than {$retention_days} days."
            : "No request payloads are older than {$retention_days} days; nothing pruned.");

        $task->state([
            'pruned' => $total,
            'retention_days' => $retention_days,
            'kept_forever' => false,
        ]);
        $task->summary("Pruned {$total} request payloads older than {$retention_days} days.");

        return null;
    }
}
