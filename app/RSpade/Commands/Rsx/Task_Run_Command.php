<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Cli_Actor;
use App\RSpade\Core\Console\Rsx_Cli_Actor_Exception;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Dispatcher_Context;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * RSX Task Run Command
 * ====================
 *
 * Run a task inline from the command line. The run is recorded like any other (a _tasks row,
 * origin Inline) and #[Exclusive] / #[Debounce] hold: a single-instance task waits for a
 * running instance of its identity to finish first.
 *
 * OUTPUT CONTRACT (shared with every #[Command] alias - see rsx:man task_commands):
 *   STDOUT is the task's stdout: what it writes with $task->stdout() and what it echoes.
 *   STDERR is the task's stderr: $task->stderr(), every status() change, and the failure
 *          line of a run that fails. `-q` silences it; stdout is never silenced.
 *   EXIT CODE is the run's return code: 0 for success (the task returned null, true or 0),
 *          the code it returned for a failure (clamped to 1..255), 1 for false or a throw.
 *
 * WHO THE RUN ACTS AS: nobody, unless rsx:task:run is given --user=<id|email> [--site=<id>].
 * The pair means exactly what it means to rsx:ajax (Rsx_Cli_Actor - one resolution for both):
 * the login identity, the site (required when the identity has users rows on several sites),
 * and the identity's users row there, declared on this process's virtual session before the
 * task runs. No sign-in is created: get_session_id(), get_user_session_id() and
 * get_audit_session_id() stay null. The flags work for any task.
 *
 * A #[Run_As_Dispatcher] task runs as a person, so it needs them: without --user it is
 * REFUSED, with a message on STDERR naming the flags and exit 1; with them it runs inline as
 * that person - its writes, revisions and dispatched_by attributed to them, on their site.
 *
 * A #[Command] alias does NOT take --user / --site: every option of an alias is the task's
 * own parameter, and the build refuses #[Command] on a #[Run_As_Dispatcher] task
 * (TASK-RUN-AS-01).
 *
 * WHILE THE TASK SYSTEM IS OFF (php artisan rsx:tasks:disable) nothing runs: rsx:task:run and
 * every #[Command] alias queue their run, say once on STDERR that they are waiting for
 * `php artisan rsx:tasks:enable`, and wait - with no limit - until the system is back on;
 * the task then runs and the command exits with its code as always. There is no flag that
 * insists. A command killed while it waits leaves its run queued for a worker.
 *
 * USAGE:
 * php artisan rsx:task:run Service task_name                    # Basic execution
 * php artisan rsx:task:run Service task_name --param=value      # With parameters
 * php artisan rsx:task:run Service task_name --flag             # Boolean parameter
 * php artisan rsx:task:run Service task_name --user=a@b.com --site=1   # Acting as a user
 *
 * PARAMETER HANDLING:
 * All --key=value options become $params['key'] = 'value', except rsx:task:run's own --user
 * and --site
 * Boolean flags: --flag becomes $params['flag'] = true
 * JSON values: --data='{"foo":"bar"}' is parsed automatically
 */
class Task_Run_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:task:run
        {service : The RSX service name}
        {task : The task/method name}
        {--user= : Run as this login identity (login_users id or email); the site user is resolved from it}
        {--site= : Run under this site (required with --user when the login identity has users rows on several sites)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a task inline from the command line';

    /**
     * Configure the command to accept any options
     */
    protected function configure(): void
    {
        parent::configure();

        // Every --option is a task parameter, so none is validated.
        $this->ignoreValidationErrors();
    }

    /**
     * The service this invocation runs.
     *
     * An alias registered from a #[Command] attribute overrides this with its fixed
     * service (see Task_Alias_Command); rsx:task:run reads it from argv.
     */
    protected function resolve_service(): string
    {
        return (string) $this->argument('service');
    }

    /**
     * The task method this invocation runs. See resolve_service().
     */
    protected function resolve_task(): string
    {
        return (string) $this->argument('task');
    }

    /**
     * Whether this invocation reads --user / --site as who the run acts as. rsx:task:run does;
     * a #[Command] alias does not - every option it is given is the task's own parameter.
     */
    protected function takes_actor_flags(): bool
    {
        return true;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = $this->resolve_service();
        $task = $this->resolve_task();

        // The task's own streams. Set by the RUNNER and never by the task system itself:
        // Task::internal() called from application code prints to nobody's console.
        $streams = [STDOUT, $this->output->isQuiet() ? null : STDERR];

        try {
            $class = Task::resolve_task_class($service, $task);
        } catch (\Exception $e) {
            fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");

            return 1;
        }

        // Who the run acts as: --user / --site, resolved exactly as rsx:ajax resolves them.
        $actor = null;
        if ($this->takes_actor_flags()) {
            try {
                $actor = Rsx_Cli_Actor::resolve($this->option('user'), $this->option('site'));
            } catch (Rsx_Cli_Actor_Exception $e) {
                fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");

                return 1;
            }
        }

        // A #[Run_As_Dispatcher] task is a person's request run later: it acts as that person,
        // and from a command line the person is whoever --user names. With nobody named it is
        // refused here, before a row is written.
        if (Task_Dispatcher_Context::is_declared($class, $task) && ($actor['login_user_id'] ?? null) === null) {
            fwrite(STDERR, '[ERROR] ' . class_basename($class) . "::{$task} runs as the user who dispatched it (#[Run_As_Dispatcher]). "
                . "From the command line, say who it acts as: --user=<id|email> --site=<id>\n");

            return 1;
        }

        if ($actor !== null) {
            Rsx_Cli_Actor::establish($actor);
        }

        try {
            $run = Task::internal($service, $task, $this->task_params(), $streams);
        } catch (\Throwable $e) {
            // The task threw: the run is recorded FAILED and its failure line already went to
            // stderr.
            return 1;
        }

        $code = (int) $run->return_code;
        if ((int) $run->status_id === Task_Run_Model::STATUS_FAILED) {
            return $code >= 1 && $code <= 255 ? $code : 1;
        }

        // A run queued while the task system was off can end without this process: cancelled
        // from the queue, or killed under the worker that ran it.
        if (in_array((int) $run->status_id, [Task_Run_Model::STATUS_CANCELLED, Task_Run_Model::STATUS_KILLED], true)) {
            fwrite(STDERR, "Task {$run->id} was " . strtolower($run->status_id__label) . ($run->status_reason ? ": {$run->status_reason}" : '') . "\n");

            return 1;
        }

        return 0;
    }

    /**
     * Every --option on the command line except the framework's own, as task parameters.
     * Parsed from raw argv because Laravel validates options.
     */
    private function task_params(): array
    {
        $params = [];
        // 'force' is the maintenance-gate escape hatch (system/artisan refuses rsx:task:run
        // under maintenance mode unless --force is present). It is a gate token, never a task
        // parameter, so it must be skipped here or it would leak into every task's $params.
        $skip_builtins = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'force'];

        // rsx:task:run's own flags say who the run acts as; they are never a task parameter.
        if ($this->takes_actor_flags()) {
            $skip_builtins = array_merge($skip_builtins, ['user', 'site']);
        }

        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $option_string = substr($arg, 2);
            if (str_contains($option_string, '=')) {
                [$key, $value] = explode('=', $option_string, 2);
            } else {
                $key = $option_string;
                $value = true;
            }

            if (in_array($key, $skip_builtins, true)) {
                continue;
            }

            if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $params[$key] = $value;
        }

        return $params;
    }
}
