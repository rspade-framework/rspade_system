<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use App\RSpade\Core\Task\Task;

/**
 * RSX Task Run Command
 * ====================
 *
 * PURPOSE:
 * Execute task methods from CLI with JSON output.
 * Designed for background jobs, seeders, maintenance, and automation.
 *
 * OUTPUT CONTRACT (shared with every #[Command] alias - see rsx:man task_commands):
 * STDOUT is the VALUE and nothing else: the task's return value as JSON (--debug wraps it
 * as {success, result}; a failure prints the JSON error and exits 1). It is written at
 * quiet verbosity so `-q` suppresses the narration without swallowing the result.
 * STDERR is the NARRATION: every $task->info()/error()/debug() line, live and unbuffered,
 * formatted exactly as the _tasks.logs lines. `-q`/`--quiet` turns it off, and
 * `2>/dev/null` discards it - so `php artisan ... | jq` works with no flags at all.
 *
 * USAGE EXAMPLES:
 * php artisan rsx:task:run Service task_name                    # Basic execution
 * php artisan rsx:task:run Service task_name --param=value      # With parameters
 * php artisan rsx:task:run Service task_name --flag             # Boolean parameter
 * php artisan rsx:task:run Service task_name --debug            # Wrapped response
 *
 * PARAMETER HANDLING:
 * All --key=value options become $params['key'] = 'value'
 * Boolean flags: --flag becomes $params['flag'] = true
 * JSON values: --data='{"foo":"bar"}' is parsed automatically
 *
 * ERROR HANDLING:
 * All errors return JSON (never throws to stderr)
 * {"success":false, "error":"Error message", "error_type":"exception_type"}
 *
 * USE CASES:
 * - Run database seeders
 * - Execute maintenance tasks
 * - Generate reports
 * - Process background jobs
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
        {--debug : Wrap output in formatted response (success, result)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute tasks from CLI with JSON output';

    /**
     * Get the console command arguments and options.
     * Allow any options to be passed through to tasks.
     */
    protected function getOptions()
    {
        // Parse parent options first
        $options = parent::getOptions();

        // Allow any additional options by not validating them
        return $options;
    }

    /**
     * Configure the command to accept any options
     */
    protected function configure(): void
    {
        parent::configure();

        // Tell Symfony to ignore unknown options
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
        $debug_mode = $this->option('debug');

        // STDERR carries the task's narration, live. The sink is set by the RUNNER and
        // never by the task system itself: Task::internal() called from application code
        // prints nothing to anybody's console.
        $console_sink = $this->output->isQuiet() ? null : STDERR;

        // Parse all remaining options as task parameters
        // We need to manually parse from $_SERVER['argv'] because Laravel validates options
        $params = [];
        // 'force' is the maintenance-gate escape hatch (system/artisan refuses rsx:task:run
        // under maintenance mode unless --force is present). It is a gate token, never a task
        // parameter, so it must be skipped here or it would leak into every task's $params.
        $skip_builtins = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'debug', 'force'];

        // Parse from raw argv
        $argv = $_SERVER['argv'] ?? [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            // Check if this is an option
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            // Remove -- prefix
            $option_string = substr($arg, 2);

            // Parse key=value or just key
            if (str_contains($option_string, '=')) {
                [$key, $value] = explode('=', $option_string, 2);
            } else {
                $key = $option_string;
                $value = true; // Boolean flag
            }

            // Skip built-in Laravel options
            if (in_array($key, $skip_builtins)) {
                continue;
            }

            // Try to parse JSON values
            if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            // Store parameter
            $params[$key] = $value;
        }

        try {
            // Execute the task
            $response = Task::internal($service, $task, $params, $console_sink);

            // Output response based on mode
            if ($debug_mode) {
                $wrapped_response = Task::format_task_response($response);
                $this->output_json($wrapped_response);
            } else {
                // Just output the raw response
                $this->output_json($response);
            }

        } catch (\Exception $e) {
            $this->output_json_error($e->getMessage(), get_class($e), [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Output JSON to stdout (pretty-printed)
     */
    protected function output_json($data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // VERBOSITY_QUIET here means "print at every verbosity, quiet included". The value
        // IS the command's primary result: -q silences the narration, never the answer.
        $this->output->writeln($json, OutputInterface::VERBOSITY_QUIET);
    }

    /**
     * Output error as JSON
     */
    protected function output_json_error(string $message, string $error_type, array $extra = []): void
    {
        $error = [
            'success' => false,
            'error' => $message,
            'error_type' => $error_type,
        ];

        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }

        $this->output_json($error);
    }
}
