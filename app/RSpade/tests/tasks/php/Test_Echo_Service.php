<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Test-only service used by the tasks/ concern tests.
 *
 * No real #[Schedule] attribute - tasks are never auto-run by the cron processor.
 * Methods are trivial and side-effect-free.
 *
 * NOTE: Under a test run the manifest indexes app/RSpade/tests (Manifest::
 * scan_directories()), so this class IS discovered by Task::internal() / Task::dispatch().
 * A build that is not a test run does not index it at all, which is the point: a fixture
 * task must not exist on a served site.
 *
 * The #[Command] attributes give the tasks/cli tests REAL registered artisan aliases to
 * drive - one that writes to both streams, one that throws, one that returns a chosen exit
 * code - without touching a template feature. They exist only in the monorepo: bin/publish
 * ships no framework tests, so no release registers rsx_test:*.
 */
class Test_Echo_Service extends Rsx_Service_Abstract
{
    /**
     * Writes its params to stdout as JSON ({"echo": params}), narrates on stderr, and reports
     * the same value as its state.
     */
    #[Task('Echo params back (test-only)')]
    #[Command('rsx_test:echo', 'Echo params back (framework test fixture)')]
    public static function echo_params(Task_Instance $task, array $params = [])
    {
        $task->stderr('echo_params started');
        $task->stderr('received ' . count($params) . ' param(s)');
        $task->stdout(json_encode(['echo' => $params], JSON_UNESCAPED_SLASHES));
        $task->state(['echo' => $params]);

        return null;
    }

    /**
     * A task that always throws, to test failure handling.
     * Safe: throws before any side effects.
     */
    #[Task('Always throw an exception (test-only)')]
    #[Command('rsx_test:fail', 'Always throw (framework test fixture)')]
    public static function always_fail(Task_Instance $task, array $params = [])
    {
        $task->stderr('about to throw');
        throw new \Exception('deliberate test failure');
    }

    /**
     * Returns the integer --code it was given: the exit code a console runner exits with.
     */
    #[Task('Return a chosen exit code (test-only)')]
    #[Command('rsx_test:exit', 'Return the --code given (framework test fixture)')]
    public static function exit_with(Task_Instance $task, array $params = [])
    {
        return (int) ($params['code'] ?? 0);
    }
}
