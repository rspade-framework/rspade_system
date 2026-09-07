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
 * WHETHER A RESET IS PROTECTED, and what it tells the operator when it is not.
 *
 * The reset extends Maint_Migrate so it uses migrate's OWN predicate: there is exactly
 * one answer on any box to "can the datadir be snapshotted here", and one place it is
 * decided. This class proves the reset inherits that answer unchanged, and that a reset
 * running bare can still enumerate every reason - because an operator who believes they
 * are protected and is not is the failure the reasons exist to prevent.
 *
 * Same technique as migrate/php/Snapshot_Protection_Predicate_Test: an anonymous subclass
 * presents a synthetic environment through the probe seams and the REAL predicate runs.
 * No container, no database, no process, and above all no reset.
 */
class Db_Reset_Snapshot_Decision_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The protected case: development mode, an RSpade DEVELOPMENT container, a local
     * database. The reset takes the snapshot before it drops anything.
     */
    public static function test_a_dev_container_reset_is_snapshot_protected()
    {
        $command = static::__harness(true, true, true, ['host' => '127.0.0.1']);

        static::__assert_true($command->__engaged(), 'a development-container reset must snapshot');
        static::__assert_count(0, $command->__skipped_reasons());
    }

    /**
     * A production-target container ships mysql-client only - there is no local mysqld to
     * stop and no datadir to copy. The reset runs BARE and says which condition disabled
     * the undo.
     */
    public static function test_a_production_target_container_runs_bare_and_says_why()
    {
        $command = static::__harness(true, true, false, ['host' => '127.0.0.1']);

        static::__assert_false($command->__engaged(), 'no dev container means no snapshot');

        $reasons = static::__reasons($command);
        static::__assert_contains('production-target RSpade container', $reasons);
        static::__assert_contains('mysql-client only', $reasons);
    }

    /**
     * Pointed at an external database host, /var/lib/mysql is not the database being
     * reset. Snapshotting it would protect the wrong bytes and restoring it would report
     * a rollback that never happened.
     */
    public static function test_an_external_database_host_runs_bare_and_names_the_host()
    {
        $command = static::__harness(true, true, true, ['host' => 'db.internal.example']);

        static::__assert_false($command->__engaged());
        static::__assert_contains('db.internal.example', static::__reasons($command));
    }

    /**
     * The case the change request cares about most: a reset on a production box. It is
     * allowed (with both flags), it is NOT protected, and it says so plainly rather than
     * letting the operator assume the datadir snapshot has their back.
     */
    public static function test_a_production_mode_reset_runs_bare_and_names_the_mode()
    {
        $command = static::__harness(false, false, false, ['host' => '127.0.0.1']);

        static::__assert_false($command->__engaged());
        static::__assert_contains('not development', static::__reasons($command));
    }

    /**
     * The reset declares no --framework-only option of its own, and a total data reset is
     * never a schema-only subset - so that inherited suppressor must never fire, and must
     * never reach Symfony asking for an option this command does not have.
     */
    public static function test_the_reset_is_never_a_framework_only_run()
    {
        $command = static::__harness(true, true, true, ['host' => '127.0.0.1']);

        static::__assert_true(
            !str_contains(static::__reasons($command), 'framework-only'),
            'a reset must never classify itself as a framework-only run'
        );
    }

    /** Every skipped reason as one string, for containment assertions. */
    private static function __reasons(Database_And_Storage_Reset_Command $command): string
    {
        return implode("\n", $command->__skipped_reasons());
    }

    /**
     * A reset command whose environment probes describe a synthetic machine.
     */
    private static function __harness(
        bool $development,
        bool $container,
        bool $dev_container,
        array $db
    ): Database_And_Storage_Reset_Command {
        return new class($development, $container, $dev_container, $db) extends Database_And_Storage_Reset_Command {
            public bool $__development;
            public bool $__container;
            public bool $__dev_container;
            public array $__db;

            public function __construct(bool $development, bool $container, bool $dev_container, array $db)
            {
                $this->__development = $development;
                $this->__container = $container;
                $this->__dev_container = $dev_container;
                $this->__db = $db;
            }

            /** Public seams so the test can drive the protected predicates directly. */
            public function __engaged(): bool
            {
                return $this->snapshot_protection_engaged();
            }

            public function __skipped_reasons(): array
            {
                return $this->snapshot_skipped_reasons();
            }

            protected function probe_is_development(): bool
            {
                return $this->__development;
            }

            protected function probe_is_rspade_container(): bool
            {
                return $this->__container;
            }

            protected function probe_is_rspade_dev_container(): bool
            {
                return $this->__dev_container;
            }

            protected function configured_database_connection(): array
            {
                return $this->__db;
            }
        };
    }
}
