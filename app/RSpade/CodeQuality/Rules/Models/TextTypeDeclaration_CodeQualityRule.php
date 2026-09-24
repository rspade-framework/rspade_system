<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * TEXT-TYPE-01 - a $text_types declaration must be one the cast can honour.
 *
 * A declared text column is where markup and plain text stop being confused, and every way
 * the declaration can be wrong fails SILENTLY at runtime - the column quietly stops being
 * special, or fails only when a browser first receives it. Hence a manifest-build FATAL,
 * the same family as POLY-01 and REVISION-01. Three checks:
 *
 *   1. Each entry names a class that exists and extends Rsx_Text_Abstract. Otherwise the
 *      first read or write of the column throws, far from the declaration.
 *   2. Each entry's type has a JavaScript twin of the same simple name extending the JS
 *      Rsx_Text_Abstract. Otherwise the column fails only when a browser first receives
 *      it - PHP owns the encoding, the twin owns presentation, and a type is both.
 *   3. No $casts entry (or casts() entry) names a declared column. The text cast is
 *      attached only where no cast already exists, so such an entry SHADOWS it: the column
 *      stores whatever it is handed, unfiltered, and reads back a plain string - a
 *      stored-XSS path with no error anywhere.
 *
 * Checked on every class that DECLARES $text_types (checks 1 and 2, reported once at the
 * declaration), and on every model whose effective $text_types is non-empty (check 3,
 * reported on that model, since a shadowing $casts may live on either side of an
 * override).
 *
 * NOT detected (deliberately): a casts() method whose body cannot be evaluated without a
 * booted model. This rule is fatal, so an unprovable case is skipped rather than guessed at.
 *
 * Suppressed by @TEXT-TYPE-01-EXCEPTION anywhere in the file.
 *
 * See: php artisan rsx:man text_types
 */
class TextTypeDeclaration_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'TEXT-TYPE-01';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Text Type Declaration';
    }

    public function get_description(): string
    {
        return 'A $text_types entry must name an Rsx_Text_Abstract type with a JS twin, and no $casts entry may shadow it';
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
     * Runs during the manifest scan: every failure mode here is silent until the column is
     * read, written, or first reaches a browser.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every PHP file (models and types), every JS file (the twins), and the class indexes
     * both are resolved through.
     *
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.php',
            'files:*.js',
            'php_classes',
            'php_subclass_index',
            'js_classes',
        ];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        foreach (Manifest::get_all() as $rel_path => $file_metadata) {
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            $fqcn = $file_metadata['fqcn'] ?? null;
            if (!$fqcn || !class_exists($fqcn) || !is_subclass_of($fqcn, Rsx_Model_Abstract::class)) {
                continue;
            }

            $this->evaluate_class($fqcn, base_path($rel_path), Manifest::php_is_abstract($file_metadata['class'] ?? class_basename($fqcn)));
        }
    }

    /**
     * Validate one model class. The testable seam: production passes manifest classes, tests
     * pass fixture classes loaded from a temporary file - which the manifest cannot answer
     * for, so whether the class is abstract is the caller's to say.
     */
    public function evaluate_class(string $fqcn, string $abs_file, bool $is_abstract = false): void
    {
        $text_types = (array) $fqcn::$text_types;

        if ($text_types === []) {
            return;
        }

        $contents = (string) $this->source()->content($abs_file);

        if (str_contains($contents, '@' . self::RULE_ID . '-EXCEPTION')) {
            return;
        }

        $declares = (new \ReflectionProperty($fqcn, 'text_types'))->getDeclaringClass()->getName() === $fqcn;

        if ($declares) {
            $this->check_entries($fqcn, $abs_file, $contents, $text_types);
        }

        if (!$is_abstract) {
            $this->check_casts_do_not_shadow($fqcn, $abs_file, $contents, $text_types);
        }
    }

    /**
     * CHECKS 1 and 2: every entry names a real type, and that type has its JS twin.
     */
    private function check_entries(string $fqcn, string $abs_file, string $contents, array $text_types): void
    {
        $line = $this->line_of($contents, '/\$text_types\s*=/');

        foreach ($text_types as $column => $type_class) {
            $type_name = class_basename((string) $type_class);
            $class_name = class_basename($fqcn);

            if (!is_string($type_class) || !class_exists($type_class)
                || !is_subclass_of($type_class, \App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract::class)) {
                $this->add_violation(
                    $abs_file,
                    $line,
                    "{$class_name}::\$text_types['{$column}'] names " . (is_string($type_class) ? $type_class : gettype($type_class))
                    . ", which is not a class extending Rsx_Text_Abstract.\n\n"
                    . "The first read or write of the column would throw, far from this declaration.",
                    $this->line_text($contents, $line),
                    "Name a text type class - Rich_Text::class, or one of your own extending Rsx_Text_Abstract.\n"
                    . "See: php artisan rsx:man text_types",
                    'critical'
                );

                continue;
            }

            if (!$this->has_js_twin($type_name)) {
                $this->add_violation(
                    $abs_file,
                    $line,
                    "{$class_name}::\$text_types['{$column}'] names {$type_name}, which has no JavaScript twin.\n\n"
                    . "A text type is two classes of the same name: PHP owns the encoding, JavaScript owns "
                    . "which component prints and edits it. Without the twin the column fails only when a "
                    . "browser first receives it.",
                    $this->line_text($contents, $line),
                    "Add a JS class {$type_name} extending Rsx_Text_Abstract (static PRINTER / EDITOR), "
                    . "beside the PHP class.\nSee: php artisan rsx:man text_types, WRITING A TYPE",
                    'critical'
                );
            }
        }
    }

    /**
     * CHECK 3: no explicit cast shadows the text cast on a declared column.
     */
    private function check_casts_do_not_shadow(string $fqcn, string $abs_file, string $contents, array $text_types): void
    {
        $casts = $this->declared_casts($fqcn);

        foreach (array_keys($text_types) as $column) {
            if (!array_key_exists($column, $casts)) {
                continue;
            }

            $class_name = class_basename($fqcn);
            $cast = is_string($casts[$column]) ? $casts[$column] : get_debug_type($casts[$column]);
            $line = $this->line_of($contents, '/\$casts\s*=|function\s+casts\s*\(/');

            $this->add_violation(
                $abs_file,
                $line,
                "{$class_name} declares a cast for '{$column}' ('{$cast}'), which is a declared text column.\n\n"
                . "The text cast is attached only where no cast already exists, so this entry SHADOWS it: "
                . "the column stores whatever it is handed with no filter and reads back a plain string. "
                . "Markup from a request is stored verbatim - a stored-XSS path - and nothing reports it.",
                $this->line_text($contents, $line),
                "Remove '{$column}' from the model's casts. \$text_types is the column's cast.\n"
                . "See: php artisan rsx:man text_types",
                'critical'
            );
        }
    }

    /**
     * The explicit casts a model states: the $casts default plus a casts() method's answer,
     * the two sources Eloquent's getCasts() merges before RSpade adds its own.
     *
     * @return array<string, mixed>
     */
    private function declared_casts(string $fqcn): array
    {
        $reflection = new \ReflectionClass($fqcn);
        $casts = (array) ($reflection->getDefaultProperties()['casts'] ?? []);

        if ($reflection->hasMethod('casts')) {
            $method = $reflection->getMethod('casts');

            if ($method->getDeclaringClass()->getName() !== \Illuminate\Database\Eloquent\Model::class) {
                try {
                    $method->setAccessible(true);
                    $casts = array_merge($casts, (array) $method->invoke($reflection->newInstanceWithoutConstructor()));
                } catch (\Throwable $unevaluable) {
                    // A casts() body that needs a booted model cannot be read here; the rule
                    // is fatal, so it skips what it cannot prove.
                }
            }
        }

        return $casts;
    }

    /**
     * Whether the JS class of this simple name exists and extends the JS Rsx_Text_Abstract.
     */
    private function has_js_twin(string $type_name): bool
    {
        try {
            Manifest::js_find_class($type_name);
        } catch (\RuntimeException $absent) {
            return false;
        }

        return Manifest::js_is_subclass_of($type_name, 'Rsx_Text_Abstract');
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
