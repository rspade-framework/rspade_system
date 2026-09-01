<?php

namespace App\RSpade\Core\Externals;

use RuntimeException;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Rsx_Externals - the read model over declared external resources.
 *
 * Every external URL a page loads (a CDN library, a vendor widget) is DECLARED in a
 * `*.externals.php` file next to the feature that needs it, consolidated at manifest
 * build time by Externals_ManifestSupport, and reached from here by identifier. Nothing
 * else may inject a `<script>` or `<link>` at an external host: the Content-Security-Policy
 * whitelist DERIVES from these declarations, so an undeclared resource is a blocked
 * resource by construction, and policy can never drift from code.
 *
 * Two questions this class answers, and they are separate:
 *
 * - WHERE does the browser fetch the asset from? `resolve_url()` - the locally mirrored
 *   `/_vendor/{file}` copy whenever the entry declares `mirror`, and the raw external URL
 *   only when it declares `mirror => false`. ONE answer in every mode: a dev box serves the
 *   same page a sealed box serves. Resolution is PURE: it never downloads anything;
 *   populating the mirror is the compiler's job (Cdn_Cache::mirror_externals()).
 * - WHAT must the CSP permit? `csp_hosts_for_realm()` - the asset origins of the
 *   `mirror:false` entries (mirrored assets are same-origin and contribute nothing), plus
 *   every entry's declared runtime `csp` extras.
 *
 * See: php artisan rsx:man external_resources
 */
class Rsx_Externals
{
    /** Realms a caller may query for. 'both' is a DECLARATION value, never a query value. */
    public const QUERYABLE_REALMS = ['staff', 'portal'];

    /**
     * Testing seam: stand in for the manifest-backed entry table.
     *
     * The declared table is whatever the tree declares, so per-realm and per-mirror policy
     * cannot be exercised against it. Tests set this to a table they built by running
     * Externals_ManifestSupport over fixture declarations, and restore it to null.
     */
    public static ?array $_testing_entries = null;

    /**
     * Every declared entry, keyed by identifier (ksorted at build time).
     */
    public static function all(): array
    {
        if (static::$_testing_entries !== null) {
            return static::$_testing_entries;
        }

        $manifest = Manifest::get_full_manifest();

        return $manifest['data']['external_resources'] ?? [];
    }

    /**
     * One entry, fully normalized.
     *
     * @throws RuntimeException when nothing declares the identifier.
     */
    public static function get(string $identifier): array
    {
        $all = static::all();

        if (!isset($all[$identifier])) {
            $known = empty($all) ? '(none declared)' : implode(', ', array_keys($all));

            throw new RuntimeException(
                "Unknown external resource '{$identifier}'\n" .
                "  Declared identifiers: {$known}\n" .
                "  Declare it in a *.externals.php file beside the feature that needs it.\n" .
                '  See: php artisan rsx:man external_resources'
            );
        }

        return $all[$identifier];
    }

    /**
     * Entries visible in one realm: those declaring that realm, plus every 'both'.
     */
    public static function all_for_realm(string $realm): array
    {
        static::_assert_queryable_realm($realm);

        $entries = [];

        foreach (static::all() as $identifier => $entry) {
            if ($entry['realm'] === $realm || $entry['realm'] === 'both') {
                $entries[$identifier] = $entry;
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The URL the browser should actually request.
     *
     * A mirrored entry is ALWAYS served from our own /_vendor/ copy - in development exactly
     * as in a sealed build - reusing Cdn_Cache's filenames so the store and the page name the
     * same file. There is no development exception: `mirror => false` is the only way an
     * asset stays external, and a `mirror:false` STYLESHEET whose fonts live on another host
     * must name those hosts itself in its `csp => ['font-src' => [...]]` extras.
     *
     * PURE: no download, no filesystem write. The mirror is populated by the compiler.
     */
    public static function resolve_url(string $url, bool $mirror): string
    {
        if (!$mirror) {
            return $url;
        }

        return '/_vendor/' . Cdn_Cache::filename_for($url, static::url_asset_type($url));
    }

    /**
     * 'css' for a stylesheet URL, 'js' for everything else (the two Cdn_Cache types).
     */
    public static function url_asset_type(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css' ? 'css' : 'js';
    }

    /**
     * The client-facing map baked into a bundle: identifier => what the loader needs.
     *
     * `integrity` survives only for a URL still fetched from its external host - a mirrored
     * copy is served same-origin from our own build and carries no external hash.
     */
    public static function resolved_map_for_realm(string $realm): array
    {
        $map = [];

        foreach (static::all_for_realm($realm) as $identifier => $entry) {
            $js = [];
            $css = [];
            $integrity = [];

            foreach (['js' => &$js, 'css' => &$css] as $key => &$bucket) {
                foreach ($entry[$key] as $url) {
                    $resolved = static::resolve_url($url, $entry['mirror']);
                    $bucket[] = $resolved;

                    if ($resolved === $url && isset($entry['integrity'][$url])) {
                        $integrity[$url] = $entry['integrity'][$url];
                    }
                }
            }
            unset($bucket);

            ksort($integrity);

            $map[$identifier] = [
                'js' => $js,
                'css' => $css,
                'integrity' => $integrity,
                'readiness' => $entry['readiness'],
            ];
        }

        ksort($map);

        return $map;
    }

    /**
     * The CSP sources a realm's policy must permit, as directive => origins.
     *
     * An asset origin is whitelisted only while the browser still fetches from it, which
     * means only for a `mirror:false` entry - a mirrored asset is served from /_vendor/ in
     * every mode, is same-origin, and is already covered by 'self'. Each entry's declared
     * `csp` extras merge in ALWAYS - they describe what the script does at runtime (frames it
     * opens, hosts it calls, font hosts its stylesheet names), which mirroring does not
     * change.
     */
    public static function csp_hosts_for_realm(string $realm): array
    {
        $directives = [];

        foreach (static::all_for_realm($realm) as $entry) {
            if (!$entry['mirror']) {
                foreach ($entry['js'] as $url) {
                    $directives['script-src'][] = static::url_origin($url);
                }

                foreach ($entry['css'] as $url) {
                    $directives['style-src'][] = static::url_origin($url);
                }
            }

            foreach ($entry['csp'] as $directive => $sources) {
                foreach ($sources as $source) {
                    $directives[$directive][] = $source;
                }
            }
        }

        foreach ($directives as $directive => $sources) {
            $sources = array_values(array_unique($sources));
            sort($sources);
            $directives[$directive] = $sources;
        }

        ksort($directives);

        return $directives;
    }

    /**
     * scheme://host[:port] - a CSP source never carries a path.
     */
    public static function url_origin(string $url): string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException(
                "Cannot derive an origin from external resource URL '{$url}'\n" .
                '  A declared URL must be absolute (scheme + host).'
            );
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private static function _assert_queryable_realm(string $realm): void
    {
        if (!in_array($realm, self::QUERYABLE_REALMS, true)) {
            throw new RuntimeException(
                "Invalid realm '{$realm}'\n" .
                '  Query one of: ' . implode(', ', self::QUERYABLE_REALMS) . ".\n" .
                "  'both' is a declaration value meaning 'visible in either realm'; it is not something to query for."
            );
        }
    }
}
