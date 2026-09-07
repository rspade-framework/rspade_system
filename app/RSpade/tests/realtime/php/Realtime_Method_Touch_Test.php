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
use App\RSpade\Tests\Realtime\Php\Realtime_Method_Touch_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Method_Touch_Parent_Fixture_Model;

/**
 * realtime_touch() METHOD rung: a touch-only child (no $realtime, no #[Realtime_Touch]
 * attribute - the overridden method is its ONLY realtime surface) must still walk the cascade
 * and notify its PARENTS, publishing NOTHING for its own frame. This mirrors the attribute
 * rung and closes the dead-code trap the CR reported (Entity_Association_Model). Covers the
 * per-row save path, both bulk-builder paths (update/delete hydrate the method surface), the
 * own-frame negative, and manual realtime_emit() (which DOES publish the own frame - calling
 * it IS the intent, protecting request_emit() semantics).
 *
 * The class opts OUT of the per-test transaction and controls its own BEGIN/ROLLBACK so it
 * can peek the pending buffer while the transaction is still open (the afterCommit flush is
 * otherwise deferred) and clean up its rows.
 */
class Realtime_Method_Touch_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE realtime_method_touch_parents (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE realtime_method_touch_children (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            parent_id BIGINT NULL,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
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
            'realtime_method_touch_children',
            'realtime_method_touch_parents',
        ] as $table) {
            DB::statement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * The "model|id" identity of each pending emission.
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

    private static function __seed_parent(): int
    {
        $parent = new Realtime_Method_Touch_Parent_Fixture_Model();
        $parent->site_id = 1;
        $parent->name = 'seed parent';
        $parent->save();

        return (int) $parent->id;
    }

    private static function __seed_child(int $parent_id): int
    {
        $child = new Realtime_Method_Touch_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = $parent_id;
        $child->name = 'seed child';
        $child->save();

        return (int) $child->id;
    }

    public static function test_save_walks_method_cascade_without_realtime_flag()
    {
        DB::beginTransaction();
        try {
            $parent_id = static::__seed_parent();

            Realtime_Emissions::_testing_reset();

            $child = new Realtime_Method_Touch_Fixture_Model();
            $child->site_id = 1;
            $child->parent_id = $parent_id;
            $child->save();

            $keys = static::__pending_keys();
            static::__assert_true(
                in_array('Realtime_Method_Touch_Parent_Fixture_Model|' . $parent_id, $keys, true),
                'parent emission queued through the realtime_touch() method cascade'
            );
            static::__assert_false(
                in_array('Realtime_Method_Touch_Fixture_Model|' . (int) $child->id, $keys, true),
                'the touch-only child publishes NO own frame (no $realtime)'
            );
            static::__assert_count(1, $keys, 'exactly one emission: the parent, never the child');
        } finally {
            DB::rollBack();
        }
    }

    public static function test_builder_update_walks_method_cascade()
    {
        DB::beginTransaction();
        try {
            $parent_id = static::__seed_parent();
            $child_id = static::__seed_child($parent_id);

            Realtime_Emissions::_testing_reset();

            Realtime_Method_Touch_Fixture_Model::where('parent_id', $parent_id)->update(['name' => 'bulk']);

            $keys = static::__pending_keys();
            static::__assert_true(
                in_array('Realtime_Method_Touch_Parent_Fixture_Model|' . $parent_id, $keys, true),
                'bulk update hydrates the method surface and queues the parent'
            );
            static::__assert_false(
                in_array('Realtime_Method_Touch_Fixture_Model|' . $child_id, $keys, true),
                'the touch-only child publishes NO own frame on a bulk update'
            );
        } finally {
            DB::rollBack();
        }
    }

    public static function test_builder_delete_walks_method_cascade()
    {
        DB::beginTransaction();
        try {
            $parent_id = static::__seed_parent();
            $child_id = static::__seed_child($parent_id);

            Realtime_Emissions::_testing_reset();

            Realtime_Method_Touch_Fixture_Model::where('parent_id', $parent_id)->delete();

            $keys = static::__pending_keys();
            static::__assert_true(
                in_array('Realtime_Method_Touch_Parent_Fixture_Model|' . $parent_id, $keys, true),
                'bulk delete hydrates the method surface and queues the parent'
            );
            static::__assert_false(
                in_array('Realtime_Method_Touch_Fixture_Model|' . $child_id, $keys, true),
                'the touch-only child publishes NO own frame on a bulk delete'
            );
        } finally {
            DB::rollBack();
        }
    }

    public static function test_own_frame_still_requires_realtime_flag()
    {
        DB::beginTransaction();
        try {
            $parent_id = static::__seed_parent();

            // Per-row save.
            Realtime_Emissions::_testing_reset();
            $child = new Realtime_Method_Touch_Fixture_Model();
            $child->site_id = 1;
            $child->parent_id = $parent_id;
            $child->save();
            $child_id = (int) $child->id;
            static::__assert_false(
                in_array('Realtime_Method_Touch_Fixture_Model|' . $child_id, static::__pending_keys(), true),
                'save: no own frame without $realtime'
            );

            // Bulk update.
            Realtime_Emissions::_testing_reset();
            Realtime_Method_Touch_Fixture_Model::where('parent_id', $parent_id)->update(['name' => 'again']);
            static::__assert_false(
                in_array('Realtime_Method_Touch_Fixture_Model|' . $child_id, static::__pending_keys(), true),
                'bulk update: no own frame without $realtime'
            );

            // Bulk delete.
            Realtime_Emissions::_testing_reset();
            Realtime_Method_Touch_Fixture_Model::where('parent_id', $parent_id)->delete();
            static::__assert_false(
                in_array('Realtime_Method_Touch_Fixture_Model|' . $child_id, static::__pending_keys(), true),
                'bulk delete: no own frame without $realtime'
            );
        } finally {
            DB::rollBack();
        }
    }

    public static function test_manual_realtime_emit_still_publishes_own_frame()
    {
        DB::beginTransaction();
        try {
            $parent_id = static::__seed_parent();
            $child_id = static::__seed_child($parent_id);

            $child = Realtime_Method_Touch_Fixture_Model::find($child_id);

            Realtime_Emissions::_testing_reset();

            // realtime_emit() ignores $realtime (calling it IS the intent): it publishes the
            // child's OWN frame AND runs the same touch cascade -> the parent too.
            $child->realtime_emit();

            $keys = static::__pending_keys();
            static::__assert_true(
                in_array('Realtime_Method_Touch_Fixture_Model|' . $child_id, $keys, true),
                'manual emit publishes the child own frame'
            );
            static::__assert_true(
                in_array('Realtime_Method_Touch_Parent_Fixture_Model|' . $parent_id, $keys, true),
                'manual emit also walks the touch cascade to the parent'
            );
        } finally {
            DB::rollBack();
        }
    }
}
