<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Task\Task_Gates;

/**
 * Support module that bakes the #[Task_Access] table the browser task API decides from.
 *
 * A #[Task] the browser may show declares who may see and act on its runs:
 *
 * ```php
 * #[Task('Import a contact file')]
 * #[Task_Access('dispatcher')]                          // the person who started it
 * public static function import(Task_Instance $task, array $params = []) { }
 *
 * #[Task('Sync the file share')]
 * #[Task_Access('sync_access')]                         // a check on the same service
 * public static function sync(Task_Instance $task, array $params = []) { }
 *
 * public static function sync_access(Task_Run_Model $run, $user, string $action): bool { }
 * ```
 *
 * and the manifest records it here as
 *
 *     data['task_access']['<fqcn>::<method>'] = ['class', 'service', 'method', 'check', 'realm']
 *
 * which Task_Gates reads. The arguments: the check - 'dispatcher' (the built-in) or the
 * name of a public static method of the same service class (inherited is fine) taking
 * exactly three parameters - and an optional `realm:` naming who asks: 'staff' (default),
 * 'portal' or 'any'.
 *
 * Every rule below is a manifest-build FATAL naming the file and the method:
 *
 *   - #[Task_Access] on a method with no #[Task], or on a class.
 *   - #[Task_Access] declared more than once on one task.
 *   - A missing or empty check name, or an argument other than the check and `realm`.
 *   - A realm other than staff, portal or any.
 *   - A check method that does not exist on the service, is not public, is not static, or
 *     does not take exactly three parameters (Task_Run_Model $run, $user, string $action).
 *
 * A FULL module: it only regroups what the manifest already indexed (attribute_index and
 * the method maps), so it rebuilds its section outright every pass.
 */
class Task_Access_ManifestSupport extends Full_ManifestSupport_Abstract
{
    public static function get_name(): string
    {
        return 'Task Access';
    }

    public static function rebuild(array &$manifest_data): void
    {
        $table = [];

        $declarations = $manifest_data['data']['attribute_index']['Task_Access'] ?? [];
        $files = $manifest_data['data']['files'] ?? [];

        foreach ($declarations as $declaration) {
            $file = $declaration['file'];
            $method_name = $declaration['member'] ?? null;
            $metadata = $files[$file] ?? [];
            $fqcn = $metadata['fqcn'] ?? $metadata['class'] ?? ($declaration['class'] ?? '(unknown)');

            if ($method_name === null) {
                throw new \RuntimeException(
                    "Invalid #[Task_Access]: {$fqcn} in {$file}\n" .
                    "  #[Task_Access] annotates a #[Task] METHOD - it says who may see and act on that\n" .
                    "  task's runs. Move it onto the task method."
                );
            }

            $location = "{$fqcn}::{$method_name} in {$file}";
            $method_data = $metadata['public_static_methods'][$method_name] ?? [];

            if (!static::_method_has_attribute($method_data, 'Task')) {
                throw new \RuntimeException(
                    "Invalid #[Task_Access]: {$location}\n" .
                    "  #[Task_Access] may only annotate a #[Task] method - there is no run for it to\n" .
                    "  govern. Add #[Task('<description>')] or remove the #[Task_Access]."
                );
            }

            $instances = array_values($declaration['instances'] ?? []);
            if (count($instances) !== 1) {
                throw new \RuntimeException(
                    "Duplicate #[Task_Access]: {$location}\n" .
                    "  A task declares #[Task_Access] once: one check answers every question about its\n" .
                    "  runs. Combine the rules into one check method."
                );
            }

            $arguments = $instances[0];
            $unknown = array_diff(array_keys($arguments), [0, 'check', 'realm']);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    "Invalid #[Task_Access]: {$location}\n" .
                    "  Unknown argument(s): " . implode(', ', $unknown) . ". The attribute takes the check name\n" .
                    "  and an optional realm: #[Task_Access('dispatcher')], #[Task_Access('my_check', realm: 'portal')]."
                );
            }

            $check = $arguments[0] ?? $arguments['check'] ?? null;
            $realm = $arguments['realm'] ?? Task_Gates::REALM_STAFF;

            if (!is_string($check) || trim($check) === '') {
                throw new \RuntimeException(
                    "Invalid #[Task_Access]: {$location}\n" .
                    "  The first argument is required: 'dispatcher' (only the person who dispatched a run\n" .
                    "  may view, stop and cancel it) or the name of a public static check method on this\n" .
                    "  service, e.g. #[Task_Access('dispatcher')]. See rsx:man tasks, TASK ACCESS."
                );
            }

            if (!in_array($realm, Task_Gates::REALMS, true)) {
                throw new \RuntimeException(
                    "Invalid #[Task_Access] realm '" . (is_scalar($realm) ? $realm : gettype($realm)) . "': {$location}\n" .
                    "  realm is one of: " . implode(', ', Task_Gates::REALMS) . " (default staff)."
                );
            }

            if ($check !== Task_Gates::DISPATCHER) {
                static::_validate_check_method($manifest_data, $metadata, $check, $location);
            }

            $table["{$fqcn}::{$method_name}"] = [
                'class' => $fqcn,
                'service' => $metadata['class'] ?? ($declaration['class'] ?? $fqcn),
                'method' => $method_name,
                'check' => $check,
                'realm' => $realm,
            ];
        }

        ksort($table);

        $manifest_data['data']['task_access'] = $table;
    }

    /**
     * The named check must be a public static method of the service (declared there or
     * inherited) taking exactly ($run, $user, $action).
     */
    private static function _validate_check_method(array $manifest_data, array $metadata, string $check, string $location): void
    {
        $record = $metadata;
        $seen = [];

        while ($record !== []) {
            if (isset($record['public_static_methods'][$check])) {
                $parameters = $record['public_static_methods'][$check]['parameters'] ?? [];
                if (count($parameters) !== 3) {
                    throw new \RuntimeException(
                        "Invalid #[Task_Access('{$check}')]: {$location}\n" .
                        "  The check {$check}() takes " . count($parameters) . " parameter(s); it must take exactly three:\n" .
                        "  public static function {$check}(Task_Run_Model \$run, \$user, string \$action): bool"
                    );
                }

                return;
            }

            if (isset($record['public_instance_methods'][$check])) {
                throw new \RuntimeException(
                    "Invalid #[Task_Access('{$check}')]: {$location}\n" .
                    "  {$check}() is not static. The check is called statically:\n" .
                    "  public static function {$check}(Task_Run_Model \$run, \$user, string \$action): bool"
                );
            }

            $parent = $record['extends'] ?? null;
            if ($parent === null || isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;

            $parent_file = $manifest_data['data']['php_classes'][$parent]['file'] ?? null;
            $record = $parent_file !== null ? ($manifest_data['data']['files'][$parent_file] ?? []) : [];
        }

        throw new \RuntimeException(
            "Invalid #[Task_Access('{$check}')]: {$location}\n" .
            "  The service has no public static method {$check}(). Declare the check on the same service:\n" .
            "  public static function {$check}(Task_Run_Model \$run, \$user, string \$action): bool\n" .
            "  or use the built-in #[Task_Access('dispatcher')]. See rsx:man tasks, TASK ACCESS."
        );
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
}
