<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * SEALED-01 - #[Sealed] properties may not be redeclared by a subclass.
 *
 * #[Sealed] is a marker-only attribute (NEVER define the attribute class - this rule
 * reads it by name from the AST, per framework convention). Placed on a PROPERTY, it
 * declares that property the sole property of the class that declared it: no descendant
 * may redeclare it, at any depth.
 *
 * WHY PROPERTIES ONLY. Methods already have `final`, which PHP itself enforces at
 * compile time - a second mechanism there would be a dual implementation. PHP has no
 * `final` for properties, so a subclass can silently redeclare one and change a value
 * the parent's own logic depends on. That is the gap #[Sealed] fills, and it is the
 * only gap it fills; putting it anywhere else is a misuse.
 *
 * The motivating case is Rsx_Actor_Model_Abstract::$actor_soft_deletes - the declared
 * soft-delete mandate for identity models. Redeclaring it is exactly the move someone
 * makes when trying to opt an actor out of soft deletes, so it must fail LOUD at build
 * time rather than appear to work.
 *
 * A REDECLARATION is what is flagged - not reading, not writing. `static::$prop = x`
 * anywhere is untouched; `public static $prop = x;` in a subclass body is the
 * violation. Redeclaring with the SAME value is still a violation: the point is that
 * the declaration lives in exactly one place.
 *
 * Detection is AST-derived (nikic/php-parser), never a regex over raw source, so a
 * comment or string mentioning the property cannot spoof it. The parse happens once,
 * inside Source_Cache::declared_members(), and this rule reads the summary: property
 * names, their #[Sealed] marker and their exception markers. Lineage comes from the
 * manifest (php_get_lineage); vendor ancestors are naturally out of scope, since the
 * manifest never scans vendor/.
 *
 * Honors @SEALED-01-EXCEPTION at file level (via CodeQualityChecker) and, with
 * precision, on or immediately above the offending property declaration.
 */
class SealedProperty_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'SEALED-01';



    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Sealed Property Redeclaration';
    }

    public function get_description(): string
    {
        return 'A subclass may not redeclare a property an ancestor marked #[Sealed]';
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
     * Runs during the manifest scan: a sealed-property redeclaration is a FATAL
     * manifest-time error. The redeclared property compiles and runs fine - it just
     * quietly detaches the subclass from a declaration the base class's contract
     * rests on - so build time is the only place it can be caught.
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
     * Every indexed PHP file and the lineage indexes: a #[Sealed] property forbids
     * redeclaration by any descendant, so the graph is the premise.
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
     * Runs once per build. Scopes validated classes to the files that changed in this
     * scan (falling back to the full manifest when nothing is flagged changed, i.e. a
     * full rebuild), and uses the manifest for lineage / ancestor lookups.
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

        // Scope to changed files; empty => full rebuild => validate everything.
        $changed = Manifest::get_changed_files();
        $changed_lookup = null;
        if (!empty($changed)) {
            $changed_lookup = [];
            foreach ($changed as $rel) {
                $changed_lookup[str_replace('\\', '/', $rel)] = true;
            }
        }

        foreach ($files as $rel_path => $file_metadata) {
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }
            if (empty($file_metadata['class'])) {
                continue;
            }

            if ($changed_lookup !== null) {
                $normalized_rel = str_replace('\\', '/', $rel_path);
                if (!isset($changed_lookup[$normalized_rel])) {
                    continue;
                }
            }

            $class_name = $file_metadata['class'];
            $abs_file = base_path($rel_path);

            // Nearest -> root ancestry (class name + absolute file path) from the
            // manifest. Climbing stops at the last manifest-visible ancestor.
            $ancestry = [];
            foreach (Manifest::php_get_lineage($class_name) as $ancestor_name) {
                try {
                    $ancestor_rel = Manifest::php_find_class($ancestor_name);
                } catch (\Throwable $e) {
                    break;
                }
                $ancestry[] = [
                    'class' => $ancestor_name,
                    'file' => base_path($ancestor_rel),
                ];
            }

            if (empty($ancestry)) {
                continue;
            }

            $this->evaluate_class_properties($abs_file, $class_name, $ancestry);
        }
    }

    /**
     * Core validation for one child class against an explicit nearest -> root ancestry
     * (each entry: ['class' => simpleName, 'file' => absolutePath]).
     *
     * This is the testable seam: production builds the ancestry from the manifest;
     * tests build it from synthetic fixture files, so seal discovery, depth-N
     * inheritance and exception markers are all exercised over real AST without
     * touching the manifest.
     */
    public function evaluate_class_properties(string $child_file, string $child_class, array $ancestry): void
    {
        $child_properties = $this->source()->declared_members($child_file, $child_class)['properties'];

        if (empty($child_properties)) {
            return;
        }

        // Sealed name => declaring class, collected across the WHOLE ancestry: a seal
        // binds every descendant, not just direct children. An ancestor that cannot be
        // located or parsed summarizes to no members and contributes no seals.
        $sealed = [];

        foreach ($ancestry as $ancestor) {
            foreach ($this->source()->declared_members($ancestor['file'], $ancestor['class'])['properties'] as $name => $property) {
                if (isset($sealed[$name])) {
                    continue;
                }

                if (isset($property['attributes']['sealed'])) {
                    $sealed[$name] = $ancestor['class'];
                }
            }
        }

        if (empty($sealed)) {
            return;
        }

        $contents = $this->source()->content($child_file);
        $lines = $contents === '' ? [] : explode("\n", $contents);

        foreach ($child_properties as $name => $property) {
            if (!isset($sealed[$name])) {
                continue;
            }

            if (isset($property['exceptions'][self::RULE_ID])) {
                continue;
            }

            $sealing_class = $sealed[$name];
            $line = $property['line'];
            $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $message = "{$child_class} redeclares \${$name}, which {$sealing_class} marked #[Sealed].\n\n"
                . "A sealed property has exactly one declaration site. Redeclaring it detaches this "
                . "class from the contract {$sealing_class} declares through it - silently, because "
                . "the redeclaration compiles and runs.";

            $suggestion = "Delete the \${$name} declaration from {$child_class} and inherit it.\n"
                . "If this class genuinely needs a different value, that is a signal the base class's "
                . "contract does not fit it - change the contract on {$sealing_class} (or stop "
                . "extending it), rather than re-opening a sealed declaration.";

            $this->add_violation(
                $child_file,
                $line,
                $message,
                $code_snippet,
                $suggestion,
                'critical'
            );
        }
    }
}
