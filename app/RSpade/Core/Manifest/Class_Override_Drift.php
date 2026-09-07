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
 * framework file is archived to <Name>.php.upstream and the rsx/ copy IS the class from
 * then on. The copy is frozen at the moment it was taken; the framework's own file keeps
 * moving. When the framework later adds a member and CALLS it - from framework code, on a
 * class it believes is its own - the call lands on the application's older copy, which
 * never had that member. A downstream field report records exactly this: core called a
 * member of a class the app had replaced, the copy predated the member, and the result was
 * a 500 on one path and a background queue that silently enqueued nothing on another.
 * Nothing compared the two files, so nothing said a word.
 *
 * WHAT IT COMPARES. Every .php.upstream sidecar the manifest indexes is paired with the
 * active class of the same simple name - which, by construction of the override pass, is
 * the rsx/ file shadowing it. For that pair it answers two questions:
 *
 *   MISSING - a public or protected member declared upstream and absent from the override.
 *             This is the defect: framework code may call it, and it is not there.
 *   ADDED   - a member the override declares and upstream does not. This is the
 *             application's own work, and the reason the override exists at all. It is
 *             reported as context, never as a problem: a reader deciding whether to
 *             re-clone needs to know what a re-clone has to carry forward.
 *
 * WHY A TOKENIZER AND NOT THE MANIFEST'S METHOD METADATA. The manifest's methods and
 * properties come from REFLECTION, and reflection is unavailable on both sides here: an
 * archived .php.upstream file is never loaded (its class name belongs to the override), so
 * it carries no reflection keys at all, and reflection on the override would report the
 * full LINEAGE rather than what the file itself declares - which is the only thing a
 * re-clone can be measured against. A token pass over each file answers the exact question.
 * It is never a regex over the source: a regex cannot tell a declaration from a call, a
 * comment or a string.
 *
 * PRIVATE MEMBERS ARE OUT OF SCOPE, deliberately. Framework code cannot call a private
 * member of another class, so a private that only exists upstream cannot break a caller.
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
     * Every archived framework file paired with the application class that shadows it.
     *
     * Reads the compiled manifest only. An .upstream entry whose class has no active
     * counterpart is skipped: that is an orphan the restore pass is about to undo, not a
     * drifting override.
     *
     * @return array<int, array{class: string, upstream_file: string, override_file: string}>
     */
    public static function pairs(): array
    {
        $files = Manifest::get_all();

        if (empty($files)) {
            return [];
        }

        $active = [];
        foreach ($files as $rel_path => $metadata) {
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }
            if (empty($metadata['class'])) {
                continue;
            }

            $active[$metadata['class']] = $rel_path;
        }

        $pairs = [];
        foreach ($files as $rel_path => $metadata) {
            if (($metadata['extension'] ?? '') !== 'php.upstream') {
                continue;
            }
            if (empty($metadata['class'])) {
                continue;
            }

            $class_name = $metadata['class'];

            if (!isset($active[$class_name])) {
                continue;
            }

            $pairs[] = [
                'class' => $class_name,
                'upstream_file' => $rel_path,
                'override_file' => $active[$class_name],
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
     * @param string $upstream_path Absolute path to the .php.upstream sidecar.
     * @param string $override_path Absolute path to the application's copy.
     * @param string $class_name    The simple class name both files declare.
     * @return array{missing: array, added: array} Member records, ordered as declared.
     */
    public static function analyze_pair(string $upstream_path, string $override_path, string $class_name): array
    {
        $upstream_source = @file_get_contents($upstream_path);
        $override_source = @file_get_contents($override_path);

        $upstream_members = $upstream_source === false
            ? []
            : static::declared_members($upstream_source, $class_name);
        $override_members = $override_source === false
            ? []
            : static::declared_members($override_source, $class_name);

        $missing = [];
        foreach ($upstream_members as $key => $member) {
            if (!isset($override_members[$key])) {
                $missing[] = $member;
            }
        }

        $added = [];
        foreach ($override_members as $key => $member) {
            if (!isset($upstream_members[$key])) {
                $added[] = $member;
            }
        }

        return ['missing' => $missing, 'added' => $added];
    }

    /**
     * Every drifting pair in this build, with its member lists.
     *
     * Pairs with no missing members are omitted: an override that carries everything
     * upstream declares is not drifting, however much it has added of its own.
     *
     * @return array<int, array{class: string, upstream_file: string, override_file: string, missing: array, added: array}>
     */
    public static function analyze_all(): array
    {
        $results = [];

        foreach (static::pairs() as $pair) {
            $analysis = static::analyze_pair(
                base_path($pair['upstream_file']),
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
