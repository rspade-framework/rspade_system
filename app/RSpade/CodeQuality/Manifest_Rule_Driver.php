<?php

namespace App\RSpade\CodeQuality;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\CodeQuality\Support\RuleDiscovery;
use App\RSpade\CodeQuality\Support\Source_Cache;
use App\RSpade\CodeQuality\Support\Validation_Ledger;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * THE DRIVER OWNS THE PASS; A RULE ONLY CHECKS.
 *
 * Everything a code-quality pass costs is decided here and nowhere else:
 *
 *   - DISCOVERY happens once per process (RuleDiscovery), not once per pass and not twice
 *     in two places that had drifted apart.
 *   - PARSING goes through ONE Source_Cache with an LRU bound, so peak memory is a
 *     function of files IN FLIGHT and not of files in the tree. The pass used to hold
 *     every file's token stream and AST in per-rule static arrays: 1.12 GB of a 1,237 MB
 *     cold build.
 *   - THE LOOP is CHANGED FILES OUTER, matching per-file rules inner - one read of each
 *     file for every rule that wants it, instead of one full walk of the index per rule
 *     with a fresh read per rule per file.
 *   - THE INCREMENTAL DECISION is the Validation_Ledger's, under "<RULE ID>@<fingerprint>"
 *     where the fingerprint is md5 of the rule's own source plus its fingerprint_extra().
 *     A file whose hash already passed a rule at THIS fingerprint is not judged again;
 *     editing the rule retires every verdict it ever recorded.
 *   - CROSS-FILE RULES run once, after the per-file pass, each gated on a fingerprint of
 *     the manifest sections it declares in depends_on(), recorded in the ledger's DERIVED
 *     slots (which the manifest prune does not govern - a derived premise is not a file
 *     hash, and filing it as one had it deleted on every flush).
 *
 * rsx:check runs THIS driver too, with scope = every indexed file, so there is one loop,
 * one discovery, one cache and one ledger contract for both entry points.
 *
 * See: rsx:man code_quality.
 */
#[Instantiatable]
class Manifest_Rule_Driver
{
    private ViolationCollector $collector;

    private Source_Cache $source;

    /** @var array<int,CodeQualityRule_Abstract> */
    private array $per_file = [];

    /** @var array<int,CodeQualityRule_Abstract> */
    private array $cross_file = [];

    /** [rule id => array<int,string>] compiled basename patterns, once per rule. */
    private array $patterns = [];

    /** [rule id => string] md5(rule source) . fingerprint_extra(), once per rule. */
    private array $fingerprints = [];

    /** [<scope>|<dependency> => digest] one walk of the index per declared dependency. */
    private array $dependency_digests = [];

    /** Test-tree file paths and the class names they declare; null until first asked. */
    private ?array $test_names = null;

    /** [rule id => [absolute, relative]] the first indexed file matching a rule's patterns. */
    private array $first_matching_file = [];

    /**
     * Throw on the first violation (the manifest pass, where a violation poisons the
     * build) rather than collecting them all (rsx:check, which reports).
     */
    private bool $throw_on_violation;

    /** Honor an `@<RULE-ID>-EXCEPTION` marker in the file being checked. */
    private bool $honor_exception_comments;

    /**
     * What "contents" means to the rules this pass runs, as fn(string $absolute): string.
     *
     * The manifest pass hands rules the file's RAW bytes; rsx:check hands them the SANITIZED
     * copy (comments blanked, string CONTENTS blanked in JS) that its rules are written
     * against. The entry point declares which, because the two rule sets genuinely differ -
     * and a rule that wants the other one asks $this->source() for it by name.
     *
     * @var null|callable(string):string
     */
    private $contents_resolver = null;

    /**
     * @param bool $only_manifest_scan     Only rules declaring is_called_during_manifest_scan()
     * @param bool $exclude_manifest_scan  Everything BUT those rules
     */
    public function __construct(
        ViolationCollector $collector,
        array $config = [],
        bool $only_manifest_scan = false,
        bool $exclude_manifest_scan = false,
        bool $throw_on_violation = false,
        bool $honor_exception_comments = false,
        ?Source_Cache $source = null,
        ?callable $contents_resolver = null
    ) {
        $this->contents_resolver = $contents_resolver;
        $this->collector = $collector;
        $this->throw_on_violation = $throw_on_violation;
        $this->honor_exception_comments = $honor_exception_comments;
        // The manifest pass hands in the BUILD's cache, so a file the fixer already read is
        // not read again; rsx:check has no build in flight and gets one of its own.
        $this->source = $source ?? new Source_Cache();

        $rules = RuleDiscovery::discover_rules($collector, $config, $only_manifest_scan, $exclude_manifest_scan);

        foreach ($rules as $rule) {
            $rule->set_source_cache($this->source);

            $id = $rule->get_id();

            $this->patterns[$id] = static::__compile_patterns($rule->get_file_patterns());
            $this->fingerprints[$id] = static::__fingerprint($rule);

            if ($rule->kind() === CodeQualityRule_Abstract::KIND_CROSS_FILE) {
                $this->cross_file[] = $rule;

                continue;
            }

            $this->per_file[] = $rule;
        }
    }

    /**
     * The pass's source cache, so a caller that reads files alongside the rules (the
     * manifest's own metadata extraction) reads through the same LRU.
     */
    public function source_cache(): Source_Cache
    {
        return $this->source;
    }

    /**
     * Every rule this driver holds, per-file rules first.
     *
     * THE ONE SET OF RULE OBJECTS for the pass. A caller that has to reach a rule directly
     * (rsx:check's directory-level check_root()/check_migrations() entry points) takes them
     * from here rather than discovering a second set - a second set would carry no source
     * cache, and a rule reaching for one it was never given fails loudly.
     *
     * @return array<int,CodeQualityRule_Abstract>
     */
    public function all_rules(): array
    {
        return array_merge($this->per_file, $this->cross_file);
    }

    /**
     * Every rule this driver holds, as [id => kind]. Reporting and tests.
     *
     * @return array<string,string>
     */
    public function rule_kinds(): array
    {
        $kinds = [];

        foreach ($this->per_file as $rule) {
            $kinds[$rule->get_id()] = CodeQualityRule_Abstract::KIND_PER_FILE;
        }

        foreach ($this->cross_file as $rule) {
            $kinds[$rule->get_id()] = CodeQualityRule_Abstract::KIND_CROSS_FILE;
        }

        ksort($kinds);

        return $kinds;
    }

    /**
     * Run the pass over $files, then the cross-file rules.
     *
     * $files is a list of MANIFEST-RELATIVE paths. A path the index does not know is still
     * checked (rsx:check reaches files the manifest never sees) - it simply carries no
     * hash, and a rule verdict about it is not remembered.
     *
     * @param array<int,string> $files
     * @param bool $include_cross_file Run the cross-file rules after the per-file pass
     */
    public function run(array $files, bool $include_cross_file = true): void
    {
        $index = Manifest::$data['data']['files'] ?? [];

        $base = base_path() . '/';

        foreach ($files as $path) {
            $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
            $metadata = $index[$relative] ?? [];
            $absolute = static::__absolute($path);

            if (!is_file($absolute)) {
                continue;
            }

            $hash = (string) ($metadata['hash'] ?? '');
            $basename = basename($relative);
            $is_console_command = str_contains($absolute, '/app/Console/Commands/');
            $contents = null;

            foreach ($this->per_file as $rule) {
                $id = $rule->get_id();

                if ($is_console_command && !$rule->supports_console_commands()) {
                    continue;
                }

                if (!static::__matches($basename, $this->patterns[$id])) {
                    continue;
                }

                $ledger_id = $id . '@' . $this->fingerprints[$id];

                if ($hash !== '' && Validation_Ledger::has_passed($ledger_id, $hash)) {
                    continue;
                }

                if ($contents === null) {
                    $contents = $this->contents_resolver === null
                        ? $this->source->content($absolute, $hash)
                        : ($this->contents_resolver)($absolute);
                }

                // THE MARKER IS READ OFF THE RAW BYTES, never off $contents. $contents may
                // be the SANITIZED copy (comments blanked), and the marker lives in a
                // comment - checking the sanitized text would find it in no file at all.
                if ($this->honor_exception_comments
                    && str_contains($this->source->content($absolute, $hash), '@' . $id . '-EXCEPTION')) {
                    continue;
                }

                $enhanced = $metadata;

                if (isset($metadata['code_quality_metadata'][$id])) {
                    $enhanced['rule_metadata'] = $metadata['code_quality_metadata'][$id];
                }

                $before = $this->__violation_count();

                $rule->check($absolute, $contents, $enhanced);

                if ($this->__violation_count() > $before) {
                    $this->__report();

                    continue;
                }

                if ($hash !== '') {
                    Validation_Ledger::record_pass($ledger_id, $hash);
                }
            }

            // One file's bytes are of no further use once every rule has seen them.
            $this->source->forget($absolute, $hash);
        }

        if ($include_cross_file) {
            $this->__run_cross_file();
        }
    }

    /**
     * Release the pass's memory and write the ledger.
     *
     * Called explicitly at the end of a pass rather than left to shutdown: a fatal later in
     * the same process must not cost the work this pass already did.
     */
    public function finish(): void
    {
        $this->source->release();
        Validation_Ledger::flush();
    }

    /**
     * Each cross-file rule once, gated on the fingerprint of what it says it reads.
     */
    private function __run_cross_file(): void
    {
        foreach ($this->cross_file as $rule) {
            $id = $rule->get_id();
            $ledger_id = $id . '@' . $this->fingerprints[$id];
            [$deps, $test_deps] = $this->__dependency_fingerprints($rule);

            if (Validation_Ledger::has_passed_derived($ledger_id, 'deps', $deps)
                && ($test_deps === null
                    || Validation_Ledger::has_passed_derived($ledger_id, 'tdeps', $test_deps))) {
                continue;
            }

            // A cross-file rule iterates the index itself; the file it is handed is only a
            // starting point, and an empty one is honest when nothing matches.
            [$absolute, $relative] = $this->__first_matching_file($id);

            $contents = $absolute === null ? '' : $this->source->content($absolute);
            $metadata = $relative === null ? [] : (Manifest::$data['data']['files'][$relative] ?? []);

            $before = $this->__violation_count();

            // Per-rule wall time on the MANIFEST debug channel, the same line the module
            // pipeline prints. A cross-file rule is the most expensive thing a build does
            // and the only way to see which one is to be told.
            $started = microtime(true);
            $rule->check($absolute ?? '', $contents, $metadata);
            console_debug('MANIFEST', 'Cross-file rule ' . $id . ': ' . round((microtime(true) - $started) * 1000, 2) . 'ms');

            if ($this->__violation_count() > $before) {
                $this->__report();

                continue;
            }

            Validation_Ledger::record_pass_derived($ledger_id, 'deps', $deps);

            if ($test_deps !== null) {
                Validation_Ledger::record_pass_derived($ledger_id, 'tdeps', $test_deps);
            }
        }
    }

    /**
     * The first indexed file matching a rule's patterns, as [absolute, relative].
     *
     * @return array{0:?string,1:?string}
     */
    private function __first_matching_file(string $rule_id): array
    {
        if (isset($this->first_matching_file[$rule_id])) {
            return $this->first_matching_file[$rule_id];
        }

        return $this->first_matching_file[$rule_id] = $this->__scan_for_first_matching_file($rule_id);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function __scan_for_first_matching_file(string $rule_id): array
    {
        foreach (Manifest::$data['data']['files'] ?? [] as $relative => $metadata) {
            if (!static::__matches(basename($relative), $this->patterns[$rule_id])) {
                continue;
            }

            $absolute = static::__absolute($relative);

            if (is_file($absolute)) {
                return [$absolute, $relative];
            }
        }

        return [null, null];
    }

    /**
     * The TWO ledger keys that decide whether a cross-file rule has to run.
     *
     * A hash over everything the rule declares it reads, computed twice:
     *
     *   [0] the `deps` slot - over the tree WITHOUT the test trees. Always checked, always
     *                     recorded. This is the key that makes the test-tree transition
     *                     free: 635 fixtures entering on the first command of a test run,
     *                     and leaving again on the first served request after it, do not
     *                     move it, so a rule that passed before the run passes after it
     *                     without being re-judged.
     *   [1] the `tdeps` slot - null outside a test run; inside one, a hash of BOTH halves. It
     *                     is what keeps the fixtures covered: the rule still runs under a
     *                     test run whenever a fixture changed, AND whenever anything
     *                     outside the test trees changed since the last time it ran with
     *                     the fixtures present (that is why the non-test hash is mixed in
     *                     rather than hashing the fixtures alone - an application change
     *                     can create a violation whose other half is a fixture).
     *
     * The transition itself moves NEITHER key, which is the whole point: a fixture cannot
     * change a verdict about a file it may not be referenced from.
     *
     * An empty depends_on() means "I read the whole index": the fingerprint is then the
     * build's own hash, so the rule runs whenever anything at all changed. That is the
     * conservative answer, and it is what a rule gets for declaring nothing.
     *
     * @return array{0:string,1:?string}
     */
    private function __dependency_fingerprints(CodeQualityRule_Abstract $rule): array
    {
        $declared = $rule->depends_on();

        if (empty($declared)) {
            return [(string) (Manifest::$data['hash'] ?? ''), null];
        }

        $plain = [];
        $full = [];

        foreach ($declared as $dependency) {
            $plain[] = $dependency . ':' . $this->__dependency_digest($dependency, true);
            $full[] = $dependency . ':' . $this->__dependency_digest($dependency, false);
        }

        $plain_hash = md5(implode('|', $plain));
        $full_hash = md5(implode('|', $full));

        if ($plain_hash === $full_hash) {
            // No test tree is indexed (an ordinary build, or a test run over a tree with no
            // fixtures): the two halves are the same tree and one key says everything.
            return [$plain_hash, null];
        }

        return [$plain_hash, md5($plain_hash . '|' . $full_hash)];
    }

    /**
     * One declared dependency's digest, memoized for the pass.
     *
     * Seventeen cross-file rules declare `files:*.php` between them; computing it once per
     * rule was seventeen walks of the file map for one answer.
     */
    private function __dependency_digest(string $dependency, bool $exclude_tests): string
    {
        $memo_key = ($exclude_tests ? 'x|' : 'a|') . $dependency;

        if (isset($this->dependency_digests[$memo_key])) {
            return $this->dependency_digests[$memo_key];
        }

        if (str_starts_with($dependency, 'files:')) {
            $pattern = static::__compile_patterns([substr($dependency, 6)]);
            $rows = [];

            foreach (Manifest::$data['data']['files'] ?? [] as $relative => $metadata) {
                if ($exclude_tests && Rsx_Paths::is_test_tree($relative)) {
                    continue;
                }

                if (static::__matches(basename($relative), $pattern)) {
                    $rows[] = $relative . '=' . ($metadata['hash'] ?? '');
                }
            }

            sort($rows);

            return $this->dependency_digests[$memo_key] = md5(implode("\n", $rows));
        }

        $section = Manifest::$data['data'][$dependency] ?? null;

        if ($exclude_tests) {
            $section = static::__without_test_entries($section, $this->__test_names());
        }

        return $this->dependency_digests[$memo_key] = md5(serialize($section));
    }

    /**
     * Every KEY and every VALUE that names a test-tree file or a class declared in one.
     *
     * The declared index sections are keyed and valued by class names and file paths -
     * `php_classes` by class, `php_subclass_index` by parent class with descendant class
     * names as values, `models` by model class, `attribute_index` by attribute with rows
     * carrying a `file`. One set of names is therefore enough to take the test trees back
     * out of any of them without the driver knowing a single section's shape.
     *
     * @return array<string,bool>
     */
    private function __test_names(): array
    {
        if ($this->test_names !== null) {
            return $this->test_names;
        }

        $names = [];

        foreach (Manifest::$data['data']['files'] ?? [] as $relative => $metadata) {
            if (!Rsx_Paths::is_test_tree($relative)) {
                continue;
            }

            $names[$relative] = true;

            if (isset($metadata['class'])) {
                $names[$metadata['class']] = true;
            }

            // A GENERATED STUB COUNTS AS ITS SOURCE. The model and controller stub
            // generators write a `.js` file under `storage/rsx-build/`, which is not in a
            // test tree by path but exists only because a fixture does - so a fixture
            // arriving added ~30 rows to `files:*` and `files:*.js` and moved every digest
            // that reads them.
            if (isset($metadata['js_stub']) && is_string($metadata['js_stub'])) {
                $names[$metadata['js_stub']] = true;
            }
        }

        return $this->test_names = $names;
    }

    /**
     * $section with every test-tree key, value and `file`-bearing row removed.
     *
     * @param array<string,bool> $test_names
     */
    private static function __without_test_entries(mixed $section, array $test_names): mixed
    {
        if (!is_array($section)) {
            return $section;
        }

        $out = [];

        foreach ($section as $key => $value) {
            if (is_string($key) && isset($test_names[$key])) {
                continue;
            }

            if (is_string($value) && isset($test_names[$value])) {
                continue;
            }

            if (is_array($value) && isset($value['file']) && is_string($value['file'])
                && isset($test_names[$value['file']])) {
                continue;
            }

            if (!is_array($value)) {
                $out[$key] = $value;

                continue;
            }

            $filtered = static::__without_test_entries($value, $test_names);

            // AN ENTRY THAT FILTERED DOWN TO NOTHING IS DROPPED, not kept as an empty
            // array: `php_subclass_index` only holds a parent BECAUSE it has descendants,
            // so a parent whose every descendant was a fixture must vanish exactly as it
            // does in a tree that never had them. The rule applies in both directions
            // (this filter runs over the plain tree too), so it can never make two equal
            // trees look different.
            if ($filtered === []) {
                continue;
            }

            $out[$key] = $filtered;
        }

        // A LIST IS RE-INDEXED, an associative array is not. `php_subclass_index` maps a
        // parent to a LIST of descendants and `attribute_index` an attribute to a LIST of
        // declarations; dropping the third of five leaves keys 0,1,3,4, and `serialize()`
        // records the keys - so the filtered view of a tree with fixtures would never equal
        // the view of the same tree without them, which is the one thing this whole
        // comparison exists to make true.
        return array_is_list($section) ? array_values($out) : $out;
    }

    /**
     * md5 of the rule's own source, plus whatever else the rule says decides its verdict.
     */
    private static function __fingerprint(CodeQualityRule_Abstract $rule): string
    {
        $file = RuleDiscovery::file_for(get_class($rule));

        $own = ($file !== null && is_file($file)) ? md5_file($file) : get_class($rule);

        return substr(md5($own . '|' . $rule->fingerprint_extra()), 0, 16);
    }

    /**
     * Turn ['*.php', 'foo*.js'] into anchored basename regexes, once per rule.
     *
     * @param array<int,string> $patterns
     * @return array<int,string>
     */
    private static function __compile_patterns(array $patterns): array
    {
        $compiled = [];

        foreach ($patterns as $pattern) {
            $compiled[] = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        }

        return $compiled;
    }

    /**
     * @param array<int,string> $compiled
     */
    private static function __matches(string $basename, array $compiled): bool
    {
        foreach ($compiled as $regex) {
            if (preg_match($regex, $basename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The absolute path of a manifest-relative path. An absolute path is passed through -
     * rsx:check names files that way.
     */
    private static function __absolute(string $relative): string
    {
        return str_starts_with($relative, '/') ? $relative : base_path($relative);
    }

    private function __violation_count(): int
    {
        return $this->collector->get_total_count() + $this->collector->get_convention_count();
    }

    /**
     * Poison the manifest and throw on the first violation, when the caller asked for it.
     */
    private function __report(): void
    {
        if (!$this->throw_on_violation) {
            return;
        }

        $violations = $this->collector->get_all();

        if (empty($violations)) {
            return;
        }

        $data = $violations[0]->to_array();

        Manifest::_set_manifest_is_bad();

        $relative_file = str_replace(base_path() . '/', '', $data['file']);

        $message = "Code Quality Violation ({$data['type']}) - {$data['message']}\n\n";
        $message .= "File: {$relative_file}:{$data['line']}\n\n";

        if (!empty($data['code'])) {
            $message .= "Code:\n    " . $data['code'] . "\n\n";
        }

        if (!empty($data['resolution'])) {
            $message .= "Resolution:\n" . $data['resolution'];
        }

        throw new YoureDoingItWrongException($message, 0, null, $data['file'], $data['line']);
    }
}
