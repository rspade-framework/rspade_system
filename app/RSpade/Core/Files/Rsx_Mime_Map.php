<?php

namespace App\RSpade\Core\Files;

use Illuminate\Support\Arr;

/**
 * Rsx_Mime_Map - the one matcher for every mime-keyed class registry in the rsx config
 * (rsx.preview.viewers, rsx.thumbnails.renderers, rsx.search.extractors, ...): a map of
 * fnmatch mime pattern => class, resolved for one mime.
 *
 * PRECEDENCE, lowest to highest: framework broad < framework specific < application broad <
 * application specific. An application entry that matches the mime always beats every
 * framework entry, so an application can take a type away from a framework pipeline with a
 * glob as broad as it likes; inside one layer the most specific matching pattern wins.
 *
 * SPECIFICITY within a layer: an exact mime (no '*', '?' or '[') beats any glob; between two
 * globs, the one with more literal characters wins (a bracket expression counts as no literal
 * character); a tie goes to the entry declared LATER.
 *
 * THE '*' ENTRY is the catch-all, consulted only after neither layer has a specific match: the
 * application's '*' when it declares one, otherwise the framework's. It never beats a specific
 * entry from either layer.
 *
 * THE LAYERS. The framework layer is the rsx config exactly as system/config/rsx.php ships it,
 * recorded by Rsx_Framework_Provider::register() before any application file is merged over
 * it. The application layer is DERIVED at every lookup from the live config() value: every
 * entry that is not identical (same pattern AND same class) to a framework entry. The live
 * value stays the single source of which entries exist, so a runtime config() write - a
 * test's override, an entry removed - is honoured exactly as written, and an application entry
 * that restates a framework pattern with a different class counts as the application's. An
 * application line identical to the framework's is indistinguishable from it and resolves as
 * the framework entry it repeats.
 */
class Rsx_Mime_Map
{
    /** @var array<string, array> config root ('rsx') => that root's framework value, unmerged. */
    private static $framework_layers = [];

    /**
     * Record a config root's framework value before the application layer is merged over it.
     * Called by Rsx_Framework_Provider::register().
     *
     * @param string $root Config root, e.g. 'rsx'.
     * @param array $value The root's value as the framework ships it.
     * @return void
     */
    public static function record_framework_layer(string $root, array $value): void
    {
        static::$framework_layers[$root] = $value;
    }

    /**
     * Resolve the class registered for a mime in a mime-keyed config map, by the precedence in
     * the class docblock.
     *
     * @param string $config_key Dot path of the map, e.g. 'rsx.preview.viewers'.
     * @param string $mime
     * @return string|null The registered class, or null when nothing matches and no '*' is set.
     */
    public static function match(string $config_key, string $mime): ?string
    {
        $root = explode('.', $config_key, 2)[0];
        if (!array_key_exists($root, static::$framework_layers)) {
            shouldnt_happen("Rsx_Mime_Map: no framework layer recorded for config root '{$root}' (key '{$config_key}')");
        }

        $framework = (array) Arr::get(static::$framework_layers, $config_key, []);
        $current = (array) config($config_key, []);

        $framework_entries = [];
        $application_entries = [];
        foreach ($current as $pattern => $class) {
            $pattern = (string) $pattern;
            if (array_key_exists($pattern, $framework) && $framework[$pattern] === $class) {
                $framework_entries[$pattern] = $class;
            } else {
                $application_entries[$pattern] = $class;
            }
        }

        foreach ([$application_entries, $framework_entries] as $entries) {
            $best_pattern = static::__most_specific_match($entries, $mime);
            if ($best_pattern !== null) {
                return $entries[$best_pattern];
            }
        }

        if (array_key_exists('*', $application_entries)) {
            return $application_entries['*'];
        }
        if (array_key_exists('*', $framework_entries)) {
            return $framework_entries['*'];
        }

        return null;
    }

    /**
     * The most specific pattern in one layer that matches the mime ('*' excluded), or null.
     *
     * @param array $entries Pattern => class, in declaration order.
     * @param string $mime
     * @return string|null
     */
    private static function __most_specific_match(array $entries, string $mime): ?string
    {
        $best_pattern = null;
        $best_score = -1;

        foreach ($entries as $pattern => $class) {
            $pattern = (string) $pattern;
            if ($pattern === '*' || !fnmatch($pattern, $mime)) {
                continue;
            }

            $score = static::__specificity($pattern);
            // >= : a tie goes to the later declaration.
            if ($score >= $best_score) {
                $best_score = $score;
                $best_pattern = $pattern;
            }
        }

        return $best_pattern;
    }

    /**
     * Specificity of a pattern: an exact mime outranks every glob; a glob ranks by its count of
     * literal characters.
     *
     * @param string $pattern
     * @return int
     */
    private static function __specificity(string $pattern): int
    {
        if (strpbrk($pattern, '*?[') === false) {
            return PHP_INT_MAX;
        }

        $literal = preg_replace('/\[[^\]]*\]/', '', $pattern);
        $literal = str_replace(['*', '?'], '', $literal);

        return strlen($literal);
    }
}
