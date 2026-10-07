<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Service;

use App\RSpade\Core\Task\Task_Instance;

/**
 * Base service class for all RSX services
 *
 * Services house background tasks that can be executed via CLI, queued, or scheduled.
 * All RSX services should extend this class and use standard OOP patterns.
 * Tasks are defined using #[Task] attributes on static methods.
 */
#[Monoprogenic]
abstract class Rsx_Service_Abstract
{
    /**
     * Pre-task hook, called before every run of every task of this service - by a worker,
     * inline or from a command alike. Override it to add pre-task logic.
     *
     * Return null to let the task run. Any other value ends the run WITHOUT running the task,
     * with that value as the task's return (the return contract, Task_Run_Outcome: true or 0
     * succeeds, false or another integer fails with that code). A throw fails the run.
     *
     * @param Task_Instance $task The run's handle (report through it like the task would)
     * @param array $params Task parameters
     * @return mixed|null
     */
    public static function pre_task(Task_Instance $task, array $params = [])
    {
        // Default implementation does nothing
        // Override in child classes to add authentication, validation, logging, etc.
        return null;
    }

    /**
     * Get a parameter value with optional default
     *
     * @param array $params Parameters array
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    protected static function __param($params, $key, $default = null)
    {
        return $params[$key] ?? $default;
    }

    /**
     * Check if a parameter exists
     *
     * @param array $params Parameters array
     * @param string $key Parameter key
     * @return bool
     */
    protected static function __has_param($params, $key)
    {
        return isset($params[$key]);
    }
}
