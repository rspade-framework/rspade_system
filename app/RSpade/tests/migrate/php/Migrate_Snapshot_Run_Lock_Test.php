<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Commands\Migrate\Migrate_Restore_Command;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ONE SNAPSHOT-PROTECTED MIGRATE AT A TIME, AND AN INTERRUPTED ONE IS RESTORED, NOT DISCARDED.
 *
 * PINNED:
 *  - a snapshot-protected run (and migrate:restore) REFUSES while another holds
 *    Maint_Migrate::SNAPSHOT_RUN_LOCK, and runs nothing;
 *  - with the lock free it runs and releases the lock on the way out;
 *  - holding the lock, a migration flag on disk means an INTERRUPTED run: create_snapshot()
 *    restores that run's backup and clears it before taking a new snapshot - it never
 *    deletes the backup unrestored;
 *  - a restore that fails leaves the flag and the backup in place and takes no snapshot.
 *
 * Nothing here stops MySQL or touches a datadir: the restore, the cleanup and the mysqld
 * preflight are replaced by recorders (the preflight throws, which is where a real snapshot
 * would start stopping mysqld), and the flag is a scratch file.
 */
class Migrate_Snapshot_Run_Lock_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A Maint_Migrate (or migrate:restore) whose outside world records instead of acting.
     */
    private static function __harness(string $flag_file, bool $restore_succeeds = true, bool $as_restore_command = false): object
    {
        $recorder = new class {
            public array $events = [];
            public array $lines = [];
        };

        if ($as_restore_command) {
            return new class($recorder, $flag_file) extends Migrate_Restore_Command {
                public function __construct(public object $r, private string $flag)
                {
                }

                protected function migrating_flag_file(): string { return $this->flag; }
                protected function restore_locked(): int { $this->r->events[] = 'restore_locked'; return 0; }
                public function info($string, $verbosity = null) { $this->r->lines[] = (string) $string; }
                public function warn($string, $verbosity = null) { $this->r->lines[] = (string) $string; }
                public function error($string, $verbosity = null) { $this->r->lines[] = (string) $string; }
            };
        }

        return new class($recorder, $flag_file, $restore_succeeds) extends Maint_Migrate {
            public function __construct(public object $r, private string $flag, private bool $restore_ok)
            {
            }

            public function __run_with_snapshot(): int { return $this->run_with_snapshot(); }
            public function __create_snapshot(): bool { return $this->create_snapshot(); }

            protected function migrating_flag_file(): string { return $this->flag; }
            protected function run_with_snapshot_locked(): int { $this->r->events[] = 'run_locked'; return 0; }
            protected function rollback_snapshot(): bool { $this->r->events[] = 'restore'; return $this->restore_ok; }
            protected function cleanup_migration_mode(): void
            {
                $this->r->events[] = 'cleanup';
                @unlink($this->flag);
            }
            protected function purge_schema_state(): void { $this->r->events[] = 'purge'; }
            protected function preflight_mysqld_topology(): void
            {
                $this->r->events[] = 'snapshot_begins';
                throw new \Exception('the harness stops before mysqld would be touched');
            }
            protected function shell_exec_privileged(string $command): ?string { $this->r->events[] = 'shell'; return ''; }
            public function info($string, $verbosity = null) { $this->r->lines[] = (string) $string; }
            public function warn($string, $verbosity = null) { $this->r->lines[] = (string) $string; }
            public function error($string, $verbosity = null) { $this->r->lines[] = (string) $string; }
        };
    }

    private static function __scratch_flag(bool $present): string
    {
        $path = sys_get_temp_dir() . '/migrate_run_lock_' . uniqid() . '.migrating';

        if ($present) {
            file_put_contents($path, json_encode(['started_at' => '2026-10-29T00:00:00Z']));
        }

        return $path;
    }

    private static function __lock_held(): bool
    {
        return RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, Maint_Migrate::SNAPSHOT_RUN_LOCK)['writer_active'];
    }

    public static function test_a_second_run_refuses_while_the_lock_is_held()
    {
        $harness = static::__harness(static::__scratch_flag(false));
        $held = RsxLocks::named_write_lock(Maint_Migrate::SNAPSHOT_RUN_LOCK);

        try {
            static::__assert_equals(1, $harness->__run_with_snapshot(), 'refused');
        } finally {
            RsxLocks::release_lock($held);
        }

        static::__assert_equals([], $harness->r->events, 'nothing ran');
        static::__assert_contains('Another migrate', implode("\n", $harness->r->lines));
    }

    public static function test_a_free_lock_runs_and_is_released()
    {
        $harness = static::__harness(static::__scratch_flag(false));

        static::__assert_equals(0, $harness->__run_with_snapshot());
        static::__assert_equals(['run_locked'], $harness->r->events);
        static::__assert_false(static::__lock_held(), 'released on the way out');
    }

    public static function test_an_interrupted_run_is_restored_before_a_new_snapshot()
    {
        $flag = static::__scratch_flag(true);
        $harness = static::__harness($flag);

        static::__assert_false($harness->__create_snapshot(), 'the harness stops at the new snapshot');

        static::__assert_equals(
            ['restore', 'cleanup', 'purge', 'snapshot_begins'],
            array_values(array_filter($harness->r->events, fn ($e) => $e !== 'shell')),
            'the interrupted run is restored and cleared FIRST, then the new snapshot starts'
        );
        static::__assert_false(file_exists($flag), 'the flag went with the restored backup');
        static::__assert_contains('interrupted', implode("\n", $harness->r->lines));
    }

    public static function test_a_failed_restore_keeps_the_flag_and_takes_no_snapshot()
    {
        $flag = static::__scratch_flag(true);
        $harness = static::__harness($flag, false);

        static::__assert_false($harness->__create_snapshot());
        static::__assert_equals(['restore'], $harness->r->events, 'no cleanup, no new snapshot');
        static::__assert_true(file_exists($flag), 'the flag stays, pointing at the only backup');
        static::__assert_contains('migrate:restore', implode("\n", $harness->r->lines));

        @unlink($flag);
    }

    public static function test_no_flag_means_no_restore()
    {
        $harness = static::__harness(static::__scratch_flag(false));

        $harness->__create_snapshot();

        static::__assert_false(in_array('restore', $harness->r->events, true));
        static::__assert_true(in_array('snapshot_begins', $harness->r->events, true));
    }

    public static function test_migrate_restore_refuses_while_the_lock_is_held()
    {
        $harness = static::__harness(static::__scratch_flag(true), true, true);
        $held = RsxLocks::named_write_lock(Maint_Migrate::SNAPSHOT_RUN_LOCK);

        try {
            static::__assert_equals(1, $harness->handle(), 'refused');
        } finally {
            RsxLocks::release_lock($held);
        }

        static::__assert_equals([], $harness->r->events);

        static::__assert_equals(0, $harness->handle(), 'runs once the lock is free');
        static::__assert_equals(['restore_locked'], $harness->r->events);
    }
}
