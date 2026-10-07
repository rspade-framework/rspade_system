<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Migrate_Dump_Rollback;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Migrate_Dump_Rollback end to end against a real database: the dump, the restore, the run
 * record, and every crash a later run must recover from (or refuse).
 *
 * IT RUNS AGAINST ITS OWN SCRATCH DATABASE through a connection of its own, never the suite's
 * test database - its subject is a database being dropped and restored. The dump directory is
 * a sandbox under tmp/. Each test starts from the same small fixture: two tables joined by a
 * foreign key, a view and a few rows.
 *
 * "A later run" is a NEW instance over the same directory and database, which is exactly what
 * the next `php artisan migrate` is.
 */
class Dump_Rollback_Scratch_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SCRATCH = 'rspade_dump_rollback_scratch';

    private const CONNECTION = 'dump_rollback_scratch';

    public static function setup()
    {
        config(['database.connections.' . self::CONNECTION => array_merge((array) config('database.connections.mysql'), ['database' => self::SCRATCH])]);
    }

    public static function teardown()
    {
        DB::disconnect(self::CONNECTION);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
        rmdir_recursive(static::__dir());
    }

    private static function __dir(): string
    {
        return Rsx_Project_Paths::tmp_path('test-dump-rollback');
    }

    /** A fresh scratch database holding the fixture, and an empty dump directory. */
    private static function __fresh(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
        DB::connection('mysql')->statement('CREATE DATABASE `' . self::SCRATCH . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        if (is_dir(static::__dir())) {
            rmdir_recursive(static::__dir());
        }
        ensure_directory(static::__dir());

        $db = static::__db();
        $db->statement('CREATE TABLE parents (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB');
        $db->statement('CREATE TABLE children (id BIGINT AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT NOT NULL, label VARCHAR(50) NOT NULL,'
            . ' CONSTRAINT fk_children_parent FOREIGN KEY (parent_id) REFERENCES parents (id)) ENGINE=InnoDB');
        $db->statement('CREATE VIEW parent_names AS SELECT name FROM parents');
        $db->table('parents')->insert([['id' => 1, 'name' => 'alpha'], ['id' => 2, 'name' => 'beta']]);
        $db->table('children')->insert([['parent_id' => 1, 'label' => 'a1'], ['parent_id' => 2, 'label' => 'b1']]);
    }

    private static function __db(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }

    private static function __rollback(): Migrate_Dump_Rollback
    {
        return new Migrate_Dump_Rollback(self::CONNECTION, static::__dir());
    }

    /** What a failed migration did: a new table, a changed column, a changed row. */
    private static function __half_migrate(): void
    {
        $db = static::__db();
        $db->statement('CREATE TABLE added_by_migration (id BIGINT AUTO_INCREMENT PRIMARY KEY)');
        $db->statement('ALTER TABLE parents ADD COLUMN extra INT NULL');
        $db->table('parents')->where('id', 1)->update(['name' => 'changed']);
    }

    /** The fixture exactly as __fresh() left it - the state every restore must return to. */
    private static function __assert_fixture_state(string $message): void
    {
        DB::disconnect(self::CONNECTION);
        $db = static::__db();

        $objects = array_map(fn ($r) => $r->TABLE_NAME . ':' . $r->TABLE_TYPE, $db->select(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> ? ORDER BY TABLE_NAME',
            [self::SCRATCH, Migrate_Dump_Rollback::RUNS_TABLE]
        ));
        static::__assert_equals(['children:BASE TABLE', 'parent_names:VIEW', 'parents:BASE TABLE'], $objects, $message . ': the same tables and view');

        $columns = array_map(fn ($r) => $r->COLUMN_NAME, $db->select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [self::SCRATCH, 'parents']
        ));
        static::__assert_equals(['id', 'name'], $columns, $message . ': the same columns');
        static::__assert_equals(['alpha', 'beta'], $db->table('parents')->orderBy('id')->pluck('name')->all(), $message . ': the same rows');
        static::__assert_equals(2, $db->table('children')->count(), $message . ': the child rows');
    }

    private static function __files(): array
    {
        return glob(static::__dir() . '/*') ?: [];
    }

    // -------------------------------------------------------------------------

    public static function test_a_committed_run_keeps_its_changes_and_leaves_nothing()
    {
        static::__fresh();
        $rollback = static::__rollback();
        $token = $rollback->begin(1);

        static::__assert_true(is_file($rollback->marker_path()), 'the marker exists while migrating');
        static::__assert_equals(Migrate_Dump_Rollback::STATUS_MIGRATING, $rollback->row_status($token));

        static::__half_migrate();
        $rollback->commit();

        static::__assert_equals([], static::__files(), 'no marker and no dump after a commit');
        static::__assert_equals(Migrate_Dump_Rollback::STATUS_COMPLETED, $rollback->row_status($token));
        static::__assert_equals('changed', static::__db()->table('parents')->where('id', 1)->value('name'), 'the migration stands');
        static::__assert_false(static::__rollback()->has_leftovers(), 'a later run finds nothing to do');
    }

    public static function test_a_failed_run_is_restored_and_recorded()
    {
        static::__fresh();
        $rollback = static::__rollback();
        $token = $rollback->begin(1);
        static::__half_migrate();

        $rollback->rollback('a migration failed');

        static::__assert_fixture_state('after the rollback');
        static::__assert_equals([], static::__files(), 'no marker and no dump after a rollback');
        static::__assert_equals(Migrate_Dump_Rollback::STATUS_ROLLED_BACK, $rollback->row_status($token));
        static::__assert_false(static::__rollback()->has_leftovers());
    }

    public static function test_a_run_that_crashed_mid_migration_is_restored_by_the_next()
    {
        static::__fresh();
        $token = static::__rollback()->begin(1);
        static::__half_migrate();
        // The process dies here: no commit, no rollback.

        $later = static::__rollback();
        static::__assert_true($later->has_leftovers());
        static::__assert_equals(Migrate_Dump_Rollback::ACTION_RESTORE, $later->resolve_leftovers());

        static::__assert_fixture_state('after the later run restored');
        static::__assert_equals([], static::__files());
        static::__assert_equals(Migrate_Dump_Rollback::STATUS_ROLLED_BACK, $later->row_status($token));
    }

    public static function test_a_run_that_crashed_mid_restore_is_restored_by_the_next()
    {
        static::__fresh();
        $rollback = static::__rollback();
        $rollback->begin(1);
        static::__half_migrate();

        // The restore began - phase written, the database half emptied - and the process died.
        $marker = json_decode(file_get_contents($rollback->marker_path()), true);
        $marker['phase'] = Migrate_Dump_Rollback::PHASE_RESTORING;
        file_put_contents($rollback->marker_path(), json_encode($marker));
        static::__db()->statement('SET FOREIGN_KEY_CHECKS=0');
        static::__db()->statement('DROP TABLE parents');
        static::__db()->statement('DROP TABLE ' . Migrate_Dump_Rollback::RUNS_TABLE);
        static::__db()->statement('SET FOREIGN_KEY_CHECKS=1');

        static::__assert_equals(Migrate_Dump_Rollback::ACTION_RESTORE, static::__rollback()->resolve_leftovers());
        static::__assert_fixture_state('after the later run restored again');
        static::__assert_equals([], static::__files());
    }

    /**
     * THE PROPERTY THE DESIGN EXISTS FOR: a successful run whose cleanup was interrupted
     * leaves a marker and a dump behind, and the next run must DELETE them - never restore
     * over the successful migration.
     */
    public static function test_a_successful_run_with_interrupted_cleanup_is_never_restored()
    {
        static::__fresh();
        $rollback = static::__rollback();
        $token = $rollback->begin(1);
        static::__half_migrate();

        // commit()'s first step only - the row says completed - then the process died before
        // the marker and the dump were deleted.
        static::__db()->table(Migrate_Dump_Rollback::RUNS_TABLE)->where('token', $token)->update(['status' => Migrate_Dump_Rollback::STATUS_COMPLETED]);
        static::__assert_count(2, static::__files(), 'marker and dump survive');

        static::__assert_equals(Migrate_Dump_Rollback::ACTION_CLEANUP, static::__rollback()->resolve_leftovers());
        static::__assert_equals([], static::__files(), 'the leftovers are deleted');
        static::__assert_equals('changed', static::__db()->table('parents')->where('id', 1)->value('name'), 'the successful migration stands');
        static::__assert_equals(1, (int) static::__db()->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [self::SCRATCH, 'added_by_migration']
        )->n, 'including the table it created');
    }

    public static function test_a_lost_dump_is_refused_until_abandoned()
    {
        static::__fresh();
        $token = static::__rollback()->begin(1);
        static::__half_migrate();
        foreach (static::__files() as $file) {
            unlink($file);
        }

        $later = static::__rollback();
        static::__assert_throws(\RuntimeException::class, fn () => $later->resolve_leftovers());
        static::__assert_equals('changed', static::__db()->table('parents')->where('id', 1)->value('name'), 'a refusal changes nothing');

        $discarded = $later->abandon();
        static::__assert_count(1, $discarded, 'the run record');
        static::__assert_equals(Migrate_Dump_Rollback::STATUS_ABANDONED, $later->row_status($token));
        static::__assert_equals(Migrate_Dump_Rollback::ACTION_NONE, static::__rollback()->resolve_leftovers(), 'settled');
    }

    public static function test_a_corrupted_dump_is_refused()
    {
        static::__fresh();
        $rollback = static::__rollback();
        $rollback->begin(1);
        static::__half_migrate();
        $marker = json_decode(file_get_contents($rollback->marker_path()), true);
        file_put_contents($marker['dump_file'], 'not the dump');

        static::__assert_throws(\RuntimeException::class, fn () => static::__rollback()->resolve_leftovers());
        static::__assert_equals('changed', static::__db()->table('parents')->where('id', 1)->value('name'), 'nothing was restored from it');
    }

    public static function test_a_marker_this_database_has_no_record_of_is_refused()
    {
        static::__fresh();
        $token = static::__rollback()->begin(1);
        // The database was replaced since: the run's row is not in it.
        static::__db()->table(Migrate_Dump_Rollback::RUNS_TABLE)->where('token', $token)->delete();

        static::__assert_throws(\RuntimeException::class, fn () => static::__rollback()->resolve_leftovers());
        static::__assert_true(is_file(static::__rollback()->marker_path()), 'the marker is kept for the operator');
    }

    public static function test_files_no_run_recorded_are_removed()
    {
        static::__fresh();
        file_put_contents(static::__dir() . '/dump_deadbeef.sql.gz.partial', 'interrupted dump');

        static::__assert_equals(Migrate_Dump_Rollback::ACTION_NONE, static::__rollback()->resolve_leftovers());
        static::__assert_equals([], static::__files());
    }

    /** An account that may not drop and recreate the database: emptied object by object. */
    public static function test_the_restore_empties_the_database_when_it_cannot_recreate_it()
    {
        static::__fresh();
        $rollback = new class(self::CONNECTION, static::__dir()) extends Migrate_Dump_Rollback {
            protected function drop_and_recreate(array $marker): bool
            {
                return false;
            }
        };
        $rollback->begin(1);
        static::__half_migrate();

        $rollback->rollback('a migration failed');

        static::__assert_fixture_state('after the object-by-object restore');
    }
}
