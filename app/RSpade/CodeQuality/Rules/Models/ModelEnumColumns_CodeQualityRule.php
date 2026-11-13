<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-ENUM-01 - every column a model's `$columns` declares, and every `from` column a
 * derived entry names, is a column of the model's table.
 *
 * A declaration for a column the table does not have does nothing at all (an enum with no
 * column to read, a never_export guarding nothing, a derived column whose source never
 * changes), and a misspelled `from` name means the derived column is never re-derived.
 * Checked on every concrete model - framework and application alike - against the connected
 * database's column map (Rsx_Schema::model_columns()), through the same comparison the
 * first-use runtime check uses (Rsx_Model_Abstract::_columns_missing_from()). The audit type
 * columns Rsx_Model_Abstract declares are exempt: the deletion pair exists only on
 * soft-deleting tables.
 *
 * A model whose table is not in the connected database is skipped (migrations not yet run).
 * rsx:check only; suppressed by @MODEL-ENUM-01-EXCEPTION anywhere in the file.
 */
class ModelEnumColumns_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-ENUM-01';
    }

    public function get_name(): string
    {
        return 'Model Column Declarations Name Real Columns';
    }

    public function get_description(): string
    {
        return 'Every $columns entry and every derived from column must be a column of the model\'s table';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Only a concrete model has a table: an abstract base's declarations are checked
        // through each concrete model that inherits them.
        $record = Manifest::php_model_record($class_name);
        if ($record === null) {
            return;
        }

        $table_columns = Rsx_Schema::model_columns($class_name);
        if ($table_columns === null) {
            return;
        }

        $fqcn = $record['fqcn'];
        $lines = explode("\n", (string) $this->source()->content($file_path));

        foreach ($fqcn::_columns_missing_from($table_columns) as $column => $why) {
            $line_number = 1;
            foreach ($lines as $i => $line) {
                if (preg_match('/[\'"]' . preg_quote($column, '/') . '[\'"]/', $line)) {
                    $line_number = $i + 1;
                    break;
                }
            }

            $this->add_violation(
                $file_path,
                $line_number,
                "{$class_name}::\$columns {$why} '{$column}', which is not a column of table '{$record['table']}' "
                . '(have the migrations been run?). The declaration does nothing, and a model with derived columns '
                . 'refuses to save until it is fixed.',
                trim($lines[$line_number - 1] ?? ''),
                "Add '{$column}' to the table with a migration, or correct the declaration.\n"
                . 'See: php artisan rsx:man model',
                'high'
            );
        }
    }
}
