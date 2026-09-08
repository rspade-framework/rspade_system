<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * PHP-PARENT-CHAIN-01 - Default parent-call chaining for method overrides.
 *
 * Contract: any method that overrides a same-named method declared by an ANCESTOR
 * class visible in the manifest MUST call parent::<method>() somewhere in its body,
 * UNLESS the nearest ancestor that declares the method is abstract or marked
 * #[Replaceable]. Chaining is the default; #[Replaceable] is the explicit opt-out
 * (the inverse of `final`).
 *
 * Motivating incident: a booted() override that forgot parent::booted() silently
 * dropped the site global scope (cross-tenant read leak). PHP does not require
 * chaining a parent override - losing the chain fails OPEN and SILENT. This rule
 * makes chaining the enforced default.
 *
 * Scope covers EVERYTHING the child declares, including __construct and magic
 * methods (owner decision 1). Overrides that legitimately fully replace opt out
 * with #[Replaceable] on the parent method.
 *
 * Detection is AST-derived (nikic/php-parser), never a regex over raw source, so a
 * comment or string literal mentioning parent::method() cannot spoof it - the parse
 * happens once, inside Source_Cache::declared_members(), and this rule reads the
 * summary it produces. The manifest is used ONLY for lineage (php_get_lineage) and
 * ancestor file lookup (php_find_class); per-method modifiers (abstract/attributes)
 * come from that summary rather than the manifest's method maps, which lack the
 * abstract flag and omit protected methods.
 */
class ParentCallChain_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'PHP-PARENT-CHAIN-01';



    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Parent Call Chaining Enforcement';
    }

    public function get_description(): string
    {
        return 'Requires a method override to call parent::<method>() unless the nearest declaring '
            . 'ancestor method is abstract or marked #[Replaceable]';
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
     * Runs during the manifest scan: a missing parent-call is a FATAL
     * manifest-time error (the end state per the design).
     *
     * A violation aborts the manifest build via YoureDoingItWrongException and
     * sets the manifest_is_bad poison flag, forcing a full rebuild + re-fire on
     * every subsequent load until the offending override is fixed (or the parent
     * method is marked #[Replaceable]).
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
     * Every indexed PHP file and the lineage indexes: an override's obligation is decided by
     * its nearest declaring ancestor, so any ancestor moving re-judges the child.
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
     * Runs once per build. Scopes validated classes to the files that changed in
     * this scan (falling back to the full manifest when nothing is flagged changed,
     * i.e. a full rebuild), and uses the manifest for lineage / ancestor lookups.
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
            // Only PHP class files carry an override obligation.
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

            // Build the nearest -> root ancestry (class name + absolute file path)
            // from the manifest. Vendor ancestors are naturally absent: the
            // manifest never scans vendor/, so php_get_lineage terminates at the
            // last framework/app ancestor and php_find_class throws for the rest -
            // an override of a vendor method is therefore never flagged.
            $ancestry = [];
            foreach (Manifest::php_get_lineage($class_name) as $ancestor_name) {
                try {
                    $ancestor_rel = Manifest::php_find_class($ancestor_name);
                } catch (\Throwable $e) {
                    // Not in the manifest (vendor or unresolved) - stop climbing.
                    break;
                }
                $ancestry[] = [
                    'class' => $ancestor_name,
                    'file' => base_path($ancestor_rel),
                ];
            }

            $this->evaluate_class_methods($abs_file, $class_name, $ancestry);
        }
    }

    /**
     * Core validation for one child class against an explicit nearest -> root
     * ancestry (each entry: ['class' => simpleName, 'file' => absolutePath]).
     *
     * This is the testable seam: production builds the ancestry from the manifest;
     * tests build it from synthetic fixture files so lineage resolution, nearest-
     * declarer anchoring, abstract/#[Replaceable] exemption, and parent-call
     * detection are all exercised over real AST without touching the manifest.
     *
     * EVERY STRUCTURAL FACT COMES FROM Source_Cache::declared_members(). The rule holds no
     * AST: the child's parent-calls and its exception markers are fields of the summary, and
     * an ancestor is asked once for its whole member list rather than once per method. The
     * summary outlives the AST it was built from, which is what stops four ancestry rules
     * re-parsing the same seven hundred files one after another.
     */
    private function evaluate_class_methods(string $child_file, string $child_class, array $ancestry): void
    {
        $child = $this->source()->declared_members($child_file, $child_class);

        if (empty($child['methods'])) {
            return;
        }

        $contents = $this->source()->content($child_file);
        $lines = $contents === '' ? [] : explode("\n", $contents);

        foreach ($child['methods'] as $method_key => $child_method) {
            // Abstract / bodiless declarations cannot call parent - not an override
            // in the calling sense.
            if (!$child_method['has_body']) {
                continue;
            }

            $method_name = $child_method['name'];

            // Find the nearest ancestor that DECLARES this method.
            $declarer = $this->find_nearest_declarer($ancestry, $method_key);

            if ($declarer === null) {
                // Fresh method, or overrides a vendor method the manifest cannot
                // see - no chaining obligation.
                continue;
            }

            // Exempt: the nearest declarer is abstract (cannot call it).
            if ($declarer['method']['abstract']) {
                continue;
            }

            // Exempt: the nearest declarer opted out via #[Replaceable].
            if (isset($declarer['method']['attributes']['replaceable'])) {
                continue;
            }

            // Obligation stands: the child body must call parent::<method>(). A dynamic
            // parent::{$x}() is recorded as '*' and counts as satisfied - the parent IS
            // invoked and the name is unprovable, so failing open is correct.
            if (isset($child_method['parent_calls'][$method_key]) || isset($child_method['parent_calls']['*'])) {
                continue;
            }

            // Documented-unsupported edge syntaxes (callable-array,
            // call_user_func('parent::x'), explicit parent-classname) escape via
            // @PHP-PARENT-CHAIN-01-EXCEPTION.
            if (isset($child_method['exceptions'][self::RULE_ID])) {
                continue;
            }

            $line = $child_method['line'];
            $ancestor_class = $declarer['class'];
            $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $message = "{$child_class}::{$method_name}() overrides {$ancestor_class}::{$method_name}() "
                . "but does not call parent::{$method_name}().";

            $suggestion = "Call parent::{$method_name}(...) somewhere in {$child_class}::{$method_name}(), "
                . "OR mark {$ancestor_class}::{$method_name}() #[Replaceable] if overriders are meant to "
                . "fully replace it (no parent call).";

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

    /**
     * Walk the nearest -> root ancestry and return the first ancestor that declares
     * $method_key (a LOWERCASED method name), as ['class' => simpleName, 'method' => the
     * member summary]. Null when no ancestry class declares it.
     *
     * An unparseable/unlocatable ancestor file summarizes to no members, so it is skipped
     * exactly as before - we cannot confirm a declaration there - and climbing continues.
     */
    private function find_nearest_declarer(array $ancestry, string $method_key): ?array
    {
        foreach ($ancestry as $ancestor) {
            $members = $this->source()->declared_members($ancestor['file'], $ancestor['class']);

            if (isset($members['methods'][$method_key])) {
                return ['class' => $ancestor['class'], 'method' => $members['methods'][$method_key]];
            }
        }

        return null;
    }
}
