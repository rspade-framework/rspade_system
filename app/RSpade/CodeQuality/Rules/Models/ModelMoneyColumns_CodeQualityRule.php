<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-MONEY-01 - every column a model declares ['money' => true] is stored as DECIMAL(p,2)
 * with p at most 18.
 *
 * The money cast reads the column through Rsx_Money::from_storage(), which accepts only the
 * canonical two-place string a DECIMAL(p,2) column returns: a column of another type or
 * scale throws on its first read. Precision 18 is the ceiling because DECIMAL(18,2) holds at
 * most 9999999999999999.99 - inside the signed 64-bit range of cents Rsx_Money holds - so
 * every stored value reads; a DECIMAL(19,2) column can hold an amount that cannot.
 *
 * Checked on every concrete model - framework and application alike - against the connected
 * database's column map (Rsx_Schema::model_columns()). A model whose table is not in the
 * connected database is skipped (migrations not yet run), and so is a declared column the
 * table lacks (MODEL-ENUM-01 reports that). rsx:check only; suppressed by
 * @MODEL-MONEY-01-EXCEPTION anywhere in the file.
 */
class ModelMoneyColumns_CodeQualityRule extends CodeQualityRule_Abstract
{
    /** The largest precision whose every value Rsx_Money can hold. */
    public const MAX_PRECISION = 18;

    public function get_id(): string
    {
        return 'MODEL-MONEY-01';
    }

    public function get_name(): string
    {
        return 'Money Columns Are DECIMAL(p,2)';
    }

    public function get_description(): string
    {
        return 'Every column a model declares money must be DECIMAL with scale 2 and precision at most 18';
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

        $this->evaluate_class($record['fqcn'], $file_path, $record['table'], $table_columns);
    }

    /**
     * Judge one concrete model's money columns against its table's column map. The testable
     * seam: production passes a manifest model and Rsx_Schema's map, tests pass a fixture
     * model and its fixture table's map.
     *
     * @param array<string, array> $table_columns column => Rsx_Schema column shape
     */
    public function evaluate_class(string $fqcn, string $file_path, string $table, array $table_columns): void
    {
        $money_columns = $fqcn::money_columns();
        if ($money_columns === []) {
            return;
        }

        $class_name = class_basename($fqcn);
        $lines = explode("\n", (string) $this->source()->content($file_path));

        foreach ($money_columns as $column) {
            $shape = $table_columns[$column] ?? null;

            if ($shape === null) {
                continue;
            }

            if ($shape['type'] === 'decimal' && $shape['scale'] === 2 && $shape['precision'] <= self::MAX_PRECISION) {
                continue;
            }

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
                "{$class_name}::\$columns declares '{$column}' money, but table '{$table}' stores it as "
                . static::column_type_text($shape) . '. A money column is DECIMAL(p,2) with p at most '
                . self::MAX_PRECISION . ': the money cast reads exactly two decimal places, and a wider column '
                . 'can hold an amount outside the range Rsx_Money holds, so a read of this column throws.',
                trim($lines[$line_number - 1] ?? ''),
                "Change the column with a migration: ALTER TABLE {$table} MODIFY {$column} DECIMAL(15,2) "
                . "(NULL or NOT NULL as it is now), or remove the money declaration if the column is not money.\n"
                . 'See: php artisan rsx:man money',
                'high'
            );
        }
    }

    /**
     * The column's type as the message names it: DECIMAL(p,s) in full, otherwise the
     * simplified type in capitals (FLOAT, DOUBLE, INTEGER, STRING, ...).
     *
     * @param array $shape An Rsx_Schema column shape
     */
    private static function column_type_text(array $shape): string
    {
        if ($shape['type'] === 'decimal') {
            return "DECIMAL({$shape['precision']},{$shape['scale']})";
        }

        return strtoupper((string) $shape['type']);
    }
}
