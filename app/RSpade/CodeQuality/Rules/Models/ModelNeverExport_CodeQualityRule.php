<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-NEVER-EXPORT-01 - a secret-shaped column must be in the model's effective never-export
 * list.
 *
 * toArray() is the one serializer behind fetch(), relationship and list payloads, the page's
 * user object and the external API. A column it does not strip travels to every browser that
 * reads the record. For a credential that is a security event with no error anywhere, and the
 * only defence is remembering to declare it - which this rule removes the need for. The one
 * declaration also redacts the column's values from the revision lane (Revision::REDACTED), so
 * the fix this rule asks for closes both doors.
 *
 * SECRET-SHAPED, deliberately narrow so that a finding is always worth reading:
 *
 *   password    or a name ending in _password
 *   token       or a name ending in _token
 *   secret      or a name ending in _secret
 *
 * A name beginning is_ or has_ is a flag ABOUT a secret (is_secret), not one, and is never
 * flagged. A `_`-prefixed column is a system column toArray() strips anyway. *_code, *_key and
 * *_hash are NOT matched: most such columns (a status code, a dedupe key, a content hash) are
 * ordinary data, and a rule that cries wolf gets suppressed wholesale.
 *
 * The list checked is the EFFECTIVE one - Rsx_Model_Abstract::never_exported_columns(), the
 * union of $neverExport up the inheritance chain - so a class override of a core model passes
 * on the columns its base already protects and is flagged only for what it adds.
 *
 * Every CONCRETE model whose table exists is checked, framework and application alike; the
 * finding is reported on that model's file, which is where an override adds its declaration.
 *
 * COLUMNS ARE READ FROM THE LIVE SCHEMA (Rsx_Schema), the same premise as MODEL-ENUM-01. When
 * no database can be reached the rule is SILENTLY SKIPPED - a box running rsx:check before its
 * first migrate has no columns to judge. Because the verdict depends on the schema, the rule's
 * fingerprint carries the state key, so a migration that adds a column re-runs it.
 *
 * A column that is exported ON PURPOSE is declared in the model's file with a rationale:
 *
 *     // @MODEL-NEVER-EXPORT-01-EXCEPTION api_token - a public, rotating display token
 *
 * The marker names ONE column and must be followed by the reason; a marker naming no column,
 * or giving no reason, excuses nothing.
 *
 * See: php artisan rsx:man model, NEVER EXPORT FIELDS
 */
class ModelNeverExport_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'MODEL-NEVER-EXPORT-01';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Secret Column Never Exported';
    }

    public function get_description(): string
    {
        return 'A password/token/secret column must be in the model\'s effective $neverExport list';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every PHP file (a model, or any ancestor contributing to its union) and the class
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
     * With the database unreachable it is the code key alone, and the rule skips itself.
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

            $table = (new \ReflectionClass($fqcn))->getDefaultProperties()['table'] ?? null;
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
        $protected = $fqcn::never_exported_columns();
        $contents = (string) $this->source()->content($abs_file);

        foreach ($columns as $column) {
            if (!self::is_secret_shaped($column) || in_array($column, $protected, true)) {
                continue;
            }

            if (self::is_excused($contents, $column)) {
                continue;
            }

            $class_name = class_basename($fqcn);
            $line = $this->line_of($contents, '/\$neverExport\s*=/') ?? $this->line_of($contents, '/\bclass\s+' . preg_quote($class_name, '/') . '\b/') ?? 1;
            $lines = explode("\n", $contents);

            $this->add_violation(
                $abs_file,
                $line,
                "{$class_name}'s table has a column '{$column}', which looks like a credential, and it is not in "
                . "the model's never-export list.\n\n"
                . "toArray() is the one serializer behind fetch(), relationship and list payloads, the page's "
                . "user object and the external API, so a column it does not strip reaches every browser that "
                . "reads the record. A payload carrying a password hash, a token or a secret is a security "
                . "event with no error anywhere. Laravel's \$hidden does not close it: makeVisible() undoes "
                . "\$hidden, and \$neverExport is applied after it.",
                $lines[$line - 1] ?? '',
                "Add '{$column}' to the model's never-export list:\n"
                . "    public static \$neverExport = ['{$column}'];\n"
                . "The effective list is the UNION of every \$neverExport up the inheritance chain, so an "
                . "override lists only what it adds - it can no longer drop a column its base protects.\n"
                . "The same declaration covers the revision lane: on a model with \$revisions, a change to a "
                . "never-exported column is recorded with both values replaced by Revision::REDACTED, so the "
                . "column needs no \$revision_exclude entry and the history still says it changed.\n"
                . "If the column is exported ON PURPOSE, declare it in this file with the reason:\n"
                . "    // @" . self::RULE_ID . "-EXCEPTION {$column} - <why a browser may hold it>\n"
                . "See: php artisan rsx:man model, NEVER EXPORT FIELDS",
                'high'
            );
        }
    }

    /**
     * password / token / secret, alone or as a _suffix; never a system column and never an
     * is_ / has_ flag.
     */
    public static function is_secret_shaped(string $column): bool
    {
        if (str_starts_with($column, '_') || str_starts_with($column, 'is_') || str_starts_with($column, 'has_')) {
            return false;
        }

        return (bool) preg_match('/^(?:.+_)?(?:password|token|secret)$/', $column);
    }

    /**
     * A marker naming this column, followed by a reason.
     */
    private static function is_excused(string $contents, string $column): bool
    {
        return (bool) preg_match(
            '/@' . preg_quote(self::RULE_ID, '/') . '-EXCEPTION[ \t]+' . preg_quote($column, '/') . '\b[ \t:\-]*[^\s\-:]/',
            $contents
        );
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
