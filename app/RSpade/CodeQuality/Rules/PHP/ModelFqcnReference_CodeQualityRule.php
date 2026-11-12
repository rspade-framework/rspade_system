<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * ModelFqcnReferenceRule - Prohibits inline FQCN references to framework models
 *
 * This rule ensures that code uses portable model class references instead of
 * hardcoded fully-qualified class names. This allows developers to override
 * framework models by placing custom versions in /rsx/models/.
 *
 * A FRAMEWORK MODEL is any class declared under app/RSpade/ that extends
 * Rsx_Model_Abstract - wherever it lives (Core/Models, Core/Files, Lib/Portal,
 * Lib/Messaging/Mail, ...) - plus a framework model an application has overridden.
 * A system model (Rsx_System_Model_Abstract: a framework-private `_` table such as
 * Session) is not an application-facing model and is left out. The set is read from
 * the manifest, never from a namespace prefix.
 *
 * Prohibited patterns:
 * - \App\RSpade\Core\Models\User_Model::...
 * - \App\RSpade\Lib\Portal\Portal_User_Model::find(...)
 * - 'App\RSpade\Core\Models\Login_User_Model' (string references)
 *
 * Allowed patterns:
 * - use App\RSpade\Core\Models\User_Model; (use statements are fine)
 * - \User_Model::... (short class name)
 * - User_Model::... (via use statement)
 * - namespace declarations
 *
 * Exception:
 * - A framework file that itself declares a framework model
 */
class ModelFqcnReference_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-FQCN-MODEL-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Model FQCN Reference Check';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Prohibits inline FQCN references to framework models. Use short class names (\\User_Model) to allow model overrides.';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Whether this rule is called during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check a file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $framework_models = self::_framework_models();

        // Skip the framework model files themselves
        if (isset($framework_models['files'][Rsx_Paths::relative($file_path)])) {
            return;
        }

        // Skip archived files
        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        // Check if file-level exception is present
        if (str_contains($contents, '@' . $this->get_id() . '-EXCEPTION')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_number => $line) {
            $actual_line_number = $line_number + 1;

            // Skip if line has exception comment
            if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
                continue;
            }

            // Skip use statements - those are fine
            if (preg_match('/^\s*use\s+/', $line)) {
                continue;
            }

            // Skip namespace declarations
            if (preg_match('/^\s*namespace\s+/', $line)) {
                continue;
            }

            // Skip comments
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Look for inline FQCN references to a framework model
            // Pattern 1: \App\RSpade\...\Some_Model (backslash-prefixed)
            // Pattern 2: 'App\RSpade\...\Some_Model' or "..." (string references)
            if (!preg_match_all('/(\\\\|[\'"])(App(?:\\\\{1,2}\w+)+)/', $line, $all, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($all as $match) {
                $fqcn = str_replace('\\\\', '\\', $match[2]);
                if (!isset($framework_models['fqcns'][$fqcn])) {
                    continue;
                }

                $model_name = substr($fqcn, strrpos($fqcn, '\\') + 1);
                $is_string = $match[1] !== '\\';

                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    $is_string
                        ? "String FQCN reference to framework model '{$fqcn}' prevents model overrides"
                        : "Inline FQCN reference to framework model '\\{$fqcn}' prevents model overrides",
                    trim($line),
                    $this->build_suggestion($fqcn, $model_name, $is_string),
                    'high'
                );
                break;
            }
        }
    }

    /**
     * Every framework model, from the manifest: ['fqcns' => [fqcn => true], 'files' => [path => true]].
     *
     * A framework PHP class under app/RSpade/ that extends Rsx_Model_Abstract and is not a
     * system model (Rsx_System_Model_Abstract), plus each
     * framework model an application overrides (its framework file is shadowed, so it is in
     * the override map rather than the live index).
     */
    private static function _framework_models(): array
    {
        static $models = null;

        if ($models !== null) {
            return $models;
        }

        $models = ['fqcns' => [], 'files' => []];

        foreach (Manifest::get_all() as $path => $info) {
            if (empty($info['fqcn']) || empty($info['class']) || !str_starts_with($path, 'app/RSpade/')) {
                continue;
            }

            if (self::_is_framework_model($info['class'])) {
                $models['fqcns'][$info['fqcn']] = true;
                $models['files'][$path] = true;
            }
        }

        foreach (Manifest::overrides() as $path => $override) {
            if (self::_is_framework_model($override['name'])) {
                $models['fqcns'][$override['fqcn']] = true;
                $models['files'][$path] = true;
            }
        }

        return $models;
    }

    private static function _is_framework_model(string $class): bool
    {
        return Manifest::php_is_subclass_of($class, 'Rsx_Model_Abstract')
            && !Manifest::php_is_subclass_of($class, 'Rsx_System_Model_Abstract');
    }

    /**
     * Build suggestion for fixing the violation
     */
    private function build_suggestion(string $fqcn, string $model_name, bool $is_string = false): string
    {
        $suggestions = [];
        $suggestions[] = "Framework models should be referenced by short class name to allow developer overrides.";
        $suggestions[] = "";

        if ($is_string) {
            $suggestions[] = "Change:";
            $suggestions[] = "    \$class = '{$fqcn}';";
            $suggestions[] = "";
            $suggestions[] = "To:";
            $suggestions[] = "    \$class = '{$model_name}';";
        } else {
            $suggestions[] = "Change:";
            $suggestions[] = "    \\{$fqcn}::method()";
            $suggestions[] = "";
            $suggestions[] = "To:";
            $suggestions[] = "    \\{$model_name}::method()";
        }

        $suggestions[] = "";
        $suggestions[] = "This allows developers to override {$model_name} by creating:";
        $suggestions[] = "    /rsx/models/" . strtolower(str_replace('_Model', '', $model_name)) . "_model.php";
        $suggestions[] = "";
        $suggestions[] = "Note: 'use {$fqcn};' statements are allowed";
        $suggestions[] = "because PHP resolves them at parse time before the override system.";

        return implode("\n", $suggestions);
    }
}
