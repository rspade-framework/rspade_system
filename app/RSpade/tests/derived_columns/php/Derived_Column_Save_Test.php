<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Database\QueryException;
use LogicException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Chain_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Plain_Fixture_Model;

/**
 * save() derives a model's derived columns: on an insert, on an update that changes a `from`
 * column, never otherwise; in dependency order; and it refuses a derived column that was
 * assigned by hand - on an existing record any change, on a new record any presence at all -
 * while still letting a save that failed after deriving be retried.
 */
class Derived_Column_Save_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Derived_Column_Fixture_Tables::drop();
    }

    private static function __person(string $first, string $last, ?string $code = null): Derived_Plain_Fixture_Model
    {
        $record = new Derived_Plain_Fixture_Model();
        $record->first_name = $first;
        $record->last_name = $last;
        $record->code = $code;
        $record->save();

        return $record;
    }

    // DERIVED-SAVE-INSERT
    public static function test_an_insert_derives_the_column()
    {
        $record = static::__person('Ada', 'Lovelace');

        static::__assert_equals('Ada Lovelace', $record->full_name, 'the instance carries the derived value');
        static::__assert_equals(
            'Ada Lovelace',
            Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name'],
            'and the row stores it'
        );
    }

    // DERIVED-SAVE-INSERT-NULL: a derive method answering null stores NULL.
    public static function test_an_insert_whose_derivation_is_null_stores_null()
    {
        $record = new Derived_Plain_Fixture_Model();
        $record->note = 'nameless';
        $record->save();

        static::__assert_null(Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name']);
    }

    // DERIVED-SAVE-DIRTY-FROM
    public static function test_an_update_of_a_source_column_rederives()
    {
        $record = static::__person('Ada', 'Lovelace');

        $record->last_name = 'Byron';
        $record->save();

        static::__assert_equals(
            'Ada Byron',
            Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name'],
            'the new source value is derived into the row'
        );
    }

    // DERIVED-SAVE-CLEAN-FROM: the derive method does not even run when no source changed.
    public static function test_an_update_of_other_columns_does_not_derive()
    {
        $record = static::__person('Ada', 'Lovelace');
        Derived_Plain_Fixture_Model::$derive_calls = 0;

        $record->note = 'unrelated';
        $record->save();

        static::__assert_equals(0, Derived_Plain_Fixture_Model::$derive_calls, 'no source changed, so nothing is derived');
        static::__assert_equals('Ada Lovelace', $record->full_name);
    }

    // DERIVED-SAVE-ORDER: quadrupled is declared before doubled and reads it.
    public static function test_dependent_derived_columns_compute_in_dependency_order()
    {
        static::__assert_equals(
            ['doubled', 'quadrupled'],
            array_keys(Derived_Chain_Fixture_Model::derived_columns()),
            'derived_columns() answers dependency order, not declaration order'
        );

        $record = new Derived_Chain_Fixture_Model();
        $record->base_value = 3;
        $record->save();

        $row = Derived_Column_Fixture_Tables::raw_row('derived_chain_fixtures', (int) $record->id);
        static::__assert_equals(6, (int) $row['doubled']);
        static::__assert_equals(12, (int) $row['quadrupled'], 'the dependent column read the fresh upstream value');

        $record->base_value = 5;
        $record->save();

        $row = Derived_Column_Fixture_Tables::raw_row('derived_chain_fixtures', (int) $record->id);
        static::__assert_equals(10, (int) $row['doubled']);
        static::__assert_equals(20, (int) $row['quadrupled'], 'a changed upstream derived column re-derives its dependents');
    }

    // DERIVED-CYCLE: a cycle among derived columns throws, naming its path.
    public static function test_a_cycle_throws_naming_its_path()
    {
        $model = new class extends Rsx_Model_Abstract {
            protected $table = 'derived_cycle_fixtures';

            public static $columns = [
                'a' => ['derived' => 'derive_a', 'from' => ['b']],
                'b' => ['derived' => 'derive_b', 'from' => ['a']],
            ];
        };

        static::__assert_throws(LogicException::class, fn () => $model::derived_columns(), 'a -> b -> a');
    }

    // DERIVED-GUARD-EXISTING
    public static function test_assigning_a_derived_column_of_a_saved_record_throws()
    {
        $record = static::__person('Ada', 'Lovelace');
        $record->full_name = 'Countess';

        static::__assert_throws(
            LogicException::class,
            fn () => $record->save(),
            'Derived_Plain_Fixture_Model::full_name is a derived column - Derived_Plain_Fixture_Model::derive_full_name() computes it from [first_name, last_name] on save. It is never assigned.'
        );
    }

    // DERIVED-GUARD-NEW-STRICT: on a new record ANY presence throws, even the right value or null.
    public static function test_any_assignment_on_a_new_record_throws()
    {
        foreach (['Ada Lovelace', null] as $value) {
            $record = new Derived_Plain_Fixture_Model();
            $record->first_name = 'Ada';
            $record->last_name = 'Lovelace';
            $record->full_name = $value;

            static::__assert_throws(LogicException::class, fn () => $record->save(), 'is a derived column');
            static::__assert_false($record->exists, 'nothing was written');
        }
    }

    // DERIVED-GUARD-RETRY: a save that failed after deriving can be retried as it stands.
    public static function test_a_save_that_failed_after_deriving_can_be_retried()
    {
        static::__person('First', 'Holder', 'taken');

        $record = new Derived_Plain_Fixture_Model();
        $record->first_name = 'Grace';
        $record->last_name = 'Hopper';
        $record->code = 'taken';

        static::__assert_throws(QueryException::class, fn () => $record->save());
        static::__assert_equals('Grace Hopper', $record->full_name, 'the derivation ran before the write failed');

        $record->code = 'free';
        static::__assert_true($record->save(), 'the retry passes the guard: the value is the framework\'s own');
        static::__assert_equals(
            'Grace Hopper',
            Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name']
        );
    }

    // DERIVED-GUARD-AFTER-WRITE: a hand assignment made after the framework's write still throws.
    public static function test_a_hand_assignment_after_the_frameworks_write_still_throws()
    {
        static::__person('First', 'Holder', 'taken');

        $record = new Derived_Plain_Fixture_Model();
        $record->first_name = 'Grace';
        $record->last_name = 'Hopper';
        $record->code = 'taken';

        static::__assert_throws(QueryException::class, fn () => $record->save());

        $record->code = 'free';
        $record->full_name = 'Admiral';

        static::__assert_throws(LogicException::class, fn () => $record->save(), 'is a derived column');
    }

    // DERIVED-SAVE-IDENTICAL: a recompute to the same value leaves the column clean.
    public static function test_an_identical_derivation_leaves_the_column_clean()
    {
        $record = static::__person('Ada', 'Lovelace');

        // A source change that derives the same value: trailing whitespace is trimmed away.
        $record->last_name = 'Lovelace ';
        $record->save();

        static::__assert_false(
            array_key_exists('full_name', $record->getChanges()),
            'full_name was recomputed to its own value, so the write did not include it'
        );
    }
}
