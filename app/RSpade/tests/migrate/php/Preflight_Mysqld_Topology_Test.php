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
 * Pins B-47: Maint_Migrate::preflight_mysqld_topology() must FAIL LOUD, before anything is
 * stopped, when the mysqld process topology is not the single supervised instance the datadir
 * snapshot assumes - instead of letting `supervisorctl stop mysql` leave a stray mysqld serving
 * and the snapshot silently time out (which in the field masqueraded as a generic 60s timeout
 * and lost migrations for days).
 *
 * The preflight's decision is pure given four probes (running mysqld pids, supervisor's managed
 * pid, ancestry, command line). Each test overrides those probes with an anonymous subclass to
 * present a synthetic topology, then drives the REAL classification + diagnostic. No process is
 * spawned and no database is touched.
 */
class Preflight_Mysqld_Topology_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Build a Maint_Migrate whose process probes return a synthetic topology.
     *
     * @param int[] $running       pids reported by `pgrep -x mysqld`
     * @param int|null $supervised pid supervisor manages (null = mysql not RUNNING)
     * @param array $ancestry      list of [child_pid, ancestor_pid] pairs treated as related
     *                             (the mysqld_safe-wrapper case)
     */
    private static function __harness(array $running, ?int $supervised, array $ancestry = []): Maint_Migrate
    {
        return new class($running, $supervised, $ancestry) extends Maint_Migrate {
            public array $__running;
            public ?int $__supervised;
            public array $__ancestry;

            public function __construct(array $running, ?int $supervised, array $ancestry)
            {
                $this->__running = $running;
                $this->__supervised = $supervised;
                $this->__ancestry = $ancestry;
            }

            // Public seam so the test can drive the protected preflight directly.
            public function __run_preflight(): void
            {
                $this->preflight_mysqld_topology();
            }

            protected function _running_mysqld_pids(): array
            {
                return $this->__running;
            }

            protected function _supervised_mysqld_pid(): ?int
            {
                return $this->__supervised;
            }

            protected function _pid_has_ancestor(int $pid, int $ancestor): bool
            {
                return in_array([$pid, $ancestor], $this->__ancestry, true);
            }

            protected function _pid_command_line(int $pid): string
            {
                return '/usr/sbin/mysqld [pid ' . $pid . ']';
            }
        };
    }

    // -------------------------------------------------------------------------
    // Good shapes - the preflight returns silently.
    // -------------------------------------------------------------------------

    public static function test_zero_mysqld_is_not_the_rogue_condition()
    {
        // A cold datadir is consistent to copy; the flow starts mysql again at the end.
        static::__harness([], null)->__run_preflight();
        static::__pass();
    }

    public static function test_single_supervised_exec_case_passes()
    {
        // Supervisor execs mysqld directly, so its managed pid IS the mysqld pid.
        static::__harness([100], 100)->__run_preflight();
        static::__pass();
    }

    public static function test_single_supervised_wrapper_child_passes()
    {
        // mysqld_safe case: supervisor tracks the wrapper, mysqld is its descendant.
        static::__harness([200], 150, [[200, 150]])->__run_preflight();
        static::__pass();
    }

    // -------------------------------------------------------------------------
    // Bad shapes - the preflight throws a B-47 diagnostic naming the stray.
    // -------------------------------------------------------------------------

    public static function test_a_stray_second_mysqld_aborts_and_names_the_stray()
    {
        $e = static::__assert_throws(
            \Exception::class,
            fn() => static::__harness([100, 999], 100)->__run_preflight(),
            'B-47'
        );

        // The supervised one is tagged, the stray one is tagged, and the remediation
        // gives the exact kill command for the stray (999), not the supervised (100).
        static::__assert_contains('[supervised] pid 100', $e->getMessage());
        static::__assert_contains('[STRAY]', $e->getMessage());
        static::__assert_contains('sudo kill 999', $e->getMessage());
        static::__assert_true(
            strpos($e->getMessage(), 'sudo kill 100') === false,
            'the supervised instance must never be offered up for killing'
        );
    }

    public static function test_single_unsupervised_mysqld_aborts()
    {
        // A lone mysqld while supervisor reports mysql NOT running: stopping the program
        // would not stop the serving instance.
        $e = static::__assert_throws(
            \Exception::class,
            fn() => static::__harness([777], null)->__run_preflight(),
            'B-47'
        );

        static::__assert_contains('does NOT report a RUNNING mysql program', $e->getMessage());
        static::__assert_contains('sudo kill 777', $e->getMessage());
    }

    public static function test_serving_pid_differs_from_supervised_pid_aborts()
    {
        // Supervisor manages a pid, but the running mysqld is a different, unrelated one.
        $e = static::__assert_throws(
            \Exception::class,
            fn() => static::__harness([777], 555)->__run_preflight(),
            'B-47'
        );

        static::__assert_contains('[STRAY]', $e->getMessage());
        static::__assert_contains('Supervisor manages mysqld pid 555', $e->getMessage());
    }
}
