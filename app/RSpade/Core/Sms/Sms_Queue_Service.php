<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sms;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Sms\Rsx_Sms;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Sms_Queue_Service - processes the outgoing SMS queue (framework core).
 */
class Sms_Queue_Service extends Rsx_Service_Abstract
{
    /**
     * Send the pending outgoing SMS queue.
     *
     * THERE IS NO SMS PROVIDER. Every claimed row is recorded SUPPRESSED saying so -
     * that is the whole runner, and it is honest: a message nobody can deliver is not
     * "pending", it is not going. The loop SHAPE is the mail runner's (claim_next() ->
     * attempt -> terminal status), so wiring a provider means replacing the body of the
     * try and adding the two catches, not designing a queue.
     *
     * DELIVERY MODE DECIDES WHETHER ANY OF THIS RUNS, exactly as it does for mail:
     * 'disabled' returns immediately with the queue FROZEN - nothing claimed, nothing
     * reclaimed, every row keeping the state it had. There is no stale-message sweep
     * here and there must not be: that sweep exists so a repaired mail queue does not
     * flood a month of stale notices, and an SMS queue cannot accumulate that backlog -
     * there is no provider to break, and every row is recorded SUPPRESSED on its first
     * pass.
     */
    #[Task('Send pending outgoing SMS queue')]
    #[Exclusive]
    #[Schedule('every minute')]
    public static function send_pending_queue(Task_Instance $task, array $params = []): array
    {
        $counts = ['sent' => 0, 'server_errors' => 0, 'failed' => 0, 'suppressed' => 0, 'reclaimed' => 0];

        if (Rsx_Sms::delivery_mode() === Rsx_Sms::MODE_DISABLED) {
            $pending = Sms_Queue_Model::pending_count();
            $task->info("SMS delivery is disabled - {$pending} message(s) left pending");

            return $counts;
        }

        // #[Exclusive] means no other runner exists right now, so anything still in
        // SENDING was claimed by a runner that died mid-message - and claim_next() can
        // never see it again. The mail drain does exactly this, for the same reason.
        $counts['reclaimed'] = Sms_Queue_Model::reclaim_stranded();

        if ($counts['reclaimed'] > 0) {
            $task->info(
                "Reclaimed {$counts['reclaimed']} stranded message(s) left SENDING by a drain that ended mid-send"
            );
        }

        while (true) {
            $queued = Sms_Queue_Model::claim_next();
            if (!$queued) {
                break;
            }

            $queued->mark_suppressed('no SMS provider configured');
            $task->info("Suppressed SMS #{$queued->id} to {$queued->to_number}: no SMS provider configured");

            $counts['suppressed']++;
        }

        return $counts;
    }

    /**
     * Delete whole SMS queue rows past the retention window.
     */
    #[Task('Clean up old SMS queue records')]
    #[Schedule('daily at 3am')]
    public static function cleanup(Task_Instance $task, array $params = []): array
    {
        $days = $params['days'] ?? config('rsx.sms.retention_days', 30);
        $deleted = Sms_Queue_Model::cleanup_old($days);
        $task->info("Deleted {$deleted} SMS records older than {$days} days");

        return ['deleted' => $deleted];
    }
}
