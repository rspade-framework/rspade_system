<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Task::dispatch() inside a database transaction starts its worker when the transaction
 * COMMITS, never before: a worker is another process, and one started while the row is still
 * uncommitted finds nothing to claim, exits, and leaves the run waiting for the next tick. A
 * rolled-back dispatch starts nothing. Outside a transaction the worker starts at once.
 *
 * What is asserted is WHEN the worker process is started (the harness's detached-process
 * registry), not the run it then claims. Commits real rows, so this class opts out of
 * transactions.
 */
class Task_Dispatch_After_Commit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const FIX = Task_Exec_Fixture_Service::class;

    public static function teardown()
    {
        Task::spawn_workers(false);
        Rsx_Test_Detached_Processes::contain();
        static::__reset();
    }

    private static function __reset(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_tasks')->where('class', self::FIX)->delete();
    }

    private static function __detached_registry(): string
    {
        $path = Rsx_Project_Paths::test_detached_registry_file();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private static function __status(int $id): ?int
    {
        $status = DB::table('_tasks')->where('id', $id)->value('status_id');

        return $status === null ? null : (int) $status;
    }

    public static function test_a_dispatch_inside_a_transaction_starts_its_worker_after_the_commit()
    {
        static::__reset();
        Task::spawn_workers(true);

        $before = static::__detached_registry();
        DB::transaction(function () use ($before) {
            Task::dispatch('Task_Exec_Fixture_Service', 'marker_a');
            static::__assert_equals($before, static::__detached_registry(), 'no worker is started while the row is uncommitted');
        });

        static::__assert_true($before !== static::__detached_registry(), 'the commit started the worker');
    }

    public static function test_a_rolled_back_dispatch_starts_nothing()
    {
        static::__reset();
        Task::spawn_workers(true);

        $before = static::__detached_registry();
        DB::beginTransaction();
        $id = Task::dispatch('Task_Exec_Fixture_Service', 'marker_a');
        DB::rollBack();

        static::__assert_null(static::__status($id), 'the row went with the rollback');
        static::__assert_equals($before, static::__detached_registry(), 'and no worker was started for it');
    }

    public static function test_a_dispatch_outside_a_transaction_starts_its_worker_at_once()
    {
        static::__reset();
        Task::spawn_workers(true);

        $before = static::__detached_registry();
        Task::dispatch('Task_Exec_Fixture_Service', 'marker_a');
        static::__assert_true($before !== static::__detached_registry(), 'a worker was started by the dispatch itself');
    }
}
