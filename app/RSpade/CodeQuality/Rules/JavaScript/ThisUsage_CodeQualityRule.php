<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;
use App\RSpade\CodeQuality\Support\Validation_Ledger;

/**
 * JavaScript 'this' Usage Rule
 *
 * PHILOSOPHY: Enforce clear 'this' patterns in anonymous functions and static methods.
 *
 * RULES:
 * 1. Anonymous functions: MUST use 'const $element = $(this)' or 'const that = this' as first line
 * 2. Static methods: MUST NOT use naked 'this' - use Class_Name or 'const CurrentClass = this'
 * 3. Instance methods: EXEMPT - can use 'this' directly (no aliasing required)
 * 4. Arrow functions: EXEMPT - they inherit 'this' context
 * 5. Constructors: EXEMPT - 'this' allowed directly for property assignment
 *
 * PATTERNS:
 * - jQuery callback: const $element = $(this)        // Variable must start with $
 * - Anonymous function: const that = this            // Instance context aliasing
 * - Static (exact): Use Class_Name                   // When you need exact class
 * - Static (polymorphic): const CurrentClass = this  // When inherited classes need different behavior
 *
 * INSTANCE METHODS POLICY:
 * Instance methods (on_ready, on_load, etc.) can use 'this' directly.
 * This rule only enforces aliasing for anonymous functions and prohibits naked 'this' in static methods.
 */
class ThisUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-THIS-01';
    }

    public function get_name(): string
    {
        return "JavaScript 'this' Usage Check";
    }

    public function get_description(): string
    {
        return "Enforces clear 'this' patterns: jQuery callbacks use '\$element = \$(this)', instance methods use 'that = this'";
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check JavaScript file for improper 'this' usage
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Only check JavaScript files that contain ES6 classes
        if (!isset($metadata['class'])) {
            return; // Not a class file
        }

        // A file whose exact bytes have already been judged clean is not analyzed again.
        // The verdict lives in the shared Validation_Ledger, not in a directory of
        // per-file JSON documents - see the docblock on parse_with_acorn().
        $file_hash = static::__file_hash($file_path, $metadata);
        $ledger_id = static::__ledger_id();

        if ($file_hash !== null && Validation_Ledger::has_passed($ledger_id, $file_hash)) {
            return;
        }

        // Get violations from AST parser
        $violations = $this->parse_with_acorn($file_path);

        if (empty($violations)) {
            if ($file_hash !== null) {
                Validation_Ledger::record_pass($ledger_id, $file_hash);
            }

            return;
        }

        // Process violations
        foreach ($violations as $violation) {
            $this->add_violation(
                $file_path,
                $violation['line'],
                $violation['message'],
                $violation['codeSnippet'],
                $violation['remediation'],
                $this->get_default_severity()
            );
        }
    }

    /**
     * Analyze JavaScript file for 'this' usage violations via RPC server.
     *
     * NO DISK CACHE OF ITS OWN. What this returns is a list of VIOLATIONS, and a violation
     * list is not worth storing: 275 of the 275 files that had one of these JSON documents
     * held the two bytes `[]`, so the cache was a per-file, per-rule way of writing down
     * "clean" - which is precisely what Validation_Ledger is for, in ONE array. The few
     * files that DO violate are re-analyzed on every check, which is the cheap half of the
     * work and keeps their violations reported from live source rather than from a memo.
     */
    private function parse_with_acorn(string $file_path): array
    {
        // Analyze via RPC server (lazy starts if not running)
        return Js_CodeQuality_Rpc::analyze_this($file_path);
    }

    /**
     * The ledger key for one file: the manifest's own hash where the manifest knows the
     * file, a content sha1 where it does not.
     */
    private static function __file_hash(string $file_path, array $metadata): ?string
    {
        $file_hash = $metadata['hash'] ?? null;

        if (is_string($file_hash) && $file_hash !== '') {
            return $file_hash;
        }

        if (!is_file($file_path)) {
            return null;
        }

        return sha1_file($file_path) ?: null;
    }

    /**
     * A GENERATIONAL ledger id: `JS-THIS-01@<fingerprint>`.
     *
     * The verdict depends on two things beyond the file's own bytes - this rule's own
     * source, and the acorn analyzer in the node service's `quality` subsystem that
     * actually walks the AST. Folding both into the id means editing either one retires
     * every verdict the old logic issued, instead of letting a stale premise vouch for a
     * file the new logic would flag.
     */
    private static function __ledger_id(): string
    {
        static $ledger_id = null;

        if ($ledger_id !== null) {
            return $ledger_id;
        }

        $inputs = [
            __FILE__,
            base_path('app/RSpade/CodeQuality/Support/resource/quality-service.js'),
        ];

        $parts = [];

        foreach ($inputs as $input) {
            $parts[] = is_file($input) ? md5_file($input) : 'missing';
        }

        $ledger_id = 'JS-THIS-01@' . substr(md5(implode(':', $parts)), 0, 16);

        return $ledger_id;
    }
}
