<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A CODE-QUALITY RULE'S check() MAY NOT KEEP A FUNCTION-LEVEL STATIC.
 *
 * The shape this refuses is the self-guard - `static $already_checked = false; if
 * ($already_checked) return; $already_checked = true;` - which a rule judging the whole tree
 * once used to stop itself re-running for every changed file. The driver
 * (`Manifest_Rule_Driver`) already calls a KIND_CROSS_FILE rule exactly once per pass, so the
 * guard does nothing in an ordinary pass and is WRONG in a process that runs two - a manifest
 * build followed by `rsx:check`, a long-lived CLI - where the second pass silently skips the
 * rule and reports nothing. Its per-file twin (walk the tree on the first file, then
 * `static $already_run`) is the same mistake: a rule that judges the tree declares
 * kind() KIND_CROSS_FILE and depends_on(), and its check() simply judges.
 *
 * A function-level static anywhere in check() outlives the pass in the same way, so the
 * refusal is the whole category, not the two spellings it was found under. NOTHING IS
 * WHITELISTED. A static in another method (a memo of a value derived from the rule's own
 * source, such as a ledger id) is untouched.
 *
 * A structural grep, like Rule_Private_Cache_Test beside it.
 */
class Rule_Self_Guard_Test extends Rsx_Test_Abstract
{
    // Pure filesystem scan - no database access.
    protected static $use_database_transactions = false;

    public static function test_no_rule_check_keeps_a_function_level_static()
    {
        $hits = [];

        foreach (static::__rule_files() as $path) {
            $body = static::__check_body($path);

            if ($body !== null && preg_match('/(?<![A-Za-z0-9_])static\s+\$\w+/', $body)) {
                $hits[] = str_replace(base_path() . '/', '', $path);
            }
        }

        static::__assert_equals(
            [],
            $hits,
            "These rules keep a function-level static in check(). A rule judging the whole tree declares "
            . "kind() KIND_CROSS_FILE and depends_on(); the driver calls it once per pass:\n  "
            . implode("\n  ", $hits)
        );
    }

    /**
     * Every .php file under CodeQuality/Rules/.
     *
     * @return array<int,string>
     */
    private static function __rule_files(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app/RSpade/CodeQuality/Rules'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The source of the class's own check() method body, comments removed, or null when the
     * file declares none.
     *
     * @param string $path
     * @return string|null
     */
    private static function __check_body(string $path): ?string
    {
        $tokens = \PhpToken::tokenize(file_get_contents($path));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_FUNCTION)) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && $tokens[$j]->isIgnorable()) {
                $j++;
            }

            if ($j >= $count || $tokens[$j]->text !== 'check') {
                continue;
            }

            while ($j < $count && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';') {
                $j++;
            }

            if ($j >= $count || $tokens[$j]->text === ';') {
                return null;
            }

            $depth = 0;
            $body = '';

            for (; $j < $count; $j++) {
                $text = $tokens[$j]->text;

                if ($tokens[$j]->is([T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }

                if ($text === '{' || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                }

                $body .= $text;

                if ($depth === 0) {
                    return $body;
                }
            }
        }

        return null;
    }
}
