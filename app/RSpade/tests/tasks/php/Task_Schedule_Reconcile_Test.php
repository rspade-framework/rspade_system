<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the #[Schedule] reconciliation logic in rsx:task:process
 * (App\RSpade\Commands\Rsx\Task_Process_Command::reconcile_schedules()).
 *
 * Each cron tick reconciles recurring schedule "tracker" rows (a _tasks row with
 * next_run_at NOT NULL and cron_expression NOT NULL, one per scheduled class::method)
 * against the manifest's #[Schedule] attributes:
 *   - CREATE   : a scheduled task with no tracker gets one inserted.
 *   - REGENERATE: a tracker with a stored cron_expression differing from the manifest
 *                 is DELETED and re-inserted fresh (new row id, recomputed next_run_at).
 *   - MATCH    : a tracker whose cron_expression equals the manifest is left untouched.
 *   - DELETE-ORPHAN: a tracker whose class::method is no longer in the manifest is deleted.
 *
 * Reconciliation is driven with Artisan::call('rsx:task:process') (no --once, which
 * would drain a task). Trackers are created with a FUTURE next_run_at (the next cron
 * occurrence), so has_pending_work() stays false and no detached worker is spawned.
 *
 * This class COMMITS rows via a real console command, so it declares both a DB reset
 * and no per-test transactions (see Task_Dispatch_Test for the same pattern). Every
 * test normalizes state itself and does not assume a run order.
 */
class Task_Schedule_Reconcile_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /**
     * Return the first real scheduled task definition from the manifest, or null if
     * there are none. Each definition is ['class', 'method', 'cron_expression', 'queue'].
     */
    private static function __first_scheduled_def(): ?array
    {
        $defs = Task::get_scheduled_tasks();
        if (empty($defs)) {
            return null;
        }
        return $defs[0];
    }

    /**
     * Fetch the single tracker row (next_run_at NOT NULL) for a class::method, or null.
     */
    private static function __tracker_for(string $class, string $method): ?object
    {
        return DB::table('_tasks')
            ->where('class', $class)
            ->where('method', $method)
            ->whereNotNull('next_run_at')
            ->first();
    }

    // -------------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------------

    public static function test_reconcile_creates_tracker()
    {
        $def = static::__first_scheduled_def();
        if ($def === null) {
            static::__skip('No scheduled tasks in the manifest to reconcile');
        }

        // Remove any existing tracker so this tick must create one from scratch.
        DB::table('_tasks')
            ->where('class', $def['class'])
            ->where('method', $def['method'])
            ->whereNotNull('next_run_at')
            ->delete();

        Artisan::call('rsx:task:process');

        $tracker = static::__tracker_for($def['class'], $def['method']);
        static::__assert_not_null($tracker, 'A tracker should be created for the scheduled task');
        static::__assert_equals($def['cron_expression'], $tracker->cron_expression, 'Tracker cron_expression should match the manifest');
        static::__assert_not_null($tracker->next_run_at, 'Tracker next_run_at should not be null');
        // next_run_at is the next cron occurrence: at or after now (allow a small skew).
        static::__assert_greater_than(time() - 60, strtotime($tracker->next_run_at), 'Tracker next_run_at should be at or after now');
    }

    // -------------------------------------------------------------------------
    // REGENERATE (delete + re-insert, NOT in-place update)
    // -------------------------------------------------------------------------

    public static function test_reconcile_regenerates_on_changed_expression()
    {
        $def = static::__first_scheduled_def();
        if ($def === null) {
            static::__skip('No scheduled tasks in the manifest to reconcile');
        }

        // Ensure a matching tracker exists first.
        Artisan::call('rsx:task:process');
        $before = static::__tracker_for($def['class'], $def['method']);
        static::__assert_not_null($before, 'Precondition: a tracker should exist after a tick');

        // Corrupt the stored expression so it differs from the manifest. Feb 29 is a
        // deliberately odd, valid cron unlikely to equal any real schedule; if it
        // somehow matches, pick another equally-unlikely expression.
        $wrong_expression = '0 0 29 2 *';
        if ($wrong_expression === $def['cron_expression']) {
            $wrong_expression = '17 4 1 1 *';
        }

        DB::table('_tasks')->where('id', $before->id)->update([
            'cron_expression' => $wrong_expression,
            'next_run_at' => date('Y-m-d H:i:s', time() + 86400 * 365),
            'updated_at' => now(),
        ]);

        Artisan::call('rsx:task:process');

        $after = static::__tracker_for($def['class'], $def['method']);
        static::__assert_not_null($after, 'A regenerated tracker should exist');
        static::__assert_equals($def['cron_expression'], $after->cron_expression, 'Tracker cron_expression should be restored to the manifest value');
        static::__assert_not_equals($before->id, $after->id, 'Regenerate should delete + re-insert (new row id), not update in place');
    }

    // -------------------------------------------------------------------------
    // MATCH (untouched)
    // -------------------------------------------------------------------------

    public static function test_reconcile_leaves_matching_tracker_untouched()
    {
        $def = static::__first_scheduled_def();
        if ($def === null) {
            static::__skip('No scheduled tasks in the manifest to reconcile');
        }

        // Normalize so the tracker matches the manifest expression.
        Artisan::call('rsx:task:process');
        $before = static::__tracker_for($def['class'], $def['method']);
        static::__assert_not_null($before, 'Precondition: a matching tracker should exist');

        Artisan::call('rsx:task:process');

        $after = static::__tracker_for($def['class'], $def['method']);
        static::__assert_not_null($after, 'The matching tracker should still exist');
        static::__assert_equals($before->id, $after->id, 'A matching tracker should be left untouched (same row id)');
    }

    // -------------------------------------------------------------------------
    // DELETE-ORPHAN
    // -------------------------------------------------------------------------

    public static function test_reconcile_deletes_orphan_tracker()
    {
        $ghost_class = 'App\\Nowhere\\Ghost_Reconcile_Probe_Service';
        $ghost_method = 'ghost';

        // A tracker for a class::method that is not in the manifest.
        DB::table('_tasks')->insert([
            'class' => $ghost_class,
            'method' => $ghost_method,
            'queue' => 'scheduled',
            'status' => 'pending',
            'params' => '[]',
            'next_run_at' => date('Y-m-d H:i:s', time() + 86400),
            'cron_expression' => '0 3 * * *',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        static::__assert_not_null(static::__tracker_for($ghost_class, $ghost_method), 'Precondition: the orphan tracker was inserted');

        Artisan::call('rsx:task:process');

        static::__assert_null(static::__tracker_for($ghost_class, $ghost_method), 'An orphan tracker (not in the manifest) should be deleted');
    }
}
