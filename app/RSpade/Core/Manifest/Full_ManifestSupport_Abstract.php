<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Base class for a support module that REBUILDS its section from the whole manifest.
 *
 * THE DISTINCTION IS COST, AND ONLY COST. The changed-set contract exists to avoid
 * EXPENSIVE per-file work on a rebuild: re-reading source off disk, including a class to
 * reflect on it, writing a generated stub. A module that does none of that - one whose
 * whole job is "read values already indexed in the manifest and regroup them into a new
 * data set" - is a loop over a few thousand in-memory records. That costs nothing on the
 * only occasion it runs, which is a code change, and it never runs in a served request.
 *
 * SO THESE MODULES DO NOT DIFF, AND THAT IS THE POINT. A diffing module is only ever as
 * correct as the section it carries forward: if that section is lost or truncated by any
 * means, nothing restores it, because restoration only happens for files that CHANGE and
 * an unchanged tree has none. That is not hypothetical - the standard route table was
 * observed empty with a fully populated file index, and every subsequent build left it
 * empty because no file was dirty. Every page 404'd and every bundle failed to compile,
 * and the only cure was `rsx:manifest:build --force`, which works purely by making
 * everything dirty at once. A section derived in full from the file map cannot enter that
 * state: it is a pure function of the manifest, recomputed every build.
 *
 * The delta machinery also disappears from the module itself - no dirty set, no
 * drop-then-re-add pass, no reasoning about which file owns which row. The code that
 * remains is the derivation, which is what the module was always for.
 *
 * WHICH KIND A MODULE IS: if `process()` would read source from disk, `include_once` a
 * file, reflect on a class, or write a generated file, it is a DELTA module and extends
 * ManifestSupport_Abstract. If it only reads `$manifest_data` and regroups it, it is a
 * FULL module and extends this.
 *
 * ORDER IS UNCHANGED. Both kinds live in the one ordered `config('rsx.manifest_support')`
 * list and run in that order, because the order is load-bearing - several modules read a
 * section an earlier one produced (the API endpoint module reads `routes`). This class is
 * a different CALLING CONVENTION, not a separate phase.
 *
 * See: Core/Manifest/CLAUDE.md, rsx:man manifest_build.
 */
abstract class Full_ManifestSupport_Abstract extends ManifestSupport_Abstract
{
    /**
     * The changed-set entry point, satisfied once here so no full module ever sees a delta.
     *
     * FINAL on purpose: a full module that started consulting the changed set would be a
     * diffing module wearing this base class, which is exactly the ambiguity the two bases
     * exist to remove.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @param array $changed_files  Ignored - this module rebuilds from the whole manifest
     * @param array $removed_files  Ignored - this module rebuilds from the whole manifest
     * @return void
     */
    final public static function process(array &$manifest_data, array $changed_files, array $removed_files): void
    {
        static::rebuild($manifest_data);
    }

    /**
     * Rebuild this module's section from the manifest, in full.
     *
     * The implementation OWNS its section: it assigns the section outright rather than
     * merging into whatever was there, so the result depends on nothing but the manifest
     * it was handed.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    abstract public static function rebuild(array &$manifest_data): void;
}
