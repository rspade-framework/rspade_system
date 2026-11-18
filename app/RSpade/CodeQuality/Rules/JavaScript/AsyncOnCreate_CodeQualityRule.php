<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\Manifest;

/**
 * JS-ON-CREATE-01 - a component's on_create() is synchronous.
 *
 * on_create() is the first lifecycle hook of every jqhtml component - Spa_Action and
 * Spa_Layout included, which extend Component. It runs before the first render, and the
 * component does NOT wait for it: an `async on_create()` returns a Promise that jqhtml
 * ignores (it prints one console warning), so every line after its first `await` runs
 * after the component has rendered and moved on. this.data and this.state written there
 * are written outside the window on_create() owns (after the first render, after the
 * snapshot reload() restores), and an Ajax call made there escapes the cache and the
 * loading state that on_load() provides. The page looks right until the call is slow, and then it is wrong
 * in a way nothing reports - hence a manifest-build FATAL rather than an advisory.
 *
 * The fix is always one of two: the asynchronous work is a load and belongs in on_load(),
 * or it reacts to the rendered component and belongs in on_ready().
 *
 * Judged per file on classes in the Component lineage (Manifest::js_is_subclass_of), on
 * the source with comments blanked: an instance method declared `async on_create(`. A
 * static method of that name is not the hook and is not judged.
 *
 * See: php artisan rsx:man jqhtml
 */
class AsyncOnCreate_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-ON-CREATE-01';
    }

    public function get_name(): string
    {
        return 'Component on_create() Must Be Synchronous';
    }

    public function get_description(): string
    {
        return 'A jqhtml component (Spa_Action and Spa_Layout included) may not declare async on_create()';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Manifest-time by framework-maintainer decision: the defect is silent at runtime and
     * breaks the component lifecycle contract, so it stops the build.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (str_contains($file_path, '/node_modules/') || str_contains($file_path, '/vendor/')) {
            return;
        }

        $class_name = $metadata['class'] ?? null;
        if (!is_string($class_name) || $class_name === '') {
            return;
        }

        if (!Manifest::js_is_subclass_of($class_name, 'Component')) {
            return;
        }

        $code = FileSanitizer::blank_js_comments($contents);

        if (!str_contains($code, 'on_create')) {
            return;
        }

        $lines = explode("\n", $code);
        $original = explode("\n", $contents);

        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*async\s+on_create\s*\(/', $line)) {
                continue;
            }

            $this->add_violation(
                $file_path,
                $index + 1,
                "{$class_name}::on_create() is declared async. on_create() must be synchronous: "
                . 'the component does not wait for the Promise it returns, so everything after its '
                . 'first await runs after the component has rendered and moved on.',
                trim($original[$index] ?? $line),
                "Remove 'async' from on_create() and move the asynchronous work to the hook it belongs to:\n"
                . "  - loading data the component renders -> async on_load() (writes this.data)\n"
                . "  - reacting to the rendered component  -> on_ready()\n"
                . "on_create() sets initial this.state and this.data defaults, synchronously.\n"
                . 'See: php artisan rsx:man jqhtml',
                'critical'
            );
        }
    }
}
