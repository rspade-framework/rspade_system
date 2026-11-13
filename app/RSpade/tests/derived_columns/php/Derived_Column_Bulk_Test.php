<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Support\Facades\DB;
use LogicException;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Plain_Fixture_Model;

/**
 * The bulk builder and derived columns: an update() naming a derived column is refused
 * (raw_bulk() included, a `table.`-qualified key included); a raw Expression on a `from`
 * column is refused; a plain value on a `from` column takes the per-record path so each
 * record re-derives - on a model that has no other side effect at all; and an explicit
 * raw_bulk() of a `from` column is allowed and leaves the derived column for rederive().
 */
class Derived_Column_Bulk_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Derived_Column_Fixture_Tables::drop();
    }

    private static function __person(string $first, string $last): Derived_Plain_Fixture_Model
    {
        $record = new Derived_Plain_Fixture_Model();
        $record->first_name = $first;
        $record->last_name = $last;
        $record->save();

        return $record;
    }

    private static function __stored(Derived_Plain_Fixture_Model $record): ?string
    {
        return Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name'];
    }

    // DERIVED-BULK-NAMES-DERIVED
    public static function test_an_update_naming_a_derived_column_throws()
    {
        $record = static::__person('Ada', 'Lovelace');

        foreach (['full_name', 'derived_plain_fixtures.full_name'] as $key) {
            static::__assert_throws(
                LogicException::class,
                fn () => Derived_Plain_Fixture_Model::where('id', $record->id)->update([$key => 'Hand']),
                'Derived_Plain_Fixture_Model::full_name is a derived column'
            );
        }

        static::__assert_equals('Ada Lovelace', static::__stored($record), 'nothing was written');
    }

    // DERIVED-BULK-RAW-NAMES-DERIVED: raw_bulk() is no way around it.
    public static function test_a_raw_bulk_update_naming_a_derived_column_throws()
    {
        $record = static::__person('Ada', 'Lovelace');

        static::__assert_throws(
            LogicException::class,
            fn () => Derived_Plain_Fixture_Model::where('id', $record->id)->raw_bulk()->update(['full_name' => 'Hand']),
            'raw_bulk() included'
        );
    }

    // DERIVED-BULK-FROM-PER-RECORD: the per-record path re-derives each record.
    public static function test_an_update_of_a_source_column_rederives_every_record()
    {
        $first = static::__person('Ada', 'Lovelace');
        $second = static::__person('Ada', 'Byron');

        $count = Derived_Plain_Fixture_Model::where('first_name', 'Ada')->update(['first_name' => 'Augusta']);

        static::__assert_equals(2, $count);
        static::__assert_equals('Augusta Lovelace', static::__stored($first));
        static::__assert_equals('Augusta Byron', static::__stored($second));
    }

    // DERIVED-BULK-FROM-EXPRESSION: a raw expression would take the raw path and leave the column stale.
    public static function test_an_expression_on_a_source_column_throws()
    {
        $record = static::__person('Ada', 'Lovelace');

        foreach ([false, true] as $raw_bulk) {
            static::__assert_throws(LogicException::class, function () use ($record, $raw_bulk) {
                $query = Derived_Plain_Fixture_Model::where('id', $record->id);

                if ($raw_bulk) {
                    $query->raw_bulk();
                }

                $query->update(['last_name' => DB::raw("CONCAT(last_name, '-King')")]);
            }, 'a raw SQL expression cannot be written to last_name');
        }

        static::__assert_equals('Ada Lovelace', static::__stored($record));
    }

    // DERIVED-BULK-RAW-FROM: allowed; the caller rederives.
    public static function test_an_explicit_raw_bulk_of_a_source_column_is_allowed_and_rederived_after()
    {
        $record = static::__person('Ada', 'Lovelace');

        Derived_Plain_Fixture_Model::where('id', $record->id)->raw_bulk()->update(['last_name' => 'King']);

        static::__assert_equals('Ada Lovelace', static::__stored($record), 'one raw statement derives nothing');

        $record->refresh();
        static::__assert_true($record->rederive(), 'rederive() is the repair');
        static::__assert_equals('Ada King', static::__stored($record));
    }

    // DERIVED-BULK-UNRELATED: an update that touches no source column changes no derived value.
    public static function test_an_update_of_an_unrelated_column_leaves_the_derived_column()
    {
        $record = static::__person('Ada', 'Lovelace');

        Derived_Plain_Fixture_Model::where('id', $record->id)->update(['note' => 'bulk']);

        $row = Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id);
        static::__assert_equals('bulk', $row['note']);
        static::__assert_equals('Ada Lovelace', $row['full_name']);
    }
}
