<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\CodeQuality\Support\Source_Cache;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Scanner;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ONE BUILD, AS AN OBJECT: the roots it scans, the storage root it writes to, the mode it
 * builds for, and the source cache it reads every file through.
 *
 * `Manifest` is the static facade the whole framework calls; the BUILD underneath it is this
 * instance. The point is testability: until this existed, "build a manifest" meant "build THE
 * manifest, from config, into the developer's own storage directory", so there was no way for
 * a test to build a small fixture tree and assert on the index that came out - and therefore
 * no way to hold the build to a MEMORY BUDGET, which is what this epic is about.
 *
 * DELIBERATELY MINIMAL. The phase code lives in `Manifest_Scanner`, `Manifest_Indexer` and
 * `Manifest_Store`, and still reaches `Manifest::$data` directly; what moved here is only the two things that were
 * hardcoded inside the build and that a test has to be able to move:
 *
 *   - `scan_directories()` - where source is read from (config, plus the test trees when the
 *     process is a test run);
 *   - `cache_file_path()` / `storage_root()` - where the index is written.
 *
 * Every scan root is expressed RELATIVE to `base_path()`, because that is the manifest's own
 * path vocabulary: an index key is a relative path and `base_path($key)` is the file. A root
 * outside `base_path()` therefore cannot be indexed at all, and a fixture tree lives under
 * `app/RSpade/temp/` for that reason.
 *
 * See: rsx:man manifest_build, Core/Manifest/CLAUDE.md.
 */
#[Instantiatable]
class Manifest_Build
{
    /**
     * How many files the BUILD's source cache holds at once.
     *
     * Not the class default (64), and MEASURED rather than chosen: a cold build of this
     * reference tree peaks at 124.8 MB with 64, 110.1 MB with 32 and 105.9 MB with 16, and
     * the wall time does not get worse as it shrinks (12.0 s / 11.4 s / 11.2 s) because the
     * allocation the eviction avoids costs more than the re-parse it causes. Below 16 it
     * turns: 8 thrashes to 15.4 s for 103.8 MB. So 16 is where the curve bottoms out, and it
     * leaves the 128 MB budget 21% of headroom instead of 7%.
     *
     * rsx:check is a different pass with a different working set and keeps the class default.
     */
    public const BUILD_SOURCE_CACHE_CAPACITY = 16;

    /** Scan roots, relative to base_path(). */
    private array $scan_directories;

    /** Absolute path of the storage root - the directory holding rsx-build/ and rsx-tmp/. */
    private string $storage_root;

    /** The application mode this build is for ('development', 'debug', 'production'). */
    private string $mode;

    /** The build's ONE reader, tokenizer and parser, shared by the fixer and the rule driver. */
    private ?Source_Cache $source_cache = null;

    /**
     * @param array<int,string> $scan_directories Relative to base_path()
     */
    public function __construct(array $scan_directories, string $storage_root, string $mode)
    {
        $this->scan_directories = array_values($scan_directories);
        $this->storage_root = rtrim($storage_root, '/');
        $this->mode = $mode;
    }

    /**
     * The build a served request or an ordinary command performs.
     *
     * config('rsx.manifest.scan_directories') is the whole answer for a served site. A TEST
     * RUN adds the three test trees on top: a fixture is real indexed source - a route, an
     * Ajax surface, an #[Auth] naming a check - and a served site must not carry one. The
     * outage that set this rule was a fixture whose #[Auth] named a check only the reference
     * application declares, which failed the manifest build of every install that scanned it.
     *
     * suite_is_running() reads the --_test-run internal flag that rsx:test declares on itself
     * and Rsx_Artisan forwards to every child, so a docker worker, a --sequential run and a
     * command a test spawns all see the fixtures. The web entrypoint carries no argv, so a
     * served request never does - and the manifest's ordinary add/remove handles the
     * transition in both directions, on the first request after a run.
     */
    public static function from_config(): self
    {
        $scan_paths = config('rsx.manifest.scan_directories', ['rsx']);

        if (Rsx_Test_Abstract::suite_is_running()) {
            foreach (Manifest_Scanner::TEST_SCAN_DIRECTORIES as $test_path) {
                if (!in_array($test_path, $scan_paths, true)) {
                    $scan_paths[] = $test_path;
                }
            }
        }

        // Two framework-INTERNAL flags (the `--_` convention: no InputOption, stripped from
        // argv pre-boot, invisible to `php artisan list`) let a test drive a build of its own
        // in a CHILD process - which is the only honest way to measure a COLD build's peak
        // memory, since the parent already holds a manifest.
        // --_manifest-scan-roots states the EXACT list, test-tree additions included; it is
        // how the memory gate builds the PLAIN production tree from inside a test run, whose
        // children otherwise inherit --_test-run and index 622 fixtures the gate is not
        // about. The caller owns the list being buildable.
        $exact = Rsx_Internal_Flags::get('--_manifest-scan-roots');

        if ($exact !== null && $exact !== '') {
            $scan_paths = array_values(array_filter(array_map('trim', explode(',', $exact))));
        }

        // The extra roots are ADDITIVE, never a replacement. A build that indexed only a
        // fixture tree cannot complete: the framework's own support modules, models and
        // parent classes are resolved THROUGH the index, so an index without them fails at
        // "Manifest support module must extend ManifestSupport_Abstract".
        $extra = Rsx_Internal_Flags::get('--_manifest-extra-scan-roots');
        $storage = Rsx_Internal_Flags::get('--_manifest-storage-root');

        if ($extra !== null && $extra !== '') {
            foreach (array_filter(array_map('trim', explode(',', $extra))) as $path) {
                if (!in_array($path, $scan_paths, true)) {
                    $scan_paths[] = $path;
                }
            }
        }

        return new self(
            $scan_paths,
            $storage !== null && $storage !== '' ? $storage : storage_path(),
            Rsx::get_mode()
        );
    }

    /**
     * @return array<int,string>
     */
    public function scan_directories(): array
    {
        return $this->scan_directories;
    }

    /**
     * Absolute paths of the scan roots.
     *
     * @return array<int,string>
     */
    public function scan_roots(): array
    {
        return array_map(fn ($path) => base_path($path), $this->scan_directories);
    }

    public function storage_root(): string
    {
        return $this->storage_root;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Where this build's index file lives.
     */
    public function cache_file_path(): string
    {
        return $this->storage_root . '/' . Manifest::CACHE_FILE;
    }

    /**
     * The build's ONE source cache - the fixer and the code-quality driver share it, so a
     * file read for one is not read again for the other.
     */
    public function source_cache(): Source_Cache
    {
        if ($this->source_cache === null) {
            $this->source_cache = new Source_Cache(self::BUILD_SOURCE_CACHE_CAPACITY);
        }

        return $this->source_cache;
    }

    /**
     * Empty the source cache. Called at the end of a build phase that read files.
     */
    public function release_source_cache(): void
    {
        if ($this->source_cache !== null) {
            $this->source_cache->release();
        }
    }
}
