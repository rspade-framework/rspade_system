<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Proxority;

use RuntimeException;

/**
 * Proxority_Directives - the three scheduling directives a surface may declare, and the
 * build-time rule that keeps them where they mean something.
 *
 *   #[Unthrottled]  never queued by Proxority (still counted busy)
 *   #[Priority]     jumps the queue (never past the per-client cap)
 *   #[Media]        counted against the pool's media cap; the slot is held until the
 *                   response ENDS (downloads, uploads, renditions)
 *
 * They are attribute-only metadata (no PHP class) and inert outside the RSpade container,
 * where no Proxority sits in front of php-fpm. Each is read once, at manifest build, by
 * Auth_ManifestSupport, which records it on the member's surface entry as
 * 'throttle' => 'unthrottled'|'priority'|'media'. Everything downstream reads that key:
 * rsx:proxority:routes (the proxy's whitelist), the /_ajax/_priority channel
 * (Ajax::handle_priority_request) and the generated controller stubs.
 *
 * PROXORITY-01 (manifest-build FATAL): a directive is declared on a method whose every
 * surface kind honours it, and a method declares at most one of the three.
 *
 *   #[Unthrottled]  #[Route], #[SPA], #[Portal_Route], #[Api_Endpoint]
 *   #[Priority]     the same, plus #[Ajax_Endpoint]
 *   #[Media]        #[Route], #[Portal_Route], #[Api_Endpoint]
 *
 * A directive anywhere else - a class, an instance method, a static method that is no
 * surface, a companion or model-fetch endpoint - would be silently ignored, so it fails the
 * build instead. On #[SPA] the directive covers every route the bootstrap serves.
 */
class Proxority_Directives
{
    public const RULE = 'PROXORITY-01';

    /** Attribute name => the value recorded on the surface. */
    public const DIRECTIVES = [
        'Unthrottled' => 'unthrottled',
        'Priority' => 'priority',
        'Media' => 'media',
    ];

    /** Attribute name => the surface kinds (Auth_ManifestSupport 'kinds') that honour it. */
    public const ALLOWED_KINDS = [
        'Unthrottled' => ['route', 'spa', 'portal_route', 'api'],
        'Priority' => ['route', 'spa', 'portal_route', 'api', 'ajax'],
        'Media' => ['route', 'portal_route', 'api'],
    ];

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
    ];

    /**
     * The directive a surface member declares, validated against the surface kinds it carries.
     *
     * @param array|null $attributes The member's attributes bucket (keyed by attribute name)
     * @param array $kinds The surface kinds the member was indexed as ([] when it is no surface)
     * @param string $location 'Class::method in file', for the error text
     * @return string|null 'unthrottled' | 'priority' | 'media', or null when none is declared
     * @throws RuntimeException PROXORITY-01
     */
    public static function resolve(?array $attributes, array $kinds, string $location): ?string
    {
        $declared = static::declared($attributes);

        if ($declared === []) {
            return null;
        }

        if (count($declared) > 1) {
            static::_throw(
                $location,
                'declares ' . static::_list($declared) . '; a method declares at most one of #[Unthrottled], #[Priority] and #[Media].',
                'Keep the one that describes the surface and remove the others.'
            );
        }

        $directive = $declared[0];

        if ($kinds === []) {
            static::_throw(
                $location,
                "declares #[{$directive}] but is not a dispatchable surface, so nothing would ever read it.",
                "Place #[{$directive}] on " . static::_allowed_text($directive) . ', or remove it.'
            );
        }

        $refused = array_values(array_diff($kinds, self::ALLOWED_KINDS[$directive]));

        if ($refused !== []) {
            $named = array_map(fn (string $kind) => self::KIND_ATTRIBUTES[$kind] ?? $kind, $refused);

            static::_throw(
                $location,
                "declares #[{$directive}] on " . implode(' and ', array_unique($named)) . ', which does not honour it.',
                "#[{$directive}] applies to " . static::_allowed_text($directive) . ' only. Remove it, or move the'
                . ' surface it should govern into a method of its own.'
            );
        }

        return self::DIRECTIVES[$directive];
    }

    /**
     * Refuse any directive at a place that can never carry one (a class, an instance method).
     *
     * @param array|null $attributes
     * @param string $what 'a class' | 'an instance method'
     * @param string $location
     * @return void
     * @throws RuntimeException PROXORITY-01
     */
    public static function assert_none(?array $attributes, string $what, string $location): void
    {
        $declared = static::declared($attributes);

        if ($declared === []) {
            return;
        }

        static::_throw(
            $location,
            'declares ' . static::_list($declared) . " on {$what}; a scheduling directive belongs on the public static"
            . ' method that is the surface.',
            'Move it onto the surface method (' . static::_allowed_text($declared[0]) . ').'
        );
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

    private static function _allowed_text(string $directive): string
    {
        $attributes = array_map(fn (string $kind) => self::KIND_ATTRIBUTES[$kind], self::ALLOWED_KINDS[$directive]);

        return implode(', ', $attributes);
    }

    private static function _list(array $declared): string
    {
        return implode(' and ', array_map(fn (string $name) => "#[{$name}]", $declared));
    }

    private static function _throw(string $location, string $problem, string $remedy): never
    {
        throw new RuntimeException(
            "Invalid scheduling directive: {$location}\n" .
            '  ' . self::RULE . ": {$problem}\n" .
            "  {$remedy}"
        );
    }
}
