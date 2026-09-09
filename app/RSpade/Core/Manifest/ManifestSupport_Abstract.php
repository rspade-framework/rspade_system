<?php

namespace App\RSpade\Core\Manifest;

/**
 * Abstract base class for manifest support modules
 *
 * A support module owns ONE derived section of the index and keeps it CORRECT AND CURRENT
 * across an incremental rebuild. It runs after every file record in the tree has been
 * parsed, in the order `config('rsx.manifest_support')` lists.
 *
 * THE CHANGED-SET CONTRACT
 * ------------------------
 * `process()` receives the two sets that describe what this build actually did:
 *
 *   $changed_files  every path whose record was RE-PARSED this build (accumulated across
 *                   build restarts, so it is a superset, never a subset)
 *   $removed_files  every path that left the tree this build
 *
 * A module's section is CARRIED FORWARD from the previous build (see
 * `Manifest::MODULE_OWNED_SECTIONS`), so the module's job is a DIFF, not a rebuild:
 *
 *   1. drop every entry derived from a changed or removed file;
 *   2. re-derive the entries the changed files declare now.
 *
 * On a cold build both sets name the whole tree, so the diff IS the full build and no
 * module needs a second code path.
 *
 * THIS BASE IS FOR EXPENSIVE WORK ONLY. The changed set exists to avoid re-reading source
 * off disk, including a class to reflect on it, or writing a generated stub - real per-file
 * cost. A module whose whole job is to read values ALREADY INDEXED in the manifest and
 * regroup them is not that: it is a loop over a few thousand in-memory records, on the only
 * occasion it runs, which is a code change. Such a module extends
 * Full_ManifestSupport_Abstract instead, rebuilds its section outright, and carries none of
 * the machinery below.
 *
 * That split is a CORRECTNESS property, not a tidiness one. A diffing section is only ever
 * as good as what it carries forward; lose it and nothing restores it, because restoration
 * only happens for files that CHANGE. A section derived in full cannot enter that state.
 * See Full_ManifestSupport_Abstract for the incident that settled this.
 *
 * STUB GENERATORS ARE ORDINARY MODULES. The generated JS stubs (controller, model, auth
 * mirror) are the last three entries in the same list; there is no second module system.
 *
 * See: Core/Manifest/CLAUDE.md, rsx:man manifest_build, rsx:man config_rsx.
 */
abstract class ManifestSupport_Abstract
{
    /**
     * Process the manifest and add supplementary metadata
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @param array $changed_files  Paths whose record was re-parsed in THIS build
     * @param array $removed_files  Paths that left the tree in THIS build
     * @return void
     */
    abstract public static function process(array &$manifest_data, array $changed_files, array $removed_files): void;

    /**
     * Get the name of this support module for logging/debugging
     *
     * @return string
     */
    abstract public static function get_name(): string;

    /**
     * Check if this support module should run
     *
     * Allows support modules to skip processing based on environment
     * or configuration. Default implementation always returns true.
     *
     * @return bool
     */
    public static function should_run(): bool
    {
        return true;
    }

    /**
     * The set a module diffs against: every path re-parsed OR removed this build.
     *
     * Returned as a HASH SET (path => true) because every caller asks "is this path in it"
     * per row, and `in_array()` over a cold build's thousands of paths is the O(N*M) shape
     * this contract exists to remove.
     *
     * @return array<string,bool>
     */
    final protected static function dirty_set(array $changed_files, array $removed_files): array
    {
        $dirty = [];

        foreach ($changed_files as $path) {
            $dirty[$path] = true;
        }

        foreach ($removed_files as $path) {
            $dirty[$path] = true;
        }

        return $dirty;
    }
}
