<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DbCache\Php;

use App\RSpade\Commands\Database\Db_Rebuild_Provision_Cache_Snapshot_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE RECOVERY STATE MACHINE of rsx:db:rebuild_provision_cache_snapshot, exercised as pure logic.
 *
 * The command destroys a database and a blob store on purpose, so its recovery path is
 * the part that must be right and the part that is hardest to reach by actually
 * interrupting it. Db_Rebuild_Provision_Cache_Snapshot_Command::__recovery_plan() is that path expressed as a
 * pure function of four state flags, and both step 7 and the interrupt handler execute
 * exactly what it returns - so what these tests assert is what runs.
 *
 * Each test names the interruption point it stands for.
 */
class Db_Cache_Recovery_Plan_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The plan for one point on the timeline.
     *
     * @return array<int, string>
     */
    protected static function __plan(
        bool $live_dump_ok,
        bool $blob_backup_ok,
        bool $db_is_build_state,
        bool $blob_store_is_build_state
    ): array {
        $command = new Db_Rebuild_Provision_Cache_Snapshot_Command();
        $command->__set_recovery_state($live_dump_ok, $blob_backup_ok, $db_is_build_state, $blob_store_is_build_state);

        return $command->__recovery_plan();
    }

    /**
     * Interrupted in step 1 (entering maintenance) or early in step 2, BEFORE the dump
     * finished: nothing was backed up and nothing was destroyed. The only entry is the
     * dump deletion, which the caller recognizes as "nothing happened" because no dump
     * was ever completed.
     */
    public static function test_nothing_touched_yet_plans_no_restore()
    {
        $plan = static::__plan(false, false, false, false);

        static::__assert_equals([Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_DELETE_LIVE_DUMP], $plan);
    }

    /**
     * Interrupted in step 2 AFTER the dump completed but BEFORE the blob store was moved:
     * the database is still the live one, so nothing is recreated - the dump is simply
     * discarded.
     */
    public static function test_dump_taken_but_nothing_destroyed_only_discards_the_dump()
    {
        $plan = static::__plan(true, false, false, false);

        static::__assert_equals([Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_DELETE_LIVE_DUMP], $plan);
    }

    /**
     * Interrupted in step 2 after the blob store was moved aside but before the database
     * was dropped: only the blob half is undone, and the dump is still deleted last.
     */
    public static function test_blob_store_moved_aside_is_moved_back()
    {
        $plan = static::__plan(true, true, false, true);

        static::__assert_equals([
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_CLEAR_BUILD_BLOB_STORE,
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_MOVE_BLOB_BACKUP_BACK,
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_DELETE_LIVE_DUMP,
        ], $plan);
    }

    /**
     * Interrupted anywhere in steps 3 through 7 - the ordinary case. Everything is undone,
     * in the reverse order it was done, and the backup is released last.
     */
    public static function test_full_build_state_plans_the_complete_restore()
    {
        $plan = static::__plan(true, true, true, true);

        static::__assert_equals([
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_RECREATE_DATABASE,
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_RESTORE_DATABASE,
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_CLEAR_BUILD_BLOB_STORE,
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_MOVE_BLOB_BACKUP_BACK,
            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_DELETE_LIVE_DUMP,
        ], $plan);
    }

    /**
     * THE DUMP IS ALWAYS DELETED LAST. That single ordering is what makes every state
     * above re-runnable: while the dump is on disk, the next run can finish the restore.
     */
    public static function test_the_dump_is_always_the_last_action()
    {
        foreach ([false, true] as $live_dump_ok) {
            foreach ([false, true] as $blob_backup_ok) {
                foreach ([false, true] as $db_is_build_state) {
                    foreach ([false, true] as $blob_store_is_build_state) {
                        $plan = static::__plan($live_dump_ok, $blob_backup_ok, $db_is_build_state, $blob_store_is_build_state);

                        static::__assert_equals(
                            Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_DELETE_LIVE_DUMP,
                            end($plan),
                            'the dump must be released last for every state combination'
                        );
                        static::__assert_equals(
                            1,
                            count(array_keys($plan, Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_DELETE_LIVE_DUMP, true)),
                            'the dump is released exactly once'
                        );
                    }
                }
            }
        }
    }

    /**
     * A dropped database is NEVER restored from a dump that does not exist. The pairing is
     * the invariant that makes an interrupt mid-dump safe: the command does not drop
     * anything until the dump is complete, so this combination should be unreachable - and
     * if it is ever reached, the plan recreates an empty database rather than pretending
     * to restore one.
     */
    public static function test_a_missing_dump_never_produces_a_restore_action()
    {
        $plan = static::__plan(false, true, true, true);

        static::__assert_true(
            !in_array(Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_RESTORE_DATABASE, $plan, true),
            'no dump means no restore action'
        );
        static::__assert_equals(Db_Rebuild_Provision_Cache_Snapshot_Command::ACTION_RECREATE_DATABASE, $plan[0]);
    }
}
