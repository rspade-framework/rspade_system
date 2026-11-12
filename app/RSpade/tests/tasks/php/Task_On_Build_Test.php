<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_On_Build;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_On_Build_Fixture_Service;

/**
 * #[On_Build] (Task_On_Build): the scheduler tick dispatches every #[On_Build] task once for
 * each build key it has not dispatched for, and records the key in _task_on_build.
 *
 *   - a database with no recorded key dispatches, and records the key;
 *   - the recorded key dispatches nothing;
 *   - a new key dispatches once, however many builds came between two ticks;
 *   - while the task system is off nothing is dispatched and the key stays as it was; the
 *     first call after it is back on dispatches;
 *   - the tick itself skips the step under the suite;
 *   - the declaration rules (Task_On_Build::declaration_problems(), TASK-ON-BUILD-01);
 *   - a wrong declaration fails the manifest build (TASK-ON-BUILD-01 is manifest-time).
 *
 * Driven through dispatch_for_build() with made-up keys. Under the suite no worker is spawned,
 * so a dispatch is a PENDING row. The calls commit, so this class provisions a clean baseline,
 * opts out of transactions, and every dispatching test starts from no recorded key and no
 * fixture runs (__fresh()).
 */
class Task_On_Build_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = Task_On_Build_Fixture_Service::class;

    public static function teardown()
    {
        Task::enable();
    }

    /** No recorded key and no fixture runs: every test starts here. */
    private static function __fresh(): void
    {
        DB::table('_task_on_build')->delete();
        static::__clear_runs();
    }

    private static function __clear_runs(): void
    {
        DB::table('_tasks')->where('class', static::FIX)->delete();
    }

    /** Pending runs of the fixture task. */
    private static function __fixture_runs(): int
    {
        return DB::table('_tasks')
            ->where('class', static::FIX)
            ->where('method', 'after_build')
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->count();
    }

    /** The fixture's entry in what one call dispatched, or null. */
    private static function __fixture_dispatch(array $dispatched): ?array
    {
        foreach ($dispatched as $row) {
            if ($row['class'] === 'Task_On_Build_Fixture_Service' && $row['method'] === 'after_build') {
                return $row;
            }
        }

        return null;
    }

    public static function test_the_fixture_is_a_declaration()
    {
        $found = false;
        foreach (Task_On_Build::declarations() as $declaration) {
            if ($declaration['class'] === 'Task_On_Build_Fixture_Service' && $declaration['member'] === 'after_build') {
                $found = true;
            }
        }

        static::__assert_true($found, 'the manifest attribute index lists the fixture');
    }

    public static function test_no_recorded_key_dispatches_and_records_it()
    {
        static::__fresh();
        static::__assert_null(Task_On_Build::recorded_build_key(), 'nothing recorded yet');

        $dispatched = Task_On_Build::dispatch_for_build('build-key-a');

        static::__assert_count(count(Task_On_Build::declarations()), $dispatched, 'one dispatch per declaration');
        $fixture = static::__fixture_dispatch($dispatched);
        static::__assert_not_null($fixture, 'the fixture was dispatched');
        static::__assert_equals(1, static::__fixture_runs(), 'one pending run');
        $run = DB::table('_tasks')->where('id', $fixture['run_id'])->first();
        static::__assert_equals(Task_Run_Model::ORIGIN_DISPATCHED, (int) $run->origin_id);
        static::__assert_equals([], json_decode($run->params, true), 'dispatched with no params');
        static::__assert_equals('build-key-a', Task_On_Build::recorded_build_key());
    }

    public static function test_the_recorded_key_dispatches_nothing()
    {
        static::__fresh();
        Task_On_Build::dispatch_for_build('build-key-a');
        static::__clear_runs();

        static::__assert_equals([], Task_On_Build::dispatch_for_build('build-key-a'));
        static::__assert_equals(0, static::__fixture_runs(), 'no run for a key already dispatched for');
        static::__assert_equals('build-key-a', Task_On_Build::recorded_build_key());
    }

    public static function test_a_new_key_dispatches_once_however_many_builds_came_between()
    {
        static::__fresh();
        Task_On_Build::dispatch_for_build('build-key-a');
        static::__clear_runs();

        // Builds b, c and d happened between two ticks; the tick only ever sees d.
        $dispatched = Task_On_Build::dispatch_for_build('build-key-d');
        static::__assert_not_null(static::__fixture_dispatch($dispatched), 'the new key dispatches');
        static::__assert_equals(1, static::__fixture_runs(), 'one run');
        static::__assert_equals('build-key-d', Task_On_Build::recorded_build_key());

        static::__assert_equals([], Task_On_Build::dispatch_for_build('build-key-d'), 'the next tick on the same build dispatches nothing');
        static::__assert_equals(1, static::__fixture_runs(), 'still one run');
    }

    public static function test_nothing_is_dispatched_while_the_task_system_is_off()
    {
        static::__fresh();
        Task_On_Build::dispatch_for_build('build-key-a');
        static::__clear_runs();

        Task::disable('Task_On_Build_Test');
        static::__assert_equals([], Task_On_Build::dispatch_for_build('build-key-b'), 'off: nothing dispatched');
        static::__assert_equals(0, static::__fixture_runs());
        static::__assert_equals('build-key-a', Task_On_Build::recorded_build_key(), 'off: the key is not recorded');

        Task::enable();
        $dispatched = Task_On_Build::dispatch_for_build('build-key-b');
        static::__assert_not_null(static::__fixture_dispatch($dispatched), 'on again: the build is dispatched for');
        static::__assert_equals(1, static::__fixture_runs());
        static::__assert_equals('build-key-b', Task_On_Build::recorded_build_key());
    }

    public static function test_the_tick_skips_the_step_under_the_suite()
    {
        static::__fresh();
        Artisan::call('rsx:task:process');

        static::__assert_equals(0, static::__fixture_runs(), 'no fixture run from the tick');
        static::__assert_null(Task_On_Build::recorded_build_key(), 'nothing recorded by the tick');
    }

    public static function test_declaration_rules()
    {
        $row = ['file' => 'x.php', 'class' => 'X_Service', 'member' => 'refresh', 'instances' => [[]]];
        $params = [
            ['name' => 'task', 'optional' => false],
            ['name' => 'params', 'optional' => true],
        ];
        $well_formed = [
            'attributes' => ['Task' => [['Refresh']], 'Exclusive' => [[]], 'On_Build' => [[]]],
            'parameters' => $params,
        ];

        static::__assert_equals([], Task_On_Build::declaration_problems($row, $well_formed), 'Task + Exclusive + On_Build is well formed');

        $debounced = $well_formed;
        $debounced['attributes'] = ['Task' => [['Refresh']], 'Debounce' => [[30]], 'On_Build' => [[]]];
        static::__assert_equals([], Task_On_Build::declaration_problems($row, $debounced), '#[Debounce] serves as well as #[Exclusive]');

        $cases = [
            'not a task' => ['attributes' => ['Exclusive' => [[]], 'On_Build' => [[]]], 'parameters' => $params],
            'neither exclusive nor debounce' => ['attributes' => ['Task' => [['Refresh']], 'On_Build' => [[]]], 'parameters' => $params],
            'arguments' => ['attributes' => ['Task' => [['Refresh']], 'Exclusive' => [[]], 'On_Build' => [['now']]], 'parameters' => $params],
            'declared twice' => ['attributes' => ['Task' => [['Refresh']], 'Exclusive' => [[]], 'On_Build' => [[], []]], 'parameters' => $params],
            'run as dispatcher' => ['attributes' => ['Task' => [['Refresh']], 'Exclusive' => [[]], 'On_Build' => [[]], 'Run_As_Dispatcher' => [[]]], 'parameters' => $params],
            'required params' => [
                'attributes' => $well_formed['attributes'],
                'parameters' => [['name' => 'task', 'optional' => false], ['name' => 'params', 'optional' => false]],
            ],
        ];
        foreach ($cases as $label => $method_info) {
            static::__assert_count(1, Task_On_Build::declaration_problems($row, $method_info), "refused: {$label}");
        }

        static::__assert_count(1, Task_On_Build::declaration_problems($row, null), 'refused: not a public static method');
        static::__assert_count(1, Task_On_Build::declaration_problems(['member' => null] + $row, null), 'refused: on a class');
    }

    public static function test_the_live_declarations_are_well_formed()
    {
        // Every declaration this tree indexes passes the rules the build asks.
        foreach (Task_On_Build::declarations() as $declaration) {
            $info = Manifest::get_file($declaration['file'])['public_static_methods'][$declaration['member']] ?? null;
            static::__assert_equals([], Task_On_Build::declaration_problems($declaration, $info), "{$declaration['class']}::{$declaration['member']} is well formed");
        }
    }

    /**
     * A wrong declaration is a manifest-build FATAL. A child builds the whole tree plus one
     * fixture service - an #[On_Build] task with neither #[Exclusive] nor #[Debounce] - into
     * a scratch build root, and the build fails naming the rule and the method; the same tree
     * with #[Exclusive] added builds.
     *
     * The fixture lives under a resource/ directory, which no build descends into: only this
     * child, which names it as an extra scan root, ever sees it.
     */
    public static function test_a_wrong_declaration_fails_the_manifest_build()
    {
        $tag = 'tob_' . getmypid();
        $tree = 'app/RSpade/temp/resource/' . $tag;
        $php = $tree . '/Tob_Probe_Service.php';
        $scratch = Rsx_Project_Paths::tmp_path('task-on-build-test/' . getmypid());

        $source = static function (string $extra_attribute) use ($php): string {
            return "<?php\n\nnamespace " . Rsx_Namespace::from_path($php) . ";\n\n"
                . "use App\\RSpade\\Core\\Service\\Rsx_Service_Abstract;\n"
                . "use App\\RSpade\\Core\\Task\\Task_Instance;\n\n"
                . "class Tob_Probe_Service extends Rsx_Service_Abstract\n{\n"
                . "    #[Task('Build-triggered probe')]\n"
                . $extra_attribute
                . "    #[On_Build]\n"
                . "    public static function probe(Task_Instance \$task, array \$params = [])\n    {\n        return null;\n    }\n}\n";
        };

        $build = static function () use ($tree, $scratch): array {
            $output = [];
            $exit = Rsx_Artisan::run('rsx:manifest:build', [
                '--_rsx-build-root=' . $scratch . '/build',
                '--_rsx-tmp-root=' . $scratch . '/tmp',
                '--_manifest-extra-scan-roots=' . $tree,
            ], $output);

            return [$exit, implode("\n", $output)];
        };

        $remove = static function () use ($tree, $scratch): void {
            foreach ([base_path($tree), $scratch] as $directory) {
                if (is_dir($directory)) {
                    rmdir_recursive($directory);
                }
            }
        };

        $remove();

        try {
            ensure_directory(base_path($tree));
            ensure_directory($scratch . '/build');
            ensure_directory($scratch . '/tmp');

            file_put_contents(base_path($php), $source(''));
            clearstatcache();

            [$exit, $output] = $build();
            static::__assert_true($exit !== 0, "an #[On_Build] task without #[Exclusive] or #[Debounce] fails the build:\n{$output}");
            static::__assert_contains('TASK-ON-BUILD-01', $output, 'naming the rule');
            static::__assert_contains('Tob_Probe_Service::probe', $output, 'and the method');

            file_put_contents(base_path($php), $source("    #[Exclusive]\n"));
            clearstatcache();

            [$exit, $output] = $build();
            static::__assert_equals(0, $exit, "the well-formed declaration builds:\n{$output}");
        } finally {
            $remove();
        }
    }
}
