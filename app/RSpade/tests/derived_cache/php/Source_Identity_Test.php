<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedCache\Php;

use App\RSpade\Core\Cache\File_Content_Cache;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ONE FILE HAS ONE DERIVED-CACHE IDENTITY, whichever spelling reached it.
 *
 * `base_path()` is `<project>/system`, and that directory carries symlinks to the volatile
 * trees and to the application tree - `system/rsx` and `system/build`. So every file the
 * build derives from or writes has TWO absolute spellings, and
 * which one a caller holds is an accident of how it got there: the bundle compiler resolves
 * includes against `base_path()`, the manifest's own path vocabulary answers with the
 * project mount.
 *
 * When the development build hash folded the ABSOLUTE path in, those two spellings were two
 * identities for one file. Every derived artifact is keyed by that hash and the Phase 7
 * sweep's live set is built from ONE spelling, so an entry written under the other matched
 * nothing live and the next sweep deleted it - a live entry, removed. With a concurrent
 * build in the tree (a web request rebuilding the manifest while the CLI compiles - the
 * container's own health probe is enough to cause one) the deletion lands between the
 * moment the compiler is handed the entry's path and the moment the concatenator opens it,
 * and the compile fails with "Input file not found: tmp/derived/babel/<hash>_modern_*.js".
 *
 * The property is therefore not a nicety: two spellings of one file must produce one key,
 * one cache entry, and one live-set membership.
 */
class Source_Identity_Test extends Rsx_Test_Abstract
{
    // Pure filesystem work - no database access.
    protected static $use_database_transactions = false;

    /**
     * The two absolute spellings of one scratch file: through the project mount, and
     * through the `system/build` symlink that `base_path()` sits above.
     *
     * @return array{0:string,1:string}
     */
    private static function __both_spellings(string $name): array
    {
        $through_project = Rsx_Project_Paths::build_path($name);
        $through_base_path = base_path('build') . '/' . $name;

        return [$through_project, $through_base_path];
    }

    /**
     * The build identity of one file is the same under both spellings.
     */
    public static function test_build_hash_is_spelling_independent()
    {
        $name = 'derived_cache_identity_' . uniqid() . '.js';
        [$through_project, $through_base_path] = self::__both_spellings($name);

        file_put_contents($through_project, "const identity = 1;\n");

        try {
            static::__assert_true(
                is_file($through_base_path),
                'the same file is reachable through base_path() - the system/build symlink'
            );

            static::__assert_not_equals(
                $through_project,
                $through_base_path,
                'the two spellings are genuinely different strings'
            );

            static::__assert_equals(
                _rsx_file_hash_for_build($through_project),
                _rsx_file_hash_for_build($through_base_path),
                'one file has one build identity, whichever spelling reached it'
            );
        } finally {
            @unlink($through_project);
        }
    }

    /**
     * A derived entry written under one spelling is READ, and SWEPT AS LIVE, under the
     * other - which is what the compile and the sweep respectively do.
     */
    public static function test_entry_is_shared_and_kept_alive_across_spellings()
    {
        $namespace = 'test-identity-' . uniqid();
        $name = 'derived_cache_identity_' . uniqid() . '.js';
        [$through_project, $through_base_path] = self::__both_spellings($name);

        file_put_contents($through_project, "const shared = 2;\n");

        try {
            $written = File_Content_Cache::put(
                $namespace,
                $through_base_path,
                '_modern_fingerprint',
                'js',
                'TRANSFORMED'
            );

            static::__assert_equals(
                'TRANSFORMED',
                File_Content_Cache::get($namespace, $through_project, '_modern_fingerprint', 'js'),
                'an entry written under one spelling is read back under the other'
            );

            // The live set is built the way the manifest build builds it: from the project
            // mount. The entry was written by a caller holding the base_path() spelling.
            $live = [File_Content_Cache::hash_of($through_project)];

            static::__assert_equals(
                0,
                File_Content_Cache::sweep($namespace, $live),
                'the sweep keeps an entry whose source is live under the other spelling'
            );

            static::__assert_true(
                is_file($written),
                'the entry the compile was handed is still on disk after a sweep'
            );
        } finally {
            File_Content_Cache::clear($namespace);
            @rmdir(File_Content_Cache::namespace_dir($namespace));
            @unlink($through_project);
        }
    }
}
