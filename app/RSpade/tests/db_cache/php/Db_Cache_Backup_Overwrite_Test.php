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
 * THE NO-OVERWRITE RULE of rsx:db:rebuild_provision_cache_snapshot.
 *
 * A live backup already on disk was left there by an interrupted run and holds THIS
 * developer's data; the database and blob store sitting in their place hold that run's
 * cache-build residue. Overwriting the backup with the residue destroys the only copy,
 * so the rule is: an existing backup is kept and used, and only the MISSING half is
 * taken. The two halves are independent.
 *
 * Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision() is that rule as a pure function of what is on
 * disk, and step 2 drives from it - so the rule asserted here is the rule that runs.
 */
class Db_Cache_Backup_Overwrite_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The clean case: no backups anywhere, so both halves are taken. */
    public static function test_a_clean_tree_takes_both_backups()
    {
        $decision = Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision(false, false, true);

        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_TAKE_DUMP]);
        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_MOVE_BLOB_STORE]);
        static::__assert_false($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_REUSE_EXISTING]);
    }

    /** Both backups present: neither is touched, and the operator is told they are reused. */
    public static function test_existing_backups_are_never_overwritten()
    {
        $decision = Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision(true, true, true);

        static::__assert_false(
            $decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_TAKE_DUMP],
            'an existing live dump must not be overwritten'
        );
        static::__assert_false(
            $decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_MOVE_BLOB_STORE],
            'an existing blob backup must not be overwritten'
        );
        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_REUSE_EXISTING]);
    }

    /** Only the dump survived a previous run: the blob half is taken, the dump is kept. */
    public static function test_only_the_missing_blob_half_is_taken()
    {
        $decision = Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision(true, false, true);

        static::__assert_false($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_TAKE_DUMP]);
        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_MOVE_BLOB_STORE]);
        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_REUSE_EXISTING]);
    }

    /**
     * Only the blob backup survived: the dump is taken, and the blob store at the root is
     * left alone precisely because it is residue, not live data.
     */
    public static function test_only_the_missing_dump_half_is_taken()
    {
        $decision = Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision(false, true, true);

        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_TAKE_DUMP]);
        static::__assert_false(
            $decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_MOVE_BLOB_STORE],
            'the residue store must never be moved over the live blob backup'
        );
        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_REUSE_EXISTING]);
    }

    /**
     * An application that has never uploaded anything has no blob store on disk at all.
     * There is nothing to move aside, and that is not a failure.
     */
    public static function test_no_blob_store_on_disk_moves_nothing()
    {
        $decision = Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision(false, false, false);

        static::__assert_true($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_TAKE_DUMP]);
        static::__assert_false($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_MOVE_BLOB_STORE]);
        static::__assert_false($decision[Db_Rebuild_Provision_Cache_Snapshot_Command::DECISION_REUSE_EXISTING]);
    }
}
