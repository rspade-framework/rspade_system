<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-DECIMAL-01 - every DECIMAL, FLOAT and DOUBLE column of a model's table says, in the
 * model's $columns, whether it is money.
 *
 * A DECIMAL column looks like it could hold money, and money is a declared column kind
 * (['money' => true]) that reads as an Rsx_Money - never a float. An undeclared DECIMAL column
 * reads as a plain string, and the arithmetic written against it tends to go through a float.
 * So the model answers the question for each one: an entry carrying a `money` key
 * (['money' => true] or ['money' => false], beside any other keys), or an entry that is
 * exactly [] (an explicitly plain column, not money). An entry with other keys and no `money`
 * key - ['revision_exclude' => true], a derived or never_export entry - is a finding: those
 * keys say something else about the column and leave the money question unanswered. A FLOAT
 * or DOUBLE column cannot hold an amount exactly at all; it is declared not money, or
 * converted to DECIMAL(p,2) and declared money.
 *
 * Checked on every concrete model - framework and application alike - against the connected
 * database's column map (Rsx_Schema::model_columns()). Only the columns of the model's OWN
 * table are judged: a detail table's columns merged into a base model's map belong to the
 * detail model, which is judged on its own table. A model whose table is not in the connected
 * database is skipped (migrations not yet run). A table no model reads is not walked. A
 * FLOAT/DOUBLE column declared money is MODEL-MONEY-01's finding.
 *
 * rsx:check only; suppressed by @MODEL-DECIMAL-01-EXCEPTION anywhere in the file.
 */
class ModelDecimalColumns_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-DECIMAL-01';
    }

    public function get_name(): string
    {
        return 'DECIMAL And Floating-Point Columns Are Declared';
    }

    public function get_description(): string
    {
        return 'Every DECIMAL, FLOAT and DOUBLE column of a model\'s table says in $columns whether it is money';
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

        // Only a concrete model has a table: an abstract base's declarations are judged
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
     * Judge one concrete model's DECIMAL, FLOAT and DOUBLE columns against its declaration.
     * The testable seam: production passes a manifest model and Rsx_Schema's map, tests pass a
     * fixture model and its fixture table's map.
     *
     * @param array<string, array> $table_columns column => Rsx_Schema column shape
     */
    public function evaluate_class(string $fqcn, string $file_path, string $table, array $table_columns): void
    {
        $declared = $fqcn::columns();
        $class_name = class_basename($fqcn);
        $lines = explode("\n", (string) $this->source()->content($file_path));
        $line_number = static::declaration_line($lines);

        foreach ($table_columns as $column => $shape) {
            if (($shape['source_table'] ?? $table) !== $table) {
                continue;
            }

            $type = $shape['type'];
            if ($type !== 'decimal' && $type !== 'float' && $type !== 'double') {
                continue;
            }

            if (array_key_exists($column, $declared)) {
                $entry = $declared[$column];
                if ($entry === [] || array_key_exists('money', $entry)) {
                    continue;
                }

                $this->report_unanswered($file_path, $lines, $line_number, $class_name, $table, $column, $shape, $entry);

                continue;
            }

            if ($type === 'decimal') {
                $message = "{$class_name}::\$columns has no entry for '{$column}', a DECIMAL({$shape['precision']},{$shape['scale']}) column of table '{$table}'.\n"
                    . "A DECIMAL column looks like it could hold money. Say which it is:\n"
                    . "\n"
                    . "    '{$column}' => ['money' => true],   // an amount of money: reads as Rsx_Money, never a float\n"
                    . "    '{$column}' => [],                  // not money (hours, a quantity, a percentage)\n"
                    . "\n"
                    . 'See: php artisan rsx:man money';
            } else {
                $type_text = strtoupper($type);
                $message = "{$class_name}::\$columns has no entry for '{$column}', a {$type_text} column of table '{$table}'.\n"
                    . "A floating-point column cannot hold an amount of money exactly. If it holds money, convert it\n"
                    . "to DECIMAL(p,2) with a migration and declare it ['money' => true]; if it does not, declare it:\n"
                    . "\n"
                    . "    '{$column}' => [],                  // not money (a measurement, a ratio, a score)\n"
                    . "\n"
                    . 'See: php artisan rsx:man money';
            }

            $this->add_violation(
                $file_path,
                $line_number,
                $message,
                trim($lines[$line_number - 1] ?? ''),
                "Add an entry for '{$column}' to {$class_name}::\$columns.\n"
                . 'See: php artisan rsx:man money',
                'high'
            );
        }
    }

    /**
     * An entry for the column that carries other keys and no `money` key: the keys say
     * something else about the column, and the money question is left unanswered.
     *
     * @param array<int, string> $lines
     * @param array<string, mixed> $shape Rsx_Schema column shape
     * @param array<string, mixed> $entry The column's effective $columns entry
     */
    private function report_unanswered(string $file_path, array $lines, int $line_number, string $class_name, string $table, string $column, array $shape, array $entry): void
    {
        $type = $shape['type'];
        $type_text = $type === 'decimal'
            ? "DECIMAL({$shape['precision']},{$shape['scale']})"
            : strtoupper($type);
        $keys = implode(', ', array_map(fn ($key) => "'{$key}'", array_keys($entry)));

        $message = "{$class_name}::\$columns['{$column}'] declares {$keys} but not whether '{$column}', a {$type_text} column of table '{$table}', is money.\n"
            . "A DECIMAL, FLOAT or DOUBLE column whose entry carries other keys says which it is with a 'money' key:\n"
            . "\n";

        if ($type === 'decimal') {
            $message .= "    '{$column}' => ['money' => true, ...],    // an amount of money: reads as Rsx_Money, never a float\n";
        } else {
            $message .= "    '{$column}' => ['money' => true, ...],    // an amount of money - after a migration converts it to DECIMAL(p,2)\n";
        }

        $message .= "    '{$column}' => ['money' => false, ...],   // not money (a rate, hours, a quantity, a percentage)\n"
            . "\n"
            . "where ... is the keys the entry carries now.\n"
            . 'See: php artisan rsx:man money';

        $this->add_violation(
            $file_path,
            $line_number,
            $message,
            trim($lines[$line_number - 1] ?? ''),
            "Add 'money' => true or 'money' => false to {$class_name}::\$columns['{$column}'].\n"
            . 'See: php artisan rsx:man money',
            'high'
        );
    }

    /**
     * The line a missing entry belongs on: the class's own $columns declaration, else the
     * class declaration, else the first line.
     *
     * @param array<int, string> $lines
     */
    private static function declaration_line(array $lines): int
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(public|protected|private)?\s*static\s+(array\s+)?\$columns\b/', $line)) {
                return $i + 1;
            }
        }

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(abstract\s+|final\s+)?class\s+\w+/', $line)) {
                return $i + 1;
            }
        }

        return 1;
    }
}
