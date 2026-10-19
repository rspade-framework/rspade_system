<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * TASK-RUN-AS-01 - #[Run_As_Dispatcher] sits only where a signed-in person starts the run.
 *
 * A task declared #[Run_As_Dispatcher] runs as the person who dispatched it, so a run with
 * nobody behind it is refused at dispatch (Task_Runner::insert_row()). Three declarations can
 * only ever produce such a run, and this rule names them before they reach a scheduler tick
 * or a terminal:
 *
 *   - #[Run_As_Dispatcher] on a method that is not a #[Task] - the attribute does nothing;
 *   - beside #[Schedule] - a scheduler tick has no dispatcher, so every tick is refused;
 *   - beside #[Command] - a command runs from a terminal, with nobody signed in.
 *
 * A manifest-build FATAL (maintainer-approved): each of the three is unambiguously broken.
 * Cross-file: runs once per pass over the whole manifest.
 */
class TaskRunAsDispatcher_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'TASK-RUN-AS-01';
    }

    public function get_name(): string
    {
        return 'Run As Dispatcher Placement';
    }

    public function get_description(): string
    {
        return '#[Run_As_Dispatcher] belongs on a #[Task] that a signed-in person dispatches - never without #[Task], and never beside #[Schedule] or #[Command]';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Manifest-time: the declaration cannot ship.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * CROSS-FILE: this rule judges the tree, not one file.
     */
    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return ['files:*.php', 'php_classes'];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        foreach (Manifest::get_all() as $base_file => $info) {
            foreach ($info['public_static_methods'] ?? [] as $method_name => $method_info) {
                $attrs = $method_info['attributes'] ?? [];

                foreach (static::attribute_problems($method_name, array_keys($attrs)) as [$message, $fix]) {
                    $this->__violate($base_file, $method_name, $message, $fix);
                }
            }
        }
    }

    /**
     * What is wrong with one method's #[Run_As_Dispatcher], as [message, fix] pairs. Empty when
     * the method does not declare it, or declares it on a dispatched #[Task].
     *
     * @param string $method_name
     * @param array<int, string> $attribute_names The method's attribute names
     * @return array<int, array{0: string, 1: string}>
     */
    public static function attribute_problems(string $method_name, array $attribute_names): array
    {
        $has = static function (string $name) use ($attribute_names): bool {
            foreach ($attribute_names as $attribute) {
                if ($attribute === $name || str_ends_with($attribute, '\\' . $name)) {
                    return true;
                }
            }

            return false;
        };

        if (!$has('Run_As_Dispatcher')) {
            return [];
        }

        $problems = [];

        if (!$has('Task')) {
            $problems[] = [
                "{$method_name} declares #[Run_As_Dispatcher] but is not a #[Task].",
                '#[Run_As_Dispatcher] tells the task runner to run a dispatched task as the person who dispatched it. '
                . 'Put it on a #[Task] method, or remove it.',
            ];
        }

        if ($has('Schedule')) {
            $problems[] = [
                "Task {$method_name} declares #[Run_As_Dispatcher] beside #[Schedule]: a scheduler tick has no dispatcher, so every scheduled run would be refused.",
                'A scheduled task runs as nobody, which is correct for system work - remove #[Run_As_Dispatcher]. '
                . 'If the same work is also started by a person, give that path its own #[Task] method declaring '
                . '#[Run_As_Dispatcher] and share the body.',
            ];
        }

        if ($has('Command')) {
            $problems[] = [
                "Task {$method_name} declares #[Run_As_Dispatcher] beside #[Command]: a command runs from a terminal with nobody signed in, so it would be refused.",
                'Remove #[Run_As_Dispatcher], or give the command its own #[Task] method without it and share the body.',
            ];
        }

        return $problems;
    }

    private function __violate(string $file, string $method, string $message, string $fix): void
    {
        $full_path = str_starts_with($file, '/') ? $file : base_path() . '/' . $file;
        $lines = file_exists($full_path) ? explode("\n", $this->source()->content($full_path)) : [];
        $line = 1;

        foreach ($lines as $i => $text) {
            if (str_contains($text, "function {$method}")) {
                $line = $i + 1;
                break;
            }
        }

        $this->add_violation($file, $line, $message, $lines[$line - 1] ?? '', $fix);
    }
}
