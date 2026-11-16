<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-TEXT-HTML-01 - a column whose stored value is HTML is named `<thing>_html`, and a
 * column named `<thing>_html` stores HTML.
 *
 * Outside the model file the column's NAME is all a reader has: a raw query, a fetch()
 * payload, a template, a CSV header, an external API body (which carries the stored string,
 * not the typed value). A markup string printed escaped shows tags to the reader; a string
 * printed raw without knowing it is markup is an injection hole; an API endpoint writing the
 * field picks the plain door or the encoded door by what it believes the string is. None of
 * those mistakes raises an error. So the name carries the stored form, and this rule keeps
 * the name honest in both directions:
 *
 *   1. a column declaring a text type whose STORES_HTML is true must be named `*_html`;
 *   2. a column named `*_html` must declare a text type whose STORES_HTML is true - an
 *      undeclared one is a markup string nothing filters on write, and one declared with a
 *      non-HTML type is a name that lies.
 *
 * Checked on every concrete model - framework and application alike - against the connected
 * database's column map (Rsx_Schema::model_columns()). Only the columns of the model's OWN
 * table are judged: a detail table's columns merged into a base model's map belong to the
 * detail model, which is judged on its own table. A model whose table is not in the connected
 * database is skipped (migrations not yet run). A declared type that does not exist or does
 * not extend Rsx_Text_Abstract is TEXT-TYPE-01's finding, and is skipped here.
 *
 * A framework-private table (a `_`-prefixed name) is not judged. Its columns are no contract
 * (application code never reads one and no payload or API field carries one), and the
 * framework ships no concrete text type to declare on them - the HTML type is the
 * application's (`_email_queue.rendered_html` holds framework-rendered email markup).
 *
 * rsx:check only; suppressed by @MODEL-TEXT-HTML-01-EXCEPTION anywhere in the file (an
 * application keeping an old name until the migration renaming it lands).
 */
class ModelTextHtmlColumns_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-TEXT-HTML-01';
    }

    public function get_name(): string
    {
        return 'HTML Columns Are Named *_html';
    }

    public function get_description(): string
    {
        return 'A column whose text type stores HTML is named *_html, and a *_html column declares a text type that stores HTML';
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
     * Judge one concrete model's columns against its text-type declarations. The testable
     * seam: production passes a manifest model and Rsx_Schema's map, tests pass a fixture
     * model and a column map.
     *
     * @param array<string, array> $table_columns column => Rsx_Schema column shape
     */
    public function evaluate_class(string $fqcn, string $file_path, string $table, array $table_columns): void
    {
        if (str_starts_with($table, '_')) {
            return;
        }

        $text_types = $fqcn::text_type_columns();
        $class_name = class_basename($fqcn);
        $lines = explode("\n", (string) $this->source()->content($file_path));
        $line_number = static::declaration_line($lines);
        $snippet = trim($lines[$line_number - 1] ?? '');

        foreach ($table_columns as $column => $shape) {
            if (($shape['source_table'] ?? $table) !== $table) {
                continue;
            }

            $column = (string) $column;
            $type = $text_types[$column] ?? null;
            if ($type !== null && !is_subclass_of($type, Rsx_Text_Abstract::class)) {
                continue;
            }

            $named_html = str_ends_with($column, '_html');
            $stores_html = $type !== null && $type::stores_html();

            if ($stores_html && !$named_html) {
                $type_name = class_basename($type);
                $expected = $column . '_html';
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "{$class_name}::\$columns['{$column}'] declares {$type_name}, a text type that stores HTML, on '{$column}' of table '{$table}'.\n"
                    . "A column whose stored value is HTML is named <thing>_html, so every layer that carries the\n"
                    . "name - a fetch() payload, an Ajax key, an API field, a raw query - says the string is markup.\n"
                    . "Rename the column to '{$expected}'.\n"
                    . 'See: php artisan rsx:man text_types',
                    $snippet,
                    "Rename '{$column}' to '{$expected}': a forward migration\n"
                    . "(ALTER TABLE {$table} RENAME COLUMN {$column} TO {$expected}), then every reference to the\n"
                    . "old name - \$columns, form \$name, templates, #[Api_Param], API examples, tests.\n"
                    . 'See: php artisan rsx:man text_types',
                    'high'
                );

                continue;
            }

            if ($named_html && !$stores_html) {
                if ($type === null) {
                    $message = "'{$column}' of table '{$table}' is named as HTML, but {$class_name}::\$columns declares no text type for it.\n"
                        . "An undeclared column is a bare string: markup assigned to it is stored unfiltered, and nothing\n"
                        . "tells a printer it is markup. Declare the HTML text type that owns it:\n"
                        . "\n"
                        . "    '{$column}' => ['text_type' => Rich_Text::class],   // or your own type with STORES_HTML true\n";
                } else {
                    $type_name = class_basename($type);
                    $message = "{$class_name}::\$columns['{$column}'] declares {$type_name}, a text type that does not store HTML, on '{$column}' of table '{$table}'.\n"
                        . "The _html suffix says the stored string is markup, and this column's stored string is not.\n";
                }

                $message .= "If the column does not hold HTML, rename it without the _html suffix.\n"
                    . 'See: php artisan rsx:man text_types';

                $this->add_violation(
                    $file_path,
                    $line_number,
                    $message,
                    $snippet,
                    "Declare a text type with STORES_HTML true on '{$column}', or rename the column without the\n"
                    . "suffix: a forward migration (ALTER TABLE {$table} RENAME COLUMN ...), then every reference\n"
                    . "to the old name.\n"
                    . 'See: php artisan rsx:man text_types',
                    'high'
                );
            }
        }
    }

    /**
     * The line a finding belongs on: the class's own $columns declaration, else the class
     * declaration, else the first line.
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
