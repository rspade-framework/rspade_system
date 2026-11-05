<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * MonoprogenicRule - Enforces single generation of concrete descendants
 *
 * Classes marked with #[Monoprogenic] attribute can only have one generation
 * of concrete (non-abstract) children. This prevents inheritance ambiguity
 * in reflection-based operations.
 *
 * Valid patterns:
 * - Monoprogenic (abstract) -> Child (concrete)
 * - Monoprogenic (abstract) -> Child (abstract) -> Grandchild (concrete)
 *
 * Invalid patterns:
 * - Monoprogenic (abstract) -> Child (concrete) -> Grandchild (concrete)
 *
 * This rule prevents situations where concrete classes extend other concrete
 * classes in a Monoprogenic hierarchy, which would create ambiguity about
 * which class should handle operations discovered through reflection (such as
 * routing, command discovery, or other framework operations).
 */
class Monoprogenic_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MANIFEST-MONO-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Monoprogenic Inheritance Validation';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Enforces that #[Monoprogenic] classes only have one generation of concrete descendants';
    }

    /**
     * Get file patterns this rule applies to
     *
     * Note: This returns PHP files, but the rule actually checks relationships
     * across the entire manifest, not individual files
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * This rule runs during manifest scan to validate inheritance patterns
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
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
     * The inheritance graph, which is the class map plus the subclass index.
     *
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.php',
            'php_classes',
            'php_subclass_index',
        ];
    }

    /**
     * Check the manifest for Monoprogenic violations
     *
     * This method is called once per file during manifest scan, but we only
     * need to check the entire manifest once. We use a static flag to ensure
     * the check only runs once per manifest build.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Step 1: every class carrying #[Monoprogenic], from the attribute index
        $monoprogenic_classes = [];
        foreach (\App\RSpade\Core\Manifest\Manifest::by_attribute('Monoprogenic') as $row) {
            if ($row['member'] === null && !empty($row['class'])) {
                $monoprogenic_classes[$row['class']] = $row['file'];
            }
        }

        ksort($monoprogenic_classes);

        // Step 2: each one's descendants
        foreach ($monoprogenic_classes as $monoprogenic_class => $monoprogenic_file) {
            $this->check_descendants($monoprogenic_class);
        }
    }

    /**
     * Check all descendants of a Monoprogenic class for violations.
     *
     * The descendants and their parents come from the manifest's class map and subclass
     * index, and no class is loaded. Asking PHP (class_exists() then is_subclass_of() on every
     * indexed file) autoloaded the whole tree to answer a question the index already holds.
     */
    private function check_descendants(string $monoprogenic_class): void
    {
        $descendants = \App\RSpade\Core\Manifest\Manifest::php_get_subclasses_of($monoprogenic_class);
        sort($descendants);

        foreach ($descendants as $class_name) {
            $record = \App\RSpade\Core\Manifest\Manifest::php_class_metadata($class_name);
            $parent_class_name = $record['extends'] ?? null;
            $file = $record['file'] ?? null;

            if (!$parent_class_name || !$file) {
                continue;
            }

            // A concrete class extending the Monoprogenic class itself is the intended shape
            if ($parent_class_name === $monoprogenic_class) {
                continue;
            }

            $parent_record = \App\RSpade\Core\Manifest\Manifest::php_class_metadata($parent_class_name);

            // A parent outside the index, or an abstract one, is allowed
            if ($parent_record === null || !empty($parent_record['abstract'])) {
                continue;
            }

            $line_number = $this->find_class_line($file, $class_name);

            $this->add_violation(
                $file,
                $line_number,
                sprintf(
                    "Class '%s' violates Monoprogenic inheritance pattern.\n\n" .
                    "This class is a concrete (non-abstract) class that extends another concrete class '%s'.\n" .
                    "The base class '%s' has the #[Monoprogenic] attribute, which means concrete descendants " .
                    "can only extend from it through abstract intermediary classes.\n\n" .
                    "Monoprogenic classes are used in reflection-based operations (like route discovery or " .
                    "command registration). Having concrete classes extend other concrete classes creates " .
                    "ambiguity about which class should handle the discovered operations.",
                    $class_name,
                    $this->get_short_name($parent_class_name),
                    $this->get_short_name($monoprogenic_class)
                ),
                "class " . $class_name . " extends",
                sprintf(
                    "To fix this violation, choose one of these approaches:\n" .
                    "1. Make the parent class '%s' abstract\n" .
                    "2. Make this class '%s' extend directly from an abstract class\n" .
                    "3. Remove the concrete inheritance chain by not extending '%s'",
                    $this->get_short_name($parent_class_name),
                    $class_name,
                    $this->get_short_name($parent_class_name)
                ),
                'critical'
            );
        }
    }

    /**
     * Find the line number where a class is declared
     */
    private function find_class_line(string $file_path, string $class_name): int
    {
        $absolute_path = base_path($file_path);
        if (!file_exists($absolute_path)) {
            return 1;
        }

        $contents = $this->source()->content($absolute_path);
        $lines = explode("\n", $contents);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:abstract\s+)?class\s+' . preg_quote($class_name) . '\b/', $line)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * Get short class name from FQCN
     */
    private function get_short_name(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}