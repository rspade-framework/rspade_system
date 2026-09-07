<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Revisions\Transaction_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Revisions\Php\Revision_Child_Fixture_Model;
use App\RSpade\Tests\Revisions\Php\Revision_Fixture_Model;
use App\RSpade\Tests\Revisions\Php\Revision_Plain_Fixture_Model;

/**
 * The recording path in Rsx_Model_Abstract, exercised with real writes against real
 * fixture tables: what a diff contains, which operation a write is, how writes group into
 * one transaction, and every way recording is deliberately switched off.
 *
 * Each write runs inside the runner's wrapping transaction and is rolled back afterwards.
 * Revisions are written on the SAME connection as the record write, so they roll back with
 * it - which is itself one of the behaviors under test.
 */
class Revision_Recording_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE revision_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NULL DEFAULT NULL,
            name VARCHAR(255) NULL,
            counter INT NOT NULL DEFAULT 0,
            _internal VARCHAR(64) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL,
            deleted_by_id BIGINT NULL DEFAULT NULL,
            deleted_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE revision_child_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id BIGINT NULL DEFAULT NULL,
            label VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE revision_plain_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Register the fixture models' type refs OUTSIDE the per-test transaction.
        // Auto-registration inside a test would insert a _type_refs row that the rollback
        // discards while the registry's in-process map keeps it - and the next class to
        // auto-register would be handed that same id, so a type-ref column would read back
        // as the wrong model. Committing them here makes the ids stable for the whole run.
        Type_Ref_Registry::class_to_id('Revision_Fixture_Model');
        Type_Ref_Registry::class_to_id('Revision_Child_Fixture_Model');
        Type_Ref_Registry::class_to_id('Revision_Plain_Fixture_Model');
    }

    public static function teardown()
    {
        static::__drop_tables();
    }

    private static function __drop_tables()
    {
        DB::statement('DROP TABLE IF EXISTS revision_fixtures');
        DB::statement('DROP TABLE IF EXISTS revision_child_fixtures');
        DB::statement('DROP TABLE IF EXISTS revision_plain_fixtures');
    }

    /**
     * A saved fixture with the two "must never be recorded" columns populated.
     */
    private static function __make_record(string $name = 'first'): Revision_Fixture_Model
    {
        $record = new Revision_Fixture_Model();
        $record->name = $name;
        $record->counter = 7;
        $record->_internal = 'bookkeeping';
        $record->save();

        return $record;
    }

    /**
     * The only revision recorded so far, asserting that there is exactly one.
     */
    private static function __only_revision(): Revision_Model
    {
        $revisions = Revision::current_revisions();
        static::__assert_count(1, $revisions, 'expected exactly one recorded revision');

        return $revisions[0];
    }

    // =====================================================================
    // The four operations
    // =====================================================================

    public static function test_create_records_every_non_null_column_from_null()
    {
        $record = static::__make_record('created');

        $revision = static::__only_revision();
        $diff = $revision->diff();

        static::__assert_equals(Revision_Model::OPERATION_CREATE, (int) $revision->operation_id);
        static::__assert_equals('Revision_Fixture_Model', $revision->record_type);
        static::__assert_equals((int) $record->id, (int) $revision->record_id);
        static::__assert_array_has_key('name', $diff);
        static::__assert_equals([null, 'created'], $diff['name'], 'a create records [null, value]');
        static::__assert_array_has_key('created_at', $diff, 'provenance columns appear once, on the create');
    }

    public static function test_update_records_only_the_changed_columns()
    {
        $record = static::__make_record('before');

        Revision::_reset_request_state('test', 'update');

        $record->name = 'after';
        $record->save();

        $diff = static::__only_revision()->diff();

        static::__assert_equals([['before', 'after']], array_values($diff), 'only the changed column is recorded');
    }

    public static function test_delete_records_an_empty_document()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'delete');

        $record->delete();

        $revision = static::__only_revision();

        static::__assert_equals(Revision_Model::OPERATION_DELETE, (int) $revision->operation_id);
        static::__assert_count(0, $revision->diff(), 'a delete records no field pairs');
    }

    public static function test_restore_records_an_undelete()
    {
        $record = static::__make_record();
        $record->delete();

        Revision::_reset_request_state('test', 'restore');

        $record->restore();

        $revision = static::__only_revision();

        static::__assert_equals(Revision_Model::OPERATION_UNDELETE, (int) $revision->operation_id);
        static::__assert_array_has_key('deleted_at', $revision->diff());
    }

    // =====================================================================
    // What never reaches a diff
    // =====================================================================

    public static function test_automatic_and_declared_exclusions_never_appear()
    {
        $record = static::__make_record();

        $diff = static::__only_revision()->diff();

        static::__assert_false(array_key_exists('counter', $diff), '$revision_exclude column must not be recorded');
        static::__assert_false(array_key_exists('_internal', $diff), 'a _-prefixed system column must not be recorded');
        static::__assert_false(array_key_exists('updated_at', $diff), 'updated_at must not be recorded');
        static::__assert_false(array_key_exists('updated_by_id', $diff), 'the updated_by pair must not be recorded');
        static::__assert_false(array_key_exists('updated_by_type', $diff), 'the updated_by pair must not be recorded');
    }

    public static function test_a_write_that_only_touches_excluded_columns_records_nothing()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'excluded-only');

        $record->counter = 99;
        $record->save();

        static::__assert_count(0, Revision::current_revisions(), 'nothing a history would show changed');
        static::__assert_null(Revision::current_transaction(), 'and no transaction was minted');
    }

    public static function test_an_unchanged_save_records_nothing()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'no-op');

        $record->save();

        static::__assert_count(0, Revision::current_revisions(), 'a save with nothing dirty records nothing');
    }

    public static function test_a_model_that_did_not_opt_in_records_nothing()
    {
        $record = new Revision_Plain_Fixture_Model();
        $record->name = 'plain';
        $record->save();

        static::__assert_count(0, Revision::current_revisions(), '$revisions = false records nothing');
        static::__assert_null(Revision::current_transaction());
    }

    public static function test_revision_without_suppresses_recording()
    {
        $created = Revision::without(function () {
            return static::__make_record('quiet');
        });

        static::__assert_not_null($created->id, 'the write itself still happened');
        static::__assert_count(0, Revision::current_revisions(), 'Revision::without() records nothing');
    }

    public static function test_suppression_is_restored_when_the_callable_throws()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Revision::without(function () {
                throw new \RuntimeException('boom');
            });
        });

        static::__assert_false(Revision::is_suppressed(), 'a throw inside without() must not leave recording off');

        static::__make_record();
        static::__assert_count(1, Revision::current_revisions());
    }

    // =====================================================================
    // Transactions
    // =====================================================================

    public static function test_two_writes_in_one_unit_share_one_transaction()
    {
        $first = static::__make_record('one');
        $second = static::__make_record('two');

        $revisions = Revision::current_revisions();

        static::__assert_count(2, $revisions);
        static::__assert_equals(
            (int) $revisions[0]->transaction_id,
            (int) $revisions[1]->transaction_id,
            'both writes belong to the same unit of work'
        );
        static::__assert_equals(1, (int) $revisions[0]->sequence);
        static::__assert_equals(2, (int) $revisions[1]->sequence);

        $transaction = Transaction_Model::find((int) $revisions[0]->transaction_id);
        static::__assert_equals(2, (int) $transaction->revision_count, 'revision_count counts them');
        static::__assert_equals(Transaction_Model::SOURCE_TEST, (int) $transaction->source_id);
    }

    public static function test_a_reset_starts_a_new_transaction()
    {
        static::__make_record('one');
        $first_transaction_id = (int) Revision::current_transaction()->id;

        Revision::_reset_request_state('ajax', 'Fixture_Controller::save');

        static::__assert_null(Revision::current_transaction(), 'a reset mints nothing by itself');

        static::__make_record('two');
        $second_transaction_id = (int) Revision::current_transaction()->id;

        static::__assert_not_equals($first_transaction_id, $second_transaction_id);
        static::__assert_count(1, Revision::current_revisions(), 'the new unit starts its own list');
        static::__assert_equals(1, (int) Revision::current_revisions()[0]->sequence, 'and its own sequence');
        static::__assert_equals(
            Transaction_Model::SOURCE_AJAX,
            (int) Revision::current_transaction()->source_id,
            'the reset declares the source'
        );
    }

    public static function test_describe_lands_on_the_transaction_before_and_after_the_mint()
    {
        Revision::describe('before the first write');
        static::__make_record();

        static::__assert_equals('before the first write', Revision::current_transaction()->description);

        Revision::describe('after it');
        static::__assert_equals(
            'after it',
            (string) Transaction_Model::find((int) Revision::current_transaction()->id)->description
        );
    }

    public static function test_the_actor_pair_is_stamped_from_the_acting_user()
    {
        static::__acting_as_user(1);

        static::__make_record();

        $transaction = Revision::current_transaction();

        // The STORED type-ref id, not the class name read back through the registry's
        // reverse map. The runner loads that map from the development connection during the
        // manifest build and then swaps to the test database, so a type ref first registered
        // inside a test can shadow a development id in the id -> class direction. The
        // forward lookup is unaffected, and it is what the stamp actually wrote.
        static::__assert_equals(
            Type_Ref_Registry::class_to_id('User_Model'),
            (int) $transaction->getAttributes()['actor_type']
        );
        static::__assert_equals(1, (int) $transaction->actor_id);
    }

    // =====================================================================
    // The root pair
    // =====================================================================

    public static function test_a_child_files_its_revisions_under_its_revision_parent()
    {
        $owner = static::__make_record('owner');

        Revision::_reset_request_state('test', 'child');

        $child = new Revision_Child_Fixture_Model();
        $child->owner_id = $owner->id;
        $child->label = 'a child';
        $child->save();

        $revision = static::__only_revision();

        static::__assert_equals('Revision_Child_Fixture_Model', $revision->record_type, 'the record pair is the child');
        static::__assert_equals((int) $child->id, (int) $revision->record_id);
        static::__assert_equals('Revision_Fixture_Model', $revision->root_type, 'the root pair is the parent');
        static::__assert_equals((int) $owner->id, (int) $revision->root_id);
    }

    public static function test_a_top_level_record_is_its_own_root()
    {
        $record = static::__make_record();

        $revision = static::__only_revision();

        static::__assert_equals('Revision_Fixture_Model', $revision->root_type);
        static::__assert_equals((int) $record->id, (int) $revision->root_id);
    }

    public static function test_revisions_including_children_reaches_the_child_writes()
    {
        $owner = static::__make_record('owner');

        $child = new Revision_Child_Fixture_Model();
        $child->owner_id = $owner->id;
        $child->label = 'a child';
        $child->save();

        $own = iterator_to_array($owner->revisions());
        $all = iterator_to_array($owner->revisions_including_children());

        static::__assert_count(1, $own, 'revisions() answers for this record only');
        static::__assert_count(2, $all, 'revisions_including_children() adds the child write');
    }

    public static function test_transactions_for_finds_the_units_that_touched_a_record()
    {
        $record = static::__make_record('one');

        Revision::_reset_request_state('test', 'second unit');

        $record->name = 'two';
        $record->save();

        $transactions = iterator_to_array(Revision::transactions_for($record));

        static::__assert_count(2, $transactions, 'two units of work touched this record');
    }

    public static function test_for_transaction_returns_the_unit_in_sequence_order()
    {
        static::__make_record('one');
        static::__make_record('two');

        $transaction_id = (int) Revision::current_transaction()->id;
        $revisions = iterator_to_array(Revision::for_transaction($transaction_id));

        static::__assert_count(2, $revisions);
        static::__assert_equals(1, (int) $revisions[0]->sequence);
        static::__assert_equals(2, (int) $revisions[1]->sequence);
    }

    // =====================================================================
    // Bulk writes, rollback, serialization
    // =====================================================================

    public static function test_a_bulk_update_records_one_revision_per_row()
    {
        static::__make_record('one');
        static::__make_record('two');

        Revision::_reset_request_state('test', 'bulk');

        Revision_Fixture_Model::whereIn('name', ['one', 'two'])->update(['name' => 'renamed']);

        static::__assert_count(2, Revision::current_revisions(), 'a bulk update records per affected record');
    }

    public static function test_raw_bulk_records_nothing()
    {
        static::__make_record('one');
        static::__make_record('two');

        Revision::_reset_request_state('test', 'raw bulk');

        Revision_Fixture_Model::whereIn('name', ['one', 'two'])->raw_bulk()->update(['name' => 'renamed']);

        static::__assert_count(0, Revision::current_revisions(), 'raw_bulk() fires no side effects at all');
    }

    public static function test_a_rolled_back_transaction_leaves_no_revision_row()
    {
        $before = (int) DB::table('_revisions')->count();

        try {
            DB::transaction(function () {
                static::__make_record('doomed');

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            // Expected: the throw is what rolls the transaction back.
        }

        static::__assert_equals(
            $before,
            (int) DB::table('_revisions')->count(),
            'a revision is written on the same connection, so it rolls back with the write it describes'
        );
    }

    public static function test_to_array_strips_system_columns_but_keeps_the_model_key()
    {
        $record = static::__make_record();

        $array = $record->toArray();

        static::__assert_false(array_key_exists('_internal', $array), 'a _-prefixed column is stripped');
        static::__assert_array_has_key('name', $array);
        static::__assert_equals('Revision_Fixture_Model', $array['__MODEL'], '__-prefixed framework keys survive');
    }

    public static function test_the_changes_blob_is_never_serialized()
    {
        static::__make_record();

        $array = static::__only_revision()->toArray();

        static::__assert_false(array_key_exists('changes', $array), '$neverExport keeps the blob out of payloads');
    }

    public static function test_a_recorded_revision_is_stored_compressed()
    {
        static::__make_record('a name long enough to be worth compressing at all');

        $revision = static::__only_revision();
        $stored = (string) DB::table('_revisions')->where('id', (int) $revision->id)->value('changes');
        $raw = json_encode($revision->diff(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        static::__assert_less_than(
            strlen($raw),
            strlen($stored),
            'a real revision document stores smaller than its JSON (the schema dictionary is doing its job)'
        );
    }

    public static function test_diff_round_trips_through_the_codec()
    {
        static::__make_record('round trip');

        $revision = static::__only_revision();

        // Re-read from the database rather than trusting the in-memory instance: the point
        // is that what was STORED decodes back to what was recorded.
        $stored = Revision_Model::find((int) $revision->id);

        static::__assert_equals([null, 'round trip'], $stored->diff()['name']);
    }
}
