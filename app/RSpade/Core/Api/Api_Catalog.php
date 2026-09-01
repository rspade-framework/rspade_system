<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Api_Catalog - read model over the finalized #[Api_Endpoint] manifest catalog.
 *
 * Pure functions over the manifest 'api_endpoints' table (populated at scan time by
 * Api_Endpoint_ManifestSupport). This is the single implementation of the version
 * display/resolution rule the documentation UI and the LLM catalog both consume.
 *
 * Display/resolution rule: endpoints are grouped by (HTTP verb, path key = pattern with
 * /api/vN stripped). For a selected catalog version, each group shows the single endpoint
 * with the highest version <= the selected version, at its REAL /api/vN pattern. Runtime
 * routing is always exact-match on the full pattern and is unaffected by this rule.
 */
class Api_Catalog
{
    /**
     * Flat list of all API endpoints from the manifest catalog.
     *
     * @param bool $include_hidden Include endpoints marked @api-hidden.
     */
    public static function get_endpoint_list(bool $include_hidden = false): array
    {
        $manifest = Manifest::get_full_manifest();
        $endpoints = $manifest['data']['api_endpoints'] ?? [];

        if (!$include_hidden) {
            $endpoints = array_filter($endpoints, fn($ep) => !$ep['hidden']);
        }

        return array_values($endpoints);
    }

    /**
     * Distinct version numbers present across all endpoints, sorted descending.
     */
    public static function get_versions(): array
    {
        $versions = [];
        foreach (static::get_endpoint_list(true) as $ep) {
            $versions[$ep['version']] = true;
        }

        $versions = array_keys($versions);
        rsort($versions);

        return array_values($versions);
    }

    /**
     * Resolve the endpoints visible for a selected catalog version, grouped by resource.
     *
     * Endpoints are grouped by (verb, path_key); each group contributes the single
     * highest-version endpoint whose version <= $version (groups with none are skipped).
     * Winners are deduped by (class, method, pattern) so a GET+POST endpoint appears once
     * when both verbs resolve to it, then grouped by resource name (ksorted).
     */
    public static function resolve_for_version(int $version, bool $include_hidden = false): array
    {
        $endpoints = static::get_endpoint_list($include_hidden);

        // Group by (verb, path_key).
        $groups = [];
        foreach ($endpoints as $ep) {
            foreach ($ep['methods'] as $verb) {
                $key = $verb . ' ' . $ep['path_key'];
                $groups[$key][] = $ep;
            }
        }

        // Pick the highest version <= $version per group, dedupe across groups.
        $winners = [];
        foreach ($groups as $group_endpoints) {
            $best = null;
            foreach ($group_endpoints as $ep) {
                if ($ep['version'] > $version) {
                    continue;
                }
                if ($best === null || $ep['version'] > $best['version']) {
                    $best = $ep;
                }
            }
            if ($best === null) {
                continue;
            }
            $winners[static::_dedupe_key($best)] = $best;
        }

        // Group winners by resource for display.
        $grouped = [];
        foreach ($winners as $ep) {
            $resource = static::_resource_name($ep['class']);
            if (!isset($grouped[$resource])) {
                $grouped[$resource] = [
                    'name' => str_replace('_', ' ', $resource),
                    'class' => $ep['class'],
                    'endpoints' => [],
                ];
            }
            $grouped[$resource]['endpoints'][] = $ep;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * The same resolved catalogue, narrowed to what one scope set actually reaches.
     *
     * PURE, and deliberately so: it takes the scopes TEXT rather than a key, touches no
     * session and asks no gate, so the same function answers "what would this scope set
     * reach" for a key that exists and for one an operator is still typing. That is what a
     * live effective-access preview needs - there is no key to look up yet.
     *
     * AN ENDPOINT IS IN OR OUT, WHOLE. A scope is a path pattern with no method in it, so
     * there is nothing to narrow an endpoint's `methods` list against: an endpoint the scopes
     * reach is listed with every verb it declares, and one they do not reach is dropped. A
     * resource left with no endpoints drops out with it. The returned structure is otherwise
     * byte-identical to resolve_for_version()'s, so anything that renders one renders the
     * other.
     *
     * IT IS NOT AN AUTHORITY CHECK. Scopes subtract from the holder's permissions and never
     * add to them, so this answers "which of these endpoints do the scopes allow", never
     * "which may the user call" - Api_Tester_Key::accessible_targets_for_key() is the one
     * that intersects the two, and Api_Dispatcher gates every real call regardless.
     *
     * A MALFORMED SCOPE IS NOT AN ERROR HERE. It grants nothing and is skipped, exactly as
     * the dispatcher skips it; the caller that wants to TELL somebody about it reads
     * Api_Scopes::parse_all()['malformed'] itself.
     *
     * READ-ONLY IS THE OTHER AXIS, and it is the one a scope cannot express. $read_only
     * narrows by VERB rather than by path: an endpoint keeps only the verbs a read-only key
     * may use, so a GET-only endpoint is untouched, a POST-only endpoint disappears, and one
     * declaring both is listed as GET alone. A resource left with no endpoints drops out.
     * Unlike the scope narrowing above, this one does edit an endpoint's `methods` - the verb
     * IS the question here, so listing a verb the key cannot use would be the lie.
     *
     * @param int $version The catalogue version, as resolve_for_version() means it.
     * @param string|null $scopes Scope text; null/blank is unrestricted and narrows nothing.
     * @param bool $include_hidden Include endpoints marked @api-hidden.
     * @param bool $read_only Narrow to what a read-only key may call (GET verbs only).
     */
    public static function resolve_for_scopes(
        int $version,
        ?string $scopes,
        bool $include_hidden = false,
        bool $read_only = false
    ): array {
        $groups = static::resolve_for_version($version, $include_hidden);

        if (Api_Scopes::is_unrestricted($scopes) && !$read_only) {
            return $groups;
        }

        $unrestricted = Api_Scopes::is_unrestricted($scopes);
        $out = [];

        foreach ($groups as $resource => $group) {
            $endpoints = [];

            foreach ($group['endpoints'] as $endpoint) {
                if (!$unrestricted && !Api_Scopes::reaches_route($scopes, (string) $endpoint['pattern'])) {
                    continue;
                }

                if ($read_only) {
                    $methods = array_values(array_filter(
                        (array) $endpoint['methods'],
                        static fn ($verb) => $verb === 'GET'
                    ));

                    if (empty($methods)) {
                        continue;
                    }

                    $endpoint['methods'] = $methods;
                }

                $endpoints[] = $endpoint;
            }

            if (!empty($endpoints)) {
                $group['endpoints'] = $endpoints;
                $out[$resource] = $group;
            }
        }

        return $out;
    }

    /**
     * Parse the integer version from an API pattern (e.g. '/api/v2/x' -> 2).
     *
     * Twin of the local regex in Api_Endpoint_ManifestSupport (which runs before this
     * class is guaranteed loadable during the manifest build).
     */
    public static function parse_version(string $pattern): int
    {
        if (preg_match('#^/api/v([0-9]+)#', $pattern, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Strip the leading /api/vN from a pattern to produce the verb-agnostic path key
     * (e.g. '/api/v1/contacts/:id' -> '/contacts/:id').
     */
    public static function path_key(string $pattern): string
    {
        return preg_replace('#^/api/v[0-9]+#', '', $pattern);
    }

    /**
     * Dedupe identity for an endpoint across verb groups: class + method + pattern.
     */
    private static function _dedupe_key(array $endpoint): string
    {
        return $endpoint['class'] . '::' . $endpoint['method'] . '::' . $endpoint['pattern'];
    }

    /**
     * Derive a resource name from a controller class by stripping the controller suffix.
     */
    private static function _resource_name(string $class): string
    {
        if (str_ends_with($class, '_Api_Controller')) {
            return substr($class, 0, -strlen('_Api_Controller'));
        }
        if (str_ends_with($class, '_Controller')) {
            return substr($class, 0, -strlen('_Controller'));
        }

        return $class;
    }
}
