<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Attribute_Read_Enum_Fixture_Model;
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
 *
 * TWO KINDS OF SUBJECT, for one reason. A SCHEMA-DERIVED cast is read out of the manifest's
 * column map, which is built from the live schema at manifest-build time - so a fixture
 * table this class creates at TEST time can never carry one, and the cast half is exercised
 * against framework models on framework tables (users, login_users) and the baseline user
 * the runner seeds. ENUM resolution is declared in PHP and needs no schema at all, so the
 * enum half is exercised against two fixture models on two fixture tables, which is the
 * only way to prove the per-class memos are not shared.
 */
class Model_Attribute_Read_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    /**
     * Two fixture tables: one carrying a SYSTEM column (single leading underscore), one
     * carrying a second, differently-shaped enum map. No model in the shipped tree declares
     * a system column, and the fast path's whole premise is that such a name is NOT mistaken
     * for a magic key - so the fixtures have to exist to prove it.
     *
     * setup()/teardown() run outside the per-test transaction, so the DDL here is safe and
     * the rows each test inserts still roll back.
     */
    public static function setup(): void
    {
        static::__drop_fixture_tables();

        DB::statement('CREATE TABLE model_attribute_read_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            state_id BIGINT NOT NULL DEFAULT 1,
            _flag VARCHAR(64) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE model_attribute_read_enum_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            status_id BIGINT NOT NULL DEFAULT 1,
            priority_id BIGINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__drop_fixture_tables();
        static::__reset_session();
    }

    private static function __drop_fixture_tables(): void
    {
        DB::statement('DROP TABLE IF EXISTS model_attribute_read_fixtures');
        DB::statement('DROP TABLE IF EXISTS model_attribute_read_enum_fixtures');
    }

    /**
     * The baseline user, refetched, so every attribute read goes through the casts against a
     * value that came back out of MySQL rather than one still sitting in PHP. Every write
     * here rolls back with the test's transaction.
     */
    private static function __refetched_user(array $overrides = []): User_Model
    {
        $user = User_Model::find(self::USER_ID);

        foreach ($overrides as $field => $value) {
            $user->$field = $value;
        }

        if (!empty($overrides)) {
            $user->save();
        }

        return User_Model::find(self::USER_ID);
    }

    // -------------------------------------------------------------------------
    // Schema-derived casts
    // -------------------------------------------------------------------------

    public static function test_a_datetime_attribute_is_an_iso_string_never_a_carbon()
    {
        $user = static::__refetched_user();

        static::__assert_true(
            is_string($user->created_at),
            'created_at is a string, not an object - got ' . gettype($user->created_at)
        );
        static::__assert_true(
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $user->created_at),
            'created_at is ISO 8601 UTC with milliseconds: ' . $user->created_at
        );
        static::__assert_false(
            $user->created_at instanceof \Carbon\Carbon,
            'never a Carbon instance'
        );
    }

    public static function test_a_date_attribute_is_a_plain_calendar_date_string()
    {
        // A DATE column is a calendar date with no timezone, and the cast must leave its
        // spelling alone. No framework table declares one, so the subject is whatever model
        // this application declares a DATE column on; an application with none skips.
        $date_column = static::__a_date_column();

        if ($date_column === null) {
            static::__skip('this application declares no model with a DATE column');

            return;
        }

        [$model_class, $fqcn, $column] = $date_column;

        // The cast applies on the attribute READ, so no row is needed - which matters,
        // because this class knows nothing about what a row of that table requires.
        $record = new $fqcn();
        $record->$column = '2026-01-15';

        static::__assert_true(is_string($record->$column), "{$model_class}.{$column} reads as a string");
        static::__assert_equals(
            '2026-01-15',
            $record->$column,
            'a DATE column keeps its calendar spelling'
        );
    }

    public static function test_a_tinyint_one_column_is_a_real_bool()
    {
        $user = static::__refetched_user([
            'is_enabled' => true,
            'is_api_access_enabled' => false,
        ]);

        static::__assert_true($user->is_enabled === true, 'is_enabled is boolean true, not 1');
        static::__assert_true($user->is_api_access_enabled === false, 'is_api_access_enabled is boolean false, not 0');
    }

    public static function test_a_type_ref_column_reads_as_a_class_name()
    {
        $user = static::__refetched_user([
            'created_by_type' => 'User_Model',
            'created_by_id' => self::USER_ID,
        ]);

        static::__assert_equals('User_Model', $user->created_by_type, 'the pair exposes the simple class name');

        // The premise: the column physically stores the type-ref INTEGER, never the string.
        // Raw SQL on purpose - the point is the value BEFORE the type-ref cast.
        $raw = DB::select('SELECT created_by_type FROM users WHERE id = ?', [self::USER_ID])[0]->created_by_type;
        static::__assert_true(is_numeric($raw), 'the stored value is the type-ref id, not a class name');
    }

    public static function test_two_model_classes_on_different_tables_do_not_share_the_cast_map()
    {
        $user_casts = (new User_Model())->getCasts();
        $login_casts = (new Login_User_Model())->getCasts();

        // Each table's own columns are cast, and NEITHER map has leaked into the other.
        static::__assert_array_has_key('is_enabled', $user_casts, 'a users-only boolean column');
        static::__assert_false(
            isset($login_casts['is_enabled']),
            'the login_users map has no users column - the memo is keyed per class+table'
        );

        static::__assert_array_has_key('is_activated', $login_casts, 'a login_users-only boolean column');
        static::__assert_false(
            isset($user_casts['is_activated']),
            'the users map has no login_users column'
        );

        // And the shared column names still agree, because they are the same schema type.
        static::__assert_equals(
            $user_casts['created_at'],
            $login_casts['created_at'],
            'the same column type yields the same cast in both maps'
        );
    }

    public static function test_merge_casts_stays_on_the_instance_it_was_called_on()
    {
        $user = static::__refetched_user(['is_enabled' => true]);

        $before = $user->getCasts();
        static::__assert_equals('boolean', $before['is_enabled'], 'the schema-derived cast, untouched');

        $user->mergeCasts(['is_enabled' => 'string']);

        static::__assert_equals(
            'string',
            $user->getCasts()['is_enabled'],
            'mergeCasts() wins on the instance it was called on'
        );

        // THIS is why parent::getCasts() is deliberately left unmemoized. A freshly fetched
        // sibling of the same class must never see the other instance's runtime cast.
        $sibling = User_Model::find(self::USER_ID);
        static::__assert_equals(
            'boolean',
            $sibling->getCasts()['is_enabled'],
            'a freshly fetched sibling still reports the schema cast'
        );
        static::__assert_true($sibling->is_enabled === true, 'and still reads as a real bool');
    }

    // -------------------------------------------------------------------------
    // Enum magic properties (the __get / __isset fast path)
    // -------------------------------------------------------------------------

    public static function test_enum_magic_properties_answer_for_the_current_value()
    {
        $record = static::__make_enum_record();

        static::__assert_equals('Prospect', $record->status_id__label, 'field__label');
        static::__assert_equals('STATUS_PROSPECT', $record->status_id__constant, 'field__constant');
        static::__assert_equals('bg-info', $record->status_id__badge, 'a CUSTOM enum property');

        // A second enum column on the same model resolves independently.
        static::__assert_equals('High', $record->priority_id__label, 'the other enum column');
    }

    public static function test_enum_magic_properties_follow_the_value_when_it_changes()
    {
        $record = static::__make_enum_record();
        static::__assert_equals('Prospect', $record->status_id__label);

        $record->status_id = Attribute_Read_Enum_Fixture_Model::STATUS_ARCHIVED;

        static::__assert_equals('Archived', $record->status_id__label, 'the map is per class, the ANSWER is per record');
        static::__assert_equals('bg-warning', $record->status_id__badge);
    }

    public static function test_isset_answers_true_for_a_matching_enum_property()
    {
        $record = static::__make_enum_record();

        static::__assert_true(isset($record->status_id__label), 'the __isset fast path finds a magic key');
        static::__assert_equals(
            'Prospect',
            $record->status_id__label ?? 'fallback',
            'so ?? reaches __get instead of taking the default'
        );

        static::__assert_false(
            isset($record->status_id__no_such_property),
            'a property no enum value declares is not set'
        );
        static::__assert_false(
            isset($record->nope_not_a_column),
            'an ordinary unknown name is not set either'
        );
    }

    public static function test_the_static_enum_lookups_answer_through_an_instance()
    {
        $record = static::__make_enum_record();

        static::__assert_equals(
            [1, 2, 3, 4],
            $record->status_id__enum_ids,
            'field__enum_ids reads off the instance'
        );

        $labels = $record->status_id__enum_labels;
        static::__assert_equals('Active', $labels[1], 'field__enum_labels is an id => label map');
    }

    public static function test_the_static_enum_form_still_works_on_the_class()
    {
        static::__assert_equals(
            [1, 2, 3],
            Attribute_Read_Enum_Fixture_Model::priority_id__enum_ids(),
            'Model::field__enum_ids()'
        );

        $enum = Attribute_Read_Enum_Fixture_Model::priority_id__enum();
        static::__assert_equals('High', $enum[1]['label'], 'Model::field__enum() carries the full metadata');

        $labels = Attribute_Read_Enum_Fixture_Model::priority_id__enum_labels();
        static::__assert_equals('Low', $labels[3], 'Model::field__enum_labels()');

        // The sorted config is memoized per class+column - a second call must be identical,
        // and it must not have been mutated by the first.
        static::__assert_equals(
            Attribute_Read_Enum_Fixture_Model::priority_id__enum(),
            $enum,
            'the memoized sort is stable across calls'
        );

        $select = Attribute_Read_Enum_Fixture_Model::priority_id__enum_select();
        static::__assert_count(3, $select, 'field__enum_select() lists every selectable value');
        static::__assert_equals(
            ['value' => 1, 'label' => 'High'],
            $select[0],
            'and keeps the declared ordering'
        );

        // A sibling class's memo is its own - a different model, a different map, and the
        // same column name would still not collide.
        static::__assert_equals(
            [1, 2],
            System_Column_Fixture_Model::state_id__enum_ids(),
            'a different class, a different map'
        );
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

    /**
     * A committed-then-refetched enum fixture row.
     */
    private static function __make_enum_record(): Attribute_Read_Enum_Fixture_Model
    {
        $record = new Attribute_Read_Enum_Fixture_Model();
        $record->name = 'Attribute Read ' . uniqid();
        $record->status_id = Attribute_Read_Enum_Fixture_Model::STATUS_PROSPECT;
        $record->priority_id = Attribute_Read_Enum_Fixture_Model::PRIORITY_HIGH;
        $record->save();

        return Attribute_Read_Enum_Fixture_Model::find($record->id);
    }

    /**
     * The first DATE column this application declares, as [model_class, fqcn, column] - or
     * null when no model has one. Deterministic: the manifest's model index is walked in
     * sorted order.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function __a_date_column(): ?array
    {
        $models = Manifest::$data['data']['models'] ?? [];
        ksort($models);

        foreach ($models as $model_class => $model) {
            $fqcn = $model['fqcn'] ?? null;

            if ($fqcn === null) {
                continue;
            }

            foreach (Manifest::php_model_columns($model_class) ?? [] as $column => $meta) {
                if (($meta['type'] ?? null) === 'date') {
                    return [$model_class, $fqcn, $column];
                }
            }
        }

        return null;
    }
}
