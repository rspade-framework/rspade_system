<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Harness\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;

/**
 * A detached process a test starts is contained by the harness: registered before the spawn
 * returns, and waited for - until it exits, with no deadline - at the class boundary.
 */
class Detached_Process_Containment_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * dispatch_detached() under the suite records its child in the registry before it returns.
     */
    public static function test_a_detached_spawn_under_the_suite_is_registered()
    {
        Rsx_Test_Detached_Processes::contain();

        Rsx_Artisan::dispatch_detached('--version');

        $registry = (string) @file_get_contents(Rsx_Project_Paths::test_detached_registry_file());

        // register() skips a child that is already gone, but --version boots the whole
        // framework before it can exit, and contention only makes that slower.
        static::__assert_true(
            (bool) preg_match('/^\d+ \d+$/m', $registry),
            'the registry records the detached child as "<pid> <start time>": ' . $registry
        );

        Rsx_Test_Detached_Processes::contain();

        static::__assert_equals('', (string) @file_get_contents(Rsx_Project_Paths::test_detached_registry_file()), 'contain() leaves the registry empty');
    }

    /**
     * contain() returns only once a registered process has exited.
     */
    public static function test_contain_returns_only_after_the_process_exits()
    {
        Rsx_Test_Detached_Processes::contain();

        // A process that runs for a while and then ends on its own - the shape of a task
        // worker draining its queue. The one second is the subject's own lifetime, not a wait.
        $process = proc_open(['bash', '-c', 'sleep 1'], [], $pipes);
        static::__assert_true(is_resource($process), 'the fixture process started');

        $pid = proc_get_status($process)['pid'];
        Rsx_Test_Detached_Processes::register($pid);

        Rsx_Test_Detached_Processes::contain();

        $status = proc_get_status($process);
        static::__assert_false($status['running'], 'the registered process had exited when contain() returned');

        proc_close($process);
    }

    /**
     * A registered pid now held by a different process (same pid, other start time) is not
     * waited for: identity is the pid AND the kernel start time.
     */
    public static function test_a_reused_pid_is_not_mistaken_for_the_registered_process()
    {
        Rsx_Test_Detached_Processes::contain();

        // This very process is running, so waiting on it would never end - unless the start
        // time recorded for it is not its own, which is exactly the pid-reuse case.
        file_put_contents(
            Rsx_Project_Paths::test_detached_registry_file(),
            getmypid() . " 1\n",
            FILE_APPEND
        );

        Rsx_Test_Detached_Processes::contain();

        static::__assert_equals('', (string) @file_get_contents(Rsx_Project_Paths::test_detached_registry_file()), 'the stale entry was consumed');
    }
}
