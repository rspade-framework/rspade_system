<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DbReset\Php;

use App\RSpade\Commands\Database\Database_And_Storage_Reset_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE REFUSAL IS THE FEATURE, so it is the thing most heavily tested.
 *
 * rsx:database_and_storage_reset is not gated by environment or role - an operator can
 * always run it. What protects them is that the first invocation refuses and prints, in
 * plain language, exactly what they would be agreeing to. The change request states the
 * bar: naming the flag without explaining the consequence, and explaining the consequence
 * without naming the flag, both FAIL.
 *
 * Both halves of the gate are pure functions driven by handle(), so everything below is
 * the code that actually runs, with no database dropped and no file deleted.
 */
class Db_Reset_Refusal_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The roots the refusal quotes; arbitrary here, because the text is pure. */
    private const ROOTS = ['/srv/app/storage/uploads', '/srv/app/storage/rsx-thumbnails', '/srv/app/storage/rsx-renditions'];

    // ---------------------------------------------------------------------------------
    // THE GATE
    // ---------------------------------------------------------------------------------

    /** No flags at all is the accidental invocation the whole design exists for. */
    public static function test_no_flags_refuses_and_asks_for_yes()
    {
        static::__assert_equals(
            Database_And_Storage_Reset_Command::REFUSAL_UNCONFIRMED,
            Database_And_Storage_Reset_Command::refusal_reason(false, false, false)
        );
    }

    /**
     * --force alone is the operator who reached for the wrong flag. It is NOT a
     * confirmation and must never be treated as one.
     */
    public static function test_force_alone_is_not_a_confirmation()
    {
        static::__assert_equals(
            Database_And_Storage_Reset_Command::REFUSAL_UNCONFIRMED,
            Database_And_Storage_Reset_Command::refusal_reason(false, true, false)
        );
    }

    /** --yes on an ordinary box is the whole gate: proceed. */
    public static function test_yes_alone_proceeds_when_the_build_is_not_sealed()
    {
        static::__assert_null(Database_And_Storage_Reset_Command::refusal_reason(true, false, false));
    }

    /**
     * A sealed production build takes a second flag, and the refusal is reported as the
     * SEALED variant even when --yes was given - that is what makes the text able to
     * name --force.
     */
    public static function test_a_sealed_build_needs_force_as_well()
    {
        static::__assert_equals(
            Database_And_Storage_Reset_Command::REFUSAL_SEALED,
            Database_And_Storage_Reset_Command::refusal_reason(true, false, true)
        );
    }

    /**
     * Sealed with NEITHER flag refuses as SEALED, not as merely unconfirmed. One refusal
     * naming both flags beats two refusals naming one each.
     */
    public static function test_a_sealed_build_with_no_flags_reports_the_sealed_refusal()
    {
        static::__assert_equals(
            Database_And_Storage_Reset_Command::REFUSAL_SEALED,
            Database_And_Storage_Reset_Command::refusal_reason(false, false, true)
        );
    }

    /** Both flags on a sealed build is the sanctioned production invocation. */
    public static function test_yes_and_force_proceed_under_a_seal()
    {
        static::__assert_null(Database_And_Storage_Reset_Command::refusal_reason(true, true, true));
    }

    // ---------------------------------------------------------------------------------
    // THE TEXT
    // ---------------------------------------------------------------------------------

    /**
     * The four things the change request requires the operator to have read: what happens
     * to the database, what happens to the files, that there is no way back, and the flag
     * spelled exactly as it must be typed.
     */
    public static function test_the_unconfirmed_refusal_states_every_consequence()
    {
        $text = static::__text(Database_And_Storage_Reset_Command::REFUSAL_UNCONFIRMED);

        // The database, in the terms an operator recognises.
        static::__assert_contains('every table', $text);
        static::__assert_contains('DROPPED', $text);
        static::__assert_contains('every business record and every user account and login', $text);

        // The files: the actual bytes, not a cache.
        static::__assert_contains('every file under these roots is DELETED', $text);
        static::__assert_contains('ACTUAL UPLOADED BYTES', $text);
        static::__assert_contains('cache that rebuilds itself', $text);
        foreach (self::ROOTS as $root) {
            static::__assert_contains($root, $text);
        }

        // No way back from inside the application.
        static::__assert_contains('THERE IS NO UNDO', $text);
        static::__assert_contains('BEFORE running this command', $text);

        // The flag, spelled as typed.
        static::__assert_contains('php artisan rsx:database_and_storage_reset --yes', $text);
    }

    /** The database is named, so the operator can see WHICH database they would lose. */
    public static function test_the_refusal_names_the_database()
    {
        static::__assert_contains('some_application_db', static::__text(
            Database_And_Storage_Reset_Command::REFUSAL_UNCONFIRMED
        ));
    }

    /**
     * The sealed variant is the same consequence text PLUS the production statement and
     * the two-flag invocation. It never leaves the operator to discover --force by
     * failing a second time.
     */
    public static function test_the_sealed_refusal_adds_production_and_names_force()
    {
        $text = static::__text(Database_And_Storage_Reset_Command::REFUSAL_SEALED);

        // Everything the ordinary refusal says is still said.
        static::__assert_contains('every business record and every user account and login', $text);
        static::__assert_contains('ACTUAL UPLOADED BYTES', $text);
        static::__assert_contains('THERE IS NO UNDO', $text);

        // Plus the production statement and both flags, in one invocation.
        static::__assert_contains('SEALED PRODUCTION BUILD', $text);
        static::__assert_contains('--force', $text);
        static::__assert_contains('php artisan rsx:database_and_storage_reset --yes --force', $text);
    }

    /** The headline is the first line, so the caller can style it as the error. */
    public static function test_the_first_line_is_the_headline()
    {
        $lines = Database_And_Storage_Reset_Command::refusal_text(
            Database_And_Storage_Reset_Command::REFUSAL_UNCONFIRMED,
            'some_application_db',
            self::ROOTS
        );

        static::__assert_contains('[ERROR]', $lines[0]);
        static::__assert_contains('Nothing has been touched', $lines[0]);
    }

    // ---------------------------------------------------------------------------------
    // THE ANNOUNCEMENT
    // ---------------------------------------------------------------------------------

    /**
     * A destructive command owes the operator its blast radius in their scrollback:
     * tables, files, bytes, and where the schema was left.
     */
    public static function test_the_announcement_reports_the_blast_radius()
    {
        $line = Database_And_Storage_Reset_Command::announcement(62, 1743, 10485760, true);

        static::__assert_contains('[OK]', $line);
        static::__assert_contains('Dropped 62 tables', $line);
        static::__assert_contains('removed 1743 files', $line);
        static::__assert_contains('10 MB', $line);
        static::__assert_contains('migrated to the fresh-install state', $line);
    }

    /** --no-migrate leaves the schema dropped, and the announcement says so. */
    public static function test_the_announcement_says_when_the_schema_was_left_dropped()
    {
        $line = Database_And_Storage_Reset_Command::announcement(0, 0, 0, false);

        static::__assert_contains('schema left dropped (--no-migrate)', $line);
        static::__assert_true(
            !str_contains($line, 'fresh-install'),
            'a skipped migrate must never claim the fresh-install landing: ' . $line
        );
    }

    /** One of a thing is one, not "1 tables". */
    public static function test_the_announcement_is_singular_for_one()
    {
        $line = Database_And_Storage_Reset_Command::announcement(1, 1, 1, true);

        static::__assert_contains('Dropped 1 table,', $line);
        static::__assert_contains('removed 1 file ', $line);
    }

    /** The whole refusal as one string, for containment assertions. */
    private static function __text(string $reason): string
    {
        return implode("\n", Database_And_Storage_Reset_Command::refusal_text(
            $reason,
            'some_application_db',
            self::ROOTS
        ));
    }
}
