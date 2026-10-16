<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * TASK-CONCURRENCY-01 - validates the arguments of the task concurrency attributes.
 *
 * #[Exclusive] and #[Debounce(seconds)] may be declared alone or together on one task
 * method (rsx:man tasks, CONCURRENCY). What the rule refuses is a malformed argument:
 *
 *   - #[Debounce] takes nothing (no delay), or one non-negative integer number of seconds,
 *     and is declared at most once - a task has one delay;
 *   - #[Exclusive] takes nothing, or one group name as a non-empty string. It is
 *     REPEATABLE: a task naming several groups runs only while it holds all their locks, and
 *     each instance is judged on its own.
 *
 * Cross-file rule: runs once per rsx:check over the whole manifest.
 */
class TaskConcurrencyAttributes_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'TASK-CONCURRENCY-01';
    }

    public function get_name(): string
    {
        return 'Task Concurrency Attributes';
    }

    public function get_description(): string
    {
        return 'Debounce is declared once and takes nothing or one non-negative integer number of seconds; each Exclusive takes nothing or one non-empty group name';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * CROSS-FILE: this rule judges the tree, not one file. The driver runs it once per
     * pass, gated on the fingerprint of what depends_on() declares.
     */
    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every indexed PHP file plus the task-command index.
     *
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.php',
            'php_classes',
            'task_commands',
        ];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        foreach (Manifest::get_all() as $base_file => $info) {
            $methods = $info['public_static_methods'] ?? null;
            if (!$methods) {
                continue;
            }

            foreach ($methods as $method_name => $method_info) {
                $attrs = $method_info['attributes'] ?? [];
                if (empty($attrs)) {
                    continue;
                }

                foreach (static::attribute_problems($method_name, $attrs) as [$message, $fix]) {
                    $this->__violate($base_file, $method_name, $message, $fix);
                }
            }
        }
    }

    /**
     * What is wrong with one method's concurrency attributes, as [message, fix] pairs.
     * Empty for a method that declares neither, either, or both with well-formed arguments.
     *
     * @param array $attrs The method's manifest attribute map (name => instances of args)
     * @return array<int, array{0: string, 1: string}>
     */
    public static function attribute_problems(string $method_name, array $attrs): array
    {
        $problems = [];

        $debounce_instances = static::__attr_instances($attrs, 'Debounce');
        if (count($debounce_instances) > 1) {
            $problems[] = [
                "Task {$method_name} declares #[Debounce] more than once.",
                'A task has one debounce delay: keep a single #[Debounce] (or #[Debounce(seconds)]).',
            ];
        }

        $debounce_args = $debounce_instances[0] ?? null;
        if ($debounce_args !== null && $debounce_args !== []) {
            $delay = $debounce_args[0] ?? null;
            if (count($debounce_args) !== 1 || !is_int($delay) || $delay < 0) {
                $problems[] = [
                    "Task {$method_name} #[Debounce] takes nothing, or one non-negative integer number of seconds.",
                    'Use #[Debounce] for no delay (a coalesced follow-up is due as soon as the previous run ' .
                    'finishes), or e.g. #[Debounce(30)] to hold it 30 seconds after the previous run completed.',
                ];
            }
        }

        foreach (static::__attr_instances($attrs, 'Exclusive') as $exclusive_args) {
            if ($exclusive_args === []) {
                continue;
            }
            $group = $exclusive_args[0] ?? null;
            if (count($exclusive_args) !== 1 || !is_string($group) || trim($group) === '') {
                $problems[] = [
                    "Task {$method_name} #[Exclusive] takes nothing, or one group name as a non-empty string.",
                    "Use #[Exclusive] for a task serialized against itself, or #[Exclusive('group_name')] to " .
                    'serialize it against every other task naming the same group; declare it once per ' .
                    "group to need several: #[Exclusive('a')] #[Exclusive('b')].",
                ];
            }
        }

        return $problems;
    }

    /** The argument lists of every instance of a named attribute; empty when absent. */
    private static function __attr_instances(array $attrs, string $name): array
    {
        foreach ($attrs as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return array_values($instances);
            }
        }

        return [];
    }

    private function __violate(string $file, string $method, string $message, string $fix): void
    {
        $lines = $this->__file_lines($file);
        $line = 1;
        foreach ($lines as $i => $text) {
            if (str_contains($text, "function {$method}")) {
                $line = $i + 1;
                break;
            }
        }

        $this->add_violation($file, $line, $message, $lines[$line - 1] ?? '', $fix);
    }

    private function __file_lines(string $file_path): array
    {
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;

        return file_exists($full_path) ? explode("\n", $this->source()->content($full_path)) : [];
    }
}
