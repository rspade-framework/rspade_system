<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * PATH-OWNER-01 - every volatile path is spelled by the path owner, not by a literal.
 *
 * RSpade keeps three volatile trees at the project root - `build/` (build outputs),
 * `tmp/` (derived caches and runtime temp) and `storage/` (user data plus
 * `storage/state`). `tmp/` and `storage/` are RELOCATABLE with `RSX_TMP_PATH` and
 * `RSX_STORAGE_PATH`; `build/` is fixed beside `system/` and `rsx/`.
 * `App\RSpade\Core\Paths\Rsx_Project_Paths` (and its pre-boot twin
 * `system/bootstrap/rsx_paths.php`, and the bash and node libs in `system/bin/lib/`)
 * is the ONE place each of those locations is named - for all three, because the
 * layout inside `build/` moves even though its root does not.
 *
 * WHY A RULE AND NOT A CONVENTION. A literal that survives a relocation does not
 * error. It addresses a directory that exists, is writable, and that nothing else
 * reads - so the writer succeeds, the reader finds nothing, and the defect surfaces
 * as missing data somewhere unrelated. That is the failure shape a lint rule is for.
 *
 * WHAT IT FLAGS:
 *   1. A literal naming one of the retired directories, in any language.
 *   2. `storage_path()` with a literal argument - the argument is the part that has
 *      to be a named method.
 *   3. `rsx_project_file_path()` or `base_path()` with a literal that begins with one
 *      of the three tree names.
 *   4. A raw write primitive whose path expression is derived from the build root,
 *      outside the small set of files that legitimately produce build outputs.
 *   5. A `./build`, `../tmp`-style relative literal naming one of the trees.
 *   6. One of the tree names concatenated onto a root expression (`base_path()`,
 *      `__DIR__`, `dirname()`, `getcwd()`, `storage_path()`, a bash `$PROJECT_ROOT`,
 *      a node `path.join()`).
 *
 * A LOGICAL KEY IS NOT A PATH. `'tmp/js-stubs/x.js'` and `'storage/uploads'` are the
 * manifest's own spelling, resolved by `absolute_for()`, and stay legal on their own -
 * every probe above requires a root expression or a `./` prefix beside them.
 *
 * Suppress with a rationale'd `@PATH-OWNER-01-EXCEPTION` comment on the line or the
 * line above.
 */
class PathOwner_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'PATH-OWNER-01';

    /** Directory names and environment keys that no longer exist, in any spelling. */
    private const RETIRED_LITERALS = [
        'rsx-build',
        'rsx-tmp',
        'rsx-framework',
        'rsx-locks',
        'rsx-thumbnails',
        'rsx-renditions',
        'storage/flock',
        'bootstrap/cache',
        'framework/views',
        'db_backups',
        'system/storage',
        'system/tmp',
        'RSX_BUILD_PATH',
    ];

    /** Logical tree prefixes a path helper must not be handed as a literal. */
    private const TREE_PREFIXES = ['storage/', 'build/', 'tmp/', 'storage', 'build', 'tmp'];

    /** PHP write primitives that must not target a build-root-derived path directly. */
    private const WRITE_PRIMITIVES = [
        'file_put_contents',
        'fopen',
        'fwrite',
        'mkdir',
        'rename',
        'unlink',
        'copy',
        'rmdir',
        'symlink',
        'touch',
    ];

    /**
     * Files that legitimately produce build outputs, and the owner and resolvers that
     * legitimately spell the directory names. Matched as path substrings.
     */
    private const ALLOWED = [
        'app/RSpade/Core/Paths/',
        'bootstrap/rsx_paths.php',
        'bootstrap/rsx_storage_link.php',
        'bin/lib/rsx_paths.sh',
        'bin/lib/rsx_paths.js',
        'bin/environment_updates/',
        'bin/publish',
        'app/RSpade/Core/Manifest/',
        'app/RSpade/Core/Bundle/BundleCompiler.php',
        '_ManifestSupport.php',
        'app/RSpade/Core/Prod/',
        'app/RSpade/Commands/Rsx/Clean_Command.php',
        'app/RSpade/Commands/Rsx/Build_Command.php',
        'app/RSpade/Commands/Rsx/Bundle_Compile_Command.php',
        'app/RSpade/Commands/Rsx/Manifest_Build_Command.php',
        'app/RSpade/Commands/Restricted/Optimize_Cache_Command.php',
        'CodeQuality/Rules/Convention/PathOwner_CodeQualityRule.php',
    ];

    /** Trees this rule never inspects at all. */
    private const IGNORED_TREES = [
        '/vendor/',
        '/node_modules/',
        '/.cdn-cache/',
        '/man/',
        '/docs/',
        '/docs.dev/',
        '/breaking_changes/',
    ];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Volatile Path Owner';
    }

    public function get_description(): string
    {
        return 'Every build/, tmp/ and storage/ location is spelled by Rsx_Project_Paths, never by a literal';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.sh'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $normalized = str_replace('\\', '/', $file_path);

        foreach (self::IGNORED_TREES as $tree) {
            if (str_contains($normalized, $tree)) {
                return;
            }
        }

        foreach (self::ALLOWED as $allowed) {
            if (str_contains($normalized, $allowed)) {
                return;
            }
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $index => $line) {
            $line_number = $index + 1;

            if ($this->__is_suppressed($lines, $index)) {
                continue;
            }

            $this->__check_retired_literal($file_path, $line, $line_number);
            $this->__check_path_helpers($file_path, $line, $line_number);
            $this->__check_build_root_write($file_path, $line, $line_number);
            $this->__check_relative_tree_literal($file_path, $line, $line_number);
            $this->__check_root_concatenation($file_path, $line, $line_number);
        }
    }

    /**
     * A retired directory name, anywhere in the line.
     *
     * Deliberately a line scan rather than a token walk: these names appear in shell
     * scripts and JS as well as PHP, and a name that no longer exists is wrong in a
     * comment too - a comment that describes a directory nobody has is a wrong answer
     * to the next person's question.
     */
    private function __check_retired_literal(string $file_path, string $line, int $line_number): void
    {
        foreach (self::RETIRED_LITERALS as $literal) {
            if (!str_contains($line, $literal)) {
                continue;
            }

            $is_env_key = str_starts_with($literal, 'RSX_');

            $this->add_violation(
                $file_path,
                $line_number,
                $is_env_key
                    ? "'{$literal}' is not an environment key RSpade reads."
                    : "'{$literal}' names a path that no longer exists.",
                trim($line),
                $this->__remediation(
                    $is_env_key
                        ? 'build/ is FIXED at <project>/build, beside system/ and rsx/, so that a '
                          . 'production box deploys and freezes the three together. Only tmp/ and '
                          . 'storage/ are relocatable, with RSX_TMP_PATH and RSX_STORAGE_PATH.'
                        : 'The volatile trees were split: build outputs live in build/, derived caches and '
                          . 'runtime temp in tmp/, and user data plus process state in storage/ (with '
                          . 'storage/state holding the maintenance flag, the flock files and the updater '
                          . 'ledger). system/ carries a link to build/ and to nothing else - a relocatable '
                          . 'root cannot have one, because a link cannot say where it moved to.'
                ),
                $this->get_default_severity()
            );

            return;
        }
    }

    /**
     * A path helper handed a literal that names one of the three trees.
     */
    private function __check_path_helpers(string $file_path, string $line, int $line_number): void
    {
        if (preg_match('/\bstorage_path\s*\(\s*[\'"]/', $line)) {
            $this->add_violation(
                $file_path,
                $line_number,
                'storage_path() is called with a literal path.',
                trim($line),
                $this->__remediation(
                    'The ARGUMENT is the part that has to be a named method: a literal inside '
                    . 'storage_path() states a location that only this call site knows about.'
                ),
                $this->get_default_severity()
            );

            return;
        }

        if (preg_match('/\b(rsx_project_file_path|base_path)\s*\(\s*[\'"]([^\'"]*)/', $line, $matches)) {
            $argument = $matches[2];

            foreach (self::TREE_PREFIXES as $prefix) {
                if ($argument === $prefix || str_starts_with($argument, rtrim($prefix, '/') . '/')) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "{$matches[1]}() is called with a literal naming a volatile tree ('{$argument}').",
                        trim($line),
                        $this->__remediation(
                            'Those three trees are relocatable, so their locations are answered by the '
                            . 'path owner rather than composed against base_path().'
                        ),
                        $this->get_default_severity()
                    );

                    return;
                }
            }
        }
    }

    /**
     * A `./build`, `../tmp`, `./storage` literal.
     *
     * A relative path is resolved against the WORKING DIRECTORY, which no framework
     * process chooses: artisan runs from wherever the operator stood, a task worker
     * from wherever it was spawned, the web worker from the pool's directory. The
     * literal therefore names a different directory depending on who ran the code, and
     * on a relocated root it names one nothing else reads.
     */
    private function __check_relative_tree_literal(string $file_path, string $line, int $line_number): void
    {
        if (!preg_match('/[\'"](?:\.\.?\/)+(build|tmp|storage)(?:\/|[\'"])/', $line, $matches)) {
            return;
        }

        $this->add_violation(
            $file_path,
            $line_number,
            "A relative literal names the {$matches[1]} tree.",
            trim($line),
            $this->__remediation(
                'A ./ or ../ path is resolved against the working directory, which no framework '
                . 'process chooses - so the same literal names a different directory depending on '
                . 'where the command was run from.'
            ),
            $this->get_default_severity()
        );
    }

    /**
     * One of the three tree names concatenated onto a root expression.
     *
     * This is the shape that survives a relocation in silence: base_path() . '/tmp/x'
     * resolves, is writable, and is read by nothing once RSX_TMP_PATH moved the tree.
     * Covered in all three languages, because the bash and node libraries answer the
     * same question the PHP owner does.
     */
    private function __check_root_concatenation(string $file_path, string $line, int $line_number): void
    {
        $patterns = [
            '/\b(?:base_path|dirname|getcwd|storage_path)\s*\([^)]*\)\s*\.\s*[\'"]\/?(build|tmp|storage)(?:\/|[\'"])/',
            '/__DIR__\s*\.\s*[\'"](?:\/\.\.)*\/(build|tmp|storage)(?:\/|[\'"])/',
            '/\$(?:\{)?(?:PROJECT_ROOT|SYSTEM_DIR|APP_DIR|BASE)(?:\})?\/(build|tmp|storage)\b/',
            '/path\.join\s*\([^)]*[\'"](build|tmp|storage)[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $line, $matches)) {
                continue;
            }

            $this->add_violation(
                $file_path,
                $line_number,
                "The {$matches[1]} tree is composed from a root expression instead of the path owner.",
                trim($line),
                $this->__remediation(
                    'Composing a tree name onto a root is the shape that survives a relocation in '
                    . 'silence: the path resolves, is writable, and is read by nothing.'
                ),
                $this->get_default_severity()
            );

            return;
        }
    }

    /**
     * A raw write primitive aimed at a build-root-derived path.
     *
     * Build outputs are produced by the build and by nothing else - on a correctly
     * configured production box the tree is read-only to the web user, and a write
     * from outside the build is a defect that only shows up on that box.
     */
    private function __check_build_root_write(string $file_path, string $line, int $line_number): void
    {
        if (!preg_match('/build_root\s*\(|build_path\s*\(|bundles_dir\s*\(/', $line)) {
            return;
        }

        foreach (self::WRITE_PRIMITIVES as $primitive) {
            if (!preg_match('/\b' . preg_quote($primitive, '/') . '\s*\(/', $line)) {
                continue;
            }

            $this->add_violation(
                $file_path,
                $line_number,
                "{$primitive}() writes into the build tree from outside the build.",
                trim($line),
                $this->__remediation(
                    'The build tree holds outputs one command produces and every served request '
                    . 'consumes. On a correctly configured production box it is read-only to the '
                    . 'web user, so a write from anywhere else fails only there.'
                ),
                $this->get_default_severity()
            );

            return;
        }
    }

    /**
     * The shared tail of every remediation: the method table and the escape hatch.
     */
    private function __remediation(string $preamble): string
    {
        return $preamble . "\n"
            . "\n"
            . "Ask App\\RSpade\\Core\\Paths\\Rsx_Project_Paths instead:\n"
            . "\n"
            . "  roots     build_root()  tmp_root()  storage_root()  state_root()  files_root()\n"
            . "  joiners   build_path(\$rel)  tmp_path(\$rel)  storage_path(\$rel)  state_path(\$rel)\n"
            . "  build/    manifest_index_file()  manifest_files_file()  build_key_file()\n"
            . "            bundles_dir()  seal_file()  laravel_cache_file(\$name)  views_compiled_dir()\n"
            . "  tmp/      stubs_dir(\$kind)  derived_dir(\$ns)  sockets_dir()  staging_dir()\n"
            . "            tasks_dir()  db_cache_dir()  db_backups_dir()  mail_catcher_dir()\n"
            . "            htmlpurifier_dir()  scratch_file(\$prefix, \$ext)  test_storage_dir()\n"
            . "            thumbnails_dir()  renditions_dir()\n"
            . "  storage/  logs_dir()  ide_bridge_dir()  app_dir(\$sub)\n"
            . "  state/    maintenance_flag_file()  flock_dir()  env_updates_fingerprint_file()\n"
            . "  keys      key_for(\$abs)  absolute_for(\$key)  stub_key(\$kind, \$file)\n"
            . "            is_under_build(\$p)  is_under_tmp(\$p)  is_stub_file(\$p)\n"
            . "\n"
            . "Pre-boot code (a bootstrap guard, a standalone handler) requires\n"
            . "system/bootstrap/rsx_paths.php and calls rsx_paths_build_root() and friends.\n"
            . "bash sources system/bin/lib/rsx_paths.sh; node requires system/bin/lib/rsx_paths.js.\n"
            . "\n"
            . "If the location genuinely cannot come from the owner, say why on the line above:\n"
            . "  // @" . self::RULE_ID . "-EXCEPTION <rationale>\n"
            . "\n"
            . "Rule: " . self::RULE_ID . ". See: rsx:man storage_directories";
    }

    /**
     * An exception comment on the line itself or the line before it.
     */
    private function __is_suppressed(array $lines, int $index): bool
    {
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        if (str_contains($lines[$index], $marker)) {
            return true;
        }

        return $index > 0 && str_contains($lines[$index - 1], $marker);
    }
}
