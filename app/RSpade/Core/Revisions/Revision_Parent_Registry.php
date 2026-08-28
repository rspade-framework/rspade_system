<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Revision_Parent_Registry
 *
 * Per-process memoized metadata for the #[Revision_Parent] attribute - a bare marker on a
 * child model's belongsTo method (e.g. Contact_Model::client()). A revision recorded on
 * that child is filed under the PARENT's root pair, so asking the client for its history
 * returns the writes that landed on its contacts too.
 *
 * The attribute carries NO arguments. The FK column and parent class are resolved ONCE per
 * class per process by reflecting the annotated relationship - a blank instance, the
 * belongsTo introspected for its foreign key and related class. This is the
 * Realtime_Touch_Registry shape, deliberately: the two attributes answer the same kind of
 * question (which parent does a write to this child concern), and the second one should
 * not invent a second way of asking it.
 *
 * ONE parent, not a cascade. A revision has exactly one root, so the FIRST declared
 * #[Revision_Parent] with a non-null foreign key wins; the root is not resolved
 * transitively up a chain. That is a storage decision, not an oversight: a transitive root
 * would have to be recomputed for every existing revision the day a middle link moved.
 *
 * The declaration is validated at MANIFEST BUILD by REVISION-01, which is why nothing here
 * re-checks that the parent opted in - by the time this runs, it has.
 */
class Revision_Parent_Registry
{
    /**
     * Resolved parent metadata per child FQCN.
     *
     * @var array<string, array<int, array{method: string, fk_column: string, parent_class: string, parent_fqcn: string}>>
     */
    private static array $parent_metadata = [];

    /**
     * Memoized "does this class opt into revision recording ($revisions = true)".
     *
     * @var array<string, bool>
     */
    private static array $records_revisions = [];

    /**
     * The #[Revision_Parent] entries for a model, resolved once per class. Empty for the
     * overwhelming majority of models, which is the answer the recorder wants fastest.
     *
     * @return array<int, array{method: string, fk_column: string, parent_class: string, parent_fqcn: string}>
     */
    public static function parent_metadata(string $fqcn): array
    {
        if (isset(self::$parent_metadata[$fqcn])) {
            return self::$parent_metadata[$fqcn];
        }

        $entries = [];

        foreach (self::_attributed_parent_methods($fqcn) as $method) {
            $relation = (new $fqcn())->$method();

            if (!$relation instanceof BelongsTo) {
                shouldnt_happen(
                    '#[Revision_Parent] on ' . $fqcn . '::' . $method . '() requires a belongsTo relationship '
                    . '(the attribute is belongsTo-only: a revision has exactly one root, and only a belongsTo '
                    . 'names exactly one parent row). Got: ' . get_debug_type($relation)
                );
            }

            $parent_fqcn = get_class($relation->getRelated());

            $entries[] = [
                'method' => $method,
                'fk_column' => $relation->getForeignKeyName(),
                'parent_class' => class_basename($parent_fqcn),
                'parent_fqcn' => $parent_fqcn,
            ];
        }

        self::$parent_metadata[$fqcn] = $entries;

        return $entries;
    }

    /**
     * Whether a model opts into revision recording. The lookup the bulk-write builder and
     * the REVISION-01 rule both need, memoized per class.
     */
    public static function records_revisions(string $fqcn): bool
    {
        if (isset(self::$records_revisions[$fqcn])) {
            return self::$records_revisions[$fqcn];
        }

        $value = (bool) (new \ReflectionClass($fqcn))->getStaticPropertyValue('revisions', false);
        self::$records_revisions[$fqcn] = $value;

        return $value;
    }

    /**
     * Whether a model declares at least one #[Revision_Parent], without reflecting the
     * relationship. The cheap presence check.
     */
    public static function has_revision_parent(string $fqcn): bool
    {
        return !empty(self::_attributed_parent_methods($fqcn));
    }

    /**
     * Reset all memoized state. Framework-test seam (statics are process-global).
     */
    public static function _testing_reset(): void
    {
        self::$parent_metadata = [];
        self::$records_revisions = [];
    }

    /**
     * The instance-method names on a class carrying #[Revision_Parent], read from the
     * manifest attribute index (simple-name keyed, mirroring the #[Realtime_Touch] scan).
     *
     * @return array<int, string>
     */
    private static function _attributed_parent_methods(string $fqcn): array
    {
        $metadata = Manifest::php_get_metadata_by_fqcn($fqcn);

        if (!$metadata || empty($metadata['public_instance_methods'])) {
            return [];
        }

        $methods = [];

        foreach ($metadata['public_instance_methods'] as $method_name => $method_data) {
            foreach ($method_data['attributes'] ?? [] as $attr_name => $attr_instances) {
                if ($attr_name === 'Revision_Parent' || str_ends_with($attr_name, '\\Revision_Parent')) {
                    $methods[] = $method_name;

                    break;
                }
            }
        }

        return $methods;
    }
}
