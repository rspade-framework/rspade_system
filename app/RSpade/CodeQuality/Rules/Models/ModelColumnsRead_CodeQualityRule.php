<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * MODEL-COLUMNS-02 - a model's `$columns` is read only through its accessors.
 *
 * `Model::$columns` answers the declaration of the ONE class that declares the property
 * nearest to Model - not the effective declaration. The effective one overlays every class
 * from Rsx_Model_Abstract down (the base's audit type columns included), and only the
 * accessors compute it: columns(), enum_columns(), enum_values(), text_type_columns(),
 * type_ref_columns(), never_exported_columns(), revision_excluded_columns(). A direct read
 * silently misses every inherited entry.
 *
 * Detected on the token stream: `::` followed by the variable `$columns`. A mention inside a
 * string or a comment is not a read. Rsx_Model_Abstract, which implements the accessors, is
 * the one file that reads the property.
 *
 * Suppressed by @MODEL-COLUMNS-02-EXCEPTION anywhere in the file.
 */
class ModelColumnsRead_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-COLUMNS-02';
    }

    public function get_name(): string
    {
        return 'Model Column Declaration Read';
    }

    public function get_description(): string
    {
        return 'A model\'s $columns is read through columns() and its kind views, never as ::$columns';
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
        if (basename($file_path) === 'Rsx_Model_Abstract.php') {
            return;
        }

        $raw = (string) $this->source()->content($file_path);

        if (!str_contains($raw, '$columns')) {
            return;
        }

        $tokens = $this->source()->tokens($file_path);
        $lines = explode("\n", $raw);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_DOUBLE_COLON) {
                continue;
            }

            $next = $i + 1;
            while ($next < $count && $tokens[$next]->isIgnorable()) {
                $next++;
            }

            if ($next >= $count || $tokens[$next]->id !== T_VARIABLE || $tokens[$next]->text !== '$columns') {
                continue;
            }

            $line = $tokens[$next]->line;

            $this->add_violation(
                $file_path,
                $line,
                "A direct ::\$columns read answers ONE class's declaration, not the model's effective "
                . "column declarations: every entry inherited from a parent (Rsx_Model_Abstract's audit "
                . "type columns included) is missing from it.",
                trim($lines[$line - 1] ?? ''),
                "Read the declaration through the accessor that answers the question:\n"
                . "    Model::columns()                    all entries, overlaid\n"
                . "    Model::enum_columns() / enum_values('col')\n"
                . "    Model::text_type_columns() / text_type_or_null('col')\n"
                . "    Model::type_ref_columns()\n"
                . "    Model::never_exported_columns()\n"
                . "    Model::revision_excluded_columns()\n"
                . 'See: php artisan rsx:man model',
                'high'
            );
        }
    }
}
