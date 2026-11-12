<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task_On_Build;

/**
 * TASK-ON-BUILD-01 - an #[On_Build] declaration the scheduler tick can dispatch.
 *
 * #[On_Build] makes the tick dispatch a #[Task] once for every new build key (rsx:man tasks,
 * ON BUILD). The tick dispatches with no params and no dispatcher, and builds can come many
 * times a minute in development, so the declaration must be: on a public static #[Task]
 * method; written once with no arguments; beside #[Exclusive] or #[Debounce] (runs cannot
 * pile up); with every parameter after the Task_Instance optional; never beside
 * #[Run_As_Dispatcher].
 *
 * The judgement is Task_On_Build::declaration_problems(); this rule is its one caller.
 *
 * A manifest-build FATAL (maintainer-approved): a wrong declaration fails the build that
 * indexes it, so no build key a tick can see ever carries one. Cross-file: runs once per pass
 * over the attribute index.
 */
class TaskOnBuild_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'TASK-ON-BUILD-01';
    }

    public function get_name(): string
    {
        return 'On Build Task Declaration';
    }

    public function get_description(): string
    {
        return '#[On_Build] belongs once, with no arguments, on a public static #[Task] that also declares #[Exclusive] or #[Debounce], requires no params, and is not #[Run_As_Dispatcher]';
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
        foreach (Manifest::by_attribute('On_Build') as $declaration) {
            $member = $declaration['member'] ?? null;
            $method_info = $member === null
                ? null
                : (Manifest::get_file($declaration['file'])['public_static_methods'][$member] ?? null);

            foreach (Task_On_Build::declaration_problems($declaration, $method_info) as [$message, $fix]) {
                $this->__violate($declaration['file'], $member, $message, $fix);
            }
        }
    }

    private function __violate(string $file, ?string $method, string $message, string $fix): void
    {
        $full_path = str_starts_with($file, '/') ? $file : base_path() . '/' . $file;
        $lines = file_exists($full_path) ? explode("\n", $this->source()->content($full_path)) : [];
        $line = 1;

        foreach ($lines as $i => $text) {
            if (str_contains($text, $method !== null ? "function {$method}" : 'On_Build')) {
                $line = $i + 1;
                break;
            }
        }

        $this->add_violation($file, $line, $message, $lines[$line - 1] ?? '', $fix);
    }
}
