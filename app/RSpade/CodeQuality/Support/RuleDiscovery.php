<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\CodeQuality\Support\ViolationCollector;

/**
 * THE ONE discovery of code-quality rules, for the manifest-time driver and for rsx:check
 * alike.
 *
 * The CodeQuality tree is deliberately outside the manifest scan, so rules are found on
 * disk rather than through the index. The walk is a RecursiveIteratorIterator and not a
 * glob: `glob('Rules/**' . '/*.php')` has no `**` in PHP and matched exactly one directory
 * level by accident, so a rule filed two levels deep was silently never discovered and
 * never ran.
 *
 * The FILE LIST is memoized for the process (the directory does not change under a running
 * build); rule OBJECTS are not, because each carries the collector of the pass that made
 * it.
 */
class RuleDiscovery
{
    /** [fqcn => absolute file path], memoized for the process. */
    private static ?array $rule_files = null;

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

        foreach (static::rule_files() as $fqcn => $file_path) {
            // Instantiate the rule
            $rule = new $fqcn($collector, $config);

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
     * [fqcn => absolute path] for every discovered rule class, memoized for the process.
     *
     * @return array<string,string>
     */
    public static function rule_files(): array
    {
        if (static::$rule_files !== null) {
            return static::$rule_files;
        }

        $rules_dir = base_path('app/RSpade/CodeQuality/Rules');

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
            if (str_ends_with($file_path, 'CodeQualityRule_Abstract.php')) {
                continue;
            }

            $metadata = static::extract_class_metadata($file_path);

            $fqcn = $metadata['fqcn'] ?? null;

            if (!isset($metadata['class']) || !$fqcn) {
                continue;
            }

            if (($metadata['extends'] ?? '') !== 'CodeQualityRule_Abstract') {
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

        static::$rule_files = $found;

        return static::$rule_files;
    }

    /**
     * The file a discovered rule class lives in, for fingerprinting.
     */
    public static function file_for(string $fqcn): ?string
    {
        return static::rule_files()[$fqcn] ?? null;
    }

    /**
     * Forget the memoized file list. The test seam for a fixture rule written to disk
     * after this process already looked.
     */
    public static function _forget_for_tests(): void
    {
        static::$rule_files = null;
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