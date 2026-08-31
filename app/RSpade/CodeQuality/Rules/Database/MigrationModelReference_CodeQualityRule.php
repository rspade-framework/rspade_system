<?php

namespace App\RSpade\CodeQuality\Rules\Database;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\MigrationPaths;

/**
 * MIGRATION-MODEL-01 - a migration never references a model class or Type_Ref_Registry.
 *
 * A migration is a FORWARD-ONLY HISTORICAL RECORD that must replay cleanly from scratch
 * forever. A model class is CURRENT CODE: it gets renamed, re-namespaced and deleted. The
 * moment a migration names one, the two are coupled - and a from-scratch replay of the
 * chain hard-fails at a file nobody has touched in a year.
 *
 * The archetype is resolving a polymorphic type-ref id through the registry:
 *
 *     $id = Type_Ref_Registry::class_to_id('Event_Model');
 *
 * class_to_id() validates the name against the MANIFEST and auto-creates the row, so it
 * requires the class to exist RIGHT NOW. Delete the model and every replay dies with
 * "Cannot create type ref for 'Event_Model': Class not found in manifest." - one retired
 * model, and the whole migration chain no longer builds a database.
 *
 * The resilient form resolves the id by a get-or-create against `_type_refs` with the
 * class-name STRING and a hardcoded table name. A string literal is DATA: it needs no
 * class to exist, and it reads the same in ten years as it does today.
 *
 * Scope is the migration directories themselves (MigrationPaths::get_all_migration_files),
 * which the manifest deliberately does not index - so this rule enumerates its own files
 * through the special check_migrations() entry point rather than the per-file driver.
 *
 * Detection is over the PHP TOKEN STREAM, never text: a class name in a comment or in a
 * string literal is exactly what this rule wants developers to write, and must never be a
 * violation.
 */
class MigrationModelReference_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Registry classes a migration must not reach for. class_to_id() is the offender in
     * the field; the whole class is named because every entry point on it resolves the
     * name against the live manifest.
     */
    private const FORBIDDEN_CLASSES = [
        'Type_Ref_Registry',
    ];

    /** Name tokens - every spelling a class symbol can arrive as. */
    private const NAME_TOKENS = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    public function get_id(): string
    {
        return 'MIGRATION-MODEL-01';
    }

    public function get_name(): string
    {
        return 'Migration Model Reference Check';
    }

    public function get_description(): string
    {
        return 'A migration must be self-contained - no model class and no Type_Ref_Registry, so it replays from scratch forever';
    }

    public function get_file_patterns(): array
    {
        // This rule enumerates the migration directories itself - see check_migrations().
        return [];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Migrations are not in the manifest, so the per-file driver never offers them.
        // check_migrations() is the entry point.
    }

    /**
     * Special method called once per run by CodeQualityChecker.
     *
     * Uses the same file list the migration RUNNER uses, so the rule can never disagree
     * with it about what a migration is (nested subdirectories included).
     */
    public function check_migrations(): void
    {
        foreach (MigrationPaths::get_all_migration_files() as $file_path) {
            $this->check_migration_file($file_path);
        }
    }

    /**
     * Evaluate one migration file. Public so tests can drive it directly.
     */
    public function check_migration_file(string $file_path): void
    {
        if (!is_file($file_path)) {
            return;
        }

        $source = file_get_contents($file_path);

        $marker = '@' . $this->get_id() . '-EXCEPTION';
        if (str_contains($source, $marker)) {
            $this->evaluate_exception_marker($file_path, $source, $marker);

            return;
        }

        $lines = explode("\n", $source);

        foreach (static::find_class_references($source) as $reference) {
            $line_text = $lines[$reference['line'] - 1] ?? '';

            $this->add_violation(
                $file_path,
                $reference['line'],
                $this->build_message($reference),
                trim($line_text),
                $this->build_resolution(),
                'high'
            );
        }
    }

    /**
     * An exception marker with no rationale is itself the violation.
     *
     * The marker permanently suppresses this rule for the whole file, and a reader a year
     * from now has nothing but the rationale to judge it by. "Why" is the entire content
     * of the grant.
     */
    private function evaluate_exception_marker(string $file_path, string $source, string $marker): void
    {
        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            $position = strpos($line, $marker);
            if ($position === false) {
                continue;
            }

            $rationale = trim(substr($line, $position + strlen($marker)));
            $rationale = trim($rationale, "-:* \t");
            $rationale = trim(preg_replace('/\*\/\s*$/', '', $rationale));

            if (str_word_count($rationale) >= 3) {
                return;
            }

            $this->add_violation(
                $file_path,
                $index + 1,
                "{$marker} carries no rationale.

The marker suppresses MIGRATION-MODEL-01 for this entire file, permanently. A reader a
year from now has only the rationale to judge that grant by, so the rationale IS the
grant - a bare marker is an unexplained hole in a rule that exists to keep the migration
chain replayable.",
                trim($line),
                "Write WHY this migration cannot be self-contained, on the marker line, in the file docblock:

    /**
     * @" . $this->get_id() . "-EXCEPTION <why this migration needs live application code>
     */

The legitimate case is DATA SEEDING that needs model behaviour no raw SQL can reproduce
(a file pipeline, an encryption cast, a factory). Schema work and type-ref lookups are
never it - convert those instead.",
                'high'
            );

            return;
        }
    }

    private function build_message(array $reference): string
    {
        $kind = $reference['kind'];
        $name = $reference['name'];

        $what = match ($kind) {
            'use'        => "imports `{$name}`",
            'registry'   => "calls `{$name}::`",
            'static'     => "references the model class symbol `{$name}::`",
            'new'        => "instantiates `{$name}`",
            'instanceof' => "tests `instanceof {$name}`",
        };

        // The registry example must name a MODEL - "type ref for 'Type_Ref_Registry'" is
        // not a message anything ever prints.
        $example = str_ends_with($name, '_Model') ? $name : 'Foo_Model';

        return "A migration must not reference application code - this one {$what}.

A migration is a FORWARD-ONLY HISTORICAL RECORD that must replay cleanly from scratch
forever. A model class is CURRENT CODE that gets renamed and deleted. Naming one couples
the two, and the day that class is retired a from-scratch replay of the whole chain
hard-fails at this file - which nobody has touched in a year, and which is not where
anyone will look.

Type_Ref_Registry::class_to_id() is the same failure with a shorter fuse: it validates
the name against the LIVE MANIFEST and refuses a class that no longer exists, so the
replay dies with \"Cannot create type ref for '{$example}': Class not found in manifest.\"";
    }

    private function build_resolution(): string
    {
        return "REQUIRED ACTION - resolve type-ref ids against the table, with the class name as a STRING:

    \$type_ref_id = function (string \$class_name, string \$table_name): int {
        \$existing = DB::select(\"SELECT id FROM _type_refs WHERE class_name = ?\", [\$class_name]);
        if (!empty(\$existing)) {
            return (int) \$existing[0]->id;
        }
        DB::statement(
            \"INSERT INTO _type_refs (class_name, table_name, created_at, updated_at) VALUES (?, ?, NOW(3), NOW(3))\",
            [\$class_name, \$table_name]
        );
        return (int) DB::getPdo()->lastInsertId();
    };

    \$id = \$type_ref_id('Event_Model', 'events');

A class-name STRING LITERAL is data, not a symbol: it needs no class to exist and it is
never flagged. The table name is hardcoded for the same reason - asking a model for its
table is asking the model to exist.

FIX THIS AUTONOMOUSLY when the migration is resolving a type-ref id, reading a table the
model happens to own, or otherwise doing SCHEMA or DATA work: convert it to raw SQL plus
the closure above. That is the overwhelming majority of cases and needs nobody's approval.

THE ONE EXCEPTION IS DATA SEEDING that genuinely needs model BEHAVIOUR raw SQL cannot
reproduce - a file-upload pipeline, an encryption cast, a factory with side effects. That
migration is knowingly coupled to code that may be deleted, and says so in its docblock:

    /**
     * @" . $this->get_id() . "-EXCEPTION <why raw SQL cannot do this>
     */

The rationale is required; a bare marker is itself a violation of this rule.

Details: `php artisan rsx:man migrations`, `php artisan rsx:man polymorphic`.";
    }

    /**
     * Every CODE reference in the file that couples it to a class symbol.
     *
     * Token-based on purpose. A migration is EXPECTED to carry class names in comments and
     * in SQL string literals ('Event_Model' is the correct thing to write), and the only
     * reliable way to tell a symbol from text is to ask the tokenizer.
     *
     * @return array<int, array{kind: string, name: string, line: int}>
     */
    public static function find_class_references(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            // `use Some\Namespace\Client_Model;` - including grouped and aliased forms.
            if ($token[0] === T_USE) {
                $next = static::significant_token($tokens, $i + 1, 1);

                // A closure's `use ($var)` binds variables, not classes.
                if ($next === '(') {
                    continue;
                }

                foreach (static::collect_use_names($tokens, $i + 1, $count) as $used) {
                    if (static::is_forbidden_name($used['name'])) {
                        $found[] = [
                            'kind' => 'use',
                            'name' => static::short_name($used['name']),
                            'line' => $used['line'],
                        ];
                    }
                }

                continue;
            }

            if (!in_array($token[0], self::NAME_TOKENS, true)) {
                continue;
            }

            $short = static::short_name($token[1]);
            if (!static::is_forbidden_name($token[1])) {
                continue;
            }

            $previous = static::significant_token($tokens, $i - 1, -1);

            // A property or method of the same name is not a class symbol.
            if (is_array($previous)
                && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }

            if (is_array($previous) && $previous[0] === T_NEW) {
                $found[] = ['kind' => 'new', 'name' => $short, 'line' => $token[2]];
                continue;
            }

            if (is_array($previous) && $previous[0] === T_INSTANCEOF) {
                $found[] = ['kind' => 'instanceof', 'name' => $short, 'line' => $token[2]];
                continue;
            }

            // A static reference: a call, a constant, or ::class.
            $next = static::significant_token($tokens, $i + 1, 1);
            if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                $kind = in_array($short, self::FORBIDDEN_CLASSES, true) ? 'registry' : 'static';
                $found[] = ['kind' => $kind, 'name' => $short, 'line' => $token[2]];
            }
        }

        return $found;
    }

    /**
     * The names introduced by one `use` statement, from $start up to its terminator.
     *
     * Handles the plain form, the comma list, and the grouped `use A\{B_Model, C_Model};`
     * form. An `as` alias does not change what is being imported, so the alias is dropped.
     *
     * @return array<int, array{name: string, line: int}>
     */
    private static function collect_use_names(array $tokens, int $start, int $count): array
    {
        $names = [];
        $skip_alias = false;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{' || $token === '(') {
                if ($token === '{') {
                    // Grouped use - keep reading the braces for the member names.
                    $skip_alias = false;
                    continue;
                }

                break;
            }

            if ($token === '}') {
                break;
            }

            if ($token === ',') {
                $skip_alias = false;
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_AS) {
                $skip_alias = true;
                continue;
            }

            if (!in_array($token[0], self::NAME_TOKENS, true)) {
                continue;
            }

            if ($skip_alias) {
                continue;
            }

            $names[] = ['name' => $token[1], 'line' => $token[2]];
        }

        return $names;
    }

    /** A model class or the type-ref registry, by SHORT name - RSpade names classes simply. */
    private static function is_forbidden_name(string $raw): bool
    {
        $short = static::short_name($raw);

        if (in_array($short, self::FORBIDDEN_CLASSES, true)) {
            return true;
        }

        return str_ends_with($short, '_Model');
    }

    private static function short_name(string $raw): string
    {
        $raw = trim($raw, '\\');
        $position = strrpos($raw, '\\');

        return $position === false ? $raw : substr($raw, $position + 1);
    }

    /** The nearest non-whitespace, non-comment token from $start in direction $step. */
    private static function significant_token(array $tokens, int $start, int $step)
    {
        $count = count($tokens);

        for ($i = $start; $i >= 0 && $i < $count; $i += $step) {
            $token = $tokens[$i];

            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
