<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Revisions\Revision_Cleanup_Service;

/**
 * Revision_Cleanup_Service::prune_request_payloads(): it nulls request_payload,
 * response_status and response_body - and nothing else - on _transactions rows older than
 * rsx.request_history.payload_retention_days, leaves newer rows alone, and does nothing
 * at 0. Called directly as a real inline run (__run_task_method()).
 */
class Request_History_Prune_Test extends Rsx_Test_Abstract
{
    public static function test_only_the_three_columns_are_nulled_and_only_past_the_window()
    {
        $old = static::__seed(30, 'ref-old');
        $recent = static::__seed(1, 'ref-recent');

        $result = static::__run_task_method(Revision_Cleanup_Service::class, 'prune_request_payloads', ['retention_days' => 7]);

        static::__assert_greater_than(0, $result['pruned']);
        static::__assert_false($result['kept_forever']);

        $row = DB::table('_transactions')->where('id', $old)->first();
        static::__assert_null($row->request_payload, 'the old payload is pruned');
        static::__assert_null($row->response_status, 'the old status is pruned');
        static::__assert_null($row->response_body, 'the old body is pruned');
        static::__assert_equals('ref-old', $row->request_ref, 'the reference is kept');
        static::__assert_equals(str_repeat('a', 64), $row->request_fingerprint, 'the fingerprint is kept');
        static::__assert_equals(25, (int) $row->duration_ms, 'the duration is kept');
        static::__assert_not_null($row->completed_at, 'the completion time is kept');
        static::__assert_equals(1, (int) DB::table('_revisions')->where('transaction_id', $old)->count(), 'its revisions are kept');

        $kept = DB::table('_transactions')->where('id', $recent)->first();
        static::__assert_equals('{"a":1}', $kept->request_payload, 'a row inside the window keeps its payload');
        static::__assert_equals(201, (int) $kept->response_status);
        static::__assert_equals('{"ok":true}', $kept->response_body);
    }

    public static function test_chunks_cover_the_whole_backlog()
    {
        $ids = [static::__seed(20, 'ref-a'), static::__seed(21, 'ref-b'), static::__seed(22, 'ref-c')];

        $result = static::__run_task_method(Revision_Cleanup_Service::class, 'prune_request_payloads', ['retention_days' => 7, 'chunk_size' => 1]);

        static::__assert_greater_than(2, $result['pruned'], 'every owed row, one per statement');
        static::__assert_equals(0, (int) DB::table('_transactions')->whereIn('id', $ids)->where('request_history_held', 1)->count());
    }

    public static function test_retention_zero_prunes_nothing()
    {
        $old = static::__seed(400, 'ref-ancient');

        $result = static::__run_task_method(Revision_Cleanup_Service::class, 'prune_request_payloads', ['retention_days' => 0]);

        static::__assert_equals(0, $result['pruned']);
        static::__assert_true($result['kept_forever'], 'zero means never prune, and the result says so');
        static::__assert_equals('{"a":1}', DB::table('_transactions')->where('id', $old)->value('request_payload'));
    }

    public static function test_the_default_window_is_seven_days()
    {
        static::__assert_equals(7, config('rsx.request_history.payload_retention_days'));
    }

    /**
     * Insert one recorded writer request dated $days_ago with one revision under it, and
     * return its id.
     */
    private static function __seed(int $days_ago, string $ref): int
    {
        $created_at = date('Y-m-d H:i:s', time() - ($days_ago * 86400));

        $transaction_id = (int) DB::table('_transactions')->insertGetId([
            'source_id' => 6,
            'revision_count' => 1,
            'request_ref' => $ref,
            'request_fingerprint' => str_repeat('a', 64),
            'request_payload' => '{"a":1}',
            'response_status' => 201,
            'response_body' => '{"ok":true}',
            'duration_ms' => 25,
            'completed_at' => $created_at,
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
}
