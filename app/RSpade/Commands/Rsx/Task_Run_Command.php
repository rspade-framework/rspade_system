<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Task\Task;
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
 * USAGE:
 * php artisan rsx:task:run Service task_name                    # Basic execution
 * php artisan rsx:task:run Service task_name --param=value      # With parameters
 * php artisan rsx:task:run Service task_name --flag             # Boolean parameter
 *
 * PARAMETER HANDLING:
 * All --key=value options become $params['key'] = 'value'
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
        {task : The task/method name}';

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
            Task::resolve_task_class($service, $task);
        } catch (\Exception $e) {
            fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");

            return 1;
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
