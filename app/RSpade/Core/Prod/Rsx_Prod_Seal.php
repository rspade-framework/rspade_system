<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Prod;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Sealed production build manifest ("the seal").
 *
 * A prod build is compiled ONCE by rsx:build and then treated as IMMUTABLE. The seal
 * is the on-disk record of that build: it pins the build_key, the mode it was built
 * for, and a sha256 of every build artifact (manifest_index.php, manifest_files.php,
 * build_key, manifest/class_overrides.php, and every file under bundles/ and
 * bundle_models/). It exists so the
 * framework can:
 *
 *   - recognize that a production-like mode has a build to serve at all (is_sealed(),
 *     which the manifest gate reads: an unsealed prod box refuses to run);
 *   - verify on demand that the assets on disk still match what was sealed
 *     (verify() - the cluster/CI drift check).
 *
 * The seal is a STATE RECORD, not a permission: what may write the build tree is
 * Rsx_Project_Paths::assert_build_writable(), which keys on the mode and the build
 * context, so an unsealed production box is guarded exactly like a sealed one. The
 * guard also reads this file fresh from disk, so a process that memoized development
 * mode before the seal was written is refused as well.
 *
 * The seal file lives inside the build root, and asset paths are stored relative to
 * it, so the seal is self-contained and location-independent.
 *
 * It is also the BUILD IDENTITY a running box reports (Rsx::build_info()): the
 * application commit, the framework revision and the time the build was made, recorded
 * here at build time because a deployed box may carry no git metadata to read them from.
 *
 * NOTE: built_at, commit, framework_revision and the seal file as a whole are
 * deliberately NOT part of any hashed build artifact - they carry the
 * non-deterministic build metadata that the manifest index intentionally drops for
 * byte-stability.
 */
class Rsx_Prod_Seal
{
    /**
     * Seal filename (inside the build root).
     */
    public const SEAL_FILENAME = 'prod_seal.json';

    /**
     * Test seam: force the is_sealed() result without touching real .env / mode.
     */
    protected static ?bool $_testing_sealed = null;

    /**
     * Memoized is_sealed() result.
     */
    protected static ?bool $_is_sealed_cache = null;

    // -------------------------------------------------------------------------
    // Paths
    // -------------------------------------------------------------------------

    /**
     * The build root that holds all sealed assets and the seal file.
     */
    public static function _build_root(): string
    {
        return Rsx_Project_Paths::build_root();
    }

    /**
     * Absolute path to the seal file.
     */
    public static function _seal_path(): string
    {
        return self::_build_root() . '/' . self::SEAL_FILENAME;
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /**
     * Does a seal file exist on disk?
     */
    public static function exists(): bool
    {
        return is_file(self::_seal_path());
    }

    /**
     * Read and decode the seal, or null when absent/unreadable.
     */
    public static function read(): ?array
    {
        $path = self::_seal_path();
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Is the system running a sealed prod build right now?
     *
     * True only when a seal file exists AND the current RSX_MODE is a
     * production-like mode. Memoized; reset by every mutation (write/clear) and by
     * the test seam.
     */
    public static function is_sealed(): bool
    {
        if (self::$_testing_sealed !== null) {
            return self::$_testing_sealed;
        }

        if (self::$_is_sealed_cache !== null) {
            return self::$_is_sealed_cache;
        }

        return self::$_is_sealed_cache = (self::exists() && Rsx::is_production());
    }

    // -------------------------------------------------------------------------
    // Mutation
    // -------------------------------------------------------------------------

    /**
     * Collect + record the current build assets as a new seal for the given mode.
     *
     * THE BUILD IDENTITY. commit is the application's commit (the HEAD of the repository
     * holding the project root), framework_revision the framework's (the HEAD of the
     * repository holding system/ - its own repository when system/ is a submodule, else the
     * application's, which then carries the framework). A value the caller passes is
     * recorded as given - the deploy step that knows them, on a host that has no git
     * metadata; one it omits is read from git here, and is null when git cannot answer.
     * Never guessed.
     *
     * @param string $mode The RSX mode the build was produced for (production|debug)
     * @param string|null $commit The application commit, or null to read it from git
     * @param string|null $framework_revision The framework revision, or null to read it from git
     * @return array The seal array that was written
     */
    public static function write(string $mode, ?string $commit = null, ?string $framework_revision = null): array
    {
        $build_root = self::_build_root();

        $build_key_file = $build_root . '/build_key';
        if (!is_file($build_key_file)) {
            shouldnt_happen('Cannot seal: build_key is missing - run the build pipeline first.');
        }
        $build_key = trim(file_get_contents($build_key_file));

        $seal = [
            'schema' => 2,
            'rsx_mode' => $mode,
            'build_key' => $build_key,
            'commit' => $commit ?? self::git_head(dirname(base_path())),
            'framework_revision' => $framework_revision ?? self::git_head(base_path()),
            'built_at' => Rsx_Time::now_iso(),
            'assets' => self::_collect_assets(),
        ];

        file_put_contents_safe(
            self::_seal_path(),
            json_encode($seal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        self::_reset_cache();

        return $seal;
    }

    /**
     * Remove the seal (unseal). Direct unlink: the command that unseals is leaving
     * prod mode, so it is not a build context and the build-tree guard would refuse a
     * routed write. Resets the memoized state so subsequent operations in this process
     * see an unsealed system.
     */
    public static function clear(): void
    {
        $path = self::_seal_path();
        if (is_file($path)) {
            @unlink($path);
        }

        self::_reset_cache();
    }

    // -------------------------------------------------------------------------
    // Verification
    // -------------------------------------------------------------------------

    /**
     * Recompute every sealed asset and compare against the seal.
     *
     * @return array List of human-readable drift findings; empty means the build
     *               on disk matches the seal exactly.
     */
    public static function verify(): array
    {
        $seal = self::read();
        if ($seal === null) {
            return ['No seal present (system is not in sealed prod mode).'];
        }

        $drift = [];

        // Mode the seal was built for vs the mode we are running now.
        $current_mode = Rsx::get_mode();
        $sealed_mode = $seal['rsx_mode'] ?? null;
        if ($sealed_mode !== $current_mode) {
            $drift[] = "Mode mismatch: seal built for '{$sealed_mode}', current RSX_MODE is '{$current_mode}'.";
        }

        // build_key file on disk vs the sealed build_key.
        $build_key_file = self::_build_root() . '/build_key';
        $disk_build_key = is_file($build_key_file) ? trim(file_get_contents($build_key_file)) : null;
        $sealed_build_key = $seal['build_key'] ?? null;
        if ($disk_build_key !== $sealed_build_key) {
            $drift[] = 'Build key mismatch: seal=' . ($sealed_build_key ?? 'MISSING')
                . ' disk=' . ($disk_build_key ?? 'MISSING') . '.';
        }

        // Every recorded asset must still exist with a matching sha256.
        foreach ($seal['assets'] ?? [] as $asset) {
            $rel = $asset['file'] ?? '';
            $abs = self::_build_root() . '/' . $rel;
            if (!is_file($abs)) {
                $drift[] = "Missing asset: {$rel}";
                continue;
            }
            $actual = hash_file('sha256', $abs);
            if ($actual !== ($asset['sha256'] ?? null)) {
                $drift[] = "Hash mismatch: {$rel}";
            }
        }

        return $drift;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Collect the build assets (the two manifest index files, build_key, the class override
     * map, bundles/*, each bundle's model list under bundle_models/*, and companions/*) with a sha256 each, recorded relative to the
     * build root and sorted by path.
     */
    protected static function _collect_assets(): array
    {
        $root = self::_build_root();
        $assets = [];

        // The index is TWO files: the hot one every request includes and the cold one
        // carrying the rest of the file metadata. Both are sealed.
        foreach (['manifest_index.php', 'manifest_files.php'] as $name) {
            $manifest = $root . '/' . $name;

            if (!is_file($manifest)) {
                shouldnt_happen("Cannot seal: {$name} is missing - run the build pipeline first.");
            }

            $assets[] = self::_asset_entry($root, $manifest);
        }

        $build_key = $root . '/build_key';
        if (is_file($build_key)) {
            $assets[] = self::_asset_entry($root, $build_key);
        }

        // The class override map, which every process reads before boot: it decides which
        // class a framework class name means, so it is as much the build as the index is.
        // The manifest build writes it on every pass, empty included.
        $class_overrides = Rsx_Project_Paths::class_overrides_file();
        if (!is_file($class_overrides)) {
            shouldnt_happen('Cannot seal: ' . basename($class_overrides) . ' is missing - run the build pipeline first.');
        }
        $assets[] = self::_asset_entry($root, $class_overrides);

        // The compiled bundles, the model list written beside each (a page serves its
        // field-length script from that list) and each bundle type's output record: all part
        // of the build as much as the bundle they describe. The companion descriptors and
        // clients are what rsx-companiond starts processes from, so they are sealed too.
        foreach ([Rsx_Project_Paths::bundles_dir(), Rsx_Project_Paths::bundle_models_dir(), Rsx_Project_Paths::bundle_outputs_dir(), Rsx_Project_Paths::companions_build_dir()] as $asset_dir) {
            if (!is_dir($asset_dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($asset_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $assets[] = self::_asset_entry($root, $file->getPathname());
                }
            }
        }

        usort($assets, fn ($a, $b) => strcmp($a['file'], $b['file']));

        return $assets;
    }

    /**
     * Build one asset record: build-root-relative path + sha256.
     */
    protected static function _asset_entry(string $root, string $abs): array
    {
        return [
            'file' => substr($abs, strlen($root) + 1),
            'sha256' => hash_file('sha256', $abs),
        ];
    }

    /**
     * The commit checked out in the repository holding $dir, or null when there is none to
     * read (no git, no repository, a repository git refuses to read as this user).
     *
     * @param string $dir
     * @return string|null A full lowercase hex object name
     */
    public static function git_head(string $dir): ?string
    {
        $output = [];
        $exit = 0;
        exec_safe('git -C ' . escapeshellarg($dir) . ' rev-parse --verify --quiet HEAD 2>/dev/null', $output, $exit);

        $head = strtolower(trim(implode("\n", $output)));

        return ($exit === 0 && self::is_revision($head)) ? $head : null;
    }

    /**
     * Is $value spelled as a git object name (7 to 64 hex digits, abbreviated or full)? What
     * rsx:build accepts for --commit and --framework-revision.
     */
    public static function is_revision(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{7,64}\z/', $value) === 1;
    }

    /**
     * Reset the memoized is_sealed() state.
     */
    public static function _reset_cache(): void
    {
        self::$_is_sealed_cache = null;
    }

    // -------------------------------------------------------------------------
    // Test seams
    // -------------------------------------------------------------------------

    /**
     * Force the is_sealed() result without touching real .env / mode (tests only).
     */
    public static function _testing_set_sealed(?bool $value): void
    {
        self::$_testing_sealed = $value;
    }

    /**
     * Restore all test seams to their defaults.
     */
    public static function _testing_reset(): void
    {
        self::$_testing_sealed = null;
        self::_reset_cache();
    }
}
