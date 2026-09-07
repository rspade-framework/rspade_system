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
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Bad_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Child_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Onward_Child_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Onward_Parent_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Parent_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Touch_Only_Fixture_Model;

/**
 * #[Realtime_Touch] attribute: a child model's belongsTo relationship annotated so any write
 * to the child queues the PARENT's change emission — regardless of the child's own $realtime
 * flag, with an onward cascade when the parent itself has touches.
 *
 * Real saves run inside the runner's wrapping transaction, so the afterCommit flush is
 * deferred; the tests peek the buffer via _testing_pending() before rollback.
 */
class Realtime_Attribute_Touch_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__drop_tables();

        foreach (['realtime_attr_parents', 'realtime_attr_onward_parents'] as $table) {
            DB::statement('CREATE TABLE ' . $table . ' (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL DEFAULT 0,
                name VARCHAR(255) NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        foreach (['realtime_attr_children', 'realtime_attr_touch_only', 'realtime_attr_onward_children'] as $table) {
            DB::statement('CREATE TABLE ' . $table . ' (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL DEFAULT 0,
                parent_id BIGINT NULL,
                name VARCHAR(255) NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        config(['rsx.realtime.enabled' => true]);
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
            'realtime_attr_touch_only',
            'realtime_attr_onward_children',
            'realtime_attr_parents',
            'realtime_attr_onward_parents',
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

    public static function test_touch_metadata_resolves_fk_and_parent()
    {
        Realtime_Touch_Registry::_testing_reset();

        $entries = Realtime_Touch_Registry::touch_metadata(Realtime_Attr_Child_Fixture_Model::class);

        static::__assert_count(1, $entries, 'one attributed relationship resolved');
        static::__assert_equals('parent_id', $entries[0]['fk_column']);
        static::__assert_equals('Realtime_Attr_Parent_Fixture_Model', $entries[0]['parent_class']);
        static::__assert_false($entries[0]['parent_has_onward'], 'the top parent has no onward touches');
    }

    public static function test_child_save_queues_own_and_parent_by_identity()
    {
        Realtime_Emissions::_testing_reset();

        $child = new Realtime_Attr_Child_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = 555;
        $child->save();

        $keys = static::__pending_keys();
        static::__assert_count(2, $keys, 'child own change plus its touched parent');
        static::__assert_true(in_array('Realtime_Attr_Child_Fixture_Model|' . (int) $child->id, $keys, true), 'own change present');
        static::__assert_true(in_array('Realtime_Attr_Parent_Fixture_Model|555', $keys, true), 'parent-by-identity present');
    }

    public static function test_touch_only_child_queues_parent_but_not_itself()
    {
        Realtime_Emissions::_testing_reset();

        // $realtime = false: the child publishes nothing for itself, but still touches its parent.
        $child = new Realtime_Attr_Touch_Only_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = 777;
        $child->save();

        $keys = static::__pending_keys();
        static::__assert_count(1, $keys, 'only the parent emission, none for the touch-only child');
        static::__assert_true(in_array('Realtime_Attr_Parent_Fixture_Model|777', $keys, true), 'parent emission present');
    }

    public static function test_null_fk_touches_nothing()
    {
        Realtime_Emissions::_testing_reset();

        $child = new Realtime_Attr_Child_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = null;
        $child->save();

        $keys = static::__pending_keys();
        static::__assert_count(1, $keys, 'a null FK skips the parent; only the own change remains');
        static::__assert_true(in_array('Realtime_Attr_Child_Fixture_Model|' . (int) $child->id, $keys, true), 'own change present');
    }

    public static function test_touch_dedupes_with_parents_own_save()
    {
        Realtime_Emissions::_testing_reset();

        // The parent writes its own change...
        $parent = new Realtime_Attr_Parent_Fixture_Model();
        $parent->site_id = 1;
        $parent->save();
        $parent_id = (int) $parent->id;

        // ...and a child touches the same parent: the parent emission dedupes to ONE.
        $child = new Realtime_Attr_Child_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = $parent_id;
        $child->save();

        $parent_emissions = 0;
        foreach (Realtime_Emissions::_testing_pending() as $entry) {
            if ($entry['model'] === 'Realtime_Attr_Parent_Fixture_Model' && $entry['id'] === $parent_id) {
                $parent_emissions++;
            }
        }

        static::__assert_equals(1, $parent_emissions, "the parent's own save and the child's touch dedupe to one parent emission");
    }

    public static function test_onward_cascade_hydrates_parent_and_walks_grandparent()
    {
        Realtime_Emissions::_testing_reset();

        // Persist an onward parent (it overrides realtime_touch() -> grandparent).
        $onward_parent = new Realtime_Attr_Onward_Parent_Fixture_Model();
        $onward_parent->site_id = 1;
        $onward_parent->save();
        $onward_parent_id = (int) $onward_parent->id;

        // Discard the parent's own save emission so we observe only the child's cascade.
        Realtime_Emissions::_testing_reset();

        $child = new Realtime_Attr_Onward_Child_Fixture_Model();
        $child->site_id = 1;
        $child->parent_id = $onward_parent_id;
        $child->save();

        $keys = static::__pending_keys();
        static::__assert_true(in_array('Realtime_Attr_Onward_Child_Fixture_Model|' . (int) $child->id, $keys, true), 'child own change present');
        static::__assert_true(in_array('Realtime_Attr_Onward_Parent_Fixture_Model|' . $onward_parent_id, $keys, true), 'hydrated onward parent present');
        static::__assert_true(
            in_array('Realtime_Attr_Parent_Fixture_Model|' . Realtime_Attr_Onward_Parent_Fixture_Model::GRANDPARENT_ID, $keys, true),
            'grandparent reached through the hydrated parents realtime_touch()'
        );
    }

    public static function test_non_belongs_to_attributed_method_fails_loud()
    {
        Realtime_Touch_Registry::_testing_reset();

        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Realtime_Touch_Registry::touch_metadata(Realtime_Attr_Bad_Fixture_Model::class);
            },
            'belongsTo-only'
        );
    }
}
