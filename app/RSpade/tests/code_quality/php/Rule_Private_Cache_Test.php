<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A CODE-QUALITY RULE MAY NOT INVENT A CACHE OF ITS OWN.
 *
 * Every rule that did produced the same artifact: a directory of tiny per-source-file
 * documents whose overwhelming majority said "clean". JS-THIS-01 kept 275 of them, almost
 * all holding the two bytes `[]`; JS-JQHTML-01 kept 316 averaging 47 bytes. Between them
 * and the two zero-byte `.lintpass` flag directories that is 1845 inodes spent writing down
 * a boolean, and each one arrived with its own key scheme and its own staleness test to get
 * wrong.
 *
 * There are exactly two sanctioned homes, and neither is a directory a rule creates:
 *
 *   - a per-file VERDICT ("this file already passed this check") goes in the shared
 *     `Validation_Ledger` - ONE var_export'd array, keyed by the manifest's file hash;
 *   - a derived FILE (a sanitized copy, a parse tree) goes through
 *     `App\RSpade\Core\Cache\File_Content_Cache`, which owns
 *     `storage/rsx-tmp/derived/<namespace>/`.
 *
 * So this test greps every rule class under `CodeQuality/Rules/` for the four spellings a
 * private cache is written in. NOTHING IS WHITELISTED. A rule that genuinely needs a file on
 * disk has a helper to reach it, and a rule that thinks it needs an exception is a rule that
 * has not been told about the helper.
 *
 * A structural grep rather than a code-quality rule of its own, deliberately: the same shape
 * as the other structural tests in this concern, and one that costs nothing on every check of
 * every file to answer a question about eight directories of framework source.
 */
class Rule_Private_Cache_Test extends Rsx_Test_Abstract
{
    // Pure filesystem scan - no database access.
    protected static $use_database_transactions = false;

    /**
     * The spellings a rule writes its own cache in. Each is matched as a call: the token
     * followed by an opening parenthesis, so a mention inside a comment or a docblock (the
     * word `mkdir` in prose, a path in an explanation) is not a hit.
     */
    private const FORBIDDEN = [
        'storage_path',
        'ensure_directory',
        'mkdir',
        'file_put_contents',
    ];

    /**
     * Every .php file under CodeQuality/Rules/.
     *
     * @return array<int,string>
     */
    private static function __rule_files(): array
    {
        $root = base_path('app/RSpade/CodeQuality/Rules');

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
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
     * The forbidden calls appearing in one file, as "file:line  spelling" rows.
     *
     * Comments are blanked first (bodies replaced with spaces, line numbers preserved), so
     * this reads CODE only - a docblock explaining why a rule does NOT call storage_path()
     * is not a violation of that same sentence.
     *
     * @return array<int,string>
     */
    private static function __hits(string $path): array
    {
        $source = file_get_contents($path);
        $code = static::__blank_php_comments($source);
        $lines = explode("\n", $code);

        $relative = str_replace(base_path() . '/', '', $path);
        $hits = [];

        foreach ($lines as $index => $line) {
            foreach (self::FORBIDDEN as $spelling) {
                if (preg_match('/(?<![A-Za-z0-9_$>])' . preg_quote($spelling, '/') . '\s*\(/', $line)) {
                    $hits[] = $relative . ':' . ($index + 1) . '  ' . $spelling . '()';
                }
            }
        }

        return $hits;
    }

    /**
     * Replace every comment body with spaces, keeping line breaks so line numbers still
     * address the original file.
     */
    private static function __blank_php_comments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= preg_replace('/[^\n]/', ' ', $token[1]);

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /**
     * No rule class touches the filesystem for itself.
     */
    public static function test_no_rule_writes_its_own_cache()
    {
        $files = static::__rule_files();

        static::__assert_greater_than(
            50,
            count($files),
            'the scan found the rules directory (a scan that finds nothing proves nothing)'
        );

        $hits = [];

        foreach ($files as $path) {
            $hits = array_merge($hits, static::__hits($path));
        }

        static::__assert_equals(
            [],
            $hits,
            "A code-quality rule may not create a cache of its own. Offending calls:\n  "
            . implode("\n  ", $hits)
            . "\n\nA per-file VERDICT goes in App\\RSpade\\CodeQuality\\Support\\Validation_Ledger."
            . "\nA derived FILE goes through App\\RSpade\\Core\\Cache\\File_Content_Cache."
            . "\nNothing is whitelisted - see rsx:man code_quality."
        );
    }
}
