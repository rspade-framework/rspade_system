<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * EVERY FRAMEWORK MODEL IS A BASE PLUS A SHELL.
 *
 * A class override is a COPY, and a copy is frozen the moment it is taken while the class
 * it replaced keeps moving. A downstream field report on 2026-09-07 records what that costs:
 * five overrides of core models had drifted by hundreds of lines, and one had lost members
 * the framework later added and CALLED - a 500 on one path, a background queue that silently
 * enqueued nothing on another.
 *
 * The answer is structural. A framework model carries every member on an abstract base and
 * ships a three-line concrete that declares nothing:
 *
 *     abstract class User_Model_Abstract extends Rsx_Site_Actor_Model_Abstract { ... }
 *     class User_Model extends User_Model_Abstract {}
 *
 * An application replaces the SHELL - `class User_Model extends User_Model_Abstract` under
 * rsx/models/, declaring only what it changes - so it keeps inheriting everything the
 * framework adds to the base, and the drift surface shrinks to the members it wrote itself.
 * The manifest's override pass refuses an override of a split model that does not extend the
 * base, so a clone cannot come back in by the side door.
 *
 * These assertions are that shape, stated over the real tree:
 *   1. every framework concrete model extends `<Same>_Abstract` declared in the same
 *      directory;
 *   2. its own class body declares nothing at all;
 *   3. every split base has exactly one concrete - the framework's shell, or the
 *      application class that replaced it.
 *
 * TWO CLASSES ARE OUT OF SCOPE. `Session` is an Rsx_Model_Abstract descendant that is a
 * static facade called as `Session::` everywhere - overriding it is not a supported concept,
 * so splitting it would buy nothing. Test fixtures (anything under a `tests/` directory) are
 * models a test wrote for itself; nobody overrides those either.
 *
 * Manifest reads only, no DB.
 */
class Core_Model_Split_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The one framework Rsx_Model_Abstract descendant that is not a split model. */
    private const NOT_A_MODEL_TO_OVERRIDE = ['Session'];

    /**
     * The framework's concrete models: simple name => relative file path.
     *
     * @return array<string, string>
     */
    private static function __framework_concrete_models(): array
    {
        $models = [];

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            $class = $entry['class'] ?? '';
            $file = $entry['file'] ?? '';

            if ($class === '' || $file === '') {
                continue;
            }

            if (!Rsx_Paths::is_framework($file)) {
                continue;
            }

            if (str_contains($file, '/tests/')) {
                continue;
            }

            if (in_array($class, self::NOT_A_MODEL_TO_OVERRIDE, true)) {
                continue;
            }

            $models[$class] = $file;
        }

        ksort($models);

        return $models;
    }

    /**
     * Every SPLIT BASE in the tree: an abstract `X_Abstract` for which a concrete model `X`
     * is also indexed. Derived from the concretes rather than from a name pattern, because
     * `Rsx_Model_Abstract` and the rest of the shared machinery also end in `_Model_Abstract`
     * and are not per-model bases - and this derivation excludes them without a hand-written
     * list of names to keep current.
     *
     * A base with NO concrete at all is therefore out of scope here: it is a dead file, not a
     * dispatch hazard, and test 1 already requires every concrete to have a base.
     *
     * @return array<string, string> abstract class name => relative file path
     */
    private static function __split_bases(): array
    {
        $bases = [];

        foreach (static::__all_concrete_models() as $class => $file) {
            $base = $class . '_Abstract';
            $record = Manifest::php_class_metadata($base);

            if ($record === null || empty($record['abstract'])) {
                continue;
            }

            $base_file = $record['file'] ?? '';

            if ($base_file === '' || !Rsx_Paths::is_framework($base_file) || str_contains($base_file, '/tests/')) {
                continue;
            }

            $bases[$base] = $base_file;
        }

        ksort($bases);

        return $bases;
    }

    /**
     * Every concrete model in the tree, framework AND application, fixtures excluded.
     *
     * The application half matters for the one-concrete-per-base assertion: a shell an
     * application has overridden is archived, so the framework has no concrete for that base
     * any more and the APPLICATION's class is the one and only concrete. That is the override
     * working, not a defect.
     *
     * @return array<string, string>
     */
    private static function __all_concrete_models(): array
    {
        $models = [];

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            $class = $entry['class'] ?? '';
            $file = $entry['file'] ?? '';

            if ($class === '' || $file === '' || str_contains($file, '/tests/')) {
                continue;
            }

            $models[$class] = $file;
        }

        ksort($models);

        return $models;
    }

    // =====================================================================
    // 1 + 2: the shape of every framework concrete
    // =====================================================================

    /**
     * Each concrete extends `<Same>_Abstract`, and that abstract is declared beside it - the
     * two files are read together and are renamed together.
     */
    public static function test_every_framework_model_extends_its_own_abstract_base()
    {
        $offenders = [];

        foreach (static::__framework_concrete_models() as $class => $file) {
            $record = Manifest::php_class_metadata($class);
            $expected = $class . '_Abstract';
            $extends = $record['extends'] ?? null;

            if ($extends !== $expected) {
                $offenders[] = "{$class} ({$file}) extends " . ($extends ?? 'nothing') . ", not {$expected}";

                continue;
            }

            $base = Manifest::php_class_metadata($expected);

            if ($base === null) {
                $offenders[] = "{$class}: {$expected} is not in the manifest";

                continue;
            }

            if (dirname($base['file'] ?? '') !== dirname($file)) {
                $offenders[] = "{$class}: {$expected} is declared in " . dirname($base['file'] ?? '?')
                    . ', not beside the concrete in ' . dirname($file);
            }
        }

        static::__assert_empty(
            $offenders,
            "Every framework model is a base plus a shell. These are not:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The shell declares NOTHING. A member on the concrete is a member an application's
     * override silently drops the moment it replaces the file - which is the whole failure
     * the split exists to end.
     */
    public static function test_a_framework_model_shell_declares_no_members_of_its_own()
    {
        $offenders = [];

        foreach (static::__framework_concrete_models() as $class => $file) {
            $record = Manifest::$data['data']['files'][$file] ?? null;

            if ($record === null) {
                $offenders[] = "{$class}: no file record for {$file}";

                continue;
            }

            $declared = [];

            foreach (array_keys($record['public_static_methods'] ?? []) as $name) {
                $declared[] = 'method ' . $name . '()';
            }

            foreach (array_keys($record['public_instance_methods'] ?? []) as $name) {
                $declared[] = 'method ' . $name . '()';
            }

            foreach ($record['properties'] ?? [] as $property) {
                $declared[] = 'property $' . ($property['name'] ?? '?');
            }

            // The reflection record carries no constants, and a constant is exactly the kind
            // of member an override drops without noticing - so the file is tokenized for
            // class-body `const` declarations. A token pass, never a regex: a comment or a
            // string saying `const` is not a declaration.
            foreach (static::__declared_constants(base_path($file)) as $name) {
                $declared[] = 'const ' . $name;
            }

            $declared = array_values(array_unique($declared));

            if (!empty($declared)) {
                $offenders[] = "{$class} ({$file}) declares: " . implode(', ', $declared);
            }
        }

        static::__assert_empty(
            $offenders,
            "A framework model's concrete is an empty shell; every member belongs on its "
            . "abstract base. These declare members of their own:\n  " . implode("\n  ", $offenders)
        );
    }

    // =====================================================================
    // 3: one concrete per base
    // =====================================================================

    /**
     * A base with two concretes is an ambiguity every reflection-driven registry in the
     * framework would have to guess at - and a `X_Model_Abstract` with a second concrete
     * beside the shell is exactly what a half-finished override looks like.
     */
    public static function test_every_model_base_has_exactly_one_concrete()
    {
        $by_base = [];

        foreach (static::__all_concrete_models() as $class => $file) {
            $extends = Manifest::php_class_metadata($class)['extends'] ?? null;

            if ($extends === null) {
                continue;
            }

            $by_base[$extends][] = $class . ' (' . $file . ')';
        }

        $offenders = [];

        foreach (static::__split_bases() as $base => $file) {
            $found = $by_base[$base] ?? [];

            if (count($found) !== 1) {
                $offenders[] = "{$base} ({$file}) has " . count($found) . ' concrete(s)'
                    . (empty($found) ? '' : ': ' . implode(', ', $found));
            }
        }

        static::__assert_empty(
            $offenders,
            "Each model base has exactly one concrete:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The class-body `const` names one PHP file declares, at the top level of its single
     * class. Method bodies are skipped wholesale, so a `const` inside one contributes
     * nothing.
     *
     * @return array<int, string>
     */
    private static function __declared_constants(string $absolute_path): array
    {
        $source = @file_get_contents($absolute_path);

        if ($source === false) {
            return [];
        }

        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        $names = [];
        $depth = 0;
        $in_class = false;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === T_CLASS && !$in_class) {
                $in_class = true;

                continue;
            }

            if (!$in_class) {
                continue;
            }

            if ($token->text === '{') {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($depth !== 1 || $token->id !== T_CONST) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is(T_WHITESPACE) || $tokens[$j]->is(T_COMMENT) || $tokens[$j]->is(T_DOC_COMMENT)) {
                    continue;
                }

                if ($tokens[$j]->is(T_STRING)) {
                    $names[] = $tokens[$j]->text;
                }

                break;
            }
        }

        return $names;
    }
}
