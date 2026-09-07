<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\System_Column_Fixture_Model;

/**
 * Reading an attribute off a hydrated model: the answers Rsx_Model_Abstract::getCasts(),
 * __get() and __isset() are contracted to give.
 *
 * These are the hottest methods in the framework - getCasts() is reached from Eloquent's
 * hasCast() once per column per row, and __get() fires on every column read because
 * Eloquent keeps attributes in an array rather than as declared properties. Both are
 * memoized (a per-class+table schema-cast map, a per-class enum-property map, a
 * per-class+column enum sort) behind a double-underscore fast path, and a memo is exactly
 * the kind of optimisation that is invisible until it answers the wrong question. This
 * class is the fence around what those answers must be.
 *
 * Every assertion here is about OBSERVABLE behavior, not the memo: the memo is correct iff
 * these answers are, including the two edges the design turns on - mergeCasts() must stay
 * per-instance (which is why parent::getCasts() is deliberately NOT memoized), and two
 * model classes on different tables must never share a cast map.
 */
class Model_Attribute_Read_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const USER_ID = 1;

    /**
     * A table carrying a SYSTEM column (single leading underscore). No model in the
     * shipped tree declares one, and the fast path's whole premise is that such a name is
     * NOT mistaken for a magic key - so the fixture has to exist to prove it.
     *
     * setup()/teardown() run outside the per-test transaction, so the DDL here is safe and
     * the rows each test inserts still roll back.
     */
    public static function setup(): void
    {
        static::__drop_fixture_table();

        DB::statement('CREATE TABLE model_attribute_read_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            state_id BIGINT NOT NULL DEFAULT 1,
            _flag VARCHAR(64) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__drop_fixture_table();
        static::__reset_session();
    }

    private static function __drop_fixture_table(): void
    {
        DB::statement('DROP TABLE IF EXISTS model_attribute_read_fixtures');
    }

    /**
     * A committed-then-refetched client, so every attribute read goes through the casts
     * against a value that came back out of MySQL rather than one still sitting in PHP.
     */
    private static function __make_client(array $overrides = []): Client_Model
    {
        $client = new Client_Model();
        $client->site_id = self::SITE_ID;
        $client->name = 'Attribute Read ' . uniqid();
        $client->status_id = Client_Model::STATUS_PROSPECT;
        $client->priority = Client_Model::PRIORITY_HIGH;
        $client->portal_enabled = true;
        $client->newsletter_opt_in = false;

        foreach ($overrides as $field => $value) {
            $client->$field = $value;
        }

        $client->save();

        return Client_Model::find($client->id);
    }

    // -------------------------------------------------------------------------
    // Schema-derived casts
    // -------------------------------------------------------------------------

    public static function test_a_datetime_attribute_is_an_iso_string_never_a_carbon()
    {
        $client = static::__make_client();

        static::__assert_true(
            is_string($client->created_at),
            'created_at is a string, not an object - got ' . gettype($client->created_at)
        );
        static::__assert_true(
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $client->created_at),
            'created_at is ISO 8601 UTC with milliseconds: ' . $client->created_at
        );
        static::__assert_false(
            $client->created_at instanceof \Carbon\Carbon,
            'never a Carbon instance'
        );
    }

    public static function test_a_date_attribute_is_a_plain_calendar_date_string()
    {
        $project = static::__make_project(['start_date' => '2026-01-15']);

        static::__assert_true(is_string($project->start_date), 'start_date is a string');
        static::__assert_equals('2026-01-15', $project->start_date, 'a DATE column keeps its calendar spelling');
    }

    public static function test_a_tinyint_one_column_is_a_real_bool()
    {
        $client = static::__make_client();

        static::__assert_true($client->portal_enabled === true, 'portal_enabled is boolean true, not 1');
        static::__assert_true($client->newsletter_opt_in === false, 'newsletter_opt_in is boolean false, not 0');
    }

    public static function test_a_type_ref_column_reads_as_a_class_name()
    {
        $client = static::__make_client([
            'created_by_type' => 'User_Model',
            'created_by_id' => self::USER_ID,
        ]);

        static::__assert_equals('User_Model', $client->created_by_type, 'the pair exposes the simple class name');

        // The premise: the column physically stores the type-ref INTEGER, never the string.
        // Raw SQL on purpose - the point is the value BEFORE the type-ref cast.
        $raw = DB::select('SELECT created_by_type FROM clients WHERE id = ?', [$client->id])[0]->created_by_type;
        static::__assert_true(is_numeric($raw), 'the stored value is the type-ref id, not a class name');
    }

    public static function test_two_model_classes_on_different_tables_do_not_share_the_cast_map()
    {
        $client_casts = (new Client_Model())->getCasts();
        $project_casts = (new Project_Model())->getCasts();

        // Each table's own columns are cast, and NEITHER map has leaked into the other.
        static::__assert_array_has_key('portal_enabled', $client_casts, 'a clients-only boolean column');
        static::__assert_false(
            isset($project_casts['portal_enabled']),
            'the projects map has no clients column - the memo is keyed per class+table'
        );

        static::__assert_array_has_key('start_date', $project_casts, 'a projects-only date column');
        static::__assert_false(
            isset($client_casts['start_date']),
            'the clients map has no projects column'
        );

        // And the shared column names still agree, because they are the same schema type.
        static::__assert_equals(
            $client_casts['created_at'],
            $project_casts['created_at'],
            'the same column type yields the same cast in both maps'
        );
    }

    public static function test_merge_casts_stays_on_the_instance_it_was_called_on()
    {
        $client = static::__make_client();

        $before = $client->getCasts();
        static::__assert_equals('boolean', $before['portal_enabled'], 'the schema-derived cast, untouched');

        $client->mergeCasts(['portal_enabled' => 'string']);

        static::__assert_equals(
            'string',
            $client->getCasts()['portal_enabled'],
            'mergeCasts() wins on the instance it was called on'
        );

        // THIS is why parent::getCasts() is deliberately left unmemoized. A freshly fetched
        // sibling of the same class must never see the other instance's runtime cast.
        $sibling = Client_Model::find($client->id);
        static::__assert_equals(
            'boolean',
            $sibling->getCasts()['portal_enabled'],
            'a freshly fetched sibling still reports the schema cast'
        );
        static::__assert_true($sibling->portal_enabled === true, 'and still reads as a real bool');
    }

    // -------------------------------------------------------------------------
    // Enum magic properties (the __get / __isset fast path)
    // -------------------------------------------------------------------------

    public static function test_enum_magic_properties_answer_for_the_current_value()
    {
        $client = static::__make_client();

        static::__assert_equals('Prospect', $client->status_id__label, 'field__label');
        static::__assert_equals('STATUS_PROSPECT', $client->status_id__constant, 'field__constant');
        static::__assert_equals('bg-info', $client->status_id__badge, 'a CUSTOM enum property');

        // A second enum column on the same model resolves independently.
        static::__assert_equals('High', $client->priority__label, 'the other enum column');
    }

    public static function test_enum_magic_properties_follow_the_value_when_it_changes()
    {
        $client = static::__make_client();
        static::__assert_equals('Prospect', $client->status_id__label);

        $client->status_id = Client_Model::STATUS_ARCHIVED;

        static::__assert_equals('Archived', $client->status_id__label, 'the map is per class, the ANSWER is per record');
        static::__assert_equals('bg-warning', $client->status_id__badge);
    }

    public static function test_isset_answers_true_for_a_matching_enum_property()
    {
        $client = static::__make_client();

        static::__assert_true(isset($client->status_id__label), 'the __isset fast path finds a magic key');
        static::__assert_equals(
            'Prospect',
            $client->status_id__label ?? 'fallback',
            'so ?? reaches __get instead of taking the default'
        );

        static::__assert_false(
            isset($client->status_id__no_such_property),
            'a property no enum value declares is not set'
        );
        static::__assert_false(
            isset($client->nope_not_a_column),
            'an ordinary unknown name is not set either'
        );
    }

    public static function test_the_static_enum_lookups_answer_through_an_instance()
    {
        $client = static::__make_client();

        static::__assert_equals(
            [1, 2, 3, 4],
            $client->status_id__enum_ids,
            'field__enum_ids reads off the instance'
        );

        $labels = $client->status_id__enum_labels;
        static::__assert_equals('Active', $labels[1], 'field__enum_labels is an id => label map');
    }

    public static function test_the_static_enum_form_still_works_on_the_class()
    {
        static::__assert_equals(
            [1, 2, 3, 4, 5],
            Project_Model::status__enum_ids(),
            'Model::field__enum_ids()'
        );

        $enum = Project_Model::status__enum();
        static::__assert_equals('Planning', $enum[1]['label'], 'Model::field__enum() carries the full metadata');

        $labels = Project_Model::status__enum_labels();
        static::__assert_equals('Cancelled', $labels[5], 'Model::field__enum_labels()');

        // The sorted config is memoized per class+column - a second call must be identical,
        // and it must not have been mutated by the first.
        static::__assert_equals(
            Project_Model::status__enum(),
            $enum,
            'the memoized sort is stable across calls'
        );

        $select = Project_Model::status__enum_select();
        static::__assert_count(5, $select, 'field__enum_select() lists every selectable value');
        static::__assert_equals(
            ['value' => 1, 'label' => 'Planning'],
            $select[0],
            'and keeps the declared ordering'
        );

        // A sibling class's memo is its own.
        static::__assert_equals([1, 2, 3, 4], Client_Model::status_id__enum_ids(), 'a different class, a different map');
    }

    // -------------------------------------------------------------------------
    // The system column: the name the fast path must NOT mistake for a magic key
    // -------------------------------------------------------------------------

    public static function test_a_system_column_reads_correctly_through_the_slow_path()
    {
        $record = new System_Column_Fixture_Model();
        $record->name = 'system column';
        $record->state_id = 2;
        $record->_flag = 'internal-value';
        $record->save();

        $fetched = System_Column_Fixture_Model::find($record->id);

        // A single leading underscore contains no '__', so it takes the fast path's short
        // exit and falls through to Eloquent - which is exactly what must happen.
        static::__assert_equals('internal-value', $fetched->_flag, 'the system column reads back');
        static::__assert_true(isset($fetched->_flag), '__isset() answers for it too');

        // The same model's enum magic still works, so the short exit is a skip and not a
        // disable.
        static::__assert_equals('Shut', $fetched->state_id__label, 'enum magic on the same model');
        static::__assert_equals('red', $fetched->state_id__tone, 'a custom property on the same model');

        // And the system column is stripped from the client payload (toArray's rule, not
        // this one - asserted here because the two conventions share the underscore).
        static::__assert_false(
            array_key_exists('_flag', $fetched->toArray()),
            'a system column never reaches a payload'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function __make_project(array $overrides = []): Project_Model
    {
        $client = static::__make_client();

        $project = new Project_Model();
        $project->site_id = self::SITE_ID;
        $project->client_id = $client->id;
        $project->name = 'Attribute Read Project ' . uniqid();
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;

        foreach ($overrides as $field => $value) {
            $project->$field = $value;
        }

        $project->save();

        return Project_Model::find($project->id);
    }
}
