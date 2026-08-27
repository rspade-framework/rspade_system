<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * CONV-BUNDLE-04 - a framework-owned bundle may not include anything from rsx/.
 *
 * THE DIRECTION OF THE DEPENDENCY IS THE WHOLE RULE. An application bundle including
 * app/RSpade/... is correct and ordinary - that is an app consuming the framework. A
 * framework bundle including rsx/... is the same edge pointed backwards: the framework
 * reaching into the application that happens to be hosting it.
 *
 * WHY IT MATTERS, from the incident that produced this rule. The API docs console is
 * framework code mounted on an application route, and its bundle had borrowed four things
 * from the template app: the theme variables, the responsive mixins, the app's Bootstrap
 * build and the app's Modal library. Every one of those is the APPLICATION'S to change -
 * restyling a theme, swapping a Bootstrap build or replacing Modal outright are all
 * perfectly legal things for a downstream app to do, and any of them silently broke the
 * console. The field symptom was `Button_Utils is not defined` on a downstream install,
 * with nothing in the framework to point at, because the framework had not changed.
 *
 * The fix is never to include the app path. It is to own the dependency on the framework
 * side - Api_Confirm_Dialog and api_docs_page_reset.scss are what those four includes
 * became - so the console renders identically no matter what the host app does to itself.
 *
 * Two shapes are caught, because both are the same edge:
 *   - a PATH under rsx/ ('rsx/theme/variables.scss', or an absolute path resolving there);
 *   - a BUNDLE CLASS whose own file lives under rsx/ (Bootstrap5_Src_Bundle) - naming a
 *     class rather than a path does not make the dependency framework-owned.
 *
 * Cross-file by necessity: rsx:check strips app/RSpade/** from the scan set unless
 * framework-developer mode is on, and a per-file rule would also miss framework bundles
 * whenever someone checks a subset of the tree. So this walks the manifest itself.
 *
 * Honors @CONV-BUNDLE-04-EXCEPTION on the bundle file (checked here rather than relying on
 * CodeQualityChecker's generic file-level suppression, which keys on the file the rule was
 * HANDED - a cross-file rule never uses it).
 */
class FrameworkBundleAppInclude_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'CONV-BUNDLE-04';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Framework Bundle Includes App Path';
    }

    public function get_description(): string
    {
        return 'A bundle owned by the framework may not include paths or bundle classes from rsx/';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Blocking, not advisory: this ships a framework that only works against one particular
     * application, and the breakage surfaces downstream rather than here.
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Cross-file: walks the manifest rather than the file it was handed.
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Runs once per invocation, over every framework-owned bundle in the manifest.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        $files = Manifest::get_all();
        if (empty($files)) {
            return;
        }

        foreach ($files as $rel_path => $file_metadata) {
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            $class_name = $file_metadata['class'] ?? null;
            if (!$class_name || !empty($file_metadata['abstract'])) {
                continue;
            }

            // Framework-owned only. An app bundle including its own rsx/ paths is correct.
            if (!$this->__is_framework_path($rel_path)) {
                continue;
            }

            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Bundle_Abstract')) {
                continue;
            }

            $this->__check_bundle($rel_path, $class_name, $file_metadata['fqcn'] ?? null);
        }
    }

    /**
     * Evaluate one framework bundle's include list.
     */
    private function __check_bundle(string $rel_path, string $class_name, ?string $fqcn): void
    {
        $file = base_path($rel_path);

        if (!$fqcn || !class_exists($fqcn) || !method_exists($fqcn, 'define')) {
            return;
        }

        $contents = is_readable($file) ? file_get_contents($file) : '';
        if ($contents !== '' && str_contains($contents, '@' . self::RULE_ID . '-EXCEPTION')) {
            return;
        }

        // define() is the declaration surface; there is no manifest index of array literals
        // inside a method body. Precedent: Rsx_Asset_Bundle_Abstract::validate_no_directory_scanning().
        $definition = $fqcn::define();
        $includes = $definition['include'] ?? [];

        if (!is_array($includes)) {
            return;
        }

        $lines = $contents === '' ? [] : explode("\n", $contents);

        foreach ($includes as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }

            $offender = $this->__resolve_app_source($entry);
            if ($offender === null) {
                continue;
            }

            $this->__report($file, $lines, $class_name, $entry, $offender);
        }
    }

    /**
     * Return the offending rsx/-relative path an include entry resolves to, or null when the
     * entry is framework-owned.
     *
     * An entry is either a path (absolute, or relative to base_path()) or the name of a
     * bundle class. Both are resolved to a real file location before judging, so the rule
     * cannot be evaded by spelling the same dependency a different way.
     */
    private function __resolve_app_source(string $entry): ?string
    {
        // A path-looking entry: absolute, or relative to base_path().
        if (str_contains($entry, '/') || str_contains($entry, '\\')) {
            $normalized = str_replace('\\', '/', $entry);
            $absolute = str_starts_with($normalized, '/') ? $normalized : base_path($normalized);

            return $this->__app_relative_path($absolute);
        }

        // Otherwise it names a class (or an alias resolving to one). php_find_class()
        // returns the manifest-relative path, which is exactly what needs judging.
        $class_file = Manifest::php_find_class($entry);
        if (!$class_file) {
            return null;
        }

        return $this->__app_relative_path(base_path($class_file));
    }

    /**
     * Given a path, return it relative to the application tree when it lands there, else null.
     *
     * TWO ROOTS ARE CHECKED, not one, because the app tree is reachable by two spellings:
     * base_path() is <project>/system and system/rsx is a SYMLINK to <project>/rsx. Symlinks
     * are deliberately NOT resolved here (rsxrealpath, not realpath - the logical path is
     * what a bundle declared and what a reader should be shown), so the two spellings do not
     * converge on their own and both have to be named.
     */
    private function __app_relative_path(string $path): ?string
    {
        $normalized = rsxrealpath($path);

        // A path that does not exist yet is still judged, on its spelling alone.
        if ($normalized === false) {
            $normalized = '/' . trim(str_replace('\\', '/', $path), '/');
        }

        foreach ($this->__app_roots() as $root) {
            if ($normalized === $root) {
                return 'rsx/';
            }

            if (str_starts_with($normalized, $root . '/')) {
                return 'rsx/' . ltrim(substr($normalized, strlen($root)), '/');
            }
        }

        return null;
    }

    /**
     * Every absolute prefix that means "the application tree".
     *
     * @return string[]
     */
    private function __app_roots(): array
    {
        return [
            rtrim(base_path('rsx'), '/'),                   // <project>/system/rsx (the symlink)
            rtrim(dirname(base_path()) . '/rsx', '/'),      // <project>/rsx (the real directory)
        ];
    }

    /**
     * Emit one violation, pointing at the offending include line where it can be found.
     */
    private function __report(string $file, array $lines, string $class_name, string $entry, string $offender): void
    {
        $line = 0;
        foreach ($lines as $index => $text) {
            if (str_contains($text, "'" . $entry . "'") || str_contains($text, '"' . $entry . '"')) {
                $line = $index + 1;
                break;
            }
        }

        $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : $entry;

        $this->add_violation(
            $file,
            $line,
            "Framework bundle {$class_name} includes '{$entry}', which resolves into the application tree ({$offender}).",
            $code_snippet,
            "A framework bundle must not depend on the application hosting it. '{$offender}' belongs to the app: "
                . "it is free to restyle its theme, redefine its Bootstrap build or replace its Modal outright, and any "
                . "of those silently breaks a framework feature that borrowed them. The field symptom is a downstream-only "
                . "error with nothing in the framework to point at.\n"
                . "\n"
                . "FIX: own the dependency on the framework side instead of borrowing the app's. The API docs console is "
                . "the worked example - it dropped rsx/theme/variables.scss, rsx/theme/responsive.scss, Bootstrap5_Src_Bundle "
                . "and rsx/lib/modal in favour of framework-owned equivalents beside the components that need them "
                . "(Api_Confirm_Dialog, api_docs_page_reset.scss), so it renders identically whatever the host app does. "
                . "See rsx/app/apidocs/apidocs_bundle.php for the resulting include list.\n"
                . "\n"
                . "Naming a bundle CLASS instead of a path does not make the dependency framework-owned - what matters is "
                . "where the included file lives.\n"
                . "\n"
                . "If a framework bundle genuinely must name an app path, add @" . self::RULE_ID . "-EXCEPTION to the bundle "
                . "file with a comment explaining why.",
            $this->get_default_severity()
        );
    }

    /**
     * True when a manifest-relative path is framework property (app/RSpade/...).
     */
    private function __is_framework_path(string $rel_path): bool
    {
        return str_starts_with(str_replace('\\', '/', $rel_path), 'app/RSpade/');
    }
}
