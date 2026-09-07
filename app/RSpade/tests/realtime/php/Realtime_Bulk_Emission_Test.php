<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Child_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Onward_Parent_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Soft_Child_Fixture_Model;

/**
 * Bulk builder emission coverage: Model::where(...)->update()/->delete() on a model with a
 * realtime surface is FETCH-THEN-ITERATE — it processes each affected record through its own
 * save()/delete(), so the SAME per-record realtime frames a manual per-row write would emit are
 * queued. Covers affected-rows-only scoping, the soft-delete single-emission (the internal
 * deleted_at write runs raw under the single-write guard), the ->raw_bulk() escape hatch, the
 * method-surface (overridden realtime_touch()) cascade, and rollback discard.
 *
 * The class opts OUT of the per-test transaction and controls its own BEGIN/ROLLBACK so it
 * can peek the pending buffer while the transaction is still open (and clean up its rows).
 */
class Realtime_Bulk_Emission_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE realtime_attr_parents (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE realtime_attr_onward_parents (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE realtime_attr_children (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            parent_id BIGINT NULL,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE realtime_attr_soft_children (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            parent_id BIGINT NULL,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_by_id BIGINT DEFAULT NULL,
            deleted_by_type BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        config(['rsx.realtime.enabled' => true]);
        Realtime_Touch_Registry::_testing_reset();
    }

    public static function teardown()
    {
        static::__drop_tables();
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_reset();
        Realtime_Touch_Registry::_testing_reset();
    }

    private static function __drop_tables()
    {
        foreach ([
            'realtime_attr_children',
            'realtime_attr_soft_children',
            'realtime_attr_parents',
            'realtime_attr_onward_parents',
        ] as $table) {
            DB::statement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * Count pending emissions matching a "model|id" identity.
     */
    private static function __count_pending(string $model, int $id): int
    {
        $count = 0;
        foreach (Realtime_Emissions::_testing_pending() as $entry) {
            if ($entry['model'] === $model && $entry['id'] === $id) {
                $count++;
            }
        }

        return $count;
    }

    private static function __insert_child(int $parent_id): int
    {
        $child = new Realtime_Attr_Child_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = $parent_id;
        $child->name = 'seed';
        $child->save();

        return (int) $child->id;
    }

    public static function test_bulk_update_queues_affected_rows_only()
    {
        DB::beginTransaction();
        try {
            $id_a = static::__insert_child(100);
            $id_b = static::__insert_child(100);
            static::__insert_child(200); // NOT affected by the where below

            Realtime_Emissions::_testing_reset();

            Realtime_Attr_Child_Fixture_Model::where('parent_id', 100)->update(['name' => 'bulk']);

            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Child_Fixture_Model', $id_a), 'first affected child own change');
            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Child_Fixture_Model', $id_b), 'second affected child own change');
            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Parent_Fixture_Model', 100), 'the shared parent, deduped to one');
            static::__assert_equals(0, static::__count_pending('Realtime_Attr_Parent_Fixture_Model', 200), 'the unaffected parent is not touched');
        } finally {
            DB::rollBack();
        }
    }

    public static function test_bulk_delete_queues_emissions()
    {
        DB::beginTransaction();
        try {
            $id_a = static::__insert_child(300);
            $id_b = static::__insert_child(300);

            Realtime_Emissions::_testing_reset();

            Realtime_Attr_Child_Fixture_Model::where('parent_id', 300)->delete();

            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Child_Fixture_Model', $id_a), 'deleted child own change');
            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Child_Fixture_Model', $id_b), 'deleted child own change');
            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Parent_Fixture_Model', 300), 'parent emission present, deduped');
        } finally {
            DB::rollBack();
        }
    }

    public static function test_soft_delete_bulk_is_single_preselect()
    {
        DB::beginTransaction();
        try {
            $soft = new Realtime_Attr_Soft_Child_Fixture_Model();
            $soft->site_id = 1;
            $soft->parent_id = 400;
            $soft->save();
            $id = (int) $soft->id;

            Realtime_Emissions::_testing_reset();

            // A SoftDeletes ->delete() becomes an UPDATE issued through our update() override
            // from inside parent::delete(); the guard must make this a SINGLE pre-select.
            Realtime_Attr_Soft_Child_Fixture_Model::where('parent_id', 400)->delete();

            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Soft_Child_Fixture_Model', $id), 'exactly one own change (no double pre-select)');
            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Parent_Fixture_Model', 400), 'exactly one parent emission (no double)');
        } finally {
            DB::rollBack();
        }
    }

    public static function test_raw_bulk_suppresses_emission()
    {
        DB::beginTransaction();
        try {
            static::__insert_child(500);
            static::__insert_child(500);

            Realtime_Emissions::_testing_reset();

            // ->raw_bulk() does the single raw UPDATE and skips all per-record side effects.
            Realtime_Attr_Child_Fixture_Model::where('parent_id', 500)->raw_bulk()->update(['name' => 'quiet']);

            static::__assert_count(0, Realtime_Emissions::_testing_pending(), 'the escape hatch suppresses all bulk emission');
        } finally {
            DB::rollBack();
        }
    }

    public static function test_rollback_discards_bulk_emissions()
    {
        DB::beginTransaction();
        static::__insert_child(700);
        static::__insert_child(700);

        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        Realtime_Attr_Child_Fixture_Model::where('parent_id', 700)->update(['name' => 'doomed']);

        // Outermost rollback: the afterCommit flush is discarded and the buffer cleared.
        DB::rollBack();

        static::__assert_count(0, Realtime_Emissions::_testing_captured(), 'a rolled-back bulk write publishes nothing');
    }

    public static function test_method_surface_bulk_hydrates_and_walks()
    {
        DB::beginTransaction();
        try {
            $p1 = new Realtime_Attr_Onward_Parent_Fixture_Model();
            $p1->site_id = 1;
            $p1->save();
            $id1 = (int) $p1->id;

            $p2 = new Realtime_Attr_Onward_Parent_Fixture_Model();
            $p2->site_id = 1;
            $p2->save();
            $id2 = (int) $p2->id;

            Realtime_Emissions::_testing_reset();

            // Onward parent overrides realtime_touch() -> the bulk path must HYDRATE each row
            // so the method runs (queuing the grandparent).
            Realtime_Attr_Onward_Parent_Fixture_Model::where('site_id', 1)->update(['name' => 'swept']);

            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Onward_Parent_Fixture_Model', $id1), 'first row own change');
            static::__assert_equals(1, static::__count_pending('Realtime_Attr_Onward_Parent_Fixture_Model', $id2), 'second row own change');
            static::__assert_equals(
                1,
                static::__count_pending('Realtime_Attr_Parent_Fixture_Model', Realtime_Attr_Onward_Parent_Fixture_Model::GRANDPARENT_ID),
                'grandparent reached through each hydrated rows realtime_touch(), deduped to one'
            );
        } finally {
            DB::rollBack();
        }
    }
}
