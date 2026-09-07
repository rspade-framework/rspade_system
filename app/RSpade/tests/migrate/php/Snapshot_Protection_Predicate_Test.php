<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the predicate that decides whether `migrate` may perform the stop-MySQL /
 * snapshot / restore-on-failure dance at all.
 *
 * The mechanism is physical - stop a LOCAL supervised mysqld, copy /var/lib/mysql, and on
 * failure wipe that directory and copy the backup back - so it is only correct where all
 * three of these hold: development mode, the RSpade DEVELOPMENT container (the PRODUCTION
 * container carries /.rspade_container but ships mysql-client only), and a LOCAL database
 * host. Anywhere else the ROLLBACK is the danger: it would repopulate a datadir that is
 * not the live database and report a successful rollback while the real database stayed
 * broken. A false rollback is worse than none.
 *
 * Each of the three inputs is an overridable probe, plus two invocation-level suppressors
 * (--framework-only, --_no-snapshot). Every test presents a synthetic environment through
 * an anonymous subclass and drives the REAL predicate. No container, no database, no
 * process.
 */
class Snapshot_Protection_Predicate_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Build a Maint_Migrate whose environment probes return a synthetic environment.
     *
     * @param array $db the default connection's config array (host / unix_socket)
     */
    private static function __harness(
        bool $development,
        bool $container,
        bool $dev_container,
        array $db = ['host' => '127.0.0.1'],
        bool $framework_only = false,
        bool $no_snapshot_flag = false
    ): Maint_Migrate {
        return new class($development, $container, $dev_container, $db, $framework_only, $no_snapshot_flag) extends Maint_Migrate {
            public bool $__development;
            public bool $__container;
            public bool $__dev_container;
            public array $__db;
            public bool $__framework_only;
            public bool $__no_snapshot_flag;

            public function __construct(
                bool $development,
                bool $container,
                bool $dev_container,
                array $db,
                bool $framework_only,
                bool $no_snapshot_flag
            ) {
                $this->__development = $development;
                $this->__container = $container;
                $this->__dev_container = $dev_container;
                $this->__db = $db;
                $this->__framework_only = $framework_only;
                $this->__no_snapshot_flag = $no_snapshot_flag;
            }

            // Public seams so the test can drive the protected predicate directly.
            public function __available(): bool
            {
                return $this->snapshot_protection_available();
            }

            public function __unavailable_reason(): ?string
            {
                return $this->snapshot_protection_unavailable_reason();
            }

            public function __engaged(): bool
            {
                return $this->snapshot_protection_engaged();
            }

            public function __skipped_reasons(): array
            {
                return $this->snapshot_skipped_reasons();
            }

            public function __is_local_database(): bool
            {
                return $this->is_local_database();
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

            protected function is_framework_only_run(): bool
            {
                return $this->__framework_only;
            }

            protected function is_snapshot_suppressed_by_flag(): bool
            {
                return $this->__no_snapshot_flag;
            }
        };
    }

    /**
     * A harness whose only variable is the configured connection.
     */
    private static function __host_harness(array $db): Maint_Migrate
    {
        return static::__harness(true, true, true, $db);
    }

    // -------------------------------------------------------------------------
    // The truth table: all three conditions, then each one falsified alone.
    // -------------------------------------------------------------------------

    public static function test_all_three_conditions_true_is_protected()
    {
        $harness = static::__harness(true, true, true, ['host' => '127.0.0.1']);

        static::__assert_true($harness->__available(), 'dev mode + dev container + local host must be protected');
        static::__assert_true($harness->__unavailable_reason() === null, 'a protected environment has no reason');
        static::__assert_true($harness->__engaged(), 'nothing suppresses this run');
        static::__assert_equals([], $harness->__skipped_reasons());
    }

    public static function test_not_development_mode_runs_bare()
    {
        $harness = static::__harness(false, true, true);

        static::__assert_false($harness->__available());
        static::__assert_false($harness->__engaged());
        static::__assert_contains('not development', (string) $harness->__unavailable_reason());
    }

    public static function test_not_an_rspade_container_runs_bare()
    {
        $harness = static::__harness(true, false, false);

        static::__assert_false($harness->__available());
        static::__assert_contains('/.rspade_container absent', (string) $harness->__unavailable_reason());
    }

    public static function test_production_target_container_runs_bare()
    {
        // THE case this predicate exists for: /.rspade_container present, but the
        // production image ships mysql-client only - no mysqld to stop, no datadir to copy.
        $harness = static::__harness(true, true, false);

        static::__assert_false($harness->__available());
        static::__assert_false($harness->__engaged());

        $reason = (string) $harness->__unavailable_reason();
        static::__assert_contains('/.rspade_container_dev absent', $reason);
        static::__assert_contains('mysql-client only', $reason);
    }

    public static function test_external_database_host_runs_bare()
    {
        $harness = static::__harness(true, true, true, ['host' => 'db.internal.example.com']);

        static::__assert_false($harness->__available());
        static::__assert_false($harness->__engaged());

        $reason = (string) $harness->__unavailable_reason();
        static::__assert_contains('db.internal.example.com', $reason);
        static::__assert_contains('not', $reason);
    }

    // -------------------------------------------------------------------------
    // Invocation-level suppressors - independent of the environment.
    // -------------------------------------------------------------------------

    public static function test_framework_only_run_is_not_protected()
    {
        $harness = static::__harness(true, true, true, ['host' => 'localhost'], true, false);

        // The MECHANISM is available; this RUN simply does not want it.
        static::__assert_true($harness->__available());
        static::__assert_false($harness->__engaged());
        static::__assert_contains('--framework-only', implode("\n", $harness->__skipped_reasons()));
    }

    public static function test_no_snapshot_flag_is_not_protected()
    {
        $harness = static::__harness(true, true, true, ['host' => 'localhost'], false, true);

        static::__assert_true($harness->__available());
        static::__assert_false($harness->__engaged());
        static::__assert_contains(Maint_Migrate::NO_SNAPSHOT_FLAG, implode("\n", $harness->__skipped_reasons()));
    }

    public static function test_every_applicable_reason_is_reported()
    {
        // An operator who cannot tell WHICH condition disabled the undo finds out by
        // needing it, so all of them are printed - not just the first.
        $harness = static::__harness(true, true, false, ['host' => 'remote.example.com'], true, true);

        $reasons = $harness->__skipped_reasons();
        static::__assert_equals(3, count($reasons), 'framework-only + the flag + the environment');

        $joined = implode("\n", $reasons);
        static::__assert_contains('--framework-only', $joined);
        static::__assert_contains(Maint_Migrate::NO_SNAPSHOT_FLAG, $joined);
        static::__assert_contains('/.rspade_container_dev absent', $joined);
    }

    // -------------------------------------------------------------------------
    // The host matcher itself.
    // -------------------------------------------------------------------------

    public static function test_loopback_spellings_are_local()
    {
        foreach (['localhost', 'LOCALHOST', ' 127.0.0.1 ', '::1', '[::1]'] as $host) {
            static::__assert_true(
                static::__host_harness(['host' => $host])->__is_local_database(),
                'host ' . var_export($host, true) . ' must be local'
            );
        }
        static::__pass();
    }

    public static function test_a_configured_unix_socket_is_local()
    {
        // A socket connection cannot reach another host, so the host value is irrelevant -
        // and it is checked first precisely because the connection ignores that value.
        static::__assert_true(
            static::__host_harness(['host' => 'db.example.com', 'unix_socket' => '/var/run/mysqld/mysqld.sock'])
                ->__is_local_database()
        );
    }

    public static function test_hosts_that_merely_begin_with_a_loopback_literal_are_remote()
    {
        // Prefix matching here would hand the rollback a remote database.
        $remote = [
            '127.0.0.1.attacker.example',
            'localhost.example.com',
            'notlocalhost',
            '127.0.0.10',
            '1270.0.1',
            '127.1.2.3',
            '::11',
            'db.example.com',
            '',
        ];

        foreach ($remote as $host) {
            static::__assert_false(
                static::__host_harness(['host' => $host])->__is_local_database(),
                'host ' . var_export($host, true) . ' must NOT be treated as local'
            );
        }
        static::__pass();
    }

    public static function test_an_empty_socket_value_does_not_make_a_remote_host_local()
    {
        static::__assert_false(
            static::__host_harness(['host' => 'db.example.com', 'unix_socket' => ''])->__is_local_database()
        );
    }
}
