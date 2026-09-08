<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Model_Lineage_Fingerprint - what a model's generated metadata is actually a function of.
 *
 * A core model is a BASE plus a CONCRETE: `abstract class X_Model_Abstract` carries the
 * table, the enums, the constants, the relationships and the detail tables, and
 * `class X_Model extends X_Model_Abstract {}` is the three-line shell an application
 * replaces. Everything the build derives from a model - the JS stub, the column map -
 * therefore comes from the WHOLE LINEAGE, and a key computed from the concrete file alone
 * cannot move when the base moves.
 *
 * That is not a slow rebuild, it is a WRONG one: the generator's own gate says "unchanged",
 * the stub keeps the enum constant that was renamed, and nothing anywhere reports it. So
 * every staleness key over a model is computed here, over the concrete file and every
 * ancestor file up to (and excluding) Rsx_Model_Abstract - which is framework base machinery
 * that no generated artifact reads member-by-member, and whose every change invalidates the
 * build key regardless.
 *
 * MANIFEST RECORDS ONLY. `php_class_metadata()` gives each link's `extends` and file, and
 * the file record carries the content hash the scanner already computed. Nothing here opens
 * a file.
 */
class Model_Lineage_Fingerprint
{
    /**
     * The relative paths of a model's own file and each ancestor's, nearest first.
     *
     * The walk stops at Rsx_Model_Abstract, at a class the index does not know, and on a
     * cycle. A class the index cannot resolve simply contributes nothing - it can only make
     * the key coarser, never wrong in the dangerous direction.
     *
     * @param string $class_name    Simple name of the concrete model.
     * @param array  $manifest_data The build's manifest data.
     * @return array<int, string>
     */
    public static function lineage_files(string $class_name, array $manifest_data): array
    {
        $files = [];
        $seen = [];
        $current = $class_name;

        while ($current !== null && $current !== '' && !isset($seen[$current])) {
            $seen[$current] = true;

            if ($current === 'Rsx_Model_Abstract') {
                break;
            }

            $record = $manifest_data['data']['php_classes'][$current] ?? Manifest::php_class_metadata($current);

            if ($record === null) {
                break;
            }

            $file = $record['file'] ?? null;

            if ($file !== null && $file !== '') {
                $files[] = $file;
            }

            $current = $record['extends'] ?? null;
        }

        return $files;
    }

    /**
     * One hash over every file in the lineage, in lineage order.
     *
     * A file the index carries no hash for contributes its path and an empty hash, which
     * still changes the key when the lineage itself changes shape.
     *
     * @param string $class_name    Simple name of the concrete model.
     * @param array  $manifest_data The build's manifest data.
     */
    public static function lineage_hash(string $class_name, array $manifest_data): string
    {
        $parts = [];

        foreach (static::lineage_files($class_name, $manifest_data) as $file) {
            $parts[] = $file . '=' . ($manifest_data['data']['files'][$file]['hash'] ?? '');
        }

        return md5(implode('|', $parts));
    }

    /**
     * The newest mtime in the lineage - the cheapest of the stub generator's gates.
     *
     * @param string $class_name    Simple name of the concrete model.
     * @param array  $manifest_data The build's manifest data.
     */
    public static function lineage_mtime(string $class_name, array $manifest_data): int
    {
        $mtime = 0;

        foreach (static::lineage_files($class_name, $manifest_data) as $file) {
            $candidate = (int) ($manifest_data['data']['files'][$file]['mtime'] ?? 0);

            if ($candidate > $mtime) {
                $mtime = $candidate;
            }
        }

        return $mtime;
    }
}
