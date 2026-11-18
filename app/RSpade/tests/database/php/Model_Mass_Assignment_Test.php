<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\Exceptions\MassAssignmentException;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Geo\Places\Country_Model;

/**
 * NO MASS ASSIGNMENT: every Eloquent door that takes an attribute array refuses it.
 *
 * Rsx_Model_Abstract overrides fill(), forceFill(), create(), firstOrCreate(), firstOrNew(),
 * updateOrCreate() and the instance update() so that `X::create($request->all())` throws
 * MassAssignmentException instead of writing whatever keys the caller sent. The constructor
 * and the builder's create() reach fill() through Eloquent, so they refuse too. The one
 * permitted arrays are an empty one and a forceFill() of the timestamp columns alone (which
 * Eloquent's query-builder update() uses to cast updated_at), and explicit
 * one-field-at-a-time assignment followed by save() is the way a record is written.
 *
 * Fixture: Country_Model (framework model, not site-scoped). Every write rolls back with
 * the per-test transaction.
 */
class Model_Mass_Assignment_Test extends Rsx_Test_Abstract
{
    /** A unique, valid set of country fields; never saved by this helper. */
    private static function __fields(): array
    {
        $suffix = strtoupper(substr(str_replace('.', '', uniqid('', true)), -5));

        return [
            'alpha2' => substr($suffix, 0, 2),
            'alpha3' => substr($suffix, 0, 3),
            'numeric' => substr($suffix, 0, 3),
            'name' => 'Mass Assignment Test ' . $suffix,
        ];
    }

    /** A saved country, written field by field. */
    private static function __saved_country(): Country_Model
    {
        $country = new Country_Model();

        foreach (static::__fields() as $column => $value) {
            $country->$column = $value;
        }

        $country->enabled = false;
        $country->save();

        return $country;
    }

    private static function __rows_named(string $name): int
    {
        return Country_Model::where('name', $name)->count();
    }

    /**
     * Assert $callable throws MassAssignmentException naming the method and the fields.
     */
    private static function __assert_refused(callable $callable, string $method, array $fields): void
    {
        $e = static::__assert_throws(MassAssignmentException::class, $callable);

        static::__assert_contains('[ERROR] MASS ASSIGNMENT PROHIBITED', $e->getMessage());
        static::__assert_equals(0, preg_match('/[^\x00-\x7F]/', $e->getMessage()), 'the message is plain ASCII');
        static::__assert_contains("Method: {$method}()", $e->getMessage(), "the message names {$method}()");

        foreach ($fields as $field) {
            static::__assert_contains($field, $e->getMessage(), "the message names the attempted field {$field}");
        }
    }

    public static function test_fill_and_force_fill_refuse_an_attribute_array()
    {
        static::__assert_refused(fn () => (new Country_Model())->fill(['name' => 'X', 'alpha2' => 'XX']), 'fill', ['name', 'alpha2']);
        static::__assert_refused(fn () => (new Country_Model())->forceFill(['name' => 'X']), 'forceFill', ['name']);
    }

    public static function test_the_constructor_refuses_an_attribute_array()
    {
        // Eloquent's constructor hands its array to fill().
        static::__assert_refused(fn () => new Country_Model(['name' => 'X']), 'fill', ['name']);
    }

    public static function test_static_create_refuses_and_writes_nothing()
    {
        $fields = static::__fields();

        static::__assert_refused(fn () => Country_Model::create($fields), 'create', ['alpha2', 'name']);
        static::__assert_equals(0, static::__rows_named($fields['name']), 'no row was written');
    }

    public static function test_builder_create_refuses_and_writes_nothing()
    {
        $fields = static::__fields();

        // The builder builds its instance through the model constructor, which fills.
        static::__assert_throws(MassAssignmentException::class, fn () => Country_Model::query()->create($fields));
        static::__assert_equals(0, static::__rows_named($fields['name']), 'no row was written');
    }

    public static function test_first_or_create_first_or_new_and_update_or_create_refuse()
    {
        $fields = static::__fields();

        static::__assert_refused(fn () => Country_Model::firstOrCreate(['alpha2' => $fields['alpha2']]), 'firstOrCreate', ['alpha2']);
        static::__assert_refused(fn () => Country_Model::firstOrCreate([], ['name' => $fields['name']]), 'firstOrCreate', ['name']);
        static::__assert_refused(fn () => Country_Model::firstOrNew(['alpha2' => $fields['alpha2']]), 'firstOrNew', ['alpha2']);
        static::__assert_refused(
            fn () => Country_Model::updateOrCreate(['alpha2' => $fields['alpha2']], ['name' => $fields['name']]),
            'updateOrCreate',
            ['alpha2', 'name']
        );
        static::__assert_refused(fn () => Country_Model::updateOrCreate([], ['name' => $fields['name']]), 'updateOrCreate', ['name']);

        static::__assert_equals(0, static::__rows_named($fields['name']), 'no row was written');
    }

    public static function test_instance_update_with_attributes_refuses_and_changes_nothing()
    {
        $country = static::__saved_country();
        $stored_name = $country->name;

        static::__assert_refused(fn () => $country->update(['name' => 'Renamed By Update']), 'update', ['name']);

        static::__assert_equals($stored_name, Country_Model::find($country->id)->name, 'the stored row is unchanged');
        static::__assert_equals(0, static::__rows_named('Renamed By Update'));
    }

    public static function test_an_empty_array_is_not_mass_assignment()
    {
        $country = new Country_Model();

        static::__assert_true($country->fill([]) === $country, 'fill([]) returns the instance');
        static::__assert_true($country->forceFill([]) === $country, 'forceFill([]) returns the instance');
        static::__assert_equals([], $country->getDirty(), 'nothing was assigned');
    }

    public static function test_explicit_assignment_creates_and_updates()
    {
        $country = static::__saved_country();

        static::__assert_true($country->exists, 'explicit assignment + save() creates the row');
        static::__assert_equals(1, static::__rows_named($country->name));

        $country->name = $country->name . ' Updated';
        $country->save();

        static::__assert_equals($country->name, Country_Model::find($country->id)->name, 'explicit assignment + save() updates it');

        // update() with no array is a plain save of what was assigned.
        $country->enabled = true;
        static::__assert_true($country->update(), 'update() with no attributes saves');
        static::__assert_true((bool) Country_Model::find($country->id)->enabled);
    }

    public static function test_only_force_fill_accepts_the_timestamp_columns_alone()
    {
        static::__assert_refused(fn () => (new Country_Model())->fill(['updated_at' => '2026-01-02 03:04:05']), 'fill', ['updated_at']);
        static::__assert_refused(
            fn () => (new Country_Model())->forceFill(['updated_at' => '2026-01-02 03:04:05', 'name' => 'X']),
            'forceFill',
            ['updated_at', 'name']
        );

        $country = new Country_Model();
        static::__assert_true(
            $country->forceFill(['created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05']) === $country,
            'forceFill() of the timestamps returns the instance'
        );
        static::__assert_equals(['created_at', 'updated_at'], array_keys($country->getDirty()), 'both timestamps were assigned, nothing else');
    }

    public static function test_a_query_builder_update_casts_updated_at_through_force_fill()
    {
        $country = static::__saved_country();
        $renamed = $country->name . ' Bulk';

        // Eloquent's Builder::update() adds updated_at through newInstance()->forceFill().
        Country_Model::query()->where('id', $country->id)->update(['name' => $renamed]);

        static::__assert_equals($renamed, Country_Model::find($country->id)->name, 'the builder update() wrote the row');
    }
}
