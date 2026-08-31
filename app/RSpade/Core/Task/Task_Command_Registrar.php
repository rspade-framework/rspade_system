<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task_Alias_Command;

/**
 * Registers one artisan command per #[Command]-annotated #[Task].
 *
 * Called ONCE, from App\Console\Kernel::commands(). It reads the table
 * Task_Command_ManifestSupport baked at build time and adds a Task_Alias_Command for each
 * row - it discovers nothing, validates nothing and reflects over nothing. Every rule
 * about what a command may be named was enforced at manifest-build time, loudly.
 *
 * A tree with no manifest yet has no table, so nothing is registered and artisan boots
 * normally: the aliases appear on the next build, along with everything else the manifest
 * provides.
 */
class Task_Command_Registrar
{
    /**
     * Add every declared alias to the console application.
     */
    public static function register(): void
    {
        foreach (Manifest::get_task_commands() as $name => $row) {
            // Task::internal() resolves a service by BASENAME, which is also what an author
            // types into rsx:task:run; the table stores the FQCN the manifest recorded.
            $service = class_basename($row['class']);

            $command = new Task_Alias_Command($name, $row['description'], $service, $row['method']);

            // Deferred exactly the way Kernel::command() defers a closure command: the
            // console application does not exist yet while commands() is running.
            \Illuminate\Console\Application::starting(function ($artisan) use ($command) {
                $artisan->add($command);
            });
        }
    }
}
