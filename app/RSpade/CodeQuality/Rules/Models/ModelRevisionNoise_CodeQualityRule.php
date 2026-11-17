<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-REVISION-NOISE-01 - on a model with $revisions, a column whose name looks like
 * bookkeeping says whether its history is worth recording.
 *
 * A revision is the history a person reads: what somebody entered or decided, and who. A
 * column the system moves on its own - a last-emailed stamp, a cached aggregate, a counter, a
 * list position, a generated code - records a revision every time it moves, so the history
 * fills with rows nobody made, and each one is a row in every history screen. Nothing fails:
 * the noise is silent, and it accumulates in a table that is never rewritten. Excluding a
 * column by its name automatically would be wrong as often as right (`last_name` would match
 * `last_*`), so the model DECLARES the answer for every column whose name matches:
 *
 *     '<column>' => ['revision_exclude' => true],          // bookkeeping: never recorded
 *     '<column>' => ['derived' => 'method', 'from' => [...]], // derived: never recorded already
 *     '<column>' => ['revision_include' => '<reason>'],     // a person sets it: recorded
 *
 * BOOKKEEPING-SHAPED names (the whole name matched, nothing else):
 *
 *   *_cache  *_cache_id  last_*_at  last_*_by*  last_seen  *_synced_at  *_read_at
 *   *_viewed_at  *_count  sort_order  position  *_position  *_token  *_code  *_key  *_hash
 *
 * A `_`-prefixed system column is never recorded and never judged. The declaration read is
 * the EFFECTIVE one ($columns overlaid down the lineage), so a class override of a core model
 * is judged only for what its base leaves unanswered.
 *
 * Checked on every CONCRETE model whose effective $revisions is true, framework and
 * application alike, against the columns of its OWN table in the connected database - a
 * detail table's columns belong to the detail model. COLUMNS ARE READ FROM THE LIVE SCHEMA
 * (Rsx_Schema), so the rule is rsx:check only, SILENTLY SKIPPED when no database can be
 * reached, and its fingerprint carries the state key, so a migration that adds a column
 * re-runs it. Not suppressible by a marker: `revision_include` with its reason IS the
 * acknowledgement, and it sits on the column it speaks for.
 *
 * See: php artisan rsx:man revisions
 */
class ModelRevisionNoise_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'MODEL-REVISION-NOISE-01';

    /**
     * The bookkeeping name patterns, as one anchored expression over the whole column name.
     */
    public const BOOKKEEPING_PATTERN = '/^(?:'
        . '.+_cache|.+_cache_id'
        . '|last_.+_at|last_.+_by.*|last_seen'
        . '|.+_synced_at|.+_read_at|.+_viewed_at'
        . '|.+_count'
        . '|sort_order|position|.+_position'
        . '|.+_token|.+_code|.+_key|.+_hash'
        . ')$/';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Bookkeeping Columns Declare Their Revision History';
    }

    public function get_description(): string
    {
        return 'On a model with $revisions, a bookkeeping-shaped column is revision_exclude, derived, or carries revision_include with a reason';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * The declaration is the acknowledgement, so the driver must not skip a file on a marker.
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
     * Every PHP file (a model, or any ancestor contributing to its declaration) and the class
     * indexes the lineage is resolved through.
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
     * The schema is part of the premise: the state key moves with every applied migration.
     */
    public function fingerprint_extra(): string
    {
        return Manifest::get_state_key();
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        try {
            $tables = Rsx_Schema::tables();
        } catch (\Throwable $no_database) {
            // No database to read columns from: nothing to judge (see the class docblock).
            return;
        }

        foreach (Manifest::php_class_records_extending('Rsx_Model_Abstract') as $record) {
            $fqcn = $record['fqcn'] ?? null;
            if (!$fqcn || !class_exists($fqcn)) {
                continue;
            }

            // @PHP-REFLECT-01-EXCEPTION - the rule reads each model's table name off its class defaults, as MODEL-NEVER-EXPORT-01 does
            $reflection = new \ReflectionClass($fqcn);
            if ($reflection->isAbstract()) {
                continue;
            }

            $table = $reflection->getDefaultProperties()['table'] ?? null;
            if (!is_string($table) || !isset($tables[$table])) {
                continue;
            }

            $this->evaluate_class($fqcn, base_path($record['file']), array_keys($tables[$table]));
        }
    }

    /**
     * Judge one model against its table's column names. The testable seam: production passes
     * a manifest class and the live columns, tests pass a fixture class and a column list.
     *
     * @param array<int,string> $columns
     */
    public function evaluate_class(string $fqcn, string $abs_file, array $columns): void
    {
        if ($fqcn::$revisions !== true) {
            return;
        }

        $declared = $fqcn::columns();
        $excluded = $fqcn::revision_excluded_columns();
        $contents = (string) $this->source()->content($abs_file);
        $lines = explode("\n", $contents);
        $class_name = class_basename($fqcn);

        foreach ($columns as $column) {
            if (!self::is_bookkeeping_shaped($column) || in_array($column, $excluded, true)) {
                continue;
            }

            $reason = $declared[$column]['revision_include'] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                continue;
            }

            $line = $this->line_of($contents, '/^\s*(public|protected|private)?\s*static\s+(array\s+)?\$columns\b/')
                ?? $this->line_of($contents, '/\bclass\s+' . preg_quote($class_name, '/') . '\b/')
                ?? 1;

            $this->add_violation(
                $abs_file,
                $line,
                "{$class_name} records revisions, and its table has a column '{$column}' whose name looks like "
                . "bookkeeping, with no answer to whether its history is worth recording.\n\n"
                . "A revision is the history a person reads. A column the system moves on its own - a "
                . "last-emailed stamp, a cached aggregate, a counter, a list position, a generated code - "
                . "would record a revision every time it moved, and every one is a row in every history "
                . "screen that nobody made.",
                trim($lines[$line - 1] ?? ''),
                "Answer for '{$column}' in {$class_name}::\$columns, one of:\n"
                . "    '{$column}' => ['revision_exclude' => true],          // bookkeeping: never recorded\n"
                . "    '{$column}' => ['derived' => '<method>', 'from' => [...]], // computed: never recorded already\n"
                . "    '{$column}' => ['revision_include' => '<reason>'],     // a person enters or decides it: recorded\n"
                . "An entry REPLACES the inherited entry for the column, so keep the keys it already carries.\n"
                . 'See: php artisan rsx:man revisions',
                'high'
            );
        }
    }

    /**
     * Whether a column name matches a bookkeeping pattern. A `_`-prefixed system column is
     * never recorded, so it never matches.
     */
    public static function is_bookkeeping_shaped(string $column): bool
    {
        if (str_starts_with($column, '_')) {
            return false;
        }

        return (bool) preg_match(self::BOOKKEEPING_PATTERN, $column);
    }

    /**
     * 1-based line of the first match, or null when nothing matches.
     */
    private function line_of(string $contents, string $pattern): ?int
    {
        foreach (explode("\n", $contents) as $i => $line) {
            if (preg_match($pattern, $line)) {
                return $i + 1;
            }
        }

        return null;
    }
}
