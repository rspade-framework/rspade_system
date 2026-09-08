<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * CONV-BUNDLE-02 - what an application bundle's include list may name.
 *
 * TWO CHECKS LIVE HERE, both about the same list, and both scoped to rsx/.
 *
 * 1. CONVENTION (low): a module bundle should include its own directory, or its files
 *    never reach the browser.
 *
 * 2. THE FRAMEWORK APPLICATION TREE IS OFF LIMITS (critical): an rsx/ bundle may not name
 *    anything under app/RSpade/Sys/, by path or by class. That tree is the system control
 *    panel - a framework application that runs BESIDE the hosting one - and it is
 *    deliberately not shared in either direction: CONV-BUNDLE-04 stops the panel borrowing
 *    from rsx/, and this stops rsx/ borrowing from the panel. Pulling the panel into an
 *    application bundle ships framework-internal screens, components and theme tokens into
 *    the app's own asset graph, where the app's variables and Bootstrap build then apply to
 *    them and every framework update can break the result. Nothing in there is an API.
 *
 *    The panel is reachable WITHOUT including it: its entry route is published into every
 *    bundle (config rsx.always_published_routes), so Rsx.Route('_Sys_Dashboard_Action')
 *    and Permission.can_access('_Sys_Dashboard_Action') both answer from an application
 *    page. See rsx:man sys_panel.
 */
class BundleIncludePath_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * The framework application tree an rsx/ bundle may never include, manifest-relative.
     */
    private const FRAMEWORK_APP_TREE = Rsx_Paths::FRAMEWORK_PREFIX . 'Sys';

    public function get_id(): string
    {
        return 'CONV-BUNDLE-02';
    }

    public function get_name(): string
    {
        return 'Bundle Include Path Convention';
    }

    public function get_description(): string
    {
        return 'Bundles should include __DIR__ or their relative path in their includes';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'low';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not a PHP class file
        if (!isset($metadata['class'])) {
            return;
        }

        // Only check files in ./rsx/ directory, trust framework authors for app/RSpade
        if (!str_starts_with($file_path, base_path() . '/rsx/')) {
            return;
        }

        // The framework-tree check applies to EVERY bundle in rsx/, including the module
        // bundles that extend Rsx_Module_Bundle_Abstract rather than the base directly - so
        // it asks the class itself rather than matching one literal parent name.
        $this->__check_framework_app_tree($file_path, $contents, $metadata);

        // Check if class extends Rsx_Bundle_Abstract
        $extends = $metadata['extends'] ?? '';
        if ($extends !== 'Rsx_Bundle_Abstract' &&
            $extends !== 'App\\RSpade\\Core\\Bundle\\Rsx_Bundle_Abstract') {
            return;
        }

        // Get relative path from base
        $relative_path = str_replace(base_path() . '/', '', $file_path);
        $dir_path = dirname($relative_path);

        // Check if bundle includes its own directory in the include array
        // Look for the define() method
        if (!preg_match('/public\s+static\s+function\s+define\s*\(\s*\)\s*:\s*array\s*\{(.*?)\}/s', $contents, $matches)) {
            return; // Can't find define method
        }

        $define_content = $matches[1];

        // Look for include array
        if (!preg_match("/['\"]include['\"]\s*=>\s*\[(.*?)\]/s", $define_content, $include_matches)) {
            return; // No include array found
        }

        $include_content = $include_matches[1];

        // Check if it references __DIR__ or the directory path
        $has_dir_reference = false;

        // Check for __DIR__ usage
        if (str_contains($include_content, '__DIR__')) {
            $has_dir_reference = true;
        }

        // Check for the relative directory path (e.g., 'rsx/app/demo')
        if (str_contains($include_content, "'" . $dir_path) ||
            str_contains($include_content, '"' . $dir_path)) {
            $has_dir_reference = true;
        }

        if (!$has_dir_reference) {
            // Get bundle class name for better message
            $class_name = $metadata['class'] ?? basename($file_path, '.php');

            $this->add_violation(
                $file_path,
                0,
                "Bundle {$class_name} should include its own directory in the 'include' array",
                null,
                "Add '__DIR__' or '{$dir_path}' to the bundle's include array to ensure all module files are included.\n" .
                "Note: This is a convention rather than a hard requirement. If your bundle intentionally doesn't need " .
                "to include its own directory, add the following comment to grant an exception: @CONV-BUNDLE-02-EXCEPTION",
                $this->get_default_severity()
            );
        }
    }

    /**
     * CRITICAL half: no entry in 'include' or 'include_routes' may name the framework
     * application tree.
     */
    private function __check_framework_app_tree(string $file_path, string $contents, array $metadata): void
    {
        $fqcn = $metadata['fqcn'] ?? null;

        if (!$fqcn || !class_exists($fqcn) || !method_exists($fqcn, 'define')) {
            return;
        }

        if (!is_subclass_of($fqcn, Rsx_Bundle_Abstract::class)) {
            return;
        }

        // define() is the declaration surface; there is no manifest index of array literals
        // inside a method body. Same precedent CONV-BUNDLE-04 uses.
        $definition = $fqcn::define();
        $lines = explode("\n", $contents);

        foreach (['include', 'include_routes'] as $list_name) {
            $entries = $definition[$list_name] ?? [];

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_string($entry) || $entry === '') {
                    continue;
                }

                $reason = $this->__framework_app_tree_reason($entry);

                if ($reason === null) {
                    continue;
                }

                $this->__report_framework_app_tree(
                    $file_path,
                    $lines,
                    $metadata['class'] ?? basename($file_path, '.php'),
                    $list_name,
                    $entry,
                    $reason
                );
            }
        }
    }

    /**
     * Why this include entry names the framework application tree, or null when it does not.
     *
     * Both spellings are resolved, because they are the same dependency: a PATH (relative to
     * base_path(), or absolute - base_path() is <project>/system, so the tree is
     * <project>/system/app/RSpade/Sys) and a bundle CLASS NAME. A class name is judged first
     * on the reserved single-underscore prefix, which rsx/ may not declare at all
     * (NAME-RESERVED-01), and then on where its file actually lives - so neither an alias nor
     * a rename evades the rule.
     */
    private function __framework_app_tree_reason(string $entry): ?string
    {
        if (str_contains($entry, '/') || str_contains($entry, '\\')) {
            $normalized = trim(str_replace('\\', '/', $entry), '/');

            foreach ($this->__framework_app_tree_roots() as $root) {
                $root = trim($root, '/');

                if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
                    return "the path resolves into the framework application tree ({$entry})";
                }
            }

            return null;
        }

        // A bare name: a bundle alias, or a bundle class.
        $aliases = config('rsx.bundle_aliases', []);
        $candidate = $aliases[$entry] ?? $entry;
        $basename = class_basename($candidate);

        if (str_starts_with($basename, '_')) {
            return "'{$basename}' carries the framework's reserved single-underscore prefix, "
                . 'so it is a framework class - rsx/ may not declare one (NAME-RESERVED-01)';
        }

        try {
            $class_file = Manifest::find_php_fqcn($candidate);
        } catch (\Throwable $e) {
            try {
                $class_file = Manifest::php_find_class($basename);
            } catch (\Throwable $e2) {
                // An include list legitimately carries names that are not manifest classes
                // (npm-backed aliases such as 'jquery'). Nothing outside the manifest can be
                // the framework application tree.
                return null;
            }
        }

        $class_path = trim(str_replace('\\', '/', $class_file), '/');

        foreach ($this->__framework_app_tree_roots() as $root) {
            if (str_starts_with($class_path, trim($root, '/') . '/')) {
                return "the class '{$basename}' is defined at {$class_path}, inside the "
                    . 'framework application tree';
            }
        }

        return null;
    }

    /**
     * Every spelling that means "the framework application tree", manifest-relative.
     *
     * @return string[]
     */
    private function __framework_app_tree_roots(): array
    {
        return [
            self::FRAMEWORK_APP_TREE,                    // app/RSpade/Sys (manifest-relative)
            base_path(self::FRAMEWORK_APP_TREE),         // <project>/system/app/RSpade/Sys
        ];
    }

    /**
     * Emit one framework-tree violation, pointing at the offending line where it can be found.
     */
    private function __report_framework_app_tree(
        string $file_path,
        array $lines,
        string $class_name,
        string $list_name,
        string $entry,
        string $reason
    ): void {
        $line = 0;
        foreach ($lines as $index => $text) {
            if (str_contains($text, "'" . $entry . "'") || str_contains($text, '"' . $entry . '"')) {
                $line = $index + 1;
                break;
            }
        }

        $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : $entry;

        $this->add_violation(
            $file_path,
            $line,
            "Application bundle {$class_name} names '{$entry}' in its '{$list_name}' list: {$reason}.",
            $code_snippet,
            "app/RSpade/Sys is the SYSTEM CONTROL PANEL - a framework application that runs beside yours. "
                . "It is never part of an application bundle, in either direction: CONV-BUNDLE-04 stops the panel "
                . "including anything from rsx/, and this rule stops rsx/ including anything from the panel. "
                . "Pulling it in ships framework-internal screens, components and theme tokens into your asset "
                . "graph, where your variables and your Bootstrap build then apply to them - and the next framework "
                . "update changes all of it underneath you. Nothing in that tree is an API.\n"
                . "\n"
                . "FIX: remove the entry. You do not need it to reach the panel. Its entry route is published into "
                . "EVERY bundle, so from any application page:\n"
                . "\n"
                . "    Rsx.Route('_Sys_Dashboard_Action')                  // the URL, resolved from the manifest\n"
                . "    Permission.can_access('_Sys_Dashboard_Action')      // whether this user may reach it\n"
                . "    Rsx::Route('_Sys_Dashboard_Action')                 // and the same in PHP\n"
                . "\n"
                . "That string is the ONE sanctioned _Sys_ reference; any other is refused. If you need panel-shaped "
                . "UI of your own, build it in rsx/ - see rsx:man sys_panel.",
            'critical'
        );
    }
}
