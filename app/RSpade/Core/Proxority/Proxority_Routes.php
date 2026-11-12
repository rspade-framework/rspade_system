<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Proxority;

use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Lib\Portal\Rsx_Portal_Url;

/**
 * Proxority_Routes - the whitelist Proxority refreshes from the application:
 *
 *     {"unthrottled": [patterns], "priority": [patterns], "media": [patterns]}
 *
 * printed by rsx:proxority:routes --json. Every pattern is a URL PATH as the proxy sees it
 * on the wire (no host, no query string), in the proxy's syntax (system/app/RSpade/resource/
 * docker/proxority/lib/classify.js): an exact path, a whole-segment ':param', a final optional ':param?', and a
 * final '*' meaning "this prefix and anything after it".
 *
 * WHAT IS LISTED
 *
 *   - every route row whose surface declares a directive (Proxority_Directives; the
 *     surface's 'throttle' key): standard #[Route] rows, the rows an #[SPA] bootstrap
 *     serves (an SPA row's surface IS its bootstrap, so a directive on the bootstrap covers
 *     every action it serves), #[Api_Endpoint] rows, and the portal's #[Portal_Route] and
 *     portal SPA rows;
 *   - unthrottled, always: /_compiled/* and /_vendor/* (build artifacts), and every file
 *     the public directories serve (AssetHandler::get_public_directories(), mounted at '/'
 *     with no prefix), each as its EXACT path - a directory wildcard would also cover routes
 *     that happen to share the directory's name. A file the asset handler refuses (PHP, an
 *     ignore pattern, a name in two public directories) is not listed.
 *
 * Ajax endpoints are never listed: the proxy treats the path /_ajax/_priority itself as
 * priority, and Ajax::handle_priority_request() refuses any call there whose endpoint lacks
 * #[Priority].
 *
 * PATTERN TRANSLATION (route syntax -> proxy syntax). The route table is richer than the
 * proxy's matcher, so a pattern is widened, never narrowed, to the nearest proxy pattern:
 *
 *   - a segment that is exactly ':name' stays; a segment MIXING a parameter with literal
 *     text (':id.:ext', 'v:n') becomes ':param' - the whole segment;
 *   - each optional ':name?' is expanded into the pattern with and without that segment
 *     (the proxy allows an optional only at the end; expansion is exact everywhere);
 *   - a '*' anywhere ends the pattern there as a prefix: '/a/*' stays, '/a/b*c' becomes
 *     '/a/b*'.
 *
 * PORTAL PATHS. A portal route is matched by the dispatcher INSIDE the portal's prefix, so
 * its wire path is that prefix joined to the pattern. The prefix comes from the PORTAL_URL
 * template (Rsx_Portal_Url::template()) with its tenant placeholder left in: in multi
 * tenancy '/_portal/:slug' is itself a valid proxy segment, so '/_portal/:slug/_download/:key'
 * covers every tenant. Where a portal is served at a host's root - a portal host of its own,
 * a per-tenant host ('https://:slug.myapp.com'), or a site's custom domain while
 * PORTAL_CUSTOM_DOMAINS is 'override' or 'only' - the bare pattern is emitted. The proxy
 * matches paths, never hosts, so a bare portal pattern also matches the same path on the
 * application host: that over-reach is the price of a host-blind proxy, and it only ever
 * widens a directive the application declared. A disabled portal contributes nothing.
 */
class Proxority_Routes
{
    /** Build artifacts, served without throttling (nginx serves them itself once wired). */
    public const ALWAYS_UNTHROTTLED = ['/_compiled/*', '/_vendor/*'];

    /**
     * The answer for this install: the live manifest, portal configuration and public files.
     *
     * @return array{unthrottled: string[], priority: string[], media: string[]}
     */
    public static function answer(): array
    {
        Manifest::init();

        return static::build(
            Manifest::get_routes(),
            Manifest::$data['data']['portal_routes'] ?? [],
            Auth_Gates::get_surfaces(),
            static::portal_bases(),
            static::public_file_paths()
        );
    }

    /**
     * The whitelist from its inputs. Pure: the synthetic-input path the tests drive.
     *
     * @param array $routes Staff route rows (standard, spa, api), each with 'pattern' and 'surface'
     * @param array $portal_routes Portal route rows (portal, portal_spa)
     * @param array $surfaces The auth surface index ('throttle' per surface)
     * @param string[] $portal_bases The prefixes portal paths appear under ('' = the bare pattern)
     * @param string[] $public_paths Exact URL paths of the public files
     * @return array{unthrottled: string[], priority: string[], media: string[]}
     */
    public static function build(array $routes, array $portal_routes, array $surfaces, array $portal_bases, array $public_paths): array
    {
        $answer = [
            'unthrottled' => array_merge(self::ALWAYS_UNTHROTTLED, $public_paths),
            'priority' => [],
            'media' => [],
        ];

        foreach ($routes as $pattern => $row) {
            $throttle = $surfaces[$row['surface'] ?? '']['throttle'] ?? null;

            if ($throttle !== null) {
                array_push($answer[$throttle], ...static::wire_patterns((string) ($row['pattern'] ?? $pattern)));
            }
        }

        foreach ($portal_routes as $pattern => $row) {
            $throttle = $surfaces[$row['surface'] ?? '']['throttle'] ?? null;

            if ($throttle === null) {
                continue;
            }

            foreach ($portal_bases as $base) {
                array_push($answer[$throttle], ...static::wire_patterns(static::join($base, (string) ($row['pattern'] ?? $pattern))));
            }
        }

        foreach ($answer as $key => $patterns) {
            $patterns = array_values(array_unique($patterns));
            sort($patterns, SORT_STRING);
            $answer[$key] = $patterns;
        }

        return $answer;
    }

    /**
     * The prefixes a portal route appears under on the wire, '' meaning the bare pattern.
     * [] when the portal is disabled.
     *
     * @return string[]
     */
    public static function portal_bases(): array
    {
        if (!Rsx_Portal_Url::is_enabled()) {
            return [];
        }

        return static::bases_for(Rsx_Portal_Url::template()['prefix'], Rsx_Portal_Url::custom_domains());
    }

    /**
     * portal_bases() from a template prefix and the PORTAL_CUSTOM_DOMAINS value. Pure.
     *
     * @param string $template_prefix '' or '/seg[/seg]', placeholder included
     * @param string $custom_domains Rsx_Portal_Url::CUSTOM_DOMAINS_*
     * @return string[]
     */
    public static function bases_for(string $template_prefix, string $custom_domains): array
    {
        if ($custom_domains === Rsx_Portal_Url::CUSTOM_DOMAINS_ONLY) {
            return [''];
        }

        $bases = [$template_prefix];

        if ($custom_domains !== Rsx_Portal_Url::CUSTOM_DOMAINS_OFF) {
            $bases[] = '';
        }

        return array_values(array_unique($bases));
    }

    /**
     * A prefix joined to a route pattern: '/_portal' + '/' is '/_portal', never '/_portal/'.
     */
    public static function join(string $base, string $pattern): string
    {
        $pattern = '/' . ltrim($pattern, '/');

        if ($base === '') {
            return $pattern;
        }

        return $pattern === '/' ? $base : $base . $pattern;
    }

    /**
     * One route pattern as the proxy patterns that cover it (see PATTERN TRANSLATION). Pure.
     *
     * @param string $pattern
     * @return string[]
     */
    public static function wire_patterns(string $pattern): array
    {
        $pattern = '/' . ltrim($pattern, '/');

        // A wildcard ends the pattern: everything after the first '*' is the remainder it
        // already matches.
        $star = strpos($pattern, '*');
        $prefix_match = $star !== false;
        if ($prefix_match) {
            $pattern = substr($pattern, 0, $star);
        }

        // The leading '' of the split is the root; a trailing '' is a trailing slash, kept.
        $segments = explode('/', substr($pattern, 1));
        $variants = [''];

        foreach ($segments as $index => $segment) {
            $last = $index === count($segments) - 1;

            // '/a/*' leaves a trailing empty segment: the prefix '/a/' and its '*'.
            if ($segment === '' && $last && $prefix_match) {
                $variants = array_map(fn (string $variant) => $variant . '/', $variants);
                continue;
            }

            if (preg_match('/^:([A-Za-z_][A-Za-z0-9_]*)(\?)?$/', $segment, $match)) {
                $required = '/:' . $match[1];

                if (!empty($match[2])) {
                    $expanded = [];
                    foreach ($variants as $variant) {
                        $expanded[] = $variant;
                        $expanded[] = $variant . $required;
                    }
                    $variants = $expanded;
                } else {
                    $variants = array_map(fn (string $variant) => $variant . $required, $variants);
                }

                continue;
            }

            // A segment that mixes a parameter with literal text is widened to the whole
            // segment - unless it is the unfinished segment before a '*', where its literal
            // head is kept and the '*' covers the rest.
            if (str_contains($segment, ':')) {
                $head = strstr($segment, ':', true);
                $segment = ($last && $prefix_match) ? $head : ':param';

                if ($last && $prefix_match) {
                    $variants = array_map(fn (string $variant) => $variant . '/' . $segment, $variants);
                    continue;
                }
            }

            $variants = array_map(fn (string $variant) => $variant . '/' . $segment, $variants);
        }

        return array_values(array_unique(array_map(
            fn (string $variant) => ($variant === '' ? '/' : $variant) . ($prefix_match ? '*' : ''),
            $variants
        )));
    }

    /**
     * Every file the public directories serve, as its exact URL path (segments URL-encoded,
     * as a browser sends them). PHP files and files the asset handler refuses are skipped.
     *
     * @return string[]
     */
    public static function public_file_paths(): array
    {
        $paths = [];

        foreach (AssetHandler::get_public_directories() as $directory) {
            if (!File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory, true) as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());

                if (strtolower($file->getExtension()) === 'php') {
                    continue;
                }

                // The asset handler decides what is served: an excluded file is a 404, and a
                // name present in two public directories is ambiguous (a 500) - neither is a
                // file the proxy should wave through.
                try {
                    AssetHandler::find_public_asset($relative);
                } catch (HttpException $e) {
                    continue;
                }

                $paths[] = '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }
}
