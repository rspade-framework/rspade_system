<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Revisions\Revision_Dictionary;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the DERIVATION of the compression dictionary - what goes into it, in what order,
 * and when a new one is minted.
 *
 * Two properties are load-bearing and neither fails visibly if it regresses. The
 * dictionary must contain the vocabulary a revision document is made of (a missing
 * column name is not an error, it is just worse compression forever), and the hottest
 * tokens must sit at the END (zlib prices a match by its distance, and the dictionary is
 * loaded nearest-last). Both are asserted directly here because nothing else would ever
 * report them.
 *
 * Every test writes inside the per-test transaction and resets the dictionary cache
 * around itself: the cache is Redis, which the rollback does not reach.
 */
class Revision_Dictionary_Test extends Rsx_Test_Abstract
{
    /**
     * A COMMITTING class, not a transactional one.
     *
     * Dictionary ids are the subject here - the 255 ceiling, "the newest row is current",
     * "a regeneration appends" - and MySQL does not roll an AUTO_INCREMENT counter back
     * with the transaction that used it. Under the default per-test transaction the ids
     * these tests observe would creep upward with every suite run until they crossed the
     * ceiling, which is a test that fails on a date rather than on a change. Committing
     * lets each test TRUNCATE the table, which resets the counter, so every run sees the
     * same ids as the first one.
     */
    protected static $requires_db_reset = true;

    protected static $use_database_transactions = false;

    public static function setup()
    {
        Revision_Dictionary::_reset_cache();
    }

    public static function teardown()
    {
        static::__reset_dictionaries();
    }

    /**
     * An empty table with its id counter back at 1, and no cached view of either.
     */
    private static function __reset_dictionaries(): void
    {
        DB::statement('TRUNCATE TABLE _revision_dictionaries');
        Revision_Dictionary::_reset_cache();
    }

    /**
     * Every column of a known table is in the vocabulary, in the shape a revision
     * document contains it.
     */
    public static function test_tokens_contain_every_column_of_a_known_table()
    {
        $tokens = Revision_Dictionary::build_tokens();

        $columns = DB::select(
            'SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [DB::connection()->getDatabaseName(), '_revisions']
        );

        static::__assert_greater_than(0, count($columns), '_revisions must have columns to check against');

        foreach ($columns as $column) {
            static::__assert_true(
                in_array('"' . $column->column_name . '":[', $tokens, true),
                'column ' . $column->column_name . ' must appear in the dictionary vocabulary'
            );
        }
    }

    /**
     * Every enum label declared by a model reaches the vocabulary. Login_User_Model is a
     * framework model, so this holds with or without the template application present.
     */
    public static function test_tokens_contain_enum_labels()
    {
        $tokens = Revision_Dictionary::build_tokens();

        $enums = Login_User_Model::$enums;
        static::__assert_greater_than(0, count($enums), 'Login_User_Model must declare enums for this test to mean anything');

        foreach ($enums as $definitions) {
            foreach ($definitions as $definition) {
                static::__assert_true(
                    in_array('"' . $definition['label'] . '"', $tokens, true),
                    'enum label ' . $definition['label'] . ' must appear in the dictionary vocabulary'
                );
            }
        }
    }

    /**
     * THE ORDER. The JSON structural tokens are the very last entries, and the
     * always-present columns sit behind every ordinary one. An enum label - the coldest
     * vocabulary there is - sits in front of all of it.
     */
    public static function test_hot_tokens_are_last()
    {
        $tokens = Revision_Dictionary::build_tokens();

        static::__assert_equals('{"', $tokens[count($tokens) - 1], 'the object opener is the hottest token of all');

        $id_position = array_search('"id":[', $tokens, true);
        $ordinary_position = array_search('"token_hash":[', $tokens, true);
        $structural_position = array_search('":[', $tokens, true);

        static::__assert_true($ordinary_position !== false, 'an ordinary column must be present');
        static::__assert_true($id_position !== false, 'the id column must be present');

        static::__assert_greater_than($ordinary_position, $id_position, 'id must sit after an ordinary column');
        static::__assert_greater_than($id_position, $structural_position, 'the structural tokens must sit after every column');
    }

    /**
     * The bytes handed to the compressors are the tokens concatenated, tail-truncated to
     * the usable window.
     */
    public static function test_build_bytes_keeps_the_tail()
    {
        $tokens = ['cold', str_repeat('x', Revision_Dictionary::MAX_DICTIONARY_BYTES), 'hot'];

        $bytes = Revision_Dictionary::build_bytes($tokens);

        static::__assert_equals(Revision_Dictionary::MAX_DICTIONARY_BYTES, strlen($bytes));
        static::__assert_equals('hot', substr($bytes, -3), 'the hottest tokens must survive truncation');
    }

    /**
     * With no dictionary at all, regeneration builds one and current() reports it.
     */
    public static function test_regenerates_when_none_exists()
    {
        static::__reset_dictionaries();

        static::__assert_equals(null, Revision_Dictionary::current(), 'no rows means no current dictionary');

        $id = Revision_Dictionary::regenerate_if_stale();

        static::__assert_not_null($id, 'an empty table must produce a dictionary');

        $current = Revision_Dictionary::current();
        static::__assert_equals($id, $current['id']);
        static::__assert_greater_than(0, strlen($current['bytes']));

        $row = DB::table('_revision_dictionaries')->where('id', $id)->first();
        static::__assert_greater_than(0, (int) $row->token_count);
        static::__assert_equals(40, strlen((string) $row->token_hash), 'token_hash is a sha1');
    }

    /**
     * A dictionary inside the cadence is left alone.
     */
    public static function test_does_nothing_when_fresh()
    {
        static::__reset_dictionaries();

        $first = Revision_Dictionary::regenerate_if_stale();
        static::__assert_not_null($first);

        static::__assert_equals(null, Revision_Dictionary::regenerate_if_stale(30), 'a dictionary minted seconds ago is not stale');
        static::__assert_equals($first, Revision_Dictionary::current()['id']);
    }

    /**
     * A dictionary older than the configured age produces a NEW row - the old one is
     * left in place, because revisions written against it still name it.
     */
    public static function test_regenerates_when_older_than_the_configured_age()
    {
        static::__reset_dictionaries();

        $first = Revision_Dictionary::regenerate_if_stale();
        DB::table('_revision_dictionaries')->where('id', $first)->update([
            'created_at' => date('Y-m-d H:i:s', time() - (31 * 86400)),
        ]);
        Revision_Dictionary::_reset_cache();

        $second = Revision_Dictionary::regenerate_if_stale(30);

        static::__assert_not_null($second, 'a 31-day-old dictionary is stale at a 30-day cadence');
        static::__assert_greater_than($first, $second, 'regeneration appends a new row');
        static::__assert_equals($second, Revision_Dictionary::current()['id'], 'the newest row becomes current');

        static::__assert_greater_than(0, strlen(Revision_Dictionary::bytes_for($first)), 'the superseded dictionary is still readable');
    }

    /**
     * The id travels in ONE byte of every payload's prefix, so an id past 255 would make
     * every revision written against it unreadable. Minting one is refused.
     */
    public static function test_id_above_the_ceiling_throws()
    {
        static::__reset_dictionaries();
        DB::table('_revision_dictionaries')->insert([
            'id' => Revision_Dictionary::MAX_DICTIONARY_ID,
            'bytes' => 'x',
            'token_hash' => str_repeat('a', 40),
            'token_count' => 1,
            'created_at' => date('Y-m-d H:i:s', time() - (999 * 86400)),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Revision_Dictionary::_reset_cache();

        static::__assert_throws(RuntimeException::class, function () {
            Revision_Dictionary::regenerate_if_stale(30);
        }, 'would exceed 255');
    }

    /**
     * bytes_for() of an id that has no row cannot invent one.
     */
    public static function test_bytes_for_absent_id_throws()
    {
        static::__assert_throws(RuntimeException::class, function () {
            Revision_Dictionary::bytes_for(Revision_Dictionary::MAX_DICTIONARY_ID - 1);
        }, 'no _revision_dictionaries row with id');
    }

    /**
     * The cache is a cache: after a reset, current() reads the table again and sees what
     * is actually there.
     */
    public static function test_cache_reset_makes_current_reread()
    {
        static::__reset_dictionaries();

        $first = Revision_Dictionary::regenerate_if_stale();
        static::__assert_equals($first, Revision_Dictionary::current()['id']);

        DB::table('_revision_dictionaries')->insert([
            'bytes' => 'a fabricated dictionary',
            'token_hash' => str_repeat('b', 40),
            'token_count' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        static::__assert_equals($first, Revision_Dictionary::current()['id'], 'the cached pointer survives a write it was not told about');

        Revision_Dictionary::_reset_cache();

        static::__assert_greater_than($first, Revision_Dictionary::current()['id'], 'after a reset the newest row is current');
    }
}
