<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Rsx_File_Paths
 *
 * THE single choke point for every on-disk location the file subsystems touch: the
 * content-addressed blob store, the thumbnail cache, and the rendition cache.
 *
 * INVARIANT: every file-subsystem disk path resolves through this class. Composing one
 * of these locations ANYWHERE else reintroduces the B-38 test-isolation hole (a test-DB
 * attachment delete unlinking a blob shared with the developer database).
 *
 * TWO LIFETIMES, TWO TREES. The blob store is USER DATA and lives under
 * Rsx_Project_Paths::files_root() - the project's storage root in a live environment,
 * and a test-scoped directory during a test run, so a run's writes and deletes can
 * never reach the real store. Thumbnails and renditions are DERIVED from a blob that
 * is still there, so they live in tmp/ and are not part of that isolation: a run has
 * nothing to lose in a cache it can regenerate.
 */
class Rsx_File_Paths
{
    /**
     * Absolute root under which the entire file subsystem lives.
     *
     * @return string
     */
    public static function storage_root(): string
    {
        return Rsx_Project_Paths::files_root();
    }

    /**
     * Root of the content-addressed blob store (<storage>/uploads in default mode).
     *
     * @return string
     */
    public static function blob_root(): string
    {
        return static::storage_root() . '/uploads';
    }

    /**
     * Root of the thumbnail cache (preset/ and dynamic/ subdirectories live here).
     *
     * @return string
     */
    public static function thumbnails_root(): string
    {
        return Rsx_Project_Paths::thumbnails_dir();
    }

    /**
     * Root of the rendition cache (PDF renditions and spreadsheet HTML).
     *
     * @return string
     */
    public static function renditions_root(): string
    {
        return Rsx_Project_Paths::renditions_dir();
    }
}
