<?php

namespace App\RSpade\CodeQuality\Rules\Meta;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Rule: META-INHERIT-01
 *
 * Detects incorrect class inheritance checking in code quality rules.
 * Code quality rules should use Manifest::php_is_subclass_of() instead of regex patterns
 * to check if a class extends another, since regex can't detect indirect inheritance.
 *
 * Example of incorrect pattern:
 *   preg_match('/class\s+\w+\s+extends\s+Component/', $contents)
 *
 * Should be:
 *   Manifest::php_is_subclass_of($class_name, 'Component')
 *
 * The same blind spot without a regex: comparing the manifest's IMMEDIATE parent against a
 * literal ($metadata['extends'] === 'Component') matches direct children only. That is
 * flagged too, unless a *_is_subclass_of() call follows within a few lines (a fast path in
 * front of the real lineage check).
 */
class Code_Quality_Meta_Inheritance_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'META-INHERIT-01';
    }

    public function get_name(): string
    {
        return 'Code Quality Meta: Incorrect Inheritance Checking';
    }

    public function get_description(): string
    {
        return 'Detects code quality rules using regex to check class inheritance instead of Manifest::php_is_subclass_of(). ' .
               'Regex patterns cannot detect indirect inheritance (A extends B extends C) and should be replaced with Manifest-based checks.';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * `['extends'] ===|!==|==|!= '<Name>'`, in either operand order.
     */
    private const EXTENDS_COMPARISON_PATTERN =
        '/\[\s*[\'"]extends[\'"]\s*\]\s*[!=]==?\s*[\'"][A-Za-z_]|[\'"][A-Za-z_][A-Za-z0-9_]*[\'"]\s*[!=]==?\s*\$[A-Za-z_][A-Za-z0-9_]*\[\s*[\'"]extends[\'"]\s*\]/';

    /** How far below a direct-parent comparison a lineage call still counts as its partner. */
    private const LINEAGE_CALL_WINDOW = 6;

    /**
     * Is a direct-parent comparison followed, within a few lines, by a *_is_subclass_of()
     * call - i.e. is it a fast path in front of the real lineage check?
     */
    private function lineage_call_follows(array $lines, int $line_index): bool
    {
        $last = min(count($lines) - 1, $line_index + self::LINEAGE_CALL_WINDOW);

        for ($i = $line_index; $i <= $last; $i++) {
            if (preg_match('/_is_subclass_of\s*\(/', $lines[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check code quality rule files for incorrect inheritance checking patterns
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check code quality rule files
        if (!str_contains($file_path, '/CodeQuality/Rules/')) {
            return;
        }

        // Skip this meta rule itself
        if (basename($file_path) === 'Code_Quality_Meta_Inheritance_CodeQualityRule.php') {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;
            $line_lower = strtolower($line);

            // Look for lines that appear to be using regex to check inheritance
            // These typically contain 'match' (from preg_match), 'class', and 'extends'
            // Allow for backslash escapes in the regex pattern
            if (str_contains($line_lower, 'match') &&
                str_contains($line_lower, 'class') &&
                str_contains($line_lower, 'extends')) {
                // Additional checks to reduce false positives
                // Look for specific patterns that indicate regex inheritance checking
                // The \\\\ matches literal backslash in PHP regex strings
                if (preg_match('/preg_match.*class.*extends/i', $line)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        'Code quality rule appears to be using regex to check class inheritance. ' .
                        'This will fail for indirect inheritance (A extends B extends C). ' .
                        'Use Manifest methods instead for accurate inheritance checking.',
                        trim($line),
                        "If checking PHP classes: Use Manifest::php_is_subclass_of(\$class_name, 'BaseClass')\n" .
                        "If checking JavaScript classes: Use Manifest::js_is_subclass_of(\$class_name, 'BaseClass')\n" .
                        "Examples:\n" .
                        "  PHP: if (Manifest::php_is_subclass_of(\$metadata['class'], 'Rsx_Model_Abstract')) { ... }\n" .
                        "  JS:  if (Manifest::js_is_subclass_of(\$class_name, 'Component')) { ... }",
                        'high'
                    );
                }
            }

            // A comparison of the IMMEDIATE parent against a literal class name:
            //   $metadata['extends'] === 'Some_Base'     'Some_Base' !== $metadata['extends']
            // It answers "is this a DIRECT child", so one intermediate base takes every
            // class beneath it out of the rule. The one sanctioned shape is a fast path that
            // a *_is_subclass_of() call follows within the next few lines.
            if (preg_match(self::EXTENDS_COMPARISON_PATTERN, $line)
                && !$this->lineage_call_follows($lines, $line_num)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Code quality rule compares \$metadata['extends'] against a class name. " .
                    'That matches DIRECT children only: a class reaching the base through an ' .
                    'intermediate abstract is silently out of the rule.',
                    trim($line),
                    "If checking PHP classes: Use Manifest::php_is_subclass_of(\$metadata['class'], 'BaseClass')\n" .
                    "If checking JavaScript classes: Use Manifest::js_is_subclass_of(\$metadata['class'], 'BaseClass')\n" .
                    "When a MEMBER must be resolved through the lineage too (an inherited method or\n" .
                    "property), use lineage_declaring_method() / lineage_declaring_property() from\n" .
                    'CodeQualityRule_Abstract.',
                    'high'
                );
            }

            // Also check for str_contains/strpos patterns checking for 'extends'
            // These are equally problematic for inheritance checking
            if ((str_contains($line, 'str_contains') || str_contains($line, 'strpos')) &&
                str_contains($line_lower, 'extends')) {
                // Check if this looks like inheritance checking
                if (preg_match('/str_(?:contains|pos)\s*\([^,]+,\s*[\'"]extends/', $line)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        'Code quality rule appears to be using string search to check class inheritance. ' .
                        'This will fail for indirect inheritance and is not reliable. ' .
                        'Use Manifest methods instead.',
                        trim($line),
                        "If checking PHP classes: Use Manifest::php_is_subclass_of(\$class_name, 'BaseClass')\n" .
                        "If checking JavaScript classes: Use Manifest::js_is_subclass_of(\$class_name, 'BaseClass')",
                        'high'
                    );
                }
            }
        }
    }
}