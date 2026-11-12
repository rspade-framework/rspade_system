<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task;

/**
 * #[On_Build] - a #[Task] dispatched once for every new build of the application.
 *
 * ```php
 * #[Task('Refresh the derived data')]
 * #[Exclusive]
 * #[Schedule('maintenance')]
 * #[On_Build]
 * public static function refresh(Task_Instance $task, array $params = []) { }
 * ```
 *
 * THE BUILD IS NEVER THE TRIGGER ITSELF. A manifest build may run with no database, inside a
 * web request, many times a minute in development, or while the task system is off - so
 * nothing is dispatched from rsx.rebuilt. The build only pokes the task daemon
 * (Manifest::__fire_lifecycle_events()), and the scheduler TICK (rsx:task:process) calls
 * dispatch_for_build() with Manifest::get_build_key(): when that key differs from the one
 * recorded in _task_on_build (row id 1), every #[On_Build] task is dispatched once, with no
 * params, and the new key is recorded. A burst of rebuilds between two ticks is one dispatch;
 * a database with no recorded key (a fresh install, a fresh deploy) dispatches.
 *
 * While the task system is off nothing is dispatched and the recorded key stays as it was,
 * so the first tick after rsx:tasks:enable dispatches for the build then running. The tick is
 * refused outright under maintenance mode, which therefore dispatches nothing either.
 *
 * DISPATCH, THEN RECORD. A dispatch that throws leaves the key unrecorded, so the next tick
 * tries again (loudly) rather than forgetting the build. Two ticks racing on one database can
 * both dispatch for one key; the declaration rules below require #[Exclusive] or #[Debounce],
 * whose coalescing makes that one pending run.
 *
 * THE DECLARATION RULES (declaration_problems(), asked by TASK-ON-BUILD-01, a manifest-build
 * FATAL - a build carrying a wrong declaration fails, so the tick only ever sees well-formed
 * ones and does not re-judge them): on a public static #[Task] method; no arguments, declared
 * once; #[Exclusive] or #[Debounce] beside it, so runs cannot pile up; every parameter after
 * the Task_Instance optional (the tick passes no params); never #[Run_As_Dispatcher] (a tick
 * has no dispatcher).
 *
 * Under the test suite the tick does not call this (a fixture's #[On_Build] would otherwise
 * queue runs into every class that ticks); a test drives dispatch_for_build() itself with the
 * keys it needs.
 */
class Task_On_Build
{
    /** The single _task_on_build row. */
    private const ROW_ID = 1;

    /**
     * Every #[On_Build] declaration in the manifest, as the attribute index records it:
     * ['file' => ..., 'class' => ?string (simple name), 'member' => ?string, 'instances' => [...]].
     *
     * @return array<int, array{file: string, class: ?string, member: ?string, instances: array}>
     */
    public static function declarations(): array
    {
        return Manifest::by_attribute('On_Build');
    }

    /**
     * The build key the #[On_Build] tasks were last dispatched for, or null when they never
     * were on this database.
     */
    public static function recorded_build_key(): ?string
    {
        $key = DB::table('_task_on_build')->where('id', self::ROW_ID)->value('build_key');

        return $key === null ? null : (string) $key;
    }

    /**
     * Dispatch every #[On_Build] task once if $build_key is not the key they were last
     * dispatched for, then record $build_key. Nothing happens - and nothing is recorded -
     * when no task declares #[On_Build], when the key is already recorded, or while the task
     * system is off.
     *
     * @param string $build_key The running build (Manifest::get_build_key())
     * @return array<int, array{class: string, method: string, run_id: int}> What was dispatched
     */
    public static function dispatch_for_build(string $build_key): array
    {
        $declarations = static::declarations();
        if ($declarations === []) {
            return [];
        }

        if (static::recorded_build_key() === $build_key) {
            return [];
        }

        if (!Task::is_enabled()) {
            return [];
        }

        $dispatched = [];
        foreach ($declarations as $declaration) {
            $run_id = Task::dispatch($declaration['class'], $declaration['member']);
            $dispatched[] = [
                'class' => $declaration['class'],
                'method' => $declaration['member'],
                'run_id' => $run_id,
            ];
        }

        // One statement: the row exists from the first dispatch on.
        $now = now()->format('Y-m-d H:i:s.v');
        DB::statement(
            'INSERT INTO _task_on_build (id, build_key, dispatched_at) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE build_key = VALUES(build_key), dispatched_at = VALUES(dispatched_at)',
            [self::ROW_ID, $build_key, $now]
        );

        return $dispatched;
    }

    /**
     * What is wrong with one #[On_Build] declaration, as [message, fix] pairs; empty when it
     * is well formed. Pure: TASK-ON-BUILD-01 asks it at every manifest build.
     *
     * @param array $declaration An attribute-index row (declarations())
     * @param array|null $method_info The method's public_static_methods record, or null when
     *                                the declaration is not on a public static method
     * @return array<int, array{0: string, 1: string}>
     */
    public static function declaration_problems(array $declaration, ?array $method_info): array
    {
        $member = $declaration['member'] ?? null;
        $name = ($declaration['class'] ?? '(unknown class)') . ($member !== null ? "::{$member}" : '');

        if ($member === null) {
            return [[
                "#[On_Build] on {$name} is on a class.",
                'Put #[On_Build] on the #[Task] method the build should dispatch.',
            ]];
        }

        if ($method_info === null) {
            return [[
                "#[On_Build] on {$name}, which is not a public static method.",
                'Put #[On_Build] on a public static #[Task] method of a service.',
            ]];
        }

        $problems = [];
        $attributes = $method_info['attributes'] ?? [];
        $instances = static::__instances($attributes, 'On_Build');

        if (static::__instances($attributes, 'Task') === []) {
            $problems[] = [
                "{$name} declares #[On_Build] but is not a #[Task].",
                'Add #[Task(\'<description>\')], or remove #[On_Build].',
            ];
        }

        if (count($instances) > 1) {
            $problems[] = [
                "Task {$name} declares #[On_Build] more than once.",
                'Keep a single #[On_Build].',
            ];
        }

        foreach ($instances as $arguments) {
            if ($arguments !== []) {
                $problems[] = [
                    "Task {$name} passes arguments to #[On_Build], which takes none.",
                    'Write #[On_Build] with no arguments.',
                ];
                break;
            }
        }

        if (static::__instances($attributes, 'Exclusive') === [] && static::__instances($attributes, 'Debounce') === []) {
            $problems[] = [
                "Task {$name} declares #[On_Build] without #[Exclusive] or #[Debounce]: builds in quick succession would pile up runs.",
                'Add #[Exclusive] (or #[Debounce]) beside #[On_Build].',
            ];
        }

        if (static::__instances($attributes, 'Run_As_Dispatcher') !== []) {
            $problems[] = [
                "Task {$name} declares #[On_Build] beside #[Run_As_Dispatcher]: the scheduler tick has no dispatcher, so every run would be refused.",
                'Remove #[Run_As_Dispatcher]; a build-triggered run is system work and runs as nobody.',
            ];
        }

        foreach (array_slice($method_info['parameters'] ?? [], 1) as $parameter) {
            if (empty($parameter['optional'])) {
                $problems[] = [
                    "Task {$name} declares #[On_Build] but its parameter \${$parameter['name']} is required: the tick dispatches it with no params.",
                    'Declare the task as (Task_Instance $task, array $params = []) and require no params.',
                ];
                break;
            }
        }

        return $problems;
    }

    /** The argument lists of every instance of a named attribute; empty when absent. */
    private static function __instances(array $attributes, string $name): array
    {
        foreach ($attributes as $attribute => $instances) {
            if ($attribute === $name || str_ends_with($attribute, '\\' . $name)) {
                return array_values($instances);
            }
        }

        return [];
    }
}
