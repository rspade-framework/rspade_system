<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-COLUMNS-01 - a model's `$columns` declaration must follow its grammar.
 *
 * `$columns` is the one place a model says what kind of value each column holds (the
 * grammar is the docblock on Rsx_Model_Abstract::$columns). A malformed entry fails
 * SILENTLY: an unknown key is ignored, a `false` flag reads as "not set", two kinds on one
 * column leave one of them unapplied. Hence a manifest-build FATAL. Checked, on every class
 * that declares `$columns` itself (Rsx_Model_Abstract included), reported at that class:
 *
 *   1. The property is an array, keyed by non-empty column-name strings, each entry an
 *      array (`'column' => []` is legal: an explicitly plain column).
 *   2. Every key of an entry is one of ALLOWED_KEYS.
 *   3. A flag (FLAG_KEYS) is the literal `true` - a kind is removed by replacing the
 *      entry, never by `false`.
 *   4. At most one of EXCLUSIVE_KEYS per column.
 *   5. Value shapes: `enum` is a non-empty map of integer value => array, whose `constant`
 *      and `label`, when present, are non-empty strings; `text_type` is a non-empty string
 *      (that it names a real Rsx_Text_Abstract with a JS twin is TEXT-TYPE-01's check).
 *   6. A replacing entry never drops a protection: an entry replacing an inherited one that
 *      carries `never_export` carries it too, and an entry for one of the audit type
 *      columns Rsx_Model_Abstract declares `type_ref` keeps `type_ref`.
 *   7. A derived column: `derived` and `from` appear together; `derived` names a public,
 *      non-static method of the class taking no required argument; `from` is a list of
 *      distinct non-empty column names that does not name the column itself; `derived`
 *      never sits beside `revision_exclude` (a derived column is never recorded already);
 *      and no derived columns of the effective declaration form a cycle through `from`
 *      (reported with its path, e.g. a -> b -> a).
 *   8. No model class declares a static `$enums`, `$text_types`, `$type_ref_columns`,
 *      `$neverExport` or `$revision_exclude`: the framework reads column declarations from
 *      `$columns` only, so such a property declares nothing - a "never exported" secret
 *      declared there would be sent to the browser.
 *
 * Cross-file: checks 6 and 7 read the ancestors' declarations, so the rule re-runs when any
 * PHP file or the class indexes move. The declarations are read by reflection over the
 * loaded classes (they are values, not syntax).
 *
 * Not suppressible: every finding is a declaration that does not do what it says.
 *
 * See: php artisan rsx:man model
 */
class ModelColumns_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'MODEL-COLUMNS-01';

    /** Every key a column entry may carry. */
    public const ALLOWED_KEYS = ['enum', 'text_type', 'type_ref', 'never_export', 'revision_exclude', 'derived', 'from'];

    /** Keys whose only legal value is the literal true. */
    public const FLAG_KEYS = ['type_ref', 'never_export', 'revision_exclude'];

    /** Keys of which a column carries at most one: each is a different kind of value. */
    public const EXCLUSIVE_KEYS = ['enum', 'text_type', 'type_ref'];

    /**
     * Static property names the framework does not read on a model: column declarations live
     * in `$columns` only (check 8).
     */
    public const UNREAD_STATIC_PROPERTIES = ['enums', 'text_types', 'type_ref_columns', 'neverExport', 'revision_exclude'];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Model Column Declarations';
    }

    public function get_description(): string
    {
        return 'A model\'s $columns entries must use the known keys, literal true flags, one kind per column, '
            . 'well-formed values and derived declarations, never drop an inherited never_export or audit type_ref, '
            . 'and no model declares the column statics the framework does not read';
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
     * Runs during the manifest scan: every finding is a declaration that silently does not
     * take effect.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Grammar errors are never excused, so the driver must not skip a file on a marker.
     */
    public function reads_own_exception_markers(): bool
    {
        return true;
    }

    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every PHP file (a model and each of its ancestors) and the class indexes the lineage
     * is resolved through.
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
     * The base itself declares `$columns`, so it is judged with every descendant.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $models = Manifest::php_get_subclasses_of('Rsx_Model_Abstract', false);
        sort($models);
        array_unshift($models, 'Rsx_Model_Abstract');

        foreach ($models as $class_name) {
            $record = Manifest::php_class_metadata($class_name);
            $fqcn = $record['fqcn'] ?? null;
            $rel_path = $record['file'] ?? null;

            if (!$fqcn || !$rel_path || !class_exists($fqcn)) {
                continue;
            }

            $this->evaluate_class($fqcn, base_path($rel_path));
        }
    }

    /**
     * Validate the `$columns` one class declares itself; a class that inherits the property
     * has nothing of its own to judge. The testable seam: production passes manifest
     * classes, tests pass fixture classes loaded from a temporary file.
     */
    public function evaluate_class(string $fqcn, string $abs_file): void
    {
        $contents = (string) $this->source()->content($abs_file);
        $class_name = class_basename($fqcn);

        $this->check_unread_statics($fqcn, $abs_file, $contents, $class_name);

        $property = new \ReflectionProperty($fqcn, 'columns');

        if ($property->getDeclaringClass()->getName() !== $fqcn) {
            return;
        }

        $declared = $property->getValue();
        $declaration_line = $this->line_of($contents, '/\$columns\s*=/');

        if (!is_array($declared)) {
            $this->report($abs_file, $contents, $declaration_line,
                "{$class_name}::\$columns is " . get_debug_type($declared) . ", not an array of column => entry.");

            return;
        }

        $inherited = $this->inherited_columns($fqcn);
        $audit_type_refs = $this->audit_type_columns();

        foreach ($declared as $column => $entry) {
            if (!is_string($column) || $column === '') {
                $this->report($abs_file, $contents, $declaration_line,
                    "{$class_name}::\$columns has an entry keyed " . var_export($column, true)
                    . ": every key is a column name.");

                continue;
            }

            $line = $this->column_line($contents, $column, $declaration_line);
            $where = "{$class_name}::\$columns['{$column}']";

            if (!is_array($entry)) {
                $this->report($abs_file, $contents, $line,
                    "{$where} is " . get_debug_type($entry) . ": an entry is an array of kind => value "
                    . "(`'{$column}' => []` declares an explicitly plain column).");

                continue;
            }

            $this->check_entry($abs_file, $contents, $line, $where, $entry);
            $this->check_derived($fqcn, $abs_file, $contents, $line, $where, $column, $entry);

            if ($fqcn === Rsx_Model_Abstract::class) {
                continue;
            }

            // CHECK 6: a replacing entry never drops a protection.
            if (($inherited[$column]['never_export'] ?? false) === true
                && ($entry['never_export'] ?? null) !== true) {
                $this->report($abs_file, $contents, $line,
                    "{$where} replaces an inherited entry that is never_export, and drops it.\n\n"
                    . "A class's entry REPLACES its parent's entry for the column, so this one would send "
                    . "a column its parent keeps on the server to every browser that reads the record.",
                    "Restate the protection on the replacing entry:\n"
                    . "    '{$column}' => [..., 'never_export' => true],");
            }

            if (in_array($column, $audit_type_refs, true) && ($entry['type_ref'] ?? null) !== true) {
                $this->report($abs_file, $contents, $line,
                    "{$where} replaces the audit type column Rsx_Model_Abstract declares type_ref, and drops it.\n\n"
                    . "The audit authorship pairs store a _type_refs id that every stamp, every author lookup "
                    . "and the orphan report read as a class name; without type_ref the column reads and "
                    . "writes raw integers.",
                    "Keep the kind on the replacing entry:\n"
                    . "    '{$column}' => ['type_ref' => true, ...],");
            }
        }

        $this->check_derived_cycles($abs_file, $contents, $declaration_line, $class_name, array_merge($inherited, $declared));
    }

    /**
     * CHECK 8: a static property the framework does not read, declared on this class.
     */
    private function check_unread_statics(string $fqcn, string $abs_file, string $contents, string $class_name): void
    {
        foreach (self::UNREAD_STATIC_PROPERTIES as $name) {
            if (!property_exists($fqcn, $name)) {
                continue;
            }

            $property = new \ReflectionProperty($fqcn, $name);

            if (!$property->isStatic() || $property->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            $this->report($abs_file, $contents, $this->line_of($contents, '/static\s+[^;=]*\$' . preg_quote($name, '/') . '\b/'),
                "{$class_name}::\${$name} declares nothing: the framework reads a model's column declarations from "
                . "\$columns only, so every entry in this property is silently ignored (a never_export column declared "
                . "here would be sent to every browser that reads the record).",
                "Move each entry into \$columns as one entry per column, then delete the property:\n"
                . "    public static \$columns = [\n"
                . "        'status_id' => ['enum' => [...]],\n"
                . "        'body'      => ['text_type' => Rich_Text::class],\n"
                . "        'owner_type'=> ['type_ref' => true],\n"
                . "        'api_secret'=> ['never_export' => true],\n"
                . "        'view_count'=> ['revision_exclude' => true],\n"
                . "    ];");
        }
    }

    /**
     * CHECK 7 on one entry: the derived / from pair.
     */
    private function check_derived(string $fqcn, string $abs_file, string $contents, int $line, string $where, string $column, array $entry): void
    {
        $has_derived = array_key_exists('derived', $entry);
        $has_from = array_key_exists('from', $entry);

        if (!$has_derived && !$has_from) {
            return;
        }

        $shape = "    '{$column}' => ['derived' => 'derive_{$column}', 'from' => ['source_column', ...]],";

        if (!$has_derived) {
            $this->report($abs_file, $contents, $line,
                "{$where} has 'from' without 'derived': 'from' names the columns a derive method reads, so it "
                . "only exists beside one.", "Declare the method, or delete 'from':\n{$shape}");

            return;
        }

        if (!$has_from) {
            $this->report($abs_file, $contents, $line,
                "{$where} has 'derived' without 'from': a derived column lists the columns of this record its "
                . "method reads ([] when it reads none), because a change to one of them is what re-derives it.",
                "Add the list:\n{$shape}");
        }

        if (($entry['revision_exclude'] ?? null) === true) {
            $this->report($abs_file, $contents, $line,
                "{$where} declares both 'derived' and 'revision_exclude': a derived column is never recorded in "
                . "revision history already, so the flag says nothing.", "Delete 'revision_exclude' from the entry.");
        }

        $method = $entry['derived'];

        if (!is_string($method) || $method === '') {
            $this->report($abs_file, $contents, $line,
                "{$where}['derived'] is " . get_debug_type($method) . ': it names the method that computes the column.',
                $shape);
        } elseif (!method_exists($fqcn, $method)) {
            $this->report($abs_file, $contents, $line,
                "{$where}['derived'] names {$method}(), which " . class_basename($fqcn) . ' does not have.',
                "Add the method, public and non-static, taking no required argument and returning the value:\n"
                . "    public function {$method}()\n    {\n        return ...;\n    }");
        } else {
            $reflection = new \ReflectionMethod($fqcn, $method);
            $problems = [];

            if (!$reflection->isPublic()) {
                $problems[] = 'is not public';
            }
            if ($reflection->isStatic()) {
                $problems[] = 'is static - it computes a value from one record, so it is an instance method';
            }
            if ($reflection->getNumberOfRequiredParameters() > 0) {
                $problems[] = 'has required parameters - save() calls it with none';
            }

            if ($problems !== []) {
                $this->report($abs_file, $contents, $line,
                    "{$where}['derived'] names {$method}(), which " . implode('; ', $problems) . '.',
                    "A derive method is public, non-static and takes no required argument:\n"
                    . "    public function {$method}()");
            }
        }

        if (!$has_from) {
            return;
        }

        $from = $entry['from'];
        $valid = is_array($from) && array_is_list($from);

        foreach ($valid ? $from : [] as $source) {
            $valid = $valid && is_string($source) && $source !== '';
        }

        if (!$valid || count(array_unique($from)) !== count($from)) {
            $this->report($abs_file, $contents, $line,
                "{$where}['from'] must be a list of distinct column names of this record ([] when the method reads none).",
                $shape);

            return;
        }

        if (in_array($column, $from, true)) {
            $this->report($abs_file, $contents, $line,
                "{$where}['from'] names '{$column}' itself: a derived column is computed from other columns.",
                "Remove '{$column}' from the list.");
        }
    }

    /**
     * CHECK 7, cycles: the derived columns of the effective declaration ($columns overlaid
     * down to this class) must not depend on each other in a cycle through `from`. A
     * self-reference is reported per entry, so only cycles of two or more are reported here.
     *
     * @param array<string, mixed> $effective
     */
    private function check_derived_cycles(string $abs_file, string $contents, int $line, string $class_name, array $effective): void
    {
        $edges = [];

        foreach ($effective as $column => $entry) {
            if (!is_string($column) || !is_array($entry) || !array_key_exists('derived', $entry) || !is_array($entry['from'] ?? null)) {
                continue;
            }

            $edges[$column] = array_values(array_filter($entry['from'], fn ($source) => is_string($source) && $source !== $column));
        }

        $state = [];
        $reported = [];

        $visit = function (string $column, array $path) use (&$visit, &$state, &$reported, $edges, $abs_file, $contents, $line, $class_name): void {
            if (($state[$column] ?? null) === 'done') {
                return;
            }

            if (($state[$column] ?? null) === 'active') {
                $cycle = array_slice($path, array_search($column, $path, true));
                $key = implode(',', $cycle);
                $cycle[] = $column;

                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    $this->report($abs_file, $contents, $line,
                        "{$class_name}::\$columns declares derived columns that depend on each other in a cycle: "
                        . implode(' -> ', $cycle) . '. No order computes them: each needs the other first.',
                        'Break the cycle: at least one of these columns must be computed from columns that are not derived from it.');
                }

                return;
            }

            $state[$column] = 'active';
            $path[] = $column;

            foreach ($edges[$column] as $source) {
                if (isset($edges[$source])) {
                    $visit($source, $path);
                }
            }

            $state[$column] = 'done';
        };

        foreach (array_keys($edges) as $column) {
            $visit($column, []);
        }
    }

    /**
     * CHECKS 2 to 5 on one entry.
     */
    private function check_entry(string $abs_file, string $contents, int $line, string $where, array $entry): void
    {
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                $this->report($abs_file, $contents, $line,
                    "{$where} has an unknown key " . var_export($key, true) . '. Known keys: '
                    . implode(', ', self::ALLOWED_KEYS) . '.');
            }
        }

        foreach (self::FLAG_KEYS as $flag) {
            if (array_key_exists($flag, $entry) && $entry[$flag] !== true) {
                $this->report($abs_file, $contents, $line,
                    "{$where}['{$flag}'] is " . var_export($entry[$flag], true) . ": a flag is the literal true or absent.\n\n"
                    . "A kind is removed by replacing the entry without it, never by setting it false.");
            }
        }

        $kinds = array_values(array_intersect(self::EXCLUSIVE_KEYS, array_keys($entry)));

        if (count($kinds) > 1) {
            $this->report($abs_file, $contents, $line,
                "{$where} declares " . implode(' and ', $kinds) . ": a column holds one kind of value, so at most one of "
                . implode(', ', self::EXCLUSIVE_KEYS) . ' per column.');
        }

        if (array_key_exists('enum', $entry)) {
            $this->check_enum($abs_file, $contents, $line, $where, $entry['enum']);
        }

        if (array_key_exists('text_type', $entry)
            && (!is_string($entry['text_type']) || $entry['text_type'] === '')) {
            $this->report($abs_file, $contents, $line,
                "{$where}['text_type'] is " . get_debug_type($entry['text_type'])
                . ': it names a text type class, e.g. Rich_Text::class.');
        }
    }

    /**
     * CHECK 5 for an `enum` value map.
     */
    private function check_enum(string $abs_file, string $contents, int $line, string $where, mixed $values): void
    {
        if (!is_array($values) || $values === []) {
            $this->report($abs_file, $contents, $line,
                "{$where}['enum'] must be a non-empty map of integer value => ['constant' => ..., 'label' => ...].");

            return;
        }

        foreach ($values as $value => $properties) {
            if (!is_int($value)) {
                $this->report($abs_file, $contents, $line,
                    "{$where}['enum'] has the value " . var_export($value, true)
                    . ': enum values are integers, stored in a BIGINT column.');

                continue;
            }

            if (!is_array($properties)) {
                $this->report($abs_file, $contents, $line,
                    "{$where}['enum'][{$value}] is " . get_debug_type($properties)
                    . ": each value maps to an array of its properties ('constant', 'label', ...).");

                continue;
            }

            foreach (['constant', 'label'] as $name) {
                if (array_key_exists($name, $properties)
                    && (!is_string($properties[$name]) || $properties[$name] === '')) {
                    $this->report($abs_file, $contents, $line,
                        "{$where}['enum'][{$value}]['{$name}'] must be a non-empty string.");
                }
            }
        }
    }

    /**
     * The overlaid `$columns` of every ancestor of $fqcn up to Rsx_Model_Abstract - what this
     * class's own entries replace. Read straight off each declaring class, so a malformed
     * ancestor (reported at that ancestor) is skipped here rather than thrown on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function inherited_columns(string $fqcn): array
    {
        $lineage = [];

        // The base itself inherits no declaration: the walk climbs only up to it.
        for ($current = $fqcn; $current !== Rsx_Model_Abstract::class; ) {
            $current = get_parent_class($current);
            $lineage[] = $current;
        }

        $columns = [];

        foreach (array_reverse($lineage) as $current) {
            $property = new \ReflectionProperty($current, 'columns');

            if ($property->getDeclaringClass()->getName() !== $current || !is_array($property->getValue())) {
                continue;
            }

            foreach ($property->getValue() as $column => $entry) {
                if (is_string($column) && is_array($entry)) {
                    $columns[$column] = $entry;
                }
            }
        }

        return $columns;
    }

    /**
     * The columns Rsx_Model_Abstract's own `$columns` declares type_ref: the audit type
     * columns, which no model may un-kind.
     *
     * @return array<int, string>
     */
    private function audit_type_columns(): array
    {
        $columns = [];

        foreach ((new \ReflectionProperty(Rsx_Model_Abstract::class, 'columns'))->getValue() as $column => $entry) {
            if (($entry['type_ref'] ?? null) === true) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function report(string $abs_file, string $contents, int $line, string $message, ?string $suggestion = null): void
    {
        $this->add_violation(
            $abs_file,
            $line,
            $message,
            $this->line_text($contents, $line),
            ($suggestion !== null ? $suggestion . "\n" : '')
            . "The grammar is the docblock on Rsx_Model_Abstract::\$columns.\n"
            . 'See: php artisan rsx:man model',
            'critical'
        );
    }

    /**
     * The line of `'column' =>` at or after the `$columns` declaration, else the
     * declaration's own line.
     */
    private function column_line(string $contents, string $column, int $declaration_line): int
    {
        $pattern = '/[\'"]' . preg_quote($column, '/') . '[\'"]\s*=>/';

        foreach (explode("\n", $contents) as $index => $text) {
            if ($index + 1 >= $declaration_line && preg_match($pattern, $text)) {
                return $index + 1;
            }
        }

        return $declaration_line;
    }

    private function line_of(string $contents, string $pattern): int
    {
        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match($pattern, $line)) {
                return $index + 1;
            }
        }

        return 1;
    }

    private function line_text(string $contents, int $line): string
    {
        $lines = explode("\n", $contents);

        return trim($lines[$line - 1] ?? '');
    }
}
