<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\Lib;

use App\RSpade\Core\Models\Site_Model;

/**
 * Enum columns as lowercase WORDS, plus the small shared lookups the panel's screens
 * read beside them.
 *
 * The panel speaks an enum value as its model's $enums label, lowercased (status_id 1
 * -> 'pending'), so a link can address a filtered grid readably and one badge
 * vocabulary (_Sys_Status_Badge.TONES) serves every screen. Every method takes the
 * model class, so the rule lives once here: the Email & SMS screen reads its two
 * queues' status and category columns through it, and the Users screen reads
 * login_users.status_id.
 */
class _Sys_Enum_Words
{
    /**
     * The lowercase word for an enum value - its label, lowercased.
     *
     * @param class-string $model_class
     */
    public static function enum_word(string $model_class, string $column, int $value): string
    {
        return strtolower($model_class::$enums[$column][$value]['label']);
    }

    /**
     * The enum value a word names, or null for a word that is not one.
     *
     * @param class-string $model_class
     */
    public static function enum_value(string $model_class, string $column, string $word): ?int
    {
        foreach ($model_class::$enums[$column] as $value => $definition) {
            if (strtolower($definition['label']) === strtolower($word)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * A filter's options for an enum column, in the enum's order.
     *
     * @param class-string $model_class
     * @return array [{value, label}] - value is the lowercase word
     */
    public static function enum_options(string $model_class, string $column): array
    {
        $options = [];

        foreach ($model_class::$enums[$column] as $value => $definition) {
            $options[] = ['value' => static::enum_word($model_class, $column, (int) $value), 'label' => $definition['label']];
        }

        return $options;
    }

    /**
     * A tile per status for a queue's headline: every status in the enum's order, a
     * zero included.
     *
     * @param class-string $model_class
     * @param array<int, int> $status_counts status_id => rows (the model's status_counts())
     * @return array [{status, label, count}]
     */
    public static function status_tiles(string $model_class, array $status_counts): array
    {
        $tiles = [];

        foreach ($status_counts as $status_id => $count) {
            $tiles[] = [
                'status' => static::enum_word($model_class, 'status_id', (int) $status_id),
                'label' => $model_class::$enums['status_id'][$status_id]['label'],
                'count' => (int) $count,
            ];
        }

        return $tiles;
    }

    /**
     * The site filter's options: every site that has a row in the queue table,
     * labelled "#id name". The read lifts the site scope - the queue is one table for
     * the whole install.
     *
     * @param class-string $model_class A site-scoped queue model
     * @return array [{value, label}]
     */
    public static function site_options(string $model_class): array
    {
        $site_ids = $model_class::without_site_scope(
            fn () => $model_class::query()->distinct()->orderBy('site_id')->pluck('site_id')->map(fn ($id) => (int) $id)->all()
        );
        $names = static::site_names($site_ids);

        return array_map(fn ($site_id) => [
            'value' => (int) $site_id,
            'label' => '#' . $site_id . ' ' . ($names[$site_id] ?? '(deleted site)'),
        ], $site_ids);
    }

    /**
     * Site names for a set of site ids, soft-deleted sites included (a queue row outlives
     * its tenant's deletion).
     *
     * @param int[] $site_ids
     * @return array<int, string> site_id => name
     */
    public static function site_names(array $site_ids): array
    {
        if ($site_ids === []) {
            return [];
        }

        return Site_Model::withTrashed()->whereIn('id', $site_ids)->pluck('name', 'id')->all();
    }

    /**
     * The first line of an error, trimmed, or null when there is none - a list row's
     * excerpt of last_error.
     */
    public static function first_line(?string $text): ?string
    {
        return $text === null ? null : trim(explode("\n", trim($text))[0]);
    }
}
