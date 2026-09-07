<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Bulk WS frame coalescing: the outbox transmit groups flushed emissions per (site_id, model)
 * and publishes {model, ids:[...]} frames (chunked at 100) for multi-record groups, keeping the
 * {model, id} singleton shape for single-record groups. Both the WEB outbox path
 * (_testing_transmit_outbox) and the CLI flush path (afterCommit within one transaction) run the
 * one shared grouping helper.
 *
 * Emissions are seeded cheaply through the by-identity queue API (no DB rows, no Redis), and the
 * shaped publishes are observed via the publish-capture seam.
 */
class Realtime_Bulk_Frame_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Realtime_Emissions::_testing_set_web_context(null);
        Realtime_Emissions::_testing_reset();
        Realtime::_testing_reset();
    }

    /**
     * Reset all buffers, force the web transmit path, and begin publish capture.
     */
    private static function __begin_web(): void
    {
        Realtime_Emissions::_testing_reset();
        Realtime::_testing_reset();
        Realtime_Emissions::_testing_set_web_context(true);
        Realtime::_testing_start_publish_capture();
    }

    /**
     * The captured publish frames whose data matches a model (for order-independent asserts).
     *
     * @return array<int, array{topic: string, data: array, site_id: int}>
     */
    private static function __frames_for(string $model, int $site_id): array
    {
        $matches = [];
        foreach (Realtime::_testing_published() as $frame) {
            if (($frame['data']['model'] ?? null) === $model && $frame['site_id'] === $site_id) {
                $matches[] = $frame;
            }
        }

        return $matches;
    }

    public static function test_multi_same_model_coalesces_to_one_bulk_frame()
    {
        static::__begin_web();

        Realtime_Emissions::queue_change_by_identity('Frame_Test_Model', 5, 1);
        Realtime_Emissions::queue_change_by_identity('Frame_Test_Model', 9, 1);
        Realtime_Emissions::queue_change_by_identity('Frame_Test_Model', 12, 1);

        Realtime_Emissions::_testing_transmit_outbox();

        $published = Realtime::_testing_published();
        static::__assert_count(1, $published, 'three same-(site, model) emissions coalesce to ONE bulk frame');
        static::__assert_equals('Model_Changed_Topic', $published[0]['topic'], 'bulk frame rides Model_Changed_Topic');
        static::__assert_equals(1, $published[0]['site_id'], 'scoped to the emission site');
        static::__assert_equals('Frame_Test_Model', $published[0]['data']['model'], 'carries the model');
        static::__assert_equals([5, 9, 12], $published[0]['data']['ids'], 'carries an ids array (no scalar id)');
        static::__assert_true(!isset($published[0]['data']['id']), 'a bulk frame has no scalar id key');
    }

    public static function test_singleton_group_keeps_id_shape()
    {
        static::__begin_web();

        Realtime_Emissions::queue_change_by_identity('Frame_Test_Model', 42, 1);

        Realtime_Emissions::_testing_transmit_outbox();

        $published = Realtime::_testing_published();
        static::__assert_count(1, $published, 'a single-record group is one frame');
        static::__assert_equals('Frame_Test_Model', $published[0]['data']['model'], 'carries the model');
        static::__assert_equals(42, $published[0]['data']['id'], 'singleton keeps the scalar {model, id} shape');
        static::__assert_true(!isset($published[0]['data']['ids']), 'no ids array on a singleton frame');
    }

    public static function test_150_ids_chunk_into_100_plus_50()
    {
        static::__begin_web();

        for ($id = 1; $id <= 150; $id++) {
            Realtime_Emissions::queue_change_by_identity('Frame_Test_Model', $id, 1);
        }

        Realtime_Emissions::_testing_transmit_outbox();

        $frames = static::__frames_for('Frame_Test_Model', 1);
        static::__assert_count(2, $frames, '150 ids chunk into two bulk frames');
        static::__assert_count(100, $frames[0]['data']['ids'], 'first chunk holds 100 ids');
        static::__assert_count(50, $frames[1]['data']['ids'], 'second chunk holds the remaining 50');

        // The union of both chunks is the full, ordered id set.
        $all = array_merge($frames[0]['data']['ids'], $frames[1]['data']['ids']);
        static::__assert_equals(range(1, 150), $all, 'the two chunks together carry every id, in order');
    }

    public static function test_mixed_models_and_sites_group_separately()
    {
        static::__begin_web();

        Realtime_Emissions::queue_change_by_identity('Frame_Alpha_Model', 1, 1);
        Realtime_Emissions::queue_change_by_identity('Frame_Alpha_Model', 2, 1);   // (site 1, Alpha) -> bulk [1, 2]
        Realtime_Emissions::queue_change_by_identity('Frame_Beta_Model', 3, 1);    // (site 1, Beta)  -> singleton 3
        Realtime_Emissions::queue_change_by_identity('Frame_Alpha_Model', 4, 2);   // (site 2, Alpha) -> singleton 4

        Realtime_Emissions::_testing_transmit_outbox();

        static::__assert_count(3, Realtime::_testing_published(), 'three distinct (site, model) groups -> three frames');

        $alpha_s1 = static::__frames_for('Frame_Alpha_Model', 1);
        static::__assert_count(1, $alpha_s1, 'one frame for (site 1, Alpha)');
        static::__assert_equals([1, 2], $alpha_s1[0]['data']['ids'], '(site 1, Alpha) is a bulk frame of its two ids');

        $beta_s1 = static::__frames_for('Frame_Beta_Model', 1);
        static::__assert_count(1, $beta_s1, 'one frame for (site 1, Beta)');
        static::__assert_equals(3, $beta_s1[0]['data']['id'], '(site 1, Beta) is a singleton');

        $alpha_s2 = static::__frames_for('Frame_Alpha_Model', 2);
        static::__assert_count(1, $alpha_s2, 'one frame for (site 2, Alpha)');
        static::__assert_equals(4, $alpha_s2[0]['data']['id'], '(site 2, Alpha) is a singleton (different site groups apart)');
    }

    public static function test_cli_flush_groups_within_one_generation()
    {
        Realtime_Emissions::_testing_reset();
        Realtime::_testing_reset();
        // CLI path: transmit happens at flush, so multiple emissions must share ONE generation.
        // A transaction buffers them; commit runs a single flush that publishes them grouped.
        Realtime_Emissions::_testing_set_web_context(false);
        Realtime::_testing_start_publish_capture();

        DB::beginTransaction();
        Realtime_Emissions::queue_change_by_identity('Frame_Cli_Model', 7, 1);
        Realtime_Emissions::queue_change_by_identity('Frame_Cli_Model', 8, 1);
        Realtime_Emissions::queue_change_by_identity('Frame_Cli_Model', 9, 1);
        DB::commit();

        $published = Realtime::_testing_published();
        static::__assert_count(1, $published, 'the CLI flush path also coalesces one generation into one bulk frame');
        static::__assert_equals('Frame_Cli_Model', $published[0]['data']['model'], 'carries the model');
        static::__assert_equals([7, 8, 9], $published[0]['data']['ids'], 'bulk ids from the single flushed generation');
    }
}
