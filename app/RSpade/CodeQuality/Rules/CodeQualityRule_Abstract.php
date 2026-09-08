<?php

namespace App\RSpade\CodeQuality\Rules;

use App\RSpade\CodeQuality\CodeQuality_Violation;
use App\RSpade\CodeQuality\Support\Source_Cache;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;

#[Instantiatable]
abstract class CodeQualityRule_Abstract
{
    /** The two kinds of rule. See kind(). */
    public const KIND_PER_FILE = 'per_file';
    public const KIND_CROSS_FILE = 'cross_file';

    protected ViolationCollector $collector;
    protected array $config = [];
    protected bool $enabled = true;

    /**
     * The build's ONE reader, tokenizer and parser of source files, handed to every rule by
     * the driver before check() is called.
     *
     * A RULE NEVER OPENS A FILE ITSELF. It does not call file_get_contents(), it does not
     * call token_get_all() or PhpToken::tokenize(), and it does not construct a
     * PhpParser\ParserFactory - all three used to be written once per rule, and the
     * retained streams and ASTs were 1.12 GB of a 1,237 MB cold build. The driver owns pass
     * memory; a rule only checks. Rule_Private_Cache_Test refuses every spelling, with no
     * whitelist.
     */
    protected ?Source_Cache $source = null;
    
    public function __construct(ViolationCollector $collector, array $config = [])
    {
        $this->collector = $collector;
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? true;
    }

    /**
     * The driver hands the pass's Source_Cache to every rule it is about to run.
     *
     * #[Sealed]-in-spirit: a rule never overrides this and never replaces the instance.
     */
    final public function set_source_cache(Source_Cache $source): void
    {
        $this->source = $source;
    }

    /**
     * The pass's source cache - how a rule reaches a file's bytes, tokens or AST.
     *
     * In a real pass the DRIVER hands one in, and every rule in that pass shares it, so a
     * file read for one rule is not read again for the next. A rule CONSTRUCTED OUTSIDE a
     * driver - a unit test that news up one rule and calls check() on a fixture - gets an
     * instance-scoped cache instead. That is still bounded (the same LRU) and still dies
     * with the object; what it loses is only the sharing, which a single-rule caller has
     * nothing to share with. It is never static, and a rule still may not build one itself.
     */
    final protected function source(): Source_Cache
    {
        if ($this->source === null) {
            $this->source = new Source_Cache();
        }

        return $this->source;
    }
    
    /**
     * Get the unique rule identifier (e.g., 'PHP-NAMING-01')
     */
    abstract public function get_id(): string;
    
    /**
     * Get human-readable rule name
     */
    abstract public function get_name(): string;
    
    /**
     * Get rule description
     */
    abstract public function get_description(): string;
    
    /**
     * Get file patterns this rule applies to (e.g., ['*.php', '*.js'])
     */
    abstract public function get_file_patterns(): array;

    /**
     * Check a file for violations
     *
     * @param string $file_path Absolute path to file
     * @param string $contents File contents (may be sanitized)
     * @param array $metadata Additional metadata from manifest
     */
    abstract public function check(string $file_path, string $contents, array $metadata = []): void;

    /**
     * Whether this rule supports checking Console Commands
     *
     * Rules that return true here will be given Console Command files to check
     * when using default paths or when a specific Console Command file is provided.
     * Rules supporting Console Commands MUST NOT rely on manifest metadata as
     * Console Commands are not indexed in the manifest.
     *
     * @return bool
     */
    #[Replaceable]
    public function supports_console_commands(): bool
    {
        return false;
    }
    
    /**
     * Check if this rule is enabled
     */
    #[Replaceable]
    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if this rule should be called during manifest scan
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     *
     * @return bool
     */
    #[Replaceable]
    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * PER-FILE or CROSS-FILE.
     *
     * A PER-FILE rule (the default) judges one file from its own bytes plus the metadata
     * the manifest already holds for it. The driver runs it once per CHANGED file, and
     * remembers a clean verdict in the Validation_Ledger against the file's hash - so an
     * unchanged file is never re-judged.
     *
     * A CROSS-FILE rule judges the tree: duplicate names, an index, a relationship between
     * files. The driver runs it ONCE per pass, after the per-file pass, and gates it on a
     * fingerprint of the manifest sections it declares in depends_on().
     *
     * @return string self::KIND_PER_FILE or self::KIND_CROSS_FILE
     */
    #[Replaceable]
    public function kind(): string
    {
        return self::KIND_PER_FILE;
    }

    /**
     * What a CROSS-FILE rule reads, so the driver can skip it when none of it moved.
     *
     * Each entry is either a manifest section name under Manifest::$data['data']
     * ('php_classes', 'php_subclass_index', 'auth', 'routes', 'models', ...) or the shape
     * 'files:<pattern>' naming a set of indexed files by basename glob ('files:*.php'),
     * whose fingerprint is the sorted list of matching paths and their hashes.
     *
     * WHEN IN DOUBT, LIST 'files:<your own patterns>'. That is the conservative answer: it
     * re-runs the rule whenever any file it could possibly look at changed. An UNDER-stated
     * dependency is a rule that silently stops firing; an over-stated one only costs time.
     *
     * Meaningless for a per-file rule - the file hash is the whole dependency there.
     *
     * @return array<int,string>
     */
    #[Replaceable]
    public function depends_on(): array
    {
        return [];
    }

    /**
     * Anything beyond this rule's OWN source file that changes its verdict.
     *
     * The driver fingerprints a rule as md5(<the rule's file>) . fingerprint_extra(), and
     * every ledger entry is filed under "<ID>@<that fingerprint>", so changing either
     * retires the rule's previous verdicts instead of letting them vouch for a stale
     * premise. Return the hash of a helper script, a generated index, a config value the
     * rule reads - anything the rule's answer depends on that its own bytes do not carry.
     *
     * @return string
     */
    #[Replaceable]
    public function fingerprint_extra(): string
    {
        return '';
    }

    /**
     * Get default severity for this rule
     */
    #[Replaceable]
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * Add a violation
     */
    protected function add_violation(
        string $file_path,
        int $line_number,
        string $message,
        ?string $code_snippet = null,
        ?string $suggestion = null,
        ?string $severity = null
    ): void {
        $violation = new CodeQuality_Violation(
            rule_id: $this->get_id(),
            file_path: $file_path,
            line_number: $line_number,
            message: $message,
            severity: $severity ?? $this->get_default_severity(),
            code_snippet: $code_snippet,
            suggestion: $suggestion
        );
        
        $this->collector->add($violation);
    }
    
    /**
     * Extract code snippet around a line
     */
    protected function get_code_snippet(array $lines, int $line_index, int $context = 2): string
    {
        $start = max(0, $line_index - $context);
        $end = min(count($lines) - 1, $line_index + $context);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $prefix = $i === $line_index ? '>>> ' : '    ';
            $snippet[] = $prefix . ($i + 1) . ': ' . $lines[$i];
        }

        return implode("\n", $snippet);
    }

    /**
     * Get all PHP files in the Console Commands directory
     *
     * This helper allows rules to optionally include Console Commands in their checks
     * without requiring these files to be in the manifest. Rules using this helper
     * MUST NOT rely on manifest metadata since Console Commands are not indexed.
     *
     * @return array Array of absolute file paths to PHP files in app/Console/Commands
     */
    protected static function get_console_command_files(): array
    {
        $commands_dir = base_path('app/Console/Commands');

        if (!is_dir($commands_dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($commands_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Check if a file is a Console Command
     *
     * @param string $file_path The file path to check
     * @return bool True if the file is in app/Console/Commands
     */
    protected static function is_console_command(string $file_path): bool
    {
        $commands_dir = base_path('app/Console/Commands');
        $normalized_path = str_replace('\\', '/', $file_path);
        $normalized_commands_dir = str_replace('\\', '/', $commands_dir);

        return str_starts_with($normalized_path, $normalized_commands_dir);
    }

    /**
     * Does this class, or any ANCESTOR of it, DECLARE a property of this name?
     *
     * A rule that reads a declaration out of the file in front of it is asking the right
     * question only while every class is one file. A model is not: a core model carries
     * `$table`, `$enums` and the rest on an abstract base and ships a three-line concrete an
     * application replaces, and an application's own override declares only what it changes.
     * So "this file does not declare it" is not "this class does not have it".
     *
     * Manifest records only, no file reads: `php_class_metadata()` gives each link's
     * `extends` and the file that declares it, and the file record carries the properties
     * that class DECLARES (reflection, filtered to the declaring class). The walk stops at a
     * class the index does not know and on a cycle.
     *
     * @param string $class_name    Simple class name to start from.
     * @param string $property_name Property name, without the `$`.
     * @param string|null $stop_at  Simple class name to stop BEFORE, when the search should
     *                              not credit a framework base (e.g. Rsx_Model_Abstract).
     */
    final protected function lineage_declares_property(
        string $class_name,
        string $property_name,
        ?string $stop_at = null
    ): bool {
        $seen = [];
        $current = $class_name;

        while ($current !== null && $current !== '' && !isset($seen[$current])) {
            if ($current === $stop_at) {
                return false;
            }

            $seen[$current] = true;

            $record = Manifest::php_class_metadata($current);

            if ($record === null) {
                return false;
            }

            $file = $record['file'] ?? null;
            $file_record = $file === null ? null : (Manifest::$data['data']['files'][$file] ?? null);

            if ($file_record !== null && $current !== $class_name) {
                foreach ($file_record['properties'] ?? [] as $property) {
                    if (($property['name'] ?? '') === $property_name) {
                        return true;
                    }
                }
            }

            $current = $record['extends'] ?? null;
        }

        return false;
    }
}
