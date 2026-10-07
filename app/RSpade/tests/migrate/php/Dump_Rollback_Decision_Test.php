<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Core\Database\Migrate_Dump_Rollback;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Migrate_Dump_Rollback::decide() - what a migrate does with what an earlier --dump-rollback
 * run left, from the evidence alone. Pure: every row of the class docblock's table, plus the
 * one property the design exists for - a run whose row says it finished NEVER restores,
 * whatever the marker and the dump say.
 */
class Dump_Rollback_Decision_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const DB = 'app_db';

    private static function __marker(string $phase, string $database = self::DB): array
    {
        return ['token' => 'tok', 'phase' => $phase, 'database' => $database, 'dump_file' => '/x/dump.sql.gz', 'dump_sha256' => str_repeat('a', 64)];
    }

    public static function test_nothing_left_is_nothing_to_do()
    {
        static::__assert_equals(Migrate_Dump_Rollback::ACTION_NONE, Migrate_Dump_Rollback::decide(null, false, null, [], self::DB));
    }

    public static function test_a_run_recorded_as_migrating_with_no_marker_is_refused()
    {
        static::__assert_equals(Migrate_Dump_Rollback::REFUSE_DUMP_LOST, Migrate_Dump_Rollback::decide(null, false, null, ['tok'], self::DB));
    }

    public static function test_a_finished_run_only_cleans_up_never_restores()
    {
        foreach ([Migrate_Dump_Rollback::STATUS_COMPLETED, Migrate_Dump_Rollback::STATUS_ROLLED_BACK, Migrate_Dump_Rollback::STATUS_ABANDONED] as $status) {
            foreach ([Migrate_Dump_Rollback::PHASE_MIGRATING, Migrate_Dump_Rollback::PHASE_RESTORING] as $phase) {
                static::__assert_equals(
                    Migrate_Dump_Rollback::ACTION_CLEANUP,
                    Migrate_Dump_Rollback::decide(static::__marker($phase), true, $status, [], self::DB),
                    "row {$status}, phase {$phase}, dump intact: cleanup only"
                );
            }
        }
    }

    public static function test_a_run_that_crashed_mid_migration_is_restored()
    {
        static::__assert_equals(Migrate_Dump_Rollback::ACTION_RESTORE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_MIGRATING), true, Migrate_Dump_Rollback::STATUS_MIGRATING, ['tok'], self::DB));
    }

    public static function test_a_run_that_crashed_mid_restore_is_restored_again()
    {
        static::__assert_equals(Migrate_Dump_Rollback::ACTION_RESTORE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_RESTORING), true, null, [], self::DB), 'the restore wiped the row');
        static::__assert_equals(Migrate_Dump_Rollback::ACTION_RESTORE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_RESTORING), true, Migrate_Dump_Rollback::STATUS_MIGRATING, ['tok'], self::DB), 'the restore had not begun dropping');
    }

    public static function test_an_unusable_dump_is_refused()
    {
        static::__assert_equals(Migrate_Dump_Rollback::REFUSE_DUMP_UNUSABLE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_MIGRATING), false, Migrate_Dump_Rollback::STATUS_MIGRATING, ['tok'], self::DB));
        static::__assert_equals(Migrate_Dump_Rollback::REFUSE_DUMP_UNUSABLE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_RESTORING), false, null, [], self::DB));
    }

    public static function test_a_marker_this_database_has_no_record_of_is_refused()
    {
        static::__assert_equals(Migrate_Dump_Rollback::REFUSE_FOREIGN_DATABASE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_MIGRATING), true, null, [], self::DB));
    }

    public static function test_a_marker_for_another_database_is_refused()
    {
        static::__assert_equals(Migrate_Dump_Rollback::REFUSE_OTHER_DATABASE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_MIGRATING, 'other_db'), true, Migrate_Dump_Rollback::STATUS_MIGRATING, ['tok'], self::DB));
        static::__assert_equals(Migrate_Dump_Rollback::REFUSE_OTHER_DATABASE, Migrate_Dump_Rollback::decide(static::__marker(Migrate_Dump_Rollback::PHASE_MIGRATING, 'other_db'), true, Migrate_Dump_Rollback::STATUS_COMPLETED, [], self::DB), 'even a finished run is not ours to clean up');
    }

    public static function test_a_missing_or_unrunnable_client_program_is_named()
    {
        $unusable = Migrate_Dump_Rollback::unusable_client_binaries(['rsx_no_such_program_dump_rollback', 'false']);

        static::__assert_contains('not installed', $unusable['rsx_no_such_program_dump_rollback'] ?? '', 'a program not on PATH');
        static::__assert_contains('does not run', $unusable['false'] ?? '', 'a program whose --version fails');
        static::__assert_equals([], Migrate_Dump_Rollback::unusable_client_binaries(), 'this box has the real client programs');
    }

    public static function test_an_unknown_phase_throws()
    {
        static::__assert_throws(\RuntimeException::class, fn () => Migrate_Dump_Rollback::decide(static::__marker('sideways'), true, Migrate_Dump_Rollback::STATUS_MIGRATING, ['tok'], self::DB));
    }
}
