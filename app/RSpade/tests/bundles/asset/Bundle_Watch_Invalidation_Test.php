<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Asset;

use RuntimeException;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * A bundle's `watch` declaration must be able to invalidate that bundle's own compiled output.
 *
 * The fixture is SYNTHETIC on purpose. A real project's layout can make a path-substring
 * bucket heuristic accidentally correct - if the watched file happens to live under a
 * `/vendor/` directory the wrong mechanism still produces the right answer - so these tests
 * build the arrangement the mechanism actually has to survive:
 *
 *   - a VENDOR-bucket asset bundle (its own include sits under a `/vendor/` directory),
 *   - whose SCSS entry imports two files that live OUTSIDE any `/vendor/` path,
 *   - both declared as `watch` targets of that same vendor bundle,
 *   - one of which is ALSO a direct `include` of a second, APP-bucket asset bundle,
 *   - plus a watched DIRECTORY, to prove the directory branch did not regress.
 *
 * Everything lives in a scratch directory under this concern's `resource/` directory, which
 * is framework-ignored, so the manifest never indexes the fixture classes or SCSS. The live
 * `rsx/` tree is never touched and the scratch directory is removed in teardown().
 */
class Bundle_Watch_Invalidation_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * base_path()-relative root of the fixture (bundle definitions take base_path()-relative
     * paths, exactly as an authored bundle does).
     */
    protected const FIXTURE_RELATIVE = 'app/RSpade/tests/bundles/asset/resource/watch_fixture-temp';

    protected const FIXTURE_NAMESPACE = 'Rsx_Watch_Fixture_Temp';

    /**
     * Original fixture file contents, keyed by fixture-relative path.
     */
    protected static function __fixture_files(): array
    {
        return [
            // Vendor-bucket entry: its path contains '/vendor/', so the compiler places the
            // compiled CSS in the vendor bucket. Vendor SCSS may use @import.
            'vendor/entry-temp.scss' => "@import \"../variables-temp\";\n"
                . "@import \"../shared-temp\";\n\n"
                . ".rsx_watch_fixture_vendor {\n"
                . "    outline-width: \$rsx_watch_fixture_marker;\n"
                . "    outline-offset: \$rsx_watch_fixture_shared;\n"
                . "}\n",

            // Watched only. Lives outside any '/vendor/' path - the whole point of the fixture.
            'variables-temp.scss' => "\$rsx_watch_fixture_marker: 11px;\n",

            // Watched by the vendor bundle AND directly included by the app bundle.
            'shared-temp.scss' => "\$rsx_watch_fixture_shared: 33px;\n",

            // App-bucket content, so the app CSS artifact exists and can be compared.
            'app_member-temp.scss' => ".rsx_watch_fixture_app {\n    display: block;\n}\n",

            // Member of the watched DIRECTORY. Not compiled - only hashed.
            'watched_dir-temp/member-temp.txt' => "original\n",
        ];
    }

    /**
     * Absolute path of a fixture-relative file.
     */
    protected static function __fixture_path(string $relative): string
    {
        return base_path(static::FIXTURE_RELATIVE . '/' . $relative);
    }

    /**
     * Write the fixture back to its original state (idempotent - every test starts here).
     */
    protected static function __write_fixture(): void
    {
        foreach (static::__fixture_files() as $relative => $contents) {
            $path = static::__fixture_path($relative);
            ensure_directory(dirname($path));
            file_put_contents($path, $contents);
        }

        static::__write_fixture_bundles();
    }

    /**
     * Write and load the fixture bundle definitions.
     *
     * These are ordinary bundle classes, but they are created at test time inside a
     * framework-ignored `resource/` directory so they are never indexed by the manifest and
     * never picked up by a real build. BundleCompiler resolves them by class_exists().
     */
    protected static function __write_fixture_bundles(): void
    {
        $base = static::FIXTURE_RELATIVE;
        $namespace = static::FIXTURE_NAMESPACE;

        $php = <<<PHP
        <?php

        namespace {$namespace};

        use App\\RSpade\\Core\\Bundle\\Rsx_Asset_Bundle_Abstract;
        use App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;

        class Watch_Fixture_Vendor_Bundle extends Rsx_Asset_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        '{$base}/vendor/entry-temp.scss',
                    ],
                    'watch' => [
                        '{$base}/variables-temp.scss',
                        '{$base}/shared-temp.scss',
                        '{$base}/watched_dir-temp',
                    ],
                ];
            }
        }

        class Watch_Fixture_App_Bundle extends Rsx_Asset_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        '{$base}/app_member-temp.scss',
                        '{$base}/shared-temp.scss',
                    ],
                ];
            }
        }

        class Watch_Fixture_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        // The APP bundle resolves FIRST on purpose: it claims shared-temp.scss
                        // into included_files before the vendor bundle declares it as a watch
                        // target, which is the exact ordering a bucket-blind de-dup drops.
                        \\{$namespace}\\Watch_Fixture_App_Bundle::class,
                        \\{$namespace}\\Watch_Fixture_Vendor_Bundle::class,
                    ],
                ];
            }
        }

        class Watch_Fixture_Missing_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        '{$base}/app_member-temp.scss',
                    ],
                    'watch' => [
                        '{$base}/does_not_exist-temp.scss',
                    ],
                ];
            }
        }
        PHP;

        $path = static::__fixture_path('bundles-temp.php');
        ensure_directory(dirname($path));
        file_put_contents($path, $php . "\n");

        // require_once is idempotent per resolved path, which is exactly the guard needed here:
        // every test re-writes these definitions byte-identically to the same path, so the
        // first test in the class loads them and the rest are no-ops. No class_exists() probe.
        require_once $path;
    }

    /**
     * Compile the fixture module bundle and return its result array.
     */
    protected static function __compile(string $bundle_class): array
    {
        $compiler = new BundleCompiler();

        return $compiler->compile($bundle_class, []);
    }

    /**
     * Compile the standard fixture bundle and return [vendor_css_filename, app_css_filename].
     */
    protected static function __compile_fixture(): array
    {
        $result = static::__compile('\\' . static::FIXTURE_NAMESPACE . '\\Watch_Fixture_Bundle');

        return [
            $result['vendor_css_bundle_path'] ?? null,
            $result['app_css_bundle_path'] ?? null,
        ];
    }

    /**
     * Contents of a compiled bundle artifact.
     */
    protected static function __artifact_contents(string $filename): string
    {
        return file_get_contents(storage_path('rsx-build/bundles/' . $filename));
    }

    /**
     * Remove the fixture scratch directory and every artifact the fixture bundle produced.
     */
    protected static function __remove_fixture(): void
    {
        $root = base_path(static::FIXTURE_RELATIVE);

        if (is_dir($root)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($root);
        }

        // Every artifact the fixture can emit, under every naming scheme the compiler uses:
        // '<Bundle>__<hash>.<ext>' for the compiled buckets and 'npm_<Bundle>_<hash>.js' for
        // the npm shim. A glob keyed only to the first scheme leaves the npm files behind.
        $patterns = [
            'rsx-build/bundles/Watch_Fixture_*',
            'rsx-build/bundles/npm_Watch_Fixture_*',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob(storage_path($pattern)) as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }
        }
    }

    public static function setup()
    {
        parent::setup();

        static::__remove_fixture();
        static::__write_fixture();
    }

    public static function teardown()
    {
        parent::teardown();

        static::__remove_fixture();
    }

    /**
     * Edit a fixture file the way a developer edits a source file, and make sure the build
     * can SEE the edit.
     *
     * In development the build's per-file hash is metadata only - path + size + mtime
     * (_rsx_file_hash_fast) - and filemtime() has one-second granularity. These fixtures
     * deliberately swap one same-length value for another (`33px` -> `99px`), so when two
     * compiles land inside the same clock second the metadata is byte-for-byte identical
     * and the cache reports a hit for a file that really did change. That is a property of
     * the DEV hash, not of the watch mechanism these tests exist to prove, and it became
     * reachable when concatenation stopped spawning a process per bundle and the whole
     * compile got fast enough to finish twice in one second.
     *
     * Advancing the mtime past the current second removes the clock from the experiment:
     * these tests then assert that a watch target's change invalidates its bucket, which is
     * what they are for.
     */
    protected static function __edit_fixture(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        touch($path, time() + 1);
        clearstatcache(true, $path);
    }

    /**
     * (a) + (b): a watch entry that resolves to a FILE outside any '/vendor/' path invalidates
     * the vendor bucket that declared it, and the invalidation round-trips.
     */
    public static function test_file_watch_target_invalidates_vendor_output_and_round_trips()
    {
        static::__write_fixture();

        [$vendor_before, ] = static::__compile_fixture();

        static::__assert_not_null($vendor_before, 'the fixture produces a vendor CSS artifact');

        // In development a build hash is metadata-based (path + size + mtime), so "the same
        // inputs" for the round trip below means the same mtime too - restoring only the bytes
        // would change the key for a reason that has nothing to do with the watch mechanism.
        $watched = static::__fixture_path('variables-temp.scss');
        $original_mtime = filemtime($watched);

        static::__assert_contains(
            '11px',
            static::__artifact_contents($vendor_before),
            'the watched variables file feeds the compiled vendor CSS'
        );

        // Edit ONLY the watched, outside-vendor file.
        static::__edit_fixture(
            static::__fixture_path('variables-temp.scss'),
            "\$rsx_watch_fixture_marker: 77px;\n"
        );

        [$vendor_after, ] = static::__compile_fixture();

        static::__assert_not_equals(
            $vendor_before,
            $vendor_after,
            'editing a watched FILE changes the vendor bundle content hash (and therefore its filename)'
        );
        static::__assert_contains(
            '77px',
            static::__artifact_contents($vendor_after),
            'the edit reached the compiled vendor artifact'
        );

        // Round trip: a cache that invalidates in only one direction is still broken.
        static::__edit_fixture($watched, "\$rsx_watch_fixture_marker: 11px;\n");
        touch($watched, $original_mtime);

        [$vendor_reverted, ] = static::__compile_fixture();

        static::__assert_equals(
            $vendor_before,
            $vendor_reverted,
            'reverting the watched file restores the original vendor artifact filename'
        );
        static::__assert_contains(
            '11px',
            static::__artifact_contents($vendor_reverted),
            'reverting the watched file restores the original compiled vendor CSS'
        );
    }

    /**
     * (c): a watch entry that resolves to a DIRECTORY still invalidates.
     */
    public static function test_directory_watch_target_still_invalidates()
    {
        static::__write_fixture();

        [$vendor_before, ] = static::__compile_fixture();

        static::__edit_fixture(
            static::__fixture_path('watched_dir-temp/member-temp.txt'),
            "edited\n"
        );

        [$vendor_after, ] = static::__compile_fixture();

        static::__assert_not_equals(
            $vendor_before,
            $vendor_after,
            'editing a file inside a watched DIRECTORY changes the vendor bundle content hash'
        );
    }

    /**
     * (d): one path that is a watch target of the vendor bundle AND a direct include of the
     * app bundle must invalidate BOTH buckets from a single edit.
     */
    public static function test_dual_membership_invalidates_both_buckets()
    {
        static::__write_fixture();

        [$vendor_before, $app_before] = static::__compile_fixture();

        static::__assert_not_null($app_before, 'the fixture produces an app CSS artifact');

        static::__edit_fixture(
            static::__fixture_path('shared-temp.scss'),
            "\$rsx_watch_fixture_shared: 99px;\n"
        );

        [$vendor_after, $app_after] = static::__compile_fixture();

        static::__assert_not_equals(
            $vendor_before,
            $vendor_after,
            'the shared file is a WATCH target of the vendor bundle, so the vendor artifact is invalidated'
        );
        static::__assert_not_equals(
            $app_before,
            $app_after,
            'the same file is a direct INCLUDE of the app bundle, so the app artifact is invalidated too'
        );
        static::__assert_contains(
            '99px',
            static::__artifact_contents($vendor_after),
            'the edit reached the compiled vendor artifact'
        );
    }

    /**
     * (e): a watch target that does not exist fails the build, naming the declaring bundle
     * class and the offending path.
     */
    public static function test_nonexistent_watch_target_fails_the_build()
    {
        static::__write_fixture();

        $exception = static::__assert_throws(
            RuntimeException::class,
            function () {
                static::__compile('\\' . static::FIXTURE_NAMESPACE . '\\Watch_Fixture_Missing_Bundle');
            }
        );

        static::__assert_contains(
            'Watch_Fixture_Missing_Bundle',
            $exception->getMessage(),
            'the failure names the bundle class that declared the target'
        );
        static::__assert_contains(
            'does_not_exist-temp.scss',
            $exception->getMessage(),
            'the failure names the offending path'
        );
    }
}
