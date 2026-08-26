<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Session_Values_Cleanup_Service
 *
 * Hourly sweep for EXPIRED rows in _session_values.
 *
 * Most session values need no sweeping at all: the FK is ON DELETE CASCADE, so a value's
 * lifetime is its session's and Session_Cleanup_Service reclaims both together. This task
 * exists only for the narrower case of a value given an explicit expires_at that has passed
 * while its session is still alive - a long-lived session could otherwise accumulate expired
 * rows indefinitely.
 *
 * IT IS NOT WHAT MAKES EXPIRY CORRECT. Session::get_value() filters on expires_at, so an
 * expired value is unreadable the instant it expires whether or not this has run. This only
 * reclaims the space, which is why running hourly rather than continuously is fine and why a
 * missed run is harmless.
 *
 * Deletes are CHUNKED, like the flash-alert and API-log sweeps: steady state is a handful of
 * rows, but the first run on an established installation could face a backlog, and no
 * maintenance sweep should hold one giant lock. #[Exclusive] so a long run never overlaps
 * its own next tick.
 *
 * Statement choice: a raw predicate DELETE via DB::table rather than a fetch-then-iterate.
 * The bulk-write mandate exists to keep per-record realtime frames and after_* hooks firing;
 * these rows are plain storage with neither, so loading them would serve no purpose. Nothing
 * is truncated - the loop runs until the predicate matches nothing.
 */
class Session_Values_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Rows deleted per DELETE statement. Small enough that each statement's lock hold is
     * trivial, large enough that a backlog clears in a few statements.
     * Overridable per run via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Delete session values whose explicit expiry has passed.
     *
     * Rows with a NULL expires_at are never touched here - "no expiry" means the value lives
     * as long as its session, and the cascade is what ends it.
     *
     * @param Task_Instance $task Task instance for heartbeats
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return array Cleanup statistics
     */
    #[Task('Delete expired session values (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_expired_values(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $now = now();

        $total = 0;

        while (true) {
            $deleted = DB::table('_session_values')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->limit($chunk_size)
                ->delete();
            $total += $deleted;

            if ($deleted < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        if ($total > 0) {
            $task->info("Deleted {$total} expired session values");
        }

        return ['deleted' => $total];
    }
}
