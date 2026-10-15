<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Class_Override_Drift - what an application's class override has stopped carrying.
 *
 * THE FAILURE THIS EXISTS TO SEE. An override replaces a framework class wholesale: the
 * framework file is shadowed - left in place and never loaded - and the rsx/ copy IS the
 * class from then on. The copy is frozen at the moment it was taken; the framework's own
 * file keeps moving with every pull. When the framework later adds a member and CALLS it - from framework code, on a
 * class it believes is its own - the call lands on the application's older copy, which
 * never had that member. A downstream field report records exactly this: core called a
 * member of a class the app had replaced, the copy predated the member, and the result was
 * a 500 on one path and a background queue that silently enqueued nothing on another.
 * Nothing compared the two files, so nothing said a word.
 *
 * WHAT IT COMPARES. Every PHP entry of the manifest's override map (Manifest::overrides()):
 * the shadowed framework file, in place under its own name, and the rsx/ file that overrides
 * it. For that pair it answers two questions:
 *
 *   MISSING - a public or protected member the framework file declares and the override lacks.
 *             This is the defect: framework code may call it, and it is not there.
 *   ADDED   - a member the override declares and the framework file does not. This is the
 *             application's own work, and the reason the override exists at all. It is
 *             reported as context, never as a problem: a reader deciding whether to
 *             re-clone needs to know what a re-clone has to carry forward.
 *
 * WHY A TOKENIZER AND NOT THE MANIFEST'S METHOD METADATA. The manifest's methods and
 * properties come from REFLECTION, and reflection is unavailable on both sides here: a
 * shadowed framework file is never loaded (its class name belongs to the override), so its
 * class cannot be reflected, and reflection on the override would report the
 * full LINEAGE rather than what the file itself declares - which is the only thing a
 * re-clone can be measured against. A token pass over each file answers the exact question.
 * It is never a regex over the source: a regex cannot tell a declaration from a call, a
 * comment or a string.
 *
 * A SPLIT FRAMEWORK CLASS HAS NO DRIFT SURFACE AND IS SKIPPED. When the framework file
 * declares nothing but `class X extends X_Abstract`, every member lives on the base, and an
 * override that extends that same base inherits all of them - including the ones the
 * framework adds tomorrow. There is no frozen copy to measure, so the pair reports nothing
 * rather than naming the base's members as absent from a class that has them. An override of
 * a split class that does NOT extend the base never reaches here: the manifest's override
 * pass refuses it by name. A clone of a NON-split class is compared in full, as ever.
 *
 * PRIVATE MEMBERS ARE OUT OF SCOPE, deliberately. Framework code cannot call a private
 * member of another class, so a private only the framework file declares cannot break a caller.
 * A `use Some_Trait;` adoption IS in scope: dropping one silently drops every member the
 * trait supplied, which is the same failure with an extra step.
 *
 * @see CLASS-OVERRIDE-DRIFT-01 (ClassOverrideDrift_CodeQualityRule) - the rsx:check finding.
 * @see Class_Override_Drift_Health_Checks - the advisory rsx:health row.
 * @see rsx:man class_override
 */
class Class_Override_Drift
{
    /**
     * Every shadowed framework PHP file paired with the application class that overrides it,
     * read off the manifest's override map. Both paths are manifest keys (relative to the
     * framework tree), and the framework file is the real one, in place.
     *
     * @return array<int, array{class: string, framework_file: string, override_file: string}>
     */
    public static function pairs(): array
    {
        $pairs = [];

        foreach (Manifest::overrides() as $framework_file => $override) {
            $pairs[] = [
                'class' => $override['name'],
                'framework_file' => $framework_file,
                'override_file' => $override['by'],
            ];
        }

        usort($pairs, static fn ($a, $b) => strcmp($a['class'], $b['class']));

        return $pairs;
    }

    /**
     * Compare one pair, reading both files from disk.
     *
     * Absolute paths, so a test can drive it against fixtures that are in no manifest.
     * A file that cannot be read yields an empty member set on that side, which is
     * reported as nothing missing rather than as everything missing - this analysis
     * never invents a defect out of an unreadable file.
     *
     * @param string $framework_path Absolute path to the shadowed framework file.
     * @param string $override_path  Absolute path to the application's copy.
     * @param string $class_name     The simple class name both files declare.
     * @return array{missing: array, added: array} Member records, ordered as declared.
     */
    public static function analyze_pair(string $framework_path, string $override_path, string $class_name): array
    {
        $framework_source = @file_get_contents($framework_path);
        $override_source = @file_get_contents($override_path);

        // A SPLIT FRAMEWORK CLASS IS NOT A CLONE, AND HAS NO DRIFT SURFACE. When the
        // framework file declares nothing but `class X extends X_Abstract`, every member
        // lives on the base - and an override that extends that same base INHERITS every
        // one of them, including the ones the framework adds tomorrow. There is no frozen
        // copy here and nothing to compare: reporting the base's members as "missing" from
        // an override that inherits them would be pure noise on the one pattern this
        // framework asks applications to use.
        //
        // An override of a split class that does NOT extend the base never reaches here -
        // the manifest's override pass refuses it outright.
        if ($framework_source !== false && $override_source !== false) {
            $framework_parent = static::declared_parent($framework_source, $class_name);

            if (
                $framework_parent === $class_name . '_Abstract'
                && static::declared_parent($override_source, $class_name) === $framework_parent
            ) {
                return ['missing' => [], 'added' => []];
            }
        }

        $framework_members = $framework_source === false
            ? []
            : static::declared_members($framework_source, $class_name);
        $override_members = $override_source === false
            ? []
            : static::declared_members($override_source, $class_name);

        $missing = [];
        foreach ($framework_members as $key => $member) {
            if (!isset($override_members[$key])) {
                $missing[] = $member;
            }
        }

        $added = [];
        foreach ($override_members as $key => $member) {
            if (!isset($framework_members[$key])) {
                $added[] = $member;
            }
        }

        return ['missing' => $missing, 'added' => $added];
    }

    /**
     * Every drifting pair in this build, with its member lists.
     *
     * Pairs with no missing members are omitted: an override that carries everything
     * the framework file declares is not drifting, however much it has added of its own.
     *
     * @return array<int, array{class: string, framework_file: string, override_file: string, missing: array, added: array}>
     */
    public static function analyze_all(): array
    {
        $results = [];

        foreach (static::pairs() as $pair) {
            $analysis = static::analyze_pair(
                base_path($pair['framework_file']),
                base_path($pair['override_file']),
                $pair['class']
            );

            if (empty($analysis['missing'])) {
                continue;
            }

            $results[] = $pair + $analysis;
        }

        return $results;
    }

    /**
     * The public and protected members a source file DECLARES inside one named class.
     *
     * Keyed so two files can be compared directly: "method:name", "property:name",
     * "trait:Name". The value carries what a finding has to print - kind, name, whether it
     * is static, and the line it is declared on.
     *
     * Only the body of the named class or trait is read, at its top level: a member of an
     * anonymous class or of a nested declaration inside a method body is not a member of
     * this class and is never reported. Method bodies are skipped wholesale, which is also
     * what keeps braces inside them from confusing the depth count.
     *
     * @param string $source     File contents.
     * @param string $class_name The simple class (or trait) name to read.
     * @return array<string, array{kind: string, name: string, static: bool, line: int}>
     */
    public static function declared_members(string $source, string $class_name): array
    {
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        $index = static::__find_class_body($tokens, $count, $class_name);

        if ($index === null) {
            return [];
        }

        $members = [];

        // Modifiers seen since the last statement boundary. A declaration's visibility and
        // staticness are always to its LEFT, and every declaration ends at a ; or a body.
        $visibility = null;
        $is_static = false;
        $depth = 1;

        for ($i = $index; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token->id;

            if ($token->text === '{') {
                $depth++;
                continue;
            }

            if ($token->text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($token->is(T_WHITESPACE) || $token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                continue;
            }

            if ($id === T_PUBLIC || $id === T_PROTECTED || $id === T_PRIVATE || $id === T_VAR) {
                $visibility = $id === T_VAR ? T_PUBLIC : $id;
                continue;
            }

            if ($id === T_STATIC) {
                $is_static = true;
                continue;
            }

            if ($id === T_USE) {
                // A `use` at class-body top level is a trait adoption. Every name it lists
                // is one adoption; the optional { } conflict block is skipped by the depth
                // counter above, and contributes nothing.
                foreach (static::__use_names($tokens, $count, $i) as $trait_name) {
                    $members['trait:' . $trait_name] = [
                        'kind' => 'trait',
                        'name' => $trait_name,
                        'static' => false,
                        'line' => $token->line,
                    ];
                }

                $visibility = null;
                $is_static = false;
                continue;
            }

            if ($id === T_FUNCTION) {
                $name = static::__following_name($tokens, $count, $i);

                if ($name !== null && $visibility !== T_PRIVATE) {
                    $members['method:' . $name] = [
                        'kind' => 'method',
                        'name' => $name,
                        'static' => $is_static,
                        'line' => $token->line,
                    ];
                }

                $i = static::__skip_function($tokens, $count, $i);
                $visibility = null;
                $is_static = false;
                continue;
            }

            if ($id === T_VARIABLE) {
                // A variable at class-body top level is a property. A visibility keyword or
                // a bare `static` introduces one (`static $x;` is implicitly public);
                // nothing else at this level produces a variable token.
                if (($visibility !== null || $is_static) && $visibility !== T_PRIVATE) {
                    $name = ltrim($token->text, '$');
                    $members['property:' . $name] = [
                        'kind' => 'property',
                        'name' => $name,
                        'static' => $is_static,
                        'line' => $token->line,
                    ];
                }

                $i = static::__skip_to_semicolon($tokens, $count, $i);
                $visibility = null;
                $is_static = false;
                continue;
            }

            if ($token->text === ';') {
                $visibility = null;
                $is_static = false;
            }
        }

        return $members;
    }

    /**
     * The SIMPLE name of the class the named class extends, or null when it extends nothing.
     *
     * Token-based, like every other reader here: a mention of the parent in a comment, a
     * string or a `::class` reference can never be mistaken for the declaration. A qualified
     * parent (`\App\...\Foo`) is reduced to `Foo`, which is what the manifest indexes and
     * what an override in another namespace writes.
     *
     * @param string $source     File contents.
     * @param string $class_name The simple class name to read.
     */
    public static function declared_parent(string $source, string $class_name): ?string
    {
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_CLASS) {
                continue;
            }

            $previous = static::__previous_meaningful($tokens, $i);
            if ($previous !== null && $tokens[$previous]->is(T_DOUBLE_COLON)) {
                continue;
            }

            if (static::__following_name($tokens, $count, $i) !== $class_name) {
                continue;
            }

            for ($j = $i; $j < $count; $j++) {
                if ($tokens[$j]->text === '{') {
                    return null;
                }

                if ($tokens[$j]->id !== T_EXTENDS) {
                    continue;
                }

                $parent = static::__following_type_name($tokens, $count, $j);

                if ($parent === null) {
                    return null;
                }

                $parent = ltrim($parent, '\\');
                $parts = explode('\\', $parent);

                return end($parts);
            }

            return null;
        }

        return null;
    }

    /**
     * The 1-based line the named class or trait is DECLARED on, or 0 when the file declares
     * no such thing. Token-based, so a mention in a comment, a string or a `::class`
     * reference can never be mistaken for the declaration.
     */
    public static function class_declaration_line(string $source, string $class_name): int
    {
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i]->id;

            if ($id !== T_CLASS && $id !== T_TRAIT) {
                continue;
            }

            $previous = static::__previous_meaningful($tokens, $i);
            if ($previous !== null && $tokens[$previous]->is(T_DOUBLE_COLON)) {
                continue;
            }

            if (static::__following_name($tokens, $count, $i) === $class_name) {
                return $tokens[$i]->line;
            }
        }

        return 0;
    }

    /**
     * Index of the first token INSIDE the body of the named class/trait, or null when the
     * file declares no such thing.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function __find_class_body(array $tokens, int $count, string $class_name): ?int
    {
        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i]->id;

            if ($id !== T_CLASS && $id !== T_TRAIT) {
                continue;
            }

            // `Foo::class` is not a declaration.
            $previous = static::__previous_meaningful($tokens, $i);
            if ($previous !== null && $tokens[$previous]->is(T_DOUBLE_COLON)) {
                continue;
            }

            $name = static::__following_name($tokens, $count, $i);

            if ($name !== $class_name) {
                continue;
            }

            for ($j = $i; $j < $count; $j++) {
                if ($tokens[$j]->text === '{') {
                    return $j + 1;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The next identifier after $index, skipping whitespace, comments and the by-reference
     * ampersand of `function &foo()`. Null when the next meaningful token is not a name.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function __following_name(array $tokens, int $count, int $index): ?string
    {
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_WHITESPACE) || $token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                continue;
            }

            if ($token->text === '&') {
                continue;
            }

            if ($token->is(T_STRING)) {
                return $token->text;
            }

            return null;
        }

        return null;
    }

    /**
     * The next TYPE name after $index - a bare identifier or a namespaced one, which PHP
     * tokenizes as a single T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED token rather than the
     * T_STRING `__following_name()` reads. Null when the next meaningful token is not a name.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function __following_type_name(array $tokens, int $count, int $index): ?string
    {
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_WHITESPACE) || $token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                continue;
            }

            if ($token->is(T_STRING) || $token->is(T_NAME_QUALIFIED) || $token->is(T_NAME_FULLY_QUALIFIED)) {
                return $token->text;
            }

            return null;
        }

        return null;
    }

    /**
     * Index of the previous non-whitespace, non-comment token, or null.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function __previous_meaningful(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token->is(T_WHITESPACE) || $token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * The trait names a class-body `use` statement adopts, up to its `;` or `{`.
     *
     * A namespaced name is reduced to its last segment: RSX classes are unique by simple
     * name, and the two files being compared may spell the same trait differently (the
     * override's copy was import-fixed to point at the rsx/ tree).
     *
     * @param array<int, \PhpToken> $tokens
     * @return array<int, string>
     */
    private static function __use_names(array $tokens, int $count, int $index): array
    {
        $names = [];

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === ';' || $token->text === '{') {
                break;
            }

            if ($token->is(T_STRING) || $token->is(T_NAME_QUALIFIED) || $token->is(T_NAME_FULLY_QUALIFIED)) {
                $segments = explode('\\', $token->text);
                $names[] = end($segments);
            }
        }

        return $names;
    }

    /**
     * Index of the last token of a function declaration that starts at $index: the `;` of
     * an abstract/interface declaration, or the `}` closing its body.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function __skip_function(array $tokens, int $count, int $index): int
    {
        $depth = 0;

        for ($i = $index + 1; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '{') {
                $depth++;
                continue;
            }

            if ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                continue;
            }

            if ($text === ';' && $depth === 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * Index of the `;` ending a property declaration that starts at $index. Brackets and
     * braces inside a default value are counted so a `;` inside one is not mistaken for
     * the end of the statement.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function __skip_to_semicolon(array $tokens, int $count, int $index): int
    {
        $depth = 0;

        for ($i = $index + 1; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '[' || $text === '(' || $text === '{') {
                $depth++;
                continue;
            }

            if ($text === ']' || $text === ')' || $text === '}') {
                $depth--;
                continue;
            }

            if ($text === ';' && $depth <= 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * A member rendered for a human: "public static function foo()", "protected $bar".
     *
     * Visibility is not carried in the record (the analysis only ever keeps public and
     * protected, and which of the two it is does not change what a reader must do), so the
     * label states the shape and the name.
     *
     * @param array{kind: string, name: string, static: bool, line: int} $member
     */
    public static function describe(array $member): string
    {
        if ($member['kind'] === 'trait') {
            return 'use ' . $member['name'] . ';';
        }

        if ($member['kind'] === 'property') {
            return ($member['static'] ? 'static ' : '') . '$' . $member['name'];
        }

        return ($member['static'] ? 'static ' : '') . 'function ' . $member['name'] . '()';
    }
}
