<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module that bakes the #[Command] table an application's artisan aliases are
 * registered from.
 *
 * An application never writes a Laravel command class. It annotates a #[Task] method:
 *
 * ```php
 * #[Task('Seed the demo dataset')]
 * #[Command('rsx_app:seed', 'Seed clients, contacts, projects and tasks')]
 * public static function seed_all(Task_Instance $task, array $params = []) { }
 * ```
 *
 * and the manifest records it here as
 *
 *     data['task_commands']['rsx_app:seed'] = ['class' => ..., 'method' => ..., 'description' => ...]
 *
 * Task_Command_Registrar turns each row into one thin artisan command at boot. Discovery
 * happens HERE, once, at build time - registration reads a baked table and decides nothing.
 *
 * Every rule below is a manifest-build FATAL naming the file and the method. A declaration
 * that violates one breaks the build until it is fixed:
 *
 *   - #[Command] on a method with no #[Task]. There is nothing to run.
 *   - A name with no `prefix:` segment. An unprefixed name squats on artisan's root
 *     namespace and collides with whatever Laravel adds next.
 *   - A name starting `rsx:`. That prefix is the framework's.
 *   - A name colliding with another #[Command], or with a framework command (the first
 *     token of every $signature/$name under app/RSpade/Commands, read at build time).
 *   - A missing or empty description. It is the line `php artisan list` prints.
 */
class Task_Command_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Directories (relative to base_path()) whose command classes an app command may not
     * collide with - both trees Kernel::commands() loads. Read at build time rather than
     * from a registered-command list: the manifest builds before Laravel's console kernel
     * has registered anything, and a collision that slipped through would not error - the
     * later registration silently shadows the earlier one.
     */
    private const FRAMEWORK_COMMAND_DIRS = ['app/RSpade/Commands', 'app/Console/Commands'];

    public static function get_name(): string
    {
        return 'Task Commands';
    }

    public static function process(array &$manifest_data): void
    {
        $manifest_data['data']['task_commands'] = [];

        $framework_commands = static::_scan_framework_command_names();
        $table = [];

        foreach ($manifest_data['data']['files'] as $file => $metadata) {
            foreach ($metadata['public_static_methods'] ?? [] as $method_name => $method_data) {
                foreach ($method_data['attributes'] ?? [] as $attr_name => $attr_instances) {
                    if ($attr_name !== 'Command' && !str_ends_with($attr_name, '\\Command')) {
                        continue;
                    }

                    $fqcn = $metadata['fqcn'] ?? $metadata['class'] ?? '(unknown)';
                    $location = "{$fqcn}::{$method_name} in {$file}";

                    if (!static::_method_has_attribute($method_data, 'Task')) {
                        throw new \RuntimeException(
                            "Invalid #[Command]: {$location}\n" .
                            "  #[Command] may only annotate a #[Task] method - there is nothing else for the\n" .
                            "  command to run. Add #[Task('<description>')] or remove the #[Command]."
                        );
                    }

                    foreach ($attr_instances as $arguments) {
                        $name = $arguments[0] ?? $arguments['name'] ?? null;
                        $description = $arguments[1] ?? $arguments['description'] ?? null;

                        static::_validate_name($name, $location, $table, $framework_commands);
                        static::_validate_description($description, $location);

                        $table[$name] = [
                            'class' => $fqcn,
                            'method' => $method_name,
                            'description' => $description,
                        ];
                    }
                }
            }
        }

        ksort($table);

        $manifest_data['data']['task_commands'] = $table;
    }

    /**
     * Enforce the four naming rules, in the order an author most likely broke them.
     *
     * @param mixed $name
     * @param string $location
     * @param array<string, array> $table Names accepted so far this build.
     * @param array<string, string> $framework_commands name => declaring file.
     */
    private static function _validate_name($name, string $location, array $table, array $framework_commands): void
    {
        if (!is_string($name) || trim($name) === '') {
            throw new \RuntimeException(
                "Invalid #[Command]: {$location}\n" .
                "  The first argument is the command name and is required, e.g.\n" .
                "  #[Command('myapp:import', 'Import the nightly feed')]."
            );
        }

        if (str_starts_with($name, 'rsx:')) {
            throw new \RuntimeException(
                "Invalid #[Command]('{$name}'): {$location}\n" .
                "  The 'rsx:' prefix belongs to the framework. Name the command after the\n" .
                "  application, e.g. 'myapp:" . substr($name, 4) . "'."
            );
        }

        $colon = strpos($name, ':');

        if ($colon === false || $colon === 0 || $colon === strlen($name) - 1) {
            throw new \RuntimeException(
                "Invalid #[Command]('{$name}'): {$location}\n" .
                "  A command name needs a 'prefix:name' segment - an unprefixed name squats on\n" .
                "  artisan's root namespace. Use e.g. 'myapp:{$name}'."
            );
        }

        if (isset($table[$name])) {
            throw new \RuntimeException(
                "Duplicate #[Command]('{$name}'): {$location}\n" .
                "  Already declared by {$table[$name]['class']}::{$table[$name]['method']}.\n" .
                "  One command name names exactly one task."
            );
        }

        if (isset($framework_commands[$name])) {
            throw new \RuntimeException(
                "Invalid #[Command]('{$name}'): {$location}\n" .
                "  That name is already a framework command, declared in {$framework_commands[$name]}.\n" .
                "  Choose another name - a #[Command] never shadows one."
            );
        }
    }

    /**
     * @param mixed $description
     */
    private static function _validate_description($description, string $location): void
    {
        if (!is_string($description) || trim($description) === '') {
            throw new \RuntimeException(
                "Invalid #[Command]: {$location}\n" .
                "  The second argument is the description and is required - it is the line\n" .
                "  `php artisan list` prints beside the command."
            );
        }
    }

    /**
     * Whether a method carries the named attribute (bare or fully qualified).
     */
    private static function _method_has_attribute(array $method_data, string $attribute): bool
    {
        foreach (array_keys($method_data['attributes'] ?? []) as $attr_name) {
            if ($attr_name === $attribute || str_ends_with($attr_name, '\\' . $attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every framework command name, read straight from the source at build time.
     *
     * Commands are not manifest-indexed (see rsx:man artisan_commands), so there is no
     * index to consult - the names are taken from the first token of each class's
     * $signature (or $name, for a class that sets one instead).
     *
     * @return array<string, string> command name => declaring file, relative to base_path()
     */
    private static function _scan_framework_command_names(): array
    {
        $names = [];

        foreach (self::FRAMEWORK_COMMAND_DIRS as $relative_dir) {
            $root = base_path($relative_dir);

            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if (!preg_match('/\$(?:signature|name)\s*=\s*[\'"]\s*([^\s\'"{}]+)/', $source, $match)) {
                    continue;
                }

                $names[$match[1]] = $relative_dir . '/'
                    . ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        return $names;
    }
}
