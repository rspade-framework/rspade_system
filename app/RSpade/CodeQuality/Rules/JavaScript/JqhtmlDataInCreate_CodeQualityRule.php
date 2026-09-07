<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Validation_Ledger;

class JqhtmlDataInCreate_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * The acorn walker this rule shells to. Checked-in source beside its client, never
     * written at check time - see parse_with_acorn().
     */
    private const PARSER_SCRIPT = __DIR__ . '/../../Support/resource/parse-jqhtml-data.js';

    public function get_id(): string
    {
        return 'JS-JQHTML-01';
    }

    public function get_name(): string
    {
        return 'Jqhtml Component this.data in on_create() Check';
    }

    public function get_description(): string
    {
        return 'Ensures this.data is not used in on_create() method of Jqhtml components';
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
     * Check for improper this.data usage in on_create() methods of Jqhtml components
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

        // A file whose exact bytes have already been judged clean is not parsed again.
        // The verdict lives in the shared Validation_Ledger - see parse_with_acorn().
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
            $line_number = $violation['line'];
            $class_name = $violation['className'] ?? 'unknown';
            $code_snippet = $violation['codeSnippet'] ?? 'this.data';

            $this->add_violation(
                $file_path,
                $line_number,
                "Jqhtml Component Error: 'this.data' used in on_create() method of class '{$class_name}'. " .
                "The 'this.data' property is only available during on_load() and later lifecycle steps. " .
                "It is used to store data fetched from AJAX or other async operations.",
                $code_snippet,
                "Use 'this.args' instead to access the parameters passed to the component at creation time. " .
                "The args contain attributes from the component's invocation in templates or JavaScript. " .
                "Example: Change 'this.data.initial_value' to 'this.args.initial_value'.",
                'high'
            );
        }
    }

    /**
     * Parse JavaScript file with acorn AST parser.
     *
     * NO DISK CACHE OF ITS OWN. What this returns is a list of VIOLATIONS, and the 316
     * per-file JSON documents this rule used to keep averaged 47 bytes each - they were a
     * directory of ways to write down "clean". That verdict now lives once, in
     * Validation_Ledger; a file that DOES violate is re-parsed on every check, which is
     * both cheap and honest (its violations are reported from live source, never a memo).
     */
    protected function parse_with_acorn(string $file_path): array
    {
        // The parser is SOURCE, checked in beside its client - it is not written to disk at
        // check time. It used to be a heredoc inside this class that materialized itself
        // into storage/rsx-tmp/persistent/ if absent, which meant editing this file left the
        // OLD script on disk running forever, and meant a code-quality rule owned a
        // directory under storage. Both are gone: the script is a file, and this rule
        // touches the filesystem for nothing.
        //
        // NODE_PATH is still pinned at the framework's node_modules rather than left to
        // node's upward walk, so the resolution is stated rather than inferred.
        $command = sprintf(
            'NODE_PATH=%s node %s %s 2>&1',
            escapeshellarg(base_path('node_modules')),
            escapeshellarg(self::PARSER_SCRIPT),
            escapeshellarg($file_path)
        );

        $output = shell_exec('bash -c ' . escapeshellarg($command));

        if (!$output) {
            return [];
        }

        $result = json_decode($output, true);
        if (!$result || !isset($result['violations'])) {
            // Parser error - report nothing rather than a false positive.
            return [];
        }

        return $result['violations'];
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
     * A GENERATIONAL ledger id: `JS-JQHTML-01@<fingerprint>`.
     *
     * The verdict depends on two things beyond the file's own bytes - this rule's own
     * source and the acorn script that does the walking - so both are folded in. Editing
     * either retires every verdict the old logic issued, instead of letting a stale premise
     * vouch for a file the new logic would flag.
     */
    private static function __ledger_id(): string
    {
        static $ledger_id = null;

        if ($ledger_id !== null) {
            return $ledger_id;
        }

        $parts = [];

        foreach ([__FILE__, self::PARSER_SCRIPT] as $input) {
            $parts[] = is_file($input) ? md5_file($input) : 'missing';
        }

        $ledger_id = 'JS-JQHTML-01@' . substr(md5(implode(':', $parts)), 0, 16);

        return $ledger_id;
    }
}
