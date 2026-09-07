<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Revisions\Revision_Cleanup_Service;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Revision_Cleanup_Service: the retention window, the keep-forever default, and the FK
 * cascade that takes a pruned transaction's revisions with it.
 *
 * The task is driven directly (it is a plain static method) with a Task_Instance the
 * dispatcher would otherwise supply, so nothing here depends on the worker pool.
 */
class Revision_Cleanup_Test extends Rsx_Test_Abstract
{
    /**
     * A Task_Instance to hand the task, standing in for the one a worker would build.
     */
    private static function __task(): Task_Instance
    {
        return new Task_Instance(Revision_Cleanup_Service::class, 'cleanup_revisions', [], 'default', true);
    }

    /**
     * Insert one transaction dated $days_ago with one revision under it, and return its id.
     */
    private static function __seed_transaction(int $days_ago): int
    {
        $created_at = date('Y-m-d H:i:s', time() - ($days_ago * 86400));

        $transaction_id = (int) DB::table('_transactions')->insertGetId([
            'source_id' => 6,
            'revision_count' => 1,
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ]);

        DB::table('_revisions')->insert([
            'transaction_id' => $transaction_id,
            'record_type' => 1,
            'record_id' => 1,
            'root_type' => 1,
            'root_id' => 1,
            'operation_id' => 1,
            'sequence' => 1,
            'changes' => chr(0) . chr(0) . '{}',
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ]);

        return $transaction_id;
    }

    public static function test_retention_zero_deletes_nothing()
    {
        $old = static::__seed_transaction(400);

        $result = Revision_Cleanup_Service::cleanup_revisions(static::__task(), ['retention_days' => 0]);

        static::__assert_equals(0, $result['deleted']);
        static::__assert_true($result['kept_forever'], 'zero means keep forever, and the result says so');
        static::__assert_equals(1, (int) DB::table('_transactions')->where('id', $old)->count());
    }

    public static function test_rows_past_the_window_are_deleted_and_newer_ones_kept()
    {
        $old = static::__seed_transaction(60);
        $recent = static::__seed_transaction(1);

        $result = Revision_Cleanup_Service::cleanup_revisions(static::__task(), ['retention_days' => 30]);

        static::__assert_greater_than(0, $result['deleted']);
        static::__assert_equals(0, (int) DB::table('_transactions')->where('id', $old)->count(), 'the old transaction is gone');
        static::__assert_equals(1, (int) DB::table('_transactions')->where('id', $recent)->count(), 'the recent one is kept');
    }

    public static function test_pruning_a_transaction_cascades_to_its_revisions()
    {
        $old = static::__seed_transaction(60);

        Revision_Cleanup_Service::cleanup_revisions(static::__task(), ['retention_days' => 30]);

        static::__assert_equals(
            0,
            (int) DB::table('_revisions')->where('transaction_id', $old)->count(),
            'the FK cascade removes the revisions with their transaction'
        );
    }

    public static function test_a_backlog_larger_than_one_chunk_is_fully_drained()
    {
        for ($i = 0; $i < 5; $i++) {
            static::__seed_transaction(60 + $i);
        }

        $result = Revision_Cleanup_Service::cleanup_revisions(static::__task(), [
            'retention_days' => 30,
            'chunk_size' => 2,
        ]);

        static::__assert_greater_than(4, $result['deleted'], 'the loop keeps going past one chunk');
        static::__assert_equals(
            0,
            (int) DB::table('_transactions')->where('created_at', '<', date('Y-m-d H:i:s', time() - (30 * 86400)))->count(),
            'nothing past the window survives'
        );
    }
}
