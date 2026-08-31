<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Commands\Rsx\Task_Run_Command;

/**
 * The one artisan command class every #[Command] alias is an instance of.
 *
 * It is Task_Run_Command with the service and method already decided, so an alias IS
 * `rsx:task:run <service> <method>` - the same argv parsing, the same --debug, the same
 * output contract, the same exit codes, running the same code path. Nothing about the
 * behaviour of a task changes because it acquired a name.
 *
 * It lives HERE and not in Commands/ deliberately: Kernel::commands() hands that directory
 * to Laravel's load(), which instantiates every command class it finds through the
 * container. This one takes constructor arguments - it is built by Task_Command_Registrar,
 * once per baked table row, and never discovered.
 */
#[Instantiatable]
class Task_Alias_Command extends Task_Run_Command
{
    /** The service class this alias runs. */
    private string $task_service;

    /** The static task method this alias runs. */
    private string $task_method;

    /**
     * @param string $name Artisan command name, e.g. 'myapp:import'
     * @param string $description The line `php artisan list` prints
     * @param string $service Service basename or FQCN
     * @param string $method Static #[Task] method
     */
    public function __construct(string $name, string $description, string $service, string $method)
    {
        // Set before parent::__construct(): Command's constructor builds the definition
        // from $signature. The two arguments rsx:task:run declares are gone - they are
        // what this class fixes - and everything else about the invocation is identical.
        $this->signature = $name . ' {--debug : Wrap output in formatted response (success, result)}';
        $this->description = $description;

        $this->task_service = $service;
        $this->task_method = $method;

        parent::__construct();
    }

    protected function resolve_service(): string
    {
        return $this->task_service;
    }

    protected function resolve_task(): string
    {
        return $this->task_method;
    }
}
