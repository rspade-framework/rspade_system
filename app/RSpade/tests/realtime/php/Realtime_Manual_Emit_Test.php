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
use App\RSpade\Tests\Realtime\Php\Realtime_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Plain_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Touch_Child_Fixture_Model;

/**
 * Manual emission (Rsx_Model_Abstract::realtime_emit() -> Realtime_Emissions::request_emit)
 * and the WEB transmission outbox.
 *
 * Like Realtime_Emissions_Flush_Test, this class opts OUT of the per-test transaction so it
 * controls transaction boundaries explicitly. Emissions are driven on in-memory fixture
 * instances (an id is assigned, the row is never written) — realtime_emit() only requires a
 * non-empty id and realtime enabled, and request_emit() runs entirely through the buffer.
 * Assertions read the capture seam (intent at flush) and, for the web path, the outbox peek
 * plus Realtime's publish capture (actual transmission).
 */
class Realtime_Manual_Emit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        // realtime_emit() and the hook are no-ops unless enabled; this env defaults it off.
        config(['rsx.realtime.enabled' => true]);
    }

    public static function teardown()
    {
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_set_web_context(null);
        Realtime_Emissions::_testing_reset();
        Realtime::_testing_reset();
    }

    private static function __fixture(int $id): Realtime_Fixture_Model
    {
        $model = new Realtime_Fixture_Model();
        $model->id = $id;
        $model->site_id = 1;

        return $model;
    }

    /**
     * A second realtime_emit() of the same record in one request is a silent no-op: the
     * record already flushed (its key is in $sent_keys), so exactly one emission results.
     */
    public static function test_manual_emit_dedupes_once_per_request()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        $model = static::__fixture(20);
        $model->realtime_emit(); // no transaction -> flushes immediately, marks sent
        $model->realtime_emit(); // already sent this request -> no-op

        $captured = Realtime_Emissions::_testing_captured();
        static::__assert_count(1, $captured, 'the second manual emit of the same record is a no-op');
        static::__assert_equals(20, $captured[0]['id']);
    }

    /**
     * realtime_emit() works on a model that did NOT opt into automatic emission — calling
     * it IS the intent, so it emits regardless of static::$realtime.
     */
    public static function test_manual_emit_on_non_realtime_model_works()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        $model = new Realtime_Plain_Fixture_Model(); // $realtime = false
        $model->id = 21;
        $model->site_id = 1;
        $model->realtime_emit();

        $captured = Realtime_Emissions::_testing_captured();
        static::__assert_count(1, $captured, 'a manual emit on a non-opt-in model still emits');
        static::__assert_equals('Realtime_Plain_Fixture_Model', $captured[0]['model']);
        static::__assert_equals(21, $captured[0]['id']);
    }

    /**
     * A manual emit inside a rolled-back transaction is discarded (the generation is
     * voided), and — critically — a LATER manual emit of the same record still succeeds
     * (the dedup is buffer/sent-based, never a call-time latch).
     */
    public static function test_manual_emit_in_rolled_back_transaction_discarded_then_later_succeeds()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        $model = static::__fixture(22);

        DB::beginTransaction();
        $model->realtime_emit();
        DB::rollBack();

        static::__assert_count(0, Realtime_Emissions::_testing_captured(), 'the rolled-back manual emit published nothing');

        $model->realtime_emit(); // no active transaction -> flushes now

        $captured = Realtime_Emissions::_testing_captured();
        static::__assert_count(1, $captured, 'a later manual emit of the same record still succeeds after rollback');
        static::__assert_equals(22, $captured[0]['id']);
    }

    /**
     * A manual emit runs the SAME touch cascade as auto-emission: the child plus its
     * touched parent both emit.
     */
    public static function test_manual_emit_runs_touch_cascade()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        $child = new Realtime_Touch_Child_Fixture_Model();
        $child->id = 23;
        $child->site_id = 1;
        $child->realtime_emit();

        $ids = [];
        foreach (Realtime_Emissions::_testing_captured() as $entry) {
            $ids[] = $entry['model'] . '|' . $entry['id'];
        }

        static::__assert_count(2, $ids, 'a manual emit cascades to the touched parent');
        static::__assert_true(in_array('Realtime_Touch_Child_Fixture_Model|23', $ids, true), 'child emission present');
        static::__assert_true(in_array('Realtime_Fixture_Model|' . Realtime_Touch_Child_Fixture_Model::PARENT_ID, $ids, true), 'touched parent emission present');
    }

    /**
     * WEB path: the flush stages emissions into the request outbox (deduped model|id|site
     * across the request) and does NOT publish; publication happens once when the outbox
     * transmits at request termination. Intent is still captured at flush.
     */
    public static function test_web_outbox_defers_and_dedupes_transmission()
    {
        Realtime_Emissions::_testing_reset();
        Realtime::_testing_reset();
        Realtime_Emissions::_testing_set_web_context(true);
        Realtime_Emissions::_testing_start_capture();
        Realtime::_testing_start_publish_capture();

        $model = static::__fixture(24);

        // First flush (no transaction -> afterCommit runs immediately) stages, not publishes.
        Realtime_Emissions::queue_model_change($model);

        static::__assert_count(1, Realtime_Emissions::_testing_captured(), 'intent captured at flush');
        static::__assert_count(1, Realtime_Emissions::_testing_outbox(), 'emission staged in the outbox');
        static::__assert_count(0, Realtime::_testing_published(), 'nothing published yet (web defers to termination)');

        // A second flush of the same record is dropped by the request-wide outbox dedup.
        Realtime_Emissions::queue_model_change($model);
        static::__assert_count(1, Realtime_Emissions::_testing_outbox(), 'the same record is not staged twice this request');

        // Termination transmit publishes the outbox exactly once, then clears it.
        Realtime_Emissions::_testing_transmit_outbox();
        static::__assert_count(1, Realtime::_testing_published(), 'the deferred transmit publishes exactly one message');
        static::__assert_equals('Model_Changed_Topic', Realtime::_testing_published()[0]['topic']);
        static::__assert_count(0, Realtime_Emissions::_testing_outbox(), 'the outbox is drained after transmit');

        Realtime_Emissions::_testing_set_web_context(null);
        Realtime::_testing_reset();
    }
}
