<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Portal\Rsx_Portal_Url;

/**
 * Portal_Url_ManifestSupport - judges the client portal's address at MANIFEST BUILD.
 *
 * Two refusals, both FATAL, in every mode (a production rsx:build runs this exactly as a
 * development rebuild does):
 *
 *   1. The settings themselves - rsx.tenancy, PORTAL_ENABLED and PORTAL_URL - by
 *      Rsx_Portal_Url::check_configured(): the template grammar, the tenant placeholder
 *      rules, the scheme policy, the framework-owned segments.
 *
 *   2. A portal path prefix that collides with a staff route. When the portal lives under
 *      a path on APP_URL's host, its FIXED prefix (the static part of the template, before
 *      any placeholder) must not be the address of a staff #[Route], SPA @route or API
 *      route - see find_route_conflicts(). On that host the portal wins the prefix, so a
 *      colliding staff route would silently stop answering.
 *
 * Nothing here runs per request. Because .env is not source, the manifest stamps
 * Rsx_Portal_Url::fingerprint() beside the mode (Manifest_Store): a development box
 * rebuilds - and re-runs this module - when a portal setting changes, and a sealed box
 * refuses to boot with settings it was not built with.
 *
 * Registered in rsx.manifest_support after the SPA and API modules, the last two
 * producers of the shared `routes` section it reads. It owns no section of its own.
 */
class Portal_Url_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Portal URL';
    }

    /**
     * Judge the configured portal address against the settings rules and the route table.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     * @throws RuntimeException naming the .env key and the rule
     */
    public static function rebuild(array &$manifest_data): void
    {
        if (!Rsx_Portal_Url::is_enabled()) {
            return;
        }

        $problem = Rsx_Portal_Url::check_configured();

        if ($problem !== null) {
            throw new RuntimeException(
                "Invalid client portal address.\n"
                . '  ' . $problem . "\n"
                . '  See: php artisan rsx:man portal'
            );
        }

        $template = Rsx_Portal_Url::template();

        if ($template['separate_host']) {
            return;
        }

        $conflicts = static::find_route_conflicts($template['fixed_prefix'], $manifest_data['data']['routes'] ?? []);

        if ($conflicts !== []) {
            $lines = [];
            foreach ($conflicts as $pattern => $row) {
                $lines[] = '    ' . $pattern . ' (' . static::__describe_route($row) . ')';
            }

            throw new RuntimeException(
                "Invalid client portal address.\n"
                . '  PORTAL_URL serves the portal under ' . $template['fixed_prefix'] . ' on the application host,'
                . " which collides with these staff routes:\n"
                . implode("\n", $lines) . "\n"
                . '  On that host the portal claims every path under its prefix, so these routes would stop'
                . " answering. Choose a PORTAL_URL path that no staff route uses (blank serves the portal at\n"
                . '  ' . Rsx_Portal_Url::DEFAULT_PREFIX . '), or move the routes. Current PORTAL_URL: "'
                . (string) config('rsx.portal.url') . "\".\n"
                . '  See: php artisan rsx:man portal'
            );
        }
    }

    /**
     * Which staff routes collide with a portal served under $fixed_prefix on the
     * application host. Pure: the route table is an argument, so the rule is asserted
     * with synthetic data.
     *
     * Routes are compared by their STATIC leading segments (up to the first ':param',
     * optional or not), case-insensitively. A route collides when:
     *
     *   - its static segments begin with every segment of the prefix - the route is the
     *     prefix itself or lives under it ('/clients' or '/clients/view/:id' against a
     *     '/clients' portal); or
     *   - its static segments are a non-empty leading part of the prefix and its pattern
     *     has enough further segments to reach the prefix's last one - the route's address
     *     space covers the portal's ('/clients/:id' against a '/clients/portal' portal,
     *     which it would answer as id "portal").
     *
     * A route whose first segment is a parameter collides with nothing static, and the
     * root route '/' with nothing at all.
     *
     * @param string $fixed_prefix The portal's static prefix ('/_portal', '/clients'); '' never collides
     * @param array $routes The manifest's `routes` section, keyed by pattern
     * @return array<string, array> The colliding rows, keyed by pattern
     */
    public static function find_route_conflicts(string $fixed_prefix, array $routes): array
    {
        $prefix = static::__segments($fixed_prefix);

        if ($prefix === []) {
            return [];
        }

        $conflicts = [];

        foreach ($routes as $pattern => $row) {
            $segments = static::__segments((string) $pattern);
            $static = [];

            foreach ($segments as $segment) {
                if (str_starts_with($segment, ':')) {
                    break;
                }

                $static[] = $segment;
            }

            if ($static === []) {
                continue;
            }

            $shared = min(count($static), count($prefix));

            if (array_slice($static, 0, $shared) !== array_slice($prefix, 0, $shared)) {
                continue;
            }

            $under_prefix = count($static) >= count($prefix);
            $covers_prefix = count($static) < count($prefix) && count($segments) >= count($prefix);

            if ($under_prefix || $covers_prefix) {
                $conflicts[$pattern] = is_array($row) ? $row : [];
            }
        }

        return $conflicts;
    }

    /**
     * A path's segments, lowercased, empty ones dropped.
     *
     * @return string[]
     */
    private static function __segments(string $path): array
    {
        return array_values(array_filter(explode('/', strtolower($path)), fn ($segment) => $segment !== ''));
    }

    /**
     * Where a route row came from, for the error message.
     */
    private static function __describe_route(array $row): string
    {
        $kind = (string) ($row['type'] ?? 'route');
        $target = $row['js_action_class'] ?? (isset($row['class'], $row['method']) ? $row['class'] . '::' . $row['method'] : null);
        $file = $row['file'] ?? null;

        return $kind . ($target !== null ? ' ' . $target : '') . ($file !== null ? ' in ' . $file : '');
    }
}
