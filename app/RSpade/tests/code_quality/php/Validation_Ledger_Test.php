<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Support\Validation_Ledger;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the shared pass ledger.
 *
 * The ledger replaced 1254 zero-byte `.lintpass` flag files with ONE var_export'd array,
 * so the properties that matter are the ones a flag file gave for free: a verdict is
 * remembered, it survives being written and re-read from disk, an unknown hash is not
 * vouched for, a rule's verdicts can be retired wholesale, and a hash the manifest no
 * longer knows is dropped rather than accumulating forever.
 *
 * Every row runs against a throwaway ledger file (`_use_path_for_tests`), so the box's own
 * memo is never touched.
 */
class Validation_Ledger_Test extends Rsx_Test_Abstract
{
    // Pure file/array work - no database access.
    protected static $use_database_transactions = false;

    private const RULE = 'TEST-LEDGER-01';

    private static ?string $fixture_path = null;

    private static function __begin(): void
    {
        self::$fixture_path = storage_path('rsx-tmp') . '/validation_ledger_test_' . uniqid() . '.php';
        Validation_Ledger::_use_path_for_tests(self::$fixture_path);
    }

    private static function __end(): void
    {
        if (self::$fixture_path !== null && file_exists(self::$fixture_path)) {
            @unlink(self::$fixture_path);
        }

        self::$fixture_path = null;
        Validation_Ledger::_use_path_for_tests(null);
    }

    /**
     * The core memo: what was recorded is remembered, and nothing else is.
     */
    public static function test_record_then_has_passed_roundtrip()
    {
        self::__begin();

        try {
            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-a'),
                'an empty ledger vouches for nothing'
            );

            Validation_Ledger::record_pass(self::RULE, 'hash-a');

            static::__assert_true(
                Validation_Ledger::has_passed(self::RULE, 'hash-a'),
                'a recorded pass is remembered in memory'
            );

            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-b'),
                'a hash that never passed is not vouched for'
            );

            static::__assert_false(
                Validation_Ledger::has_passed('OTHER-RULE', 'hash-a'),
                'a verdict belongs to ONE rule - it does not leak across rule ids'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * The whole point of a file rather than a static: the verdict outlives the process.
     */
    public static function test_flush_writes_and_reload_reads()
    {
        self::__begin();

        try {
            Validation_Ledger::record_pass(self::RULE, 'hash-a');
            Validation_Ledger::flush();

            static::__assert_true(file_exists(self::$fixture_path), 'flush() wrote the ledger file');

            $written = include self::$fixture_path;

            static::__assert_equals(1, $written['version'], 'the file declares its shape version');
            static::__assert_array_has_key(self::RULE, $written['rules']);
            static::__assert_array_has_key('hash-a', $written['rules'][self::RULE]);

            // Drop the in-memory copy the way a fresh process would.
            Validation_Ledger::_use_path_for_tests(self::$fixture_path);

            static::__assert_true(
                Validation_Ledger::has_passed(self::RULE, 'hash-a'),
                'the verdict is read back from disk by a process that never recorded it'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * A hash key is the FILE's identity, so a file whose bytes changed carries a hash the
     * ledger has never seen and is judged again.
     */
    public static function test_unknown_hash_is_not_passed()
    {
        self::__begin();

        try {
            Validation_Ledger::record_pass(self::RULE, 'hash-before-edit');
            Validation_Ledger::flush();
            Validation_Ledger::_use_path_for_tests(self::$fixture_path);

            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-after-edit'),
                'an edited file presents a new hash and is re-checked'
            );

            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, ''),
                'an empty hash is never a pass - it means the caller had no identity to offer'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * The retirement path for a rule whose premise moved.
     */
    public static function test_forget_rule_drops_only_that_rule()
    {
        self::__begin();

        try {
            Validation_Ledger::record_pass(self::RULE, 'hash-a');
            Validation_Ledger::record_pass('KEEP-ME-01', 'hash-a');

            Validation_Ledger::forget_rule(self::RULE);

            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-a'),
                'the forgotten rule has no verdicts left'
            );

            static::__assert_true(
                Validation_Ledger::has_passed('KEEP-ME-01', 'hash-a'),
                'another rule keeps its own verdicts'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * Without pruning the ledger would grow by one entry per edit, forever.
     */
    public static function test_prune_drops_hashes_the_manifest_no_longer_knows()
    {
        self::__begin();

        try {
            Validation_Ledger::record_pass(self::RULE, 'hash-live');
            Validation_Ledger::record_pass(self::RULE, 'hash-dead');
            Validation_Ledger::flush();

            // Reload the way a later process would. A pass this process just RECORDED is
            // never pruned (the caller that judged the file is better evidence than the
            // manifest is), so the accumulation the prune exists to clear has to arrive
            // from disk - which is exactly how it arrives in life.
            Validation_Ledger::_use_path_for_tests(self::$fixture_path);

            Validation_Ledger::prune(['hash-live']);

            static::__assert_true(
                Validation_Ledger::has_passed(self::RULE, 'hash-live'),
                'a hash the manifest still knows survives the prune'
            );

            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-dead'),
                'a hash nothing points at any more is dropped'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * A GENERATIONAL id (`<rule>@<fingerprint>`) holds ONE generation. Without this the
     * ledger would accumulate a full set of dead verdicts every time a rule's premise moved.
     */
    public static function test_recording_a_new_generation_retires_the_old_one()
    {
        self::__begin();

        try {
            Validation_Ledger::record_pass('GEN-RULE-01@aaaaaa', 'hash-a');
            Validation_Ledger::record_pass('GEN-RULE-01@bbbbbb', 'hash-a');

            static::__assert_false(
                Validation_Ledger::has_passed('GEN-RULE-01@aaaaaa', 'hash-a'),
                'the superseded generation is gone'
            );

            static::__assert_true(
                Validation_Ledger::has_passed('GEN-RULE-01@bbbbbb', 'hash-a'),
                'the current generation holds the verdict'
            );

            Validation_Ledger::record_pass('PLAIN-RULE-01', 'hash-a');

            static::__assert_true(
                Validation_Ledger::has_passed('GEN-RULE-01@bbbbbb', 'hash-a'),
                'an id with no generation retires nothing'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * clear() is the rsx:clean shape: nothing on disk, nothing in memory.
     */
    public static function test_clear_removes_the_file_and_the_memory()
    {
        self::__begin();

        try {
            Validation_Ledger::record_pass(self::RULE, 'hash-a');
            Validation_Ledger::flush();

            static::__assert_true(file_exists(self::$fixture_path), 'precondition: the file exists');

            Validation_Ledger::clear();

            static::__assert_false(file_exists(self::$fixture_path), 'clear() removed the file');
            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-a'),
                'clear() removed the in-memory verdicts too'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * A ledger written by an older shape is DISCARDED rather than migrated - the entire
     * content is a memo, and re-earning it costs one slow pass.
     */
    public static function test_unrecognized_shape_is_discarded()
    {
        self::__begin();

        try {
            file_put_contents(
                self::$fixture_path,
                "<?php\n\nreturn " . var_export(['version' => 999, 'rules' => [self::RULE => ['hash-a' => 1]]], true) . ";\n"
            );

            Validation_Ledger::_use_path_for_tests(self::$fixture_path);

            static::__assert_false(
                Validation_Ledger::has_passed(self::RULE, 'hash-a'),
                'a version this build does not understand vouches for nothing'
            );
        } finally {
            self::__end();
        }
    }
}
