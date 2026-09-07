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
 * The two #[Command] attributes give the tasks/cli tests a pair of REAL registered artisan
 * aliases to drive - one that returns a value and one that throws - without touching a
 * database or a template feature. They exist only in the monorepo: bin/publish ships no
 * framework tests, so no release registers rsx_test:*.
 */
class Test_Echo_Service extends Rsx_Service_Abstract
{
    /**
     * A simple task that echoes its params back as the return value.
     * Safe: pure data, no DB writes, no side effects.
     */
    #[Task('Echo params back (test-only)')]
    #[Command('rsx_test:echo', 'Echo params back (framework test fixture)')]
    public static function echo_params(Task_Instance $task, array $params = []): array
    {
        $task->info('echo_params started');
        $task->info('received ' . count($params) . ' param(s)');
        return ['echo' => $params];
    }

    /**
     * A task that always throws, to test failure handling.
     * Safe: throws before any side effects.
     */
    #[Task('Always throw an exception (test-only)')]
    #[Command('rsx_test:fail', 'Always throw (framework test fixture)')]
    public static function always_fail(Task_Instance $task, array $params = []): array
    {
        $task->info('about to throw');
        throw new \Exception('deliberate test failure');
    }
}
