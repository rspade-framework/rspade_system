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
use App\RSpade\Core\Revisions\Revision_Codec;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Revisions\Php\Revision_Secret_Fixture_Model;

/**
 * A never-exported column in the revision lane: every change to one is recorded with both
 * sides replaced by Revision::REDACTED, so the history says the column changed and the
 * stored document never holds its value - old or new, plain or encoded.
 *
 * Each assertion that a value is absent reads the STORED payload, decoded straight from
 * the `changes` column, rather than trusting diff(): the guarantee is about what is
 * written to the database.
 */
class Revision_Redaction_Test extends Rsx_Test_Abstract
{
    private const TOKEN_ONE = 'tok-first-7f3a9c';
    private const TOKEN_TWO = 'tok-second-2b8e41';
    private const NOTE = 'the vault combination';
    private const ROTATING = 'rotating-5d0c77';

    public static function setup()
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE revision_secret_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            api_token VARCHAR(255) NULL,
            secret_note TEXT NULL,
            rotating_secret VARCHAR(255) NULL,
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

        // Committed outside the per-test transaction, for the reason Revision_Recording_Test
        // gives: a type ref minted inside a rolled-back test would be reused for another class.
        Type_Ref_Registry::class_to_id('Revision_Secret_Fixture_Model');
    }

    public static function teardown()
    {
        static::__drop_tables();
    }

    private static function __drop_tables()
    {
        DB::statement('DROP TABLE IF EXISTS revision_secret_fixtures');
    }

    /**
     * A saved fixture with every secret populated.
     */
    private static function __make_record(): Revision_Secret_Fixture_Model
    {
        $record = new Revision_Secret_Fixture_Model();
        $record->name = 'visible';
        $record->api_token = self::TOKEN_ONE;
        $record->secret_note = self::NOTE;
        $record->rotating_secret = self::ROTATING;
        $record->save();

        return $record;
    }

    /**
     * The only revision recorded since the last reset, asserting that there is exactly one.
     */
    private static function __only_revision(): Revision_Model
    {
        $revisions = Revision::current_revisions();
        static::__assert_count(1, $revisions, 'expected exactly one recorded revision');

        return $revisions[0];
    }

    /**
     * The document as it was written to the database, decoded from the stored column.
     */
    private static function __stored_json(Revision_Model $revision): string
    {
        $stored = (string) DB::table('_revisions')->where('id', (int) $revision->id)->value('changes');

        return Revision_Codec::decode($stored);
    }

    private static function __assert_holds_no_secret(Revision_Model $revision): void
    {
        $json = static::__stored_json($revision);

        foreach ([self::TOKEN_ONE, self::TOKEN_TWO, self::NOTE, self::ROTATING, 'vault'] as $secret) {
            static::__assert_false(str_contains($json, $secret), "the stored document must not contain '{$secret}'");
        }
    }

    public static function test_a_create_records_each_secret_redacted_on_both_sides()
    {
        static::__make_record();

        $revision = static::__only_revision();
        $diff = $revision->diff();

        static::__assert_equals('__RSX_REDACTED__', Revision::REDACTED, 'the documented marker value');
        static::__assert_equals([Revision::REDACTED, Revision::REDACTED], $diff['api_token'], 'a create records the change, not the value');
        static::__assert_equals([null, 'visible'], $diff['name'], 'an ordinary column is untouched');
        static::__assert_holds_no_secret($revision);
    }

    public static function test_an_update_records_the_change_without_the_old_or_new_value()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'rotate');

        $record->api_token = self::TOKEN_TWO;
        $record->save();

        $revision = static::__only_revision();

        static::__assert_equals(
            ['api_token' => [Revision::REDACTED, Revision::REDACTED]],
            $revision->diff(),
            'the rotation is recorded, and neither token is'
        );
        static::__assert_holds_no_secret($revision);
    }

    public static function test_an_update_that_leaves_a_secret_alone_does_not_mention_it()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'rename');

        $record->name = 'renamed';
        $record->save();

        static::__assert_equals(
            ['name' => ['visible', 'renamed']],
            static::__only_revision()->diff(),
            'an unchanged secret is not recorded'
        );
    }

    public static function test_clearing_a_secret_is_recorded_redacted()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'clear');

        $record->api_token = null;
        $record->save();

        $revision = static::__only_revision();

        static::__assert_equals([Revision::REDACTED, Revision::REDACTED], $revision->diff()['api_token']);
        static::__assert_holds_no_secret($revision);
    }

    public static function test_a_declared_text_type_secret_is_never_encoded()
    {
        static::__make_record();

        $revision = static::__only_revision();
        $json = static::__stored_json($revision);

        static::__assert_equals([Revision::REDACTED, Revision::REDACTED], $revision->diff()['secret_note']);
        static::__assert_false(str_contains($json, '<p>'), 'the type\'s encoding never reached the document');
        static::__assert_false(str_contains($json, '__TEXT'), 'nor did a value-object envelope');
    }

    public static function test_revision_exclude_wins_over_redaction()
    {
        $record = static::__make_record();

        static::__assert_false(
            array_key_exists('rotating_secret', static::__only_revision()->diff()),
            'a column in both lists is not recorded at all on a create'
        );

        Revision::_reset_request_state('test', 'rotate-excluded');

        $record->rotating_secret = 'rotating-next';
        $record->save();

        static::__assert_count(0, Revision::current_revisions(), 'nor on an update that changes only it');
    }

    public static function test_delete_and_restore_carry_no_secret()
    {
        $record = static::__make_record();

        Revision::_reset_request_state('test', 'delete');
        $record->delete();
        $deleted = static::__only_revision();

        static::__assert_equals(Revision_Model::OPERATION_DELETE, (int) $deleted->operation_id);
        static::__assert_count(0, $deleted->diff(), 'a delete records an empty document');
        static::__assert_holds_no_secret($deleted);

        Revision::_reset_request_state('test', 'restore');
        $record->restore();
        $restored = static::__only_revision();

        static::__assert_equals(Revision_Model::OPERATION_UNDELETE, (int) $restored->operation_id);
        static::__assert_false(array_key_exists('api_token', $restored->diff()), 'a restore does not touch the secret');
        static::__assert_holds_no_secret($restored);
    }

    public static function test_diff_hands_back_the_marker_and_is_redacted_answers()
    {
        static::__make_record();

        $stored = Revision_Model::find((int) static::__only_revision()->id);
        $pair = $stored->diff()['api_token'];

        static::__assert_true(Revision_Model::is_redacted($pair[0]), 'the before side read back from storage is the marker');
        static::__assert_true(Revision_Model::is_redacted($pair[1]), 'the after side read back from storage is the marker');
        static::__assert_false(Revision_Model::is_redacted('visible'), 'an ordinary value is not');
        static::__assert_false(Revision_Model::is_redacted(null), 'null is not');
    }
}
