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

/**
 * Sealed production build manifest ("the seal").
 *
 * A prod build is compiled ONCE by rsx:build and then treated as IMMUTABLE. The seal
 * is the on-disk record of that build: it pins the build_key, the mode it was built
 * for, and a sha256 of every build artifact (manifest_index.php, manifest_files.php,
 * build_key, and every file under bundles/). It exists so the framework can:
 *
 *   - recognize that a production-like mode has a build to serve at all (is_sealed(),
 *     which the manifest gate reads: an unsealed prod box refuses to run);
 *   - verify on demand that the assets on disk still match what was sealed
 *     (verify() - the cluster/CI drift check).
 *
 * The seal is a STATE RECORD, not a permission: what may write the build tree is
 * Rsx_Project_Paths::assert_build_writable(), which keys on the mode and the build
 * context, so an unsealed production box is guarded exactly like a sealed one.
 *
 * The seal file lives inside the build root, and asset paths are stored relative to
 * it, so the seal is self-contained and location-independent.
 *
 * NOTE: created_at, git_commit and the seal file as a whole are deliberately NOT
 * part of any hashed build artifact - they carry the non-deterministic build
 * metadata that the manifest index intentionally drops for byte-stability.
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
     * @param string $mode The RSX mode the build was produced for (production|debug)
     * @return array The seal array that was written
     */
    public static function write(string $mode): array
    {
        $build_root = self::_build_root();

        $build_key_file = $build_root . '/build_key';
        if (!is_file($build_key_file)) {
            shouldnt_happen('Cannot seal: build_key is missing - run the build pipeline first.');
        }
        $build_key = trim(file_get_contents($build_key_file));

        $seal = [
            'schema' => 1,
            'rsx_mode' => $mode,
            'build_key' => $build_key,
            'git_commit' => self::_git_commit(),
            'created_at' => date('c'),
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
     * Collect the build assets (the two manifest index files, build_key, bundles/*) with a
     * sha256 each, recorded relative to the build root and sorted by path.
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

        $bundles_dir = $root . '/bundles';
        if (is_dir($bundles_dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($bundles_dir, RecursiveDirectoryIterator::SKIP_DOTS),
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
     * Best-effort current git commit; null when git is unavailable / not a repo.
     */
    protected static function _git_commit(): ?string
    {
        $cwd = base_path();
        $output = @shell_exec('bash -c ' . escapeshellarg('cd ' . escapeshellarg($cwd) . ' && git rev-parse HEAD 2>/dev/null'));
        if (!is_string($output)) {
            return null;
        }
        $commit = trim($output);

        return $commit === '' ? null : $commit;
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
