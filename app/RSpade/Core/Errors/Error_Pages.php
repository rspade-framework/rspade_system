<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Errors;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Error_Pages - which application route renders a given status, in a given realm
 *
 * An application declares its error pages as ordinary routes under the reserved
 * prefix /error/: an exact page per status (/error/404) and one catch-all
 * (/error/generic). This class is the lookup, and the ONLY thing that decides
 * which declaration answers a failure:
 *
 *   1. the realm's own table, exact status first, then generic
 *   2. for a PORTAL failure with neither, the staff pair (a framework page beats
 *      no page, and a staff page is still a page)
 *   3. nothing declared -> null, and the framework's own page renders
 *
 * THE MANIFEST MAY NOT BE THERE. These pages exist to render when the
 * application is broken, and a boot that failed before the index leaves no route
 * table to read - so an uninitialised manifest resolves to null rather than
 * building one mid-failure.
 *
 * The shape of ROUTE-ERROR-01 (the manifest rule that keeps these declarations
 * well-formed) lives in Route_ManifestSupport.
 *
 * See: php artisan rsx:man error_pages
 */
class Error_Pages
{
    /** The prefix an error-page route pattern lives under. */
    public const PREFIX = '/error/';

    /** The catch-all pattern every status falls back to. */
    public const GENERIC_PATTERN = self::PREFIX . 'generic';

    /**
     * Resolution replacement for tests - see _testing_set_resolver().
     *
     * @var callable|null
     */
    private static $_testing_resolver = null;

    /**
     * The application route that renders this status in this realm, or null.
     *
     * @param int $status The HTTP status being rendered
     * @param string $realm Auth_Gates::REALM_STAFF or REALM_PORTAL
     * @param bool $allow_exact False to consult only the generic page (the
     *                          /error/generic preview, which is asking for the
     *                          catch-all by name)
     * @return array|null ['class', 'method', 'pattern', 'surface'] or null
     */
    public static function resolve(int $status, string $realm, bool $allow_exact = true): ?array
    {
        if (static::$_testing_resolver !== null) {
            return (static::$_testing_resolver)($status, $realm, $allow_exact);
        }

        if (!Manifest::$_has_init) {
            return null;
        }

        $patterns = [];
        if ($allow_exact) {
            $patterns[] = self::PREFIX . $status;
        }
        $patterns[] = self::GENERIC_PATTERN;

        if ($realm === Auth_Gates::REALM_PORTAL) {
            $portal_match = static::__first_match(static::__portal_routes(), $patterns, 'portal');

            if ($portal_match !== null) {
                return $portal_match;
            }
        }

        return static::__first_match(Manifest::get_routes(), $patterns, 'standard');
    }

    /**
     * Replace resolution wholesale, for tests.
     *
     * The framework suite cannot declare its own /error/ fixtures: the patterns
     * are a single global namespace, and a fixture route would collide with the
     * application's real pages for as long as the suite is indexed. So a test
     * points resolution at an ordinary fixture route of its own and calls the
     * funnel normally.
     *
     * @param callable|null $fn fn(int $status, string $realm, bool $allow_exact): ?array
     * @return void
     */
    public static function _testing_set_resolver(?callable $fn): void
    {
        static::$_testing_resolver = $fn;
    }

    /**
     * The portal route table, or an empty table when the manifest carries none.
     *
     * @return array
     */
    private static function __portal_routes(): array
    {
        $manifest = Manifest::get_full_manifest();

        return $manifest['data']['portal_routes'] ?? [];
    }

    /**
     * The first of $patterns declared in $routes, as a route match.
     *
     * The type filter keeps the lookup to routes a controller method actually
     * answers: a row of another type under this prefix is not an error page.
     *
     * @param array $routes A realm's route table
     * @param array $patterns Patterns in preference order
     * @param string $type The row type this table's error pages carry
     * @return array|null
     */
    private static function __first_match(array $routes, array $patterns, string $type): ?array
    {
        foreach ($patterns as $pattern) {
            $row = $routes[$pattern] ?? null;

            if ($row === null || ($row['type'] ?? null) !== $type) {
                continue;
            }

            return [
                'class' => $row['class'],
                'method' => $row['method'],
                'pattern' => $pattern,
                'type' => $row['type'],
                'surface' => $row['surface'] ?? ($row['class'] . '::' . $row['method']),
            ];
        }

        return null;
    }
}
