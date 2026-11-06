<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_Gates;

/**
 * Site_Lock_Directives - the three site lock modes a surface may declare, the mode each
 * surface kind takes when it declares none, and the build-time rule that keeps the
 * declarations where they mean something.
 *
 *   #[Writer]       the dispatcher takes the site's WRITE lock before the handler and opens
 *                   the request transaction; committed before the release
 *   #[Reader]       the dispatcher takes the site's READ lock around the handler
 *   #[Manual_Lock]  no automatic lock: the handler takes (and may release) site write locks
 *                   itself - for work that may take more than a few moments
 *
 * They are attribute-only metadata (no PHP class). Each is read once, at manifest build, by
 * Auth_ManifestSupport, which records the RESOLVED mode on the member's surface entry as
 * 'site_lock' => 'reader'|'writer'|'manual'|null. The dispatchers read that key.
 *
 * DEFAULTS. A request surface - #[Ajax_Endpoint], #[Api_Endpoint], #[Companion_Endpoint], a
 * model fetch or fetchable relationship - is a READER unless it declares otherwise. A page
 * route (#[Route], #[SPA], #[Portal_Route]) and a JS @route action take NO automatic lock
 * (null) unless it declares #[Reader] or #[Writer].
 *
 * SITE-LOCK-01 (manifest-build FATAL):
 *
 *   - a method declares at most one of the three;
 *   - #[Writer] / #[Manual_Lock]: #[Ajax_Endpoint], #[Api_Endpoint], #[Companion_Endpoint],
 *     #[Route], #[SPA], #[Portal_Route] only (a model fetch reads);
 *   - #[Reader]: #[Route], #[SPA], #[Portal_Route] only - everywhere else it is already the
 *     default, and a redundant declaration is refused rather than left to mislead;
 *   - never anywhere else: a #[Task] method, a pre_dispatch() (Main's, Portal_Main's or a
 *     controller's), an init(), an unhandled_route(), any other function, a class or an
 *     instance method. Those run with no automatic lock (a task body, and the application
 *     hooks through Site_Lock::_run_hook()), so code there that needs to write manages its
 *     lock itself: Site_Lock::write($site_id, fn () => ...), or Site_Lock::bypass($site_id,
 *     $reason, fn () => ...) for bookkeeping that tolerates concurrent writers. One refusal
 *     wording for all of them (_throw_not_a_surface()).
 *
 * A #[Writer] GET #[Api_Endpoint] is refused by API-GET-PURE-01 (GET reads).
 */
class Site_Lock_Directives
{
    public const RULE = 'SITE-LOCK-01';

    public const READER = 'reader';
    public const WRITER = 'writer';
    public const MANUAL = 'manual';

    /** Attribute name => the mode recorded on the surface. */
    public const DIRECTIVES = [
        'Writer' => self::WRITER,
        'Reader' => self::READER,
        'Manual_Lock' => self::MANUAL,
    ];

    /** Attribute name => the surface kinds (Auth_ManifestSupport 'kinds') that honour it. */
    public const ALLOWED_KINDS = [
        'Writer' => ['ajax', 'api', 'companion', 'route', 'spa', 'portal_route'],
        'Manual_Lock' => ['ajax', 'api', 'companion', 'route', 'spa', 'portal_route'],
        'Reader' => ['route', 'spa', 'portal_route'],
    ];

    /** The kinds that are a READER when nothing is declared. Every other kind takes no lock. */
    public const READER_BY_DEFAULT_KINDS = ['ajax', 'api', 'companion', 'model_fetch', 'model_relationship'];

    /** Surface kind => the attribute that declares it, for the error text. */
    private const KIND_ATTRIBUTES = [
        'route' => '#[Route]',
        'spa' => '#[SPA]',
        'portal_route' => '#[Portal_Route]',
        'api' => '#[Api_Endpoint]',
        'ajax' => '#[Ajax_Endpoint]',
        'companion' => '#[Companion_Endpoint]',
        'model_fetch' => '#[Ajax_Endpoint_Model_Fetch]',
        'model_relationship' => '#[Ajax_Endpoint_Model_Fetch]',
        'js_action' => '@route',
        'portal_js_action' => '@portal_spa',
    ];

    /**
     * The site lock mode of a surface member: what it declares, validated against its kinds,
     * else the default for those kinds.
     *
     * @param array|null $attributes The member's attributes bucket (keyed by attribute name)
     * @param array $kinds The surface kinds the member was indexed as ([] when it is no surface)
     * @param bool $is_task The member carries #[Task]
     * @param string $location 'Class::method in file', for the error text
     * @param string|null $method_name The member's name, to name a hook (pre_dispatch, init) in the error text
     * @return string|null 'reader' | 'writer' | 'manual', or null (no automatic lock)
     * @throws RuntimeException SITE-LOCK-01
     */
    public static function resolve(?array $attributes, array $kinds, bool $is_task, string $location, ?string $method_name = null): ?string
    {
        $declared = static::declared($attributes);

        if ($declared === []) {
            return $kinds === [] ? null : static::default_for($kinds);
        }

        if (count($declared) > 1) {
            static::_throw(
                $location,
                'declares ' . static::_list($declared) . '; a method declares at most one of #[Writer], #[Reader] and #[Manual_Lock].',
                'Keep the one that describes what the handler does and remove the others.'
            );
        }

        $directive = $declared[0];

        if ($is_task) {
            static::_throw_not_a_surface($location, $directive, 'a #[Task] method');
        }

        if ($kinds === []) {
            static::_throw_not_a_surface($location, $directive, match ($method_name) {
                'pre_dispatch' => 'a pre_dispatch() hook',
                'init' => 'an init() hook',
                'unhandled_route' => 'an unhandled_route() hook',
                default => 'a function that is not an endpoint or route',
            });
        }

        $refused = array_values(array_diff($kinds, self::ALLOWED_KINDS[$directive]));

        if ($refused !== []) {
            $named = implode(' and ', array_unique(array_map(fn (string $kind) => self::KIND_ATTRIBUTES[$kind] ?? $kind, $refused)));

            if ($directive === 'Reader' && array_diff($refused, self::READER_BY_DEFAULT_KINDS) === []) {
                static::_throw(
                    $location,
                    "declares #[Reader] on {$named}, which is already a reader by default.",
                    'Remove #[Reader]; declare #[Writer] (or #[Manual_Lock]) only when the endpoint writes.'
                );
            }

            static::_throw(
                $location,
                "declares #[{$directive}] on {$named}, which does not honour it.",
                "#[{$directive}] applies to " . static::_allowed_text($directive) . ' only. Remove it, or move the'
                . ' surface it should govern into a method of its own.'
            );
        }

        return self::DIRECTIVES[$directive];
    }

    /**
     * The mode a member with these kinds takes when it declares none.
     *
     * @param array $kinds
     * @return string|null 'reader', or null (no automatic lock)
     */
    public static function default_for(array $kinds): ?string
    {
        return array_intersect($kinds, self::READER_BY_DEFAULT_KINDS) === [] ? null : self::READER;
    }

    /**
     * Every SERVER-dispatched surface and the site lock mode it runs under, for the
     * rsx:site_lock:endpoints inventory: Ajax, external API and companion endpoints, page
     * routes, model fetches and fetchable relationships (JS @route actions have no server
     * handler of their own and are left out). Sorted by surface.
     *
     * @return array<int, array{surface: string, kinds: string[], mode: string, declared: bool, file: string}>
     *         mode is 'reader' | 'writer' | 'manual' | 'none'; declared is false when the mode
     *         is its kinds' default
     */
    public static function inventory(): array
    {
        $server_kinds = ['ajax', 'api', 'companion', 'route', 'spa', 'portal_route', 'model_fetch', 'model_relationship'];
        $rows = [];

        foreach (Auth_Gates::get_surfaces() as $target => $surface) {
            $kinds = array_values(array_intersect($surface['kinds'] ?? [], $server_kinds));

            if ($kinds === []) {
                continue;
            }

            $mode = $surface['site_lock'] ?? null;

            $rows[] = [
                'surface' => $target,
                'kinds' => $kinds,
                'mode' => $mode ?? 'none',
                'declared' => $mode !== static::default_for($surface['kinds']),
                'file' => (string) ($surface['file'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Refuse any directive at a place that can never carry one (a class, an instance method).
     *
     * @param array|null $attributes
     * @param string $what 'a class' | 'an instance method'
     * @param string $location
     * @throws RuntimeException SITE-LOCK-01
     */
    public static function assert_none(?array $attributes, string $what, string $location): void
    {
        $declared = static::declared($attributes);

        if ($declared === []) {
            return;
        }

        static::_throw_not_a_surface($location, $declared[0], $what);
    }

    /**
     * The directive attribute names present in an attributes bucket, in DIRECTIVES order.
     *
     * @param array|null $attributes
     * @return string[]
     */
    public static function declared(?array $attributes): array
    {
        $found = [];

        foreach (array_keys(self::DIRECTIVES) as $name) {
            foreach (array_keys($attributes ?? []) as $attr_name) {
                if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                    $found[] = $name;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The one refusal for a directive placed anywhere but a surface that honours it: a task,
     * a hook (pre_dispatch, init, unhandled_route), another function, a class, an instance
     * method.
     *
     * @param string $location
     * @param string $directive 'Writer' | 'Reader' | 'Manual_Lock'
     * @param string $place What it was found on, for the text ('a #[Task] method', 'a class')
     * @throws RuntimeException SITE-LOCK-01
     */
    private static function _throw_not_a_surface(string $location, string $directive, string $place): never
    {
        if ($directive === 'Reader') {
            static::_throw(
                $location,
                "declares #[Reader] on {$place}. #[Reader] is only allowed on an MVC route (#[Route], #[SPA],"
                . ' #[Portal_Route]); an Ajax, API or companion endpoint is already a reader.',
                'A task, pre_dispatch(), init() or other function that needs a consistent read manages its lock'
                . ' itself: wrap the work in Site_Lock::read($site_id, fn () => ...).'
            );
        }

        static::_throw(
            $location,
            "declares #[{$directive}] on {$place}. #[{$directive}] is only allowed on an Ajax endpoint, an API"
            . ' endpoint, an MVC route (#[Route], #[SPA], #[Portal_Route]) or a companion endpoint.',
            'A task, pre_dispatch(), init() or other function that needs to write manages its lock itself: wrap the'
            . ' work in Site_Lock::write($site_id, fn () => ...) (or Site_Lock::bypass($site_id, $reason, fn () => ...)'
            . ' for bookkeeping that tolerates concurrent writers).'
        );
    }

    private static function _allowed_text(string $directive): string
    {
        return implode(', ', array_map(fn (string $kind) => self::KIND_ATTRIBUTES[$kind], self::ALLOWED_KINDS[$directive]));
    }

    private static function _list(array $declared): string
    {
        return implode(' and ', array_map(fn (string $name) => "#[{$name}]", $declared));
    }

    private static function _throw(string $location, string $problem, string $remedy): never
    {
        throw new RuntimeException(
            "Invalid site lock mode: {$location}\n" .
            '  ' . self::RULE . ": {$problem}\n" .
            "  {$remedy}"
        );
    }
}
