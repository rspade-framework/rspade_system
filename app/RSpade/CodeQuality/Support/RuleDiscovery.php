<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * THE ONE discovery of code-quality rules, for the manifest-time driver and for rsx:check
 * alike. A rule comes from one of two places:
 *
 *   - FRAMEWORK RULES live in app/RSpade/CodeQuality/Rules/ and are found ON DISK. The walk
 *     is a RecursiveIteratorIterator and not a glob: `glob('Rules/**' . '/*.php')` has no `**`
 *     in PHP and matched exactly one directory level by accident, so a rule filed two levels
 *     deep was silently never discovered and never ran.
 *   - APPLICATION RULES are every other concrete class extending CodeQualityRule_Abstract,
 *     found BY CLASS through the manifest index - in a real tree, classes under rsx/ (the
 *     conventional home is rsx/lib/code_quality/, which is not enforced). An application
 *     rule's id must start with APP_RULE_ID_PREFIX, so no application rule can ever claim, or
 *     collide with, a framework rule id; discover_rules() refuses one that does not.
 *
 * A framework rule the application class-overrides stays a FRAMEWORK rule: its class name is
 * answered by the override loader with the application's class, which is the rule that runs,
 * under the framework's id.
 *
 * The framework FILE LIST is memoized for the process (the directory does not change under a
 * running build). The application list is read from the index each time it is asked for, so
 * it always describes the manifest the process currently holds. Rule OBJECTS are never
 * memoized, because each carries the collector of the pass that made it.
 */
class RuleDiscovery
{
    /** Where the framework's own rules live, manifest-relative. */
    public const FRAMEWORK_RULES_DIR = 'app/RSpade/CodeQuality/Rules';

    /** Every application rule id starts with this. */
    public const APP_RULE_ID_PREFIX = 'APP-';

    /** The base class every rule extends, by simple name. */
    private const RULE_BASE_CLASS = 'CodeQualityRule_Abstract';

    /** [fqcn => absolute file path] of the framework rules, memoized for the process. */
    private static ?array $framework_rule_files = null;

    /** [manifest hash => [fqcn => absolute file path]] of the application rules. */
    private static array $app_rule_files = [];

    /**
     * Discover and load all code quality rules
     *
     * @param ViolationCollector $collector The violation collector to pass to rules
     * @param array $config Configuration to pass to rules
     * @param bool $only_manifest_scan If true, only return rules with is_called_during_manifest_scan() = true
     * @param bool $exclude_manifest_scan If true, exclude rules with is_called_during_manifest_scan() = true
     * @return array Array of instantiated rule objects
     */
    public static function discover_rules(ViolationCollector $collector, array $config = [], bool $only_manifest_scan = false, bool $exclude_manifest_scan = false): array
    {
        $rules = [];

        $app_rule_files = static::app_rule_files();

        // [application rule id => the class that claimed it] - a second claimant is refused.
        $claimed = [];

        foreach (static::rule_files() as $fqcn => $file_path) {
            // Instantiate the rule
            $rule = new $fqcn($collector, $config);

            if (isset($app_rule_files[$fqcn])) {
                static::assert_app_rule_id($rule, $file_path);

                // The driver keys a rule's patterns, fingerprint and ledger verdicts by its
                // id, so two application classes answering one id would silently share - and
                // corrupt - all three. (The APP- prefix already keeps them clear of every
                // framework id.)
                $id = $rule->get_id();

                if (isset($claimed[$id])) {
                    throw new YoureDoingItWrongException(
                        "Application code quality rule id '{$id}' is declared by two classes: {$claimed[$id]} and {$fqcn}.\n"
                        . 'Every rule id must be unique. Rename one of them.',
                        0,
                        null,
                        $file_path,
                        1
                    );
                }

                $claimed[$id] = $fqcn;
            }

            // Check if rule is enabled
            if (!$rule->is_enabled()) {
                continue;
            }

            // Filter based on manifest scan requirements
            $is_manifest_scan_rule = $rule->is_called_during_manifest_scan();

            if ($only_manifest_scan && !$is_manifest_scan_rule) {
                continue; // Skip non-manifest-scan rules when only wanting manifest-scan rules
            }

            if ($exclude_manifest_scan && $is_manifest_scan_rule) {
                continue; // Skip manifest-scan rules when excluding them (e.g., during rsx:check)
            }

            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Refuse an application rule whose id does not start with APP_RULE_ID_PREFIX.
     *
     * The prefix is what makes the two namespaces disjoint: a framework release can add any
     * rule id it likes without ever colliding with one an application already declared, and
     * an application can never shadow a framework rule's verdicts by reusing its id.
     *
     * Thrown as YoureDoingItWrongException, which poisons the manifest: discovery runs inside
     * the manifest-time pass too, so the refusal re-fires on every load until the id is fixed.
     */
    public static function assert_app_rule_id(CodeQualityRule_Abstract $rule, string $file_path): void
    {
        $problem = static::app_rule_id_problem($rule, $file_path);

        if ($problem !== null) {
            throw new YoureDoingItWrongException($problem, 0, null, $file_path, 1);
        }
    }

    /**
     * Why $rule's id is not a valid application rule id, or null when it is.
     */
    public static function app_rule_id_problem(CodeQualityRule_Abstract $rule, string $file_path): ?string
    {
        $id = $rule->get_id();

        if (str_starts_with($id, static::APP_RULE_ID_PREFIX)) {
            return null;
        }

        return 'Application code quality rule ' . get_class($rule) . " declares the id '{$id}'.\n"
            . "An application rule's get_id() must start with '" . static::APP_RULE_ID_PREFIX . "' "
            . "(e.g. '" . static::APP_RULE_ID_PREFIX . "RETIRED-VOCAB-01'), so it can never collide with a framework rule id.\n"
            . 'File: ' . $file_path . "\n"
            . 'See: rsx:man coding_standards, APPLICATION RULES.';
    }

    /**
     * [fqcn => absolute path] for every discovered rule class, framework and application.
     *
     * @return array<string,string>
     */
    public static function rule_files(): array
    {
        $found = static::framework_rule_files() + static::app_rule_files();

        ksort($found);

        return $found;
    }

    /**
     * [fqcn => absolute path] for every rule under FRAMEWORK_RULES_DIR, memoized for the
     * process.
     *
     * @return array<string,string>
     */
    public static function framework_rule_files(): array
    {
        if (static::$framework_rule_files !== null) {
            return static::$framework_rule_files;
        }

        $rules_dir = base_path(static::FRAMEWORK_RULES_DIR);

        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rules_dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $file_path = $entry->getPathname();

            // The base class itself is the contract, not a rule.
            if (str_ends_with($file_path, static::RULE_BASE_CLASS . '.php')) {
                continue;
            }

            $metadata = static::extract_class_metadata($file_path);

            $fqcn = $metadata['fqcn'] ?? null;

            if (!isset($metadata['class']) || !$fqcn) {
                continue;
            }

            if (($metadata['extends'] ?? '') !== static::RULE_BASE_CLASS) {
                continue;
            }

            // A rule the application overrides is never included: its class name is answered
            // by the override loader with the application's class, which is the rule that runs.
            $override = Manifest::override_for($file_path);

            if ($override !== null) {
                if (!class_exists($fqcn)) {
                    shouldnt_happen("CodeQuality rule class '{$fqcn}' is overridden by {$override['by']}, but the override did not load");
                }

                $found[$fqcn] = base_path($override['by']);

                continue;
            }

            require_once $file_path;

            if (!class_exists($fqcn)) {
                shouldnt_happen(
                    "CodeQuality rule class '{$fqcn}' found in file '{$file_path}' but class_exists() failed after require_once"
                );
            }

            $found[$fqcn] = $file_path;
        }

        ksort($found);

        static::$framework_rule_files = $found;

        return static::$framework_rule_files;
    }

    /**
     * [fqcn => absolute path] for every APPLICATION rule: each concrete class the manifest
     * index says extends CodeQualityRule_Abstract, other than the framework's own rules.
     *
     * @return array<string,string>
     */
    public static function app_rule_files(): array
    {
        // Memoized per INDEX: the key is the saved manifest's hash, so a rebuild in the same
        // process (a build followed by rsx:check) is never answered from the old index.
        $index_hash = (string) (Manifest::$data['hash'] ?? '');

        if ($index_hash !== '' && isset(static::$app_rule_files[$index_hash])) {
            return static::$app_rule_files[$index_hash];
        }

        $found = static::select_app_rule_files(
            Manifest::php_class_records_extending(static::RULE_BASE_CLASS, true),
            Manifest::overrides()
        );

        if ($index_hash !== '') {
            static::$app_rule_files = [$index_hash => $found];
        }

        return $found;
    }

    /**
     * The application rules among a set of manifest class records.
     *
     * Excluded: a class filed under FRAMEWORK_RULES_DIR (the disk walk owns those), and a
     * class OVERRIDE of a framework rule (it runs as the framework rule it replaces, under
     * that rule's id - framework_rule_files() maps it). Every class is loaded here, so a
     * record the autoloader cannot satisfy fails now rather than mid-pass.
     *
     * @param array<string,array{file:string,fqcn:string}> $records  [class => hot class record]
     * @param array<string,array{by:string}> $overrides              Manifest::overrides()
     * @return array<string,string>
     */
    public static function select_app_rule_files(array $records, array $overrides): array
    {
        $override_files = [];

        foreach ($overrides as $override) {
            $override_files[$override['by']] = true;
        }

        $found = [];

        foreach ($records as $record) {
            $file = $record['file'];

            if (Rsx_Paths::under_framework($file, 'CodeQuality/Rules/') || isset($override_files[$file])) {
                continue;
            }

            $fqcn = $record['fqcn'];

            if (!class_exists($fqcn)) {
                shouldnt_happen("Application code quality rule class '{$fqcn}' is indexed at '{$file}' but did not load");
            }

            $found[$fqcn] = Rsx_Paths::absolute($file);
        }

        ksort($found);

        return $found;
    }

    /**
     * Is this file the source of a discovered rule (framework or application), or the
     * contract every rule extends? Accepts an absolute path or a manifest key.
     */
    public static function is_rule_source(string $file_path): bool
    {
        $relative = Rsx_Paths::relative($file_path);

        if ($relative === static::FRAMEWORK_RULES_DIR . '/' . static::RULE_BASE_CLASS . '.php') {
            return true;
        }

        foreach (static::rule_files() as $rule_file) {
            if (Rsx_Paths::relative($rule_file) === $relative) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this file an APPLICATION rule? Accepts an absolute path or a manifest key.
     */
    public static function is_app_rule_source(string $file_path): bool
    {
        $relative = Rsx_Paths::relative($file_path);

        foreach (static::app_rule_files() as $rule_file) {
            if (Rsx_Paths::relative($rule_file) === $relative) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every file whose bytes decide a rule's behaviour, for fingerprinting: the rule's own
     * file, then each ancestor class between it and CodeQualityRule_Abstract that the index
     * knows. An application may give several rules a shared base of its own, and editing that
     * base must retire the verdicts of every rule beneath it.
     *
     * @return array<int,string> absolute paths, the rule's own file first
     */
    public static function files_for(string $fqcn): array
    {
        $own = static::rule_files()[$fqcn] ?? null;

        if ($own === null) {
            return [];
        }

        $files = [$own];

        $simple = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

        foreach (Manifest::php_get_lineage($simple) as $ancestor) {
            if ($ancestor === static::RULE_BASE_CLASS) {
                break;
            }

            $record = Manifest::php_class_metadata($ancestor);

            if ($record !== null && isset($record['file'])) {
                $files[] = Rsx_Paths::absolute($record['file']);
            }
        }

        return $files;
    }

    /**
     * Forget the memoized file lists. The test seam for a fixture rule written to
     * disk after this process already looked.
     */
    public static function _forget_for_tests(): void
    {
        static::$framework_rule_files = null;
        static::$app_rule_files = [];
    }

    /**
     * Extract basic class metadata from a PHP file using token parsing
     * This is a simplified version that doesn't require Manifest
     *
     * @param string $file_path Path to the PHP file
     * @return array Metadata array with namespace, class, fqcn, extends
     */
    protected static function extract_class_metadata(string $file_path): array
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);

        $metadata = [];
        $namespace = '';
        $class = '';
        $extends = '';

        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            // Look for namespace
            if ($tokens[$i][0] === T_NAMESPACE) {
                $i++;
                while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                // PHP 8.4+ uses T_NAME_QUALIFIED for fully qualified names
                if ($i < $count && (defined('T_NAME_QUALIFIED') && $tokens[$i][0] === T_NAME_QUALIFIED)) {
                    $namespace = $tokens[$i][1];
                    $i++;
                } else {
                    // Fallback for older PHP versions
                    while ($i < $count && ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR)) {
                        $namespace .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                        $i++;
                    }
                }
            }

            // Look for class
            if ($tokens[$i][0] === T_CLASS) {
                $i++;
                while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                if ($i < $count && $tokens[$i][0] === T_STRING) {
                    $class = $tokens[$i][1];
                    $i++;

                    // Look for extends
                    while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                    if ($i < $count && $tokens[$i][0] === T_EXTENDS) {
                        $i++;
                        while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                            $i++;
                        }
                        if ($i < $count && $tokens[$i][0] === T_STRING) {
                            $extends = $tokens[$i][1];
                        }
                    }
                    break; // Found the class, stop parsing
                }
            }

            $i++;
        }

        if ($class) {
            $metadata['class'] = $class;
            $metadata['namespace'] = $namespace;
            $metadata['fqcn'] = $namespace ? $namespace . '\\' . $class : $class;
            $metadata['extends'] = $extends;
        }

        return $metadata;
    }
}