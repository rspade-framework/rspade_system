<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A FRAMEWORK TEST MAY NOT DEPEND ON THE APPLICATION IT IS INSTALLED IN.
 *
 * The framework suite SHIPS: downstream it is an administrator's integrity audit of the
 * installed software, run with `rsx:test --framework` against an application whose models,
 * roles, screens and bundles are its own. A test that reaches for the reference
 * application's vocabulary does not fail there with a clear message - it fatals on an
 * undefined class or an undefined constant, in a suite whose whole job is to tell the
 * administrator whether the INSTALL is sound.
 *
 * The same rule binds framework CORE, and more strictly. Two live surfaces once read a role
 * constant an application's own User_Model need not declare, and one of them ran at the top
 * of every `rsx:test` invocation - so an application with different roles could not run the
 * suite at all. That is the third assertion below, and it is the one that would have caught
 * it.
 *
 * WHAT IS GUARDED
 *
 *   1. RESOLVED TYPE REFERENCES. Every simple name a test file references - a use, a type
 *      hint, a `::`, a `new`, a component - is resolved through the manifest and must
 *      declare in a file OUTSIDE rsx/. This is exact: it reads the same index the runtime
 *      resolves through, so it cannot be fooled by an import that is only cosmetic.
 *   1b. QUOTED class names, which resolve at RUNTIME rather than at parse time and so carry
 *      no manifest reference. Advisory in shape - it reports rather than proves, because a
 *      quoted name can legitimately be a string that no class ever answers to.
 *   2. ROLE AND PERMISSION CONSTANTS in the test tree.
 *   3. ROLE AND PERMISSION CONSTANTS in framework core.
 *
 * WHAT IS NOT GUARDED, deliberately: TABLE names, URL paths and BUNDLE names. Each of them
 * is an ordinary string with no index to resolve it against, and a regex over plausible
 * spellings would flag the framework's own tables, the framework's own routes and every
 * quoted word that happens to look like one. The rule for those lives in the suite's
 * documentation (`tests/CLAUDE.md`, PORTABILITY) and in review - a framework test names a
 * framework table, a `/_`-prefixed or `/api/` route, and a bundle it resolved from the
 * manifest.
 *
 * NOTHING IS WHITELISTED. A test that thinks it needs an exception is a test that needs a
 * fixture: a fixture model, a fixture route, a fixture component under its own concern.
 *
 * A structural test rather than a code-quality rule, deliberately: the same shape as the
 * other structural tests in this concern, and one that costs nothing on every check of
 * every file to answer a question about one directory.
 */
class Framework_Test_Portability_Test extends Rsx_Test_Abstract
{
    // Manifest and filesystem reads only - no database access.
    protected static $use_database_transactions = false;

    /**
     * The models an application is expected to override, and whose role vocabulary is
     * therefore the APPLICATION's rather than the framework's.
     */
    private const OVERRIDE_ELIGIBLE_MODELS = [
        'User_Model',
        'Portal_User_Model',
        'Login_User_Model',
    ];

    private const TEST_TREE = 'app/RSpade/tests/';

    // =========================================================================
    // HALF 3 - framework core may not name a role or permission constant
    // =========================================================================

    /**
     * Framework core does not read an application's role or permission vocabulary.
     *
     * A role constant is declared by the application's own User_Model (through $enums), so
     * core code naming one is core code that only runs in THIS application. The two known
     * offenders were a live controller and the test-baseline seed; both were rewritten to
     * derive the role they need.
     */
    public static function test_framework_core_names_no_role_or_permission_constant()
    {
        $hits = [];

        foreach (static::__framework_php_files(false) as $path) {
            $hits = array_merge($hits, static::__constant_hits($path));
        }

        static::__assert_equals(
            [],
            $hits,
            "Framework core may not name a role or permission constant - they are APPLICATION\n"
            . "vocabulary, declared by the application's own User_Model:\n  "
            . implode("\n  ", $hits)
            . "\n\nDerive the role instead: Rsx_Test_Abstract::most_privileged_role_id() /"
            . " role_triple()\nin a test, User_Model::role_id__enum() in core."
        );
    }

    // =========================================================================
    // HALF 1 - every resolved reference declares outside rsx/
    // =========================================================================

    /**
     * Every type a framework test references is declared by the framework.
     *
     * The manifest records the simple names each file references; each is resolved through
     * the php / js / jqhtml indexes - the same lookup the runtime performs - and the file it
     * declares in must not be under rsx/.
     *
     * A class OVERRIDE is not a violation: when an application replaces a framework class,
     * the manifest points the name at rsx/ and archives the framework file beside it as
     * `.php.upstream`. The name is still framework vocabulary, and the archive is the proof.
     */
    public static function test_no_framework_test_references_an_application_type()
    {
        $overridden = static::__overridden_framework_class_names();

        $hits = [];
        $scanned = 0;

        foreach (Manifest::get_all() as $path => $record) {
            if (!str_starts_with($path, self::TEST_TREE)) {
                continue;
            }

            $names = $record['referenced_simple_names'] ?? null;

            if (!is_array($names)) {
                continue;
            }

            $scanned++;

            foreach ($names as $name) {
                if (isset($overridden[$name])) {
                    continue;
                }

                foreach (static::__declaring_files($name) as $kind => $declaring_file) {
                    if (str_starts_with($declaring_file, 'rsx/')) {
                        $hits[] = $path . '  references ' . $name . '  (' . $kind . ' declared in ' . $declaring_file . ')';
                    }
                }
            }
        }

        static::__assert_greater_than(
            0,
            $scanned,
            'the manifest carries framework test files with reference lists (a scan that finds nothing proves nothing)'
        );

        static::__assert_equals(
            [],
            $hits,
            "A framework test may reference framework types ONLY - the suite ships and runs in an\n"
            . "application that declares none of these:\n  "
            . implode("\n  ", $hits)
            . "\n\nDeclare a fixture under the concern's own tests/ directory instead - see"
            . " tests/CLAUDE.md, PORTABILITY."
        );
    }

    // =========================================================================
    // HALF 2 - the test tree may not name a role or permission constant
    // =========================================================================

    /**
     * No framework test names a role or permission constant.
     *
     * The manifest indexes no class constants, so this half is a regex - over CODE only,
     * with comments blanked, so a docblock explaining the rule is not a breach of it.
     */
    public static function test_no_framework_test_names_a_role_or_permission_constant()
    {
        $hits = [];

        foreach (static::__test_tree_files() as $path) {
            $hits = array_merge($hits, static::__constant_hits($path));
        }

        static::__assert_equals(
            [],
            $hits,
            "A framework test may not name a role or permission constant - roles and permissions\n"
            . "are application vocabulary:\n  "
            . implode("\n  ", $hits)
            . "\n\nDerive them: Rsx_Test_Abstract::most_privileged_role_id() / role_triple(), or"
            . "\nUser_Model::role_id__enum() for the full map."
        );
    }

    // =========================================================================
    // HALF 1b - quoted application class names (advisory in shape)
    // =========================================================================

    /**
     * No framework test embeds an application class name as a STRING.
     *
     * A quoted name resolves at runtime, so it carries no manifest reference and Half 1
     * cannot see it - `Orm_Controller::fetch(['model' => 'Client_Model'])` is exactly as
     * broken downstream as a `use` of the same class, and exactly as invisible.
     *
     * The code_quality concern is the one directory this cannot ask about: its rule tests
     * feed SYNTHETIC SOURCE to a rule and assert what the rule says about it, so an
     * application class name there is test INPUT rather than a dependency - the file is
     * never resolved and nothing is ever loaded from it.
     */
    public static function test_no_framework_test_quotes_an_application_class_name()
    {
        $app_names = static::__application_declared_names();

        static::__assert_greater_than(
            0,
            count($app_names),
            'the manifest carries application-declared classes (a scan that finds nothing proves nothing)'
        );

        $hits = [];

        foreach (static::__test_tree_files() as $path) {
            if (str_contains($path, '/tests/code_quality/')) {
                continue;
            }

            $code = static::__code_of($path);
            $relative = str_replace(base_path() . '/', '', $path);

            foreach (explode("\n", $code) as $index => $line) {
                foreach ($app_names as $name => $declaring_file) {
                    if (preg_match('/[\'"]([A-Za-z0-9_\\\\]*\\\\)?' . preg_quote($name, '/') . '(::[A-Za-z0-9_]+)?[\'"]/', $line)) {
                        $hits[] = $relative . ':' . ($index + 1) . '  "' . $name . '"  (declared in ' . $declaring_file . ')';
                    }
                }
            }
        }

        static::__assert_equals(
            [],
            $hits,
            "A framework test may not name an application class in a string - it resolves at\n"
            . "runtime, so nothing catches it until the suite runs in an application that does not\n"
            . "declare it:\n  "
            . implode("\n  ", $hits)
            . "\n\nUse a fixture declared under the concern's own tests/ directory."
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Every declaring file a simple name resolves to, as kind => path. A name that names
     * both a PHP class and a jqhtml component answers twice, and both are checked.
     *
     * @return array<string,string>
     */
    private static function __declaring_files(string $name): array
    {
        $data = Manifest::$data['data'];

        $found = [];

        if (isset($data['php_classes'][$name]['file'])) {
            $found['php'] = $data['php_classes'][$name]['file'];
        }

        if (isset($data['js_classes'][$name]['file'])) {
            $found['js'] = $data['js_classes'][$name]['file'];
        }

        if (isset($data['jqhtml']['components'][$name]['file'])) {
            $found['jqhtml'] = $data['jqhtml']['components'][$name]['file'];
        }

        return $found;
    }

    /**
     * Every simple name this application declares under rsx/, as name => declaring file.
     *
     * @return array<string,string>
     */
    private static function __application_declared_names(): array
    {
        $data = Manifest::$data['data'];
        $overridden = static::__overridden_framework_class_names();

        $names = [];

        $sources = [
            $data['php_classes'] ?? [],
            $data['js_classes'] ?? [],
            $data['jqhtml']['components'] ?? [],
        ];

        foreach ($sources as $index) {
            foreach ($index as $name => $entry) {
                $file = $entry['file'] ?? null;

                if ($file === null || !str_starts_with($file, 'rsx/') || isset($overridden[$name])) {
                    continue;
                }

                $names[$name] = $file;
            }
        }

        ksort($names);

        return $names;
    }

    /**
     * The framework class names an application has OVERRIDDEN, read off the `.php.upstream`
     * archives the manifest's override pass leaves behind. Such a name is framework
     * vocabulary that happens to be declared in rsx/ today.
     *
     * @return array<string,true>
     */
    private static function __overridden_framework_class_names(): array
    {
        $names = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app/RSpade'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'upstream') {
                continue;
            }

            if (preg_match('/^\s*(?:abstract\s+|final\s+)*class\s+([A-Za-z0-9_]+)/m', file_get_contents($entry->getPathname()), $match)) {
                $names[$match[1]] = true;
            }
        }

        return $names;
    }

    /**
     * Every framework PHP file, either inside the test tree or outside it.
     *
     * @return array<int,string>
     */
    private static function __framework_php_files(bool $inside_tests): array
    {
        $root = base_path('app/RSpade');
        $tests_root = $root . '/tests/';

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $path = $entry->getPathname();

            if (str_starts_with($path, $tests_root) !== $inside_tests) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Every source file in the test tree, of every kind the suite runs: the PHP classes, the
     * shell tests and the playwright scripts. The last two are not manifest-indexed, and are
     * exactly where a role constant or an application class name hid longest.
     *
     * `_archive/` is excluded - it is never run, by policy.
     *
     * @return array<int,string>
     */
    private static function __test_tree_files(): array
    {
        $root = base_path('app/RSpade/tests');

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || !in_array($entry->getExtension(), ['php', 'js', 'sh'], true)) {
                continue;
            }

            $path = $entry->getPathname();

            if (str_contains($path, '/_archive/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * A file's CODE: for PHP, every comment body replaced with spaces so line numbers still
     * address the original file; for everything else, the bytes as they are.
     */
    private static function __code_of(string $path): string
    {
        $source = file_get_contents($path);

        if (!str_ends_with($path, '.php')) {
            return $source;
        }

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
     * The role / permission constant references in one file, as "file:line  spelling" rows.
     *
     * @return array<int,string>
     */
    private static function __constant_hits(string $path): array
    {
        $pattern = static::__constant_pattern();

        $code = static::__code_of($path);
        $relative = str_replace(base_path() . '/', '', $path);

        $hits = [];

        foreach (explode("\n", $code) as $index => $line) {
            if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $hits[] = $relative . ':' . ($index + 1) . '  ' . $match[0];
                }
            }
        }

        return $hits;
    }

    /**
     * The forbidden spelling: one of the override-eligible models, `::`, and either a ROLE
     * constant this application's own $enums declare or any PERM_ name.
     *
     * The role names are DERIVED, never listed - an application's roles are its own, and a
     * hardcoded list here would be the very mistake this test exists to catch.
     */
    private static function __constant_pattern(): string
    {
        $role_constants = [];

        foreach ([User_Model::class, Portal_User_Model::class, Login_User_Model::class] as $fqcn) {
            $enums = $fqcn::$enums['role_id'] ?? [];

            foreach ($enums as $definition) {
                if (isset($definition['constant'])) {
                    $role_constants[$definition['constant']] = true;
                }
            }
        }

        $alternatives = array_map(
            fn ($constant) => preg_quote($constant, '/'),
            array_keys($role_constants)
        );

        // PERM_ is a prefix rather than a list: permissions are declared as constants, which
        // the manifest does not index, so the naming convention is the only handle there is.
        $alternatives[] = 'PERM_[A-Z0-9_]+';

        $models = implode('|', array_map(
            fn ($model) => preg_quote($model, '/'),
            self::OVERRIDE_ELIGIBLE_MODELS
        ));

        return '/\\\\?(?:' . $models . ')\s*::\s*(?:' . implode('|', $alternatives) . ')\b/';
    }
}
