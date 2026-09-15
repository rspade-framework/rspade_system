<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Paths\Php;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Coverage for the pre-boot resolver (system/bootstrap/rsx_paths.php) - the functions
 * every guard uses before Laravel exists, and that Rsx_Project_Paths delegates to.
 *
 * THE CONTRACT THAT MATTERS IS PARITY. A guard running before boot and a booted
 * consumer must produce byte-identical paths, or the same file lands under two
 * spellings and the second one is a directory nothing else reads.
 *
 * Each resolution case runs in a CHILD php process against a synthetic tree, because
 * the functions memoize per process and this process has already resolved the real
 * roots. RSX_PATHS_PROJECT_ROOT is the seam that points a child at the synthetic tree.
 *
 * Pure logic, no DB.
 */
class Preboot_Paths_Resolver_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run one expression in a child php process with a synthetic project root.
     *
     * @param array<string,string> $env Extra environment for the child.
     * @param array<int,string> $argv Extra argv words, which is how the child is made to
     *                                look like `php artisan rsx:build`.
     * @return array{0:int,1:string} [exit code, trimmed output]
     */
    private static function __resolve(string $project_root, string $expression, array $env = [], array $argv = []): array
    {
        $script = 'require ' . var_export(base_path('bootstrap/rsx_paths.php'), true) . '; echo ' . $expression . ';';

        // THE PARENT EXPORTED ITS OWN RESOLVED ROOTS. Both entrypoints call
        // rsx_paths_export() so every spawned child inherits the absolutes its parent
        // resolved - which is the point, and which would make this child answer about
        // THIS box instead of the synthetic tree. Each case starts from a clean
        // environment and puts back only what it is testing.
        $assignments = ['-u', 'RSX_TMP_PATH', '-u', 'RSX_STORAGE_PATH', '-u', 'TMPDIR'];
        $assignments[] = 'RSX_PATHS_PROJECT_ROOT=' . escapeshellarg($project_root);

        foreach ($env as $key => $value) {
            $assignments[] = $key . '=' . escapeshellarg($value);
        }

        $command = 'env ' . implode(' ', $assignments) . ' php -r ' . escapeshellarg($script);

        foreach ($argv as $word) {
            $command .= ' ' . escapeshellarg($word);
        }

        $command .= ' 2>&1';

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        return [$exit, trim(implode("\n", $output))];
    }

    /**
     * A throwaway project root carrying one .env.
     */
    private static function __make_tree(string $env_contents): string
    {
        $root = Rsx_Project_Paths::tmp_path('paths-resolver-' . getmypid() . '-' . bin2hex(random_bytes(4)));
        mkdir($root, 0777, true);
        file_put_contents($root . '/.env', $env_contents);

        return $root;
    }

    private static function __remove_tree(string $root): void
    {
        @unlink($root . '/.env');
        @rmdir($root);
    }

    /**
     * With nothing configured, the three roots are the project's own directories and
     * state sits inside storage.
     */
    public static function test_the_defaults_are_the_project_root_trees()
    {
        $root = static::__make_tree("APP_NAME=RSpade\n");

        try {
            [$exit, $out] = static::__resolve(
                $root,
                'rsx_paths_build_root() . "|" . rsx_paths_tmp_root() . "|" . rsx_paths_storage_root() . "|" . rsx_paths_state_root()'
            );

            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals(
                "{$root}/build|{$root}/tmp|{$root}/storage|{$root}/storage/state",
                $out,
                'the four default roots'
            );
        } finally {
            static::__remove_tree($root);
        }
    }

    /**
     * An override in the .env moves the root, and the FIRST occurrence wins - which is
     * phpdotenv's immutable behaviour, so the booted application will read the same one.
     */
    public static function test_the_first_env_file_occurrence_wins_and_quotes_are_stripped()
    {
        $root = static::__make_tree(
            "# a comment\n"
            . "RSX_TMP_PATH=\"/opt/first-tmp\"\n"
            . "RSX_TMP_PATH=/opt/second-tmp\n"
        );

        try {
            [$exit, $out] = static::__resolve($root, 'rsx_paths_tmp_root()');

            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals('/opt/first-tmp', $out, 'the first definition wins, unquoted');
        } finally {
            static::__remove_tree($root);
        }
    }

    /**
     * The build root is FIXED beside system/ and rsx/. There is no key to set: the seal,
     * the manifest index and the build key live in it, and it is deployed and frozen with
     * the two source trees.
     */
    public static function test_the_build_root_is_fixed_and_ignores_an_environment_key()
    {
        $root = static::__make_tree("RSX_BUILD" . "_PATH=/opt/nowhere\n");

        try {
            [$exit, $out] = static::__resolve(
                $root,
                'rsx_paths_build_root()',
                ['RSX_BUILD' . '_PATH' => '/opt/also-nowhere']
            );

            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals($root . '/build', $out, 'the build root is the project root tree');
        } finally {
            static::__remove_tree($root);
        }
    }

    /**
     * A real environment variable beats the file, again matching phpdotenv - the test
     * runner and the docker entrypoint both export roots this way.
     */
    public static function test_the_environment_beats_the_env_file()
    {
        $root = static::__make_tree("RSX_TMP_PATH=/opt/from-file\n");

        try {
            [$exit, $out] = static::__resolve($root, 'rsx_paths_tmp_root()', ['RSX_TMP_PATH' => '/opt/from-environment']);

            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals('/opt/from-environment', $out, 'the environment wins');
        } finally {
            static::__remove_tree($root);
        }
    }

    /**
     * An EMPTY key means "use the default" - that is how a shipped .env.dist can declare
     * all three without relocating anything.
     */
    public static function test_an_empty_override_means_the_default()
    {
        $root = static::__make_tree("RSX_TMP_PATH=\nRSX_STORAGE_PATH=\n");

        try {
            [$exit, $out] = static::__resolve($root, 'rsx_paths_tmp_root()');

            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals($root . '/tmp', $out, 'an empty value falls to the default');
        } finally {
            static::__remove_tree($root);
        }
    }

    /**
     * A RELATIVE override is relative to the PROJECT ROOT - never to the working
     * directory, which a spawned subprocess does not choose, and never to Laravel's base
     * path one level down. './storage' and 'storage' name the same tree.
     */
    public static function test_a_relative_override_resolves_against_the_project_root()
    {
        $root = static::__make_tree("RSX_STORAGE_PATH=./shared/storage\nRSX_TMP_PATH=scratch\n");

        try {
            [$exit, $out] = static::__resolve($root, 'rsx_paths_storage_root()');
            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals($root . '/shared/storage', $out, 'a ./ prefix is the project root');

            [$exit, $out] = static::__resolve($root, 'rsx_paths_tmp_root()');
            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals($root . '/scratch', $out, 'a bare relative value is the project root too');
        } finally {
            static::__remove_tree($root);
        }
    }

    /**
     * PARITY. The booted owner and the pre-boot functions answer identically for this
     * very box - the one assertion that catches a divergence between the two readers.
     */
    public static function test_the_owner_agrees_with_the_resolver()
    {
        static::__assert_equals(rsx_paths_build_root(), Rsx_Project_Paths::build_root(), 'build root parity');
        static::__assert_equals(rsx_paths_tmp_root(), Rsx_Project_Paths::tmp_root(), 'tmp root parity');
        static::__assert_equals(rsx_paths_storage_root(), Rsx_Project_Paths::storage_root(), 'storage root parity');
        static::__assert_equals(rsx_paths_state_root(), Rsx_Project_Paths::state_root(), 'state root parity');
        static::__assert_equals(rsx_paths_tmp_root(), storage_path(), 'Laravel storage_path() IS the tmp root');
    }

    /**
     * The export sets TMPDIR, so a plain library's sys_get_temp_dir() scratch lands in
     * the project's own tmp tree instead of the machine's /tmp - which is also what puts
     * it inside what rsx:clean can reach.
     */
    public static function test_the_export_points_the_php_temp_directory_at_the_tmp_root()
    {
        $root = static::__make_tree("APP_NAME=RSpade\n");

        try {
            [$exit, $out] = static::__resolve(
                $root,
                'rsx_paths_export() ?? (getenv("TMPDIR") . "|" . sys_get_temp_dir())'
            );

            static::__assert_equals(0, $exit, 'the child resolved cleanly: ' . $out);
            static::__assert_equals("{$root}/tmp|{$root}/tmp", $out, 'TMPDIR and sys_get_temp_dir() are the tmp root');
        } finally {
            static::__remove_tree($root);
        }
    }

    // =====================================================================
    // Writability checks at init
    // =====================================================================

    /**
     * storage/ and tmp/ are CREATED when simply absent. Both are the framework's to
     * make, and a fresh checkout has neither.
     */
    public static function test_the_init_check_creates_the_two_writable_trees()
    {
        $root = static::__make_tree("APP_NAME=RSpade\n");

        try {
            [$exit, $out] = static::__resolve(
                $root,
                'rsx_paths_assert_trees_writable() ?? ((is_dir("' . $root . '/storage") ? "storage" : "no-storage")'
                . ' . "|" . (is_dir("' . $root . '/tmp") ? "tmp" : "no-tmp"))'
            );

            static::__assert_equals(0, $exit, 'the check passed: ' . $out);
            static::__assert_equals('storage|tmp', $out, 'both trees exist afterwards');
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * An unwritable storage tree is a REFUSAL, not a warning: every symptom of it -
     * a failed upload, a lock that cannot be taken, a log that goes nowhere - appears
     * far from the cause.
     */
    public static function test_an_unwritable_storage_tree_is_refused_by_name()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::__skip('this process is root, which writes a 0500 directory regardless');

            return;
        }

        $root = static::__make_tree("APP_NAME=RSpade\n");
        mkdir($root . '/storage', 0500, true);

        try {
            [$exit, $out] = static::__resolve($root, 'rsx_paths_assert_trees_writable() ?? "no-refusal"');

            static::__assert_equals(1, $exit, 'the process refused');
            static::__assert_contains($root . '/storage', $out, 'the message names the directory');
            static::__assert_contains('chown', $out, 'and the remedy');
        } finally {
            exec_safe('chmod -R u+rwx ' . escapeshellarg($root) . ' 2>/dev/null');
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * build/ is only checked where something is expected to WRITE it - development, or a
     * process that IS the build. A sealed production runtime wants those trees read-only,
     * so checking them there would report the correct deployment as broken.
     */
    public static function test_the_build_tree_is_checked_in_development_and_not_in_a_production_runtime()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::__skip('this process is root, which writes a 0500 directory regardless');

            return;
        }

        $root = static::__make_tree("RSX_MODE=production\n");
        mkdir($root . '/build', 0500, true);

        try {
            [$exit, $out] = static::__resolve($root, 'rsx_paths_assert_trees_writable() ?? "passed"');
            static::__assert_equals(0, $exit, 'a production runtime does not check build/: ' . $out);
            static::__assert_equals('passed', $out, 'and carries on');

            [$exit, $out] = static::__resolve(
                $root,
                'rsx_paths_assert_trees_writable() ?? "passed"',
                [],
                ['rsx:build']
            );
            static::__assert_equals(1, $exit, 'the build itself refuses');
            static::__assert_contains($root . '/build', $out, 'naming the build tree');
        } finally {
            exec_safe('chmod -R u+rwx ' . escapeshellarg($root) . ' 2>/dev/null');
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }
}
