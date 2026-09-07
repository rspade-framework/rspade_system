<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Cycle_A_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Cycle_B_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Deep_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Touch_Child_Fixture_Model;

/**
 * Buffer, dedupe, touch-cascade (incl. cycle guard + depth cap), and site_id
 * resolution for Realtime_Emissions::queue_model_change().
 *
 * queue_model_change() is called directly on in-memory fixture instances (no DB
 * writes needed). Each test runs inside the runner's wrapping transaction, so the
 * afterCommit flush is DEFERRED and never fires (the transaction is rolled back) —
 * which lets these tests peek the still-populated buffer via _testing_pending().
 * Flush timing itself is covered by Realtime_Emissions_Flush_Test.
 */
class Realtime_Emissions_Buffer_Test extends Rsx_Test_Abstract
{
    /**
     * Collect the "model|id" identity of every pending emission.
     *
     * @return string[]
     */
    private static function __pending_keys(): array
    {
        $keys = [];
        foreach (Realtime_Emissions::_testing_pending() as $entry) {
            $keys[] = $entry['model'] . '|' . $entry['id'];
        }

        return $keys;
    }

    private static function __model(int $id, ?int $site_id = null): Realtime_Fixture_Model
    {
        $model = new Realtime_Fixture_Model();
        $model->id = $id;
        if ($site_id !== null) {
            $model->site_id = $site_id;
        }

        return $model;
    }

    public static function test_same_model_and_id_dedupes_to_one_emission()
    {
        Realtime_Emissions::_testing_reset();

        $model = static::__model(5, 1);
        Realtime_Emissions::queue_model_change($model);
        Realtime_Emissions::queue_model_change($model);

        static::__assert_count(1, Realtime_Emissions::_testing_pending(), 'queuing the same model+id twice yields one emission');
    }

    public static function test_distinct_ids_are_separate_emissions()
    {
        Realtime_Emissions::_testing_reset();

        Realtime_Emissions::queue_model_change(static::__model(5, 1));
        Realtime_Emissions::queue_model_change(static::__model(6, 1));

        static::__assert_count(2, Realtime_Emissions::_testing_pending());
    }

    public static function test_touch_cascade_queues_parent_emission()
    {
        Realtime_Emissions::_testing_reset();

        $child = new Realtime_Touch_Child_Fixture_Model();
        $child->id = 10;
        $child->site_id = 1;

        Realtime_Emissions::queue_model_change($child);

        $keys = static::__pending_keys();
        static::__assert_count(2, $keys, 'child plus its touched parent');
        static::__assert_true(in_array('Realtime_Touch_Child_Fixture_Model|10', $keys, true), 'child emission present');
        static::__assert_true(in_array('Realtime_Fixture_Model|' . Realtime_Touch_Child_Fixture_Model::PARENT_ID, $keys, true), 'touched parent emission present');
    }

    public static function test_touch_cycle_terminates_via_visited_set()
    {
        Realtime_Emissions::_testing_reset();

        // A touches B, B touches A. Without the visited set this would loop forever.
        $a = new Realtime_Cycle_A_Fixture_Model();
        $a->id = Realtime_Cycle_B_Fixture_Model::A_ID;

        Realtime_Emissions::queue_model_change($a);

        $keys = static::__pending_keys();
        static::__assert_count(2, $keys, 'the cycle resolves to exactly A and B, then stops');
        static::__assert_true(in_array('Realtime_Cycle_A_Fixture_Model|1', $keys, true), 'A emission present');
        static::__assert_true(in_array('Realtime_Cycle_B_Fixture_Model|2', $keys, true), 'B emission present');
    }

    public static function test_unbounded_touch_chain_stops_at_depth_cap()
    {
        Realtime_Emissions::_testing_reset();

        // Each instance touches a fresh instance with id+1, so the visited set never
        // catches it; only the depth cap (25 levels) terminates the walk.
        $deep = new Realtime_Deep_Fixture_Model();
        $deep->id = 1;

        Realtime_Emissions::queue_model_change($deep);

        // Original (id 1) + 25 cascade levels (ids 2..26) = 26 emissions.
        static::__assert_count(26, Realtime_Emissions::_testing_pending(), 'depth cap bounds the runaway chain');
    }

    public static function test_site_id_prefers_row_column_over_session()
    {
        Realtime_Emissions::_testing_reset();
        static::__acting_as_site(4);

        // Row carries its own site_id = 7; it must win over the session site (4).
        Realtime_Emissions::queue_model_change(static::__model(5, 7));

        $pending = Realtime_Emissions::_testing_pending();
        static::__assert_equals(7, $pending[0]['site_id']);

        static::__reset_session();
    }

    public static function test_site_id_falls_back_to_session_when_no_row_column()
    {
        Realtime_Emissions::_testing_reset();
        static::__acting_as_site(4);

        // No site_id set on the instance -> resolve from the session site.
        Realtime_Emissions::queue_model_change(static::__model(5));

        $pending = Realtime_Emissions::_testing_pending();
        static::__assert_equals((int) Session::get_site_id(), $pending[0]['site_id']);
        static::__assert_equals(4, $pending[0]['site_id']);

        static::__reset_session();
    }
}
