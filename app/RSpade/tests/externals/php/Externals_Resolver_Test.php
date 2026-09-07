<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Php;

use RuntimeException;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Externals answers two questions that must never be conflated: where the browser
 * fetches an asset from (resolve_url / resolved_map_for_realm) and what the CSP must
 * permit (csp_hosts_for_realm). NEITHER answer depends on the mode any more - a dev box
 * serves the page a sealed box serves - so every case below is pinned in development AND
 * in a sealed build and asserts the SAME result in both.
 *
 * The entry table is driven through the Rsx_Externals::$_testing_entries seam, except
 * for the end-to-end case which reads the real manifest and asserts the framework's own
 * turnstile declaration was discovered. Mode is driven through Rsx::_testing_set_mode().
 *
 * Pure logic, no DB.
 */
class Externals_Resolver_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const MIRRORED_JS = 'https://cdn.example.com/lib/mirrored.js';
    private const MIRRORED_CSS = 'https://cdn.example.com/lib/mirrored.css';
    private const DIRECT_JS = 'https://widget.example.net/api.js?render=explicit';

    /**
     * Two entries covering the whole policy matrix: a mirrorable staff-only library and
     * an unmirrorable both-realms widget carrying runtime csp extras.
     */
    private static function _entries(): array
    {
        return [
            'mirrored' => [
                'identifier' => 'mirrored',
                'js' => [self::MIRRORED_JS],
                'css' => [self::MIRRORED_CSS],
                'integrity' => [self::MIRRORED_JS => 'sha384-mirrored'],
                'mirror' => true,
                'realm' => 'staff',
                'readiness' => 'onload',
                'csp' => [],
                'file' => 'fixture/mirrored.externals.php',
            ],
            'widget' => [
                'identifier' => 'widget',
                'js' => [self::DIRECT_JS],
                'css' => [],
                'integrity' => [self::DIRECT_JS => 'sha384-widget'],
                'mirror' => false,
                'realm' => 'both',
                'readiness' => ['callback_param' => 'onload'],
                'csp' => ['connect-src' => ['https://widget.example.net'], 'frame-src' => ['https://widget.example.net']],
                'file' => 'fixture/widget.externals.php',
            ],
        ];
    }

    /**
     * Run $fn with the fixture table installed and the process mode forced, then restore both.
     */
    private static function _with_entries(string $mode, callable $fn): void
    {
        Rsx_Externals::$_testing_entries = self::_entries();
        Rsx::_testing_set_mode($mode);

        try {
            $fn();
        } finally {
            Rsx::clear_mode_cache();
            Rsx_Externals::$_testing_entries = null;
        }
    }

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    public static function test_an_unknown_identifier_throws_and_points_at_the_man_page()
    {
        self::_with_entries(Rsx::MODE_DEVELOPMENT, function () {
            $exception = static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Externals::get('no_such_thing'),
                "Unknown external resource 'no_such_thing'"
            );

            static::__assert_contains('rsx:man external_resources', $exception->getMessage());
        });
    }

    // -------------------------------------------------------------------------
    // Realm filtering
    // -------------------------------------------------------------------------

    public static function test_realm_filtering_includes_both_but_not_the_other_realm()
    {
        self::_with_entries(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_equals(
                ['mirrored', 'widget'],
                array_keys(Rsx_Externals::all_for_realm('staff')),
                'staff sees its own entry plus every both-realm entry'
            );

            static::__assert_equals(
                ['widget'],
                array_keys(Rsx_Externals::all_for_realm('portal')),
                'portal never sees a staff-only entry'
            );
        });
    }

    public static function test_both_is_a_declaration_value_not_a_query_value()
    {
        self::_with_entries(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Externals::all_for_realm('both'),
                "Invalid realm 'both'"
            );
        });
    }

    // -------------------------------------------------------------------------
    // resolve_url
    // -------------------------------------------------------------------------

    public static function test_a_mirrored_url_resolves_to_vendor_in_every_mode()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_PRODUCTION] as $mode) {
            self::_with_entries($mode, function () use ($mode) {
                static::__assert_equals(
                    '/_vendor/' . Cdn_Cache::filename_for(self::MIRRORED_JS, 'js'),
                    Rsx_Externals::resolve_url(self::MIRRORED_JS, true),
                    "a mirrorable asset collapses to the local copy in {$mode}"
                );

                static::__assert_equals(
                    '/_vendor/' . Cdn_Cache::filename_for(self::MIRRORED_CSS, 'css'),
                    Rsx_Externals::resolve_url(self::MIRRORED_CSS, true),
                    "the css type is derived from the URL path in {$mode}"
                );
            });
        }
    }

    public static function test_a_mirror_false_url_stays_external_in_every_mode()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_PRODUCTION] as $mode) {
            self::_with_entries($mode, function () use ($mode) {
                static::__assert_equals(
                    self::DIRECT_JS,
                    Rsx_Externals::resolve_url(self::DIRECT_JS, false),
                    "mirror:false is the only way an asset stays external ({$mode})"
                );
            });
        }
    }

    public static function test_the_resolved_vendor_name_obeys_the_cdn_cache_naming_rule()
    {
        self::_with_entries(Rsx::MODE_DEVELOPMENT, function () {
            $resolved = Rsx_Externals::resolve_url(self::MIRRORED_CSS, true);

            static::__assert_true(
                str_starts_with($resolved, '/_vendor/'),
                'a mirrored asset is always served from our own /_vendor/ route'
            );
            static::__assert_true(
                (bool) preg_match(Cdn_Cache::FILENAME_PATTERN, substr($resolved, strlen('/_vendor/'))),
                'the emitted name is exactly what the /_vendor/ route admits'
            );
        });
    }

    // -------------------------------------------------------------------------
    // resolved_map_for_realm
    // -------------------------------------------------------------------------

    public static function test_the_client_map_keeps_integrity_for_an_externally_fetched_url()
    {
        self::_with_entries(Rsx::MODE_DEVELOPMENT, function () {
            $map = Rsx_Externals::resolved_map_for_realm('staff');

            static::__assert_equals(['mirrored', 'widget'], array_keys($map), 'deterministic key order');
            static::__assert_equals([self::DIRECT_JS], $map['widget']['js'], 'a mirror:false url is handed over raw');
            static::__assert_equals(
                [self::DIRECT_JS => 'sha384-widget'],
                $map['widget']['integrity'],
                'a URL still fetched from its vendor keeps its subresource hash'
            );
            static::__assert_equals('onload', $map['mirrored']['readiness']);
            static::__assert_equals(['callback_param' => 'onload'], $map['widget']['readiness']);
        });
    }

    public static function test_a_mirrored_url_drops_its_integrity_hash_in_every_mode()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_PRODUCTION] as $mode) {
            self::_with_entries($mode, function () use ($mode) {
                $map = Rsx_Externals::resolved_map_for_realm('staff');

                static::__assert_equals(
                    ['/_vendor/' . Cdn_Cache::filename_for(self::MIRRORED_JS, 'js')],
                    $map['mirrored']['js'],
                    "the mirrored copy is what the client loads in {$mode}"
                );
                static::__assert_equals(
                    [],
                    $map['mirrored']['integrity'],
                    "a same-origin copy carries no external subresource hash ({$mode})"
                );
            });
        }
    }

    // -------------------------------------------------------------------------
    // csp_hosts_for_realm
    // -------------------------------------------------------------------------

    public static function test_only_a_mirror_false_origin_is_whitelisted_and_the_policy_is_mode_free()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_PRODUCTION] as $mode) {
            self::_with_entries($mode, function () use ($mode) {
                $directives = Rsx_Externals::csp_hosts_for_realm('staff');

                static::__assert_equals(
                    ['connect-src', 'frame-src', 'script-src'],
                    array_keys($directives),
                    "directives are ksorted and the mirrored entry contributes none ({$mode})"
                );
                static::__assert_equals(
                    ['https://widget.example.net'],
                    $directives['script-src'],
                    "only the origin the browser still contacts is permitted ({$mode})"
                );
                static::__assert_false(
                    isset($directives['style-src']),
                    "the only external stylesheet is mirrored, so style-src gains nothing ({$mode})"
                );
                static::__assert_equals(
                    ['https://widget.example.net'],
                    $directives['connect-src'],
                    "declared runtime extras apply in every mode ({$mode})"
                );
            });
        }
    }

    public static function test_a_realm_only_sees_its_own_entries_csp()
    {
        self::_with_entries(Rsx::MODE_DEVELOPMENT, function () {
            $directives = Rsx_Externals::csp_hosts_for_realm('portal');

            static::__assert_equals(
                ['https://widget.example.net'],
                $directives['script-src'],
                'the staff-only library contributes nothing to the portal policy'
            );
        });
    }

    public static function test_an_origin_never_carries_a_path_or_query()
    {
        static::__assert_equals(
            'https://widget.example.net',
            Rsx_Externals::url_origin(self::DIRECT_JS)
        );
        static::__assert_equals(
            'https://widget.example.net:8443',
            Rsx_Externals::url_origin('https://widget.example.net:8443/api.js'),
            'an explicit port is part of the origin'
        );
    }

    // -------------------------------------------------------------------------
    // End to end against the real manifest
    // -------------------------------------------------------------------------

    public static function test_the_turnstile_declaration_is_discovered_from_the_manifest()
    {
        $entry = Rsx_Externals::get('turnstile');

        static::__assert_equals('app/RSpade/Core/Js/turnstile.externals.php', $entry['file']);
        static::__assert_count(1, $entry['js']);
        static::__assert_contains('https://challenges.cloudflare.com/turnstile/v0/api.js', $entry['js'][0]);
        static::__assert_contains('onload=__rsx_turnstile_onload', $entry['js'][0], 'the named-global handshake is preserved');
        static::__assert_false($entry['mirror'], 'Cloudflare serves api.js dynamically; it cannot be mirrored');
        static::__assert_equals('both', $entry['realm']);
        static::__assert_equals(['callback_param' => 'onload'], $entry['readiness']);
        static::__assert_equals(['https://challenges.cloudflare.com'], $entry['csp']['frame-src']);

        static::__assert_true(
            isset(Rsx_Externals::all_for_realm('portal')['turnstile']),
            'a both-realm entry reaches the portal policy too'
        );
    }

    /**
     * The template app's own declaration (rsx/lib/analytics/) - an APP declaring an external
     * beside the feature that loads it, which is the pattern downstream code copies.
     */
    public static function test_the_template_analytics_declaration_is_discovered_from_the_manifest()
    {
        $entry = Rsx_Externals::get('analytics');

        static::__assert_equals('rsx/lib/analytics/analytics.externals.php', $entry['file']);
        static::__assert_equals(['https://www.googletagmanager.com/gtag/js'], $entry['js'], 'the declared URL is bare and static - the measurement id is a runtime argument, never part of the declaration');
        static::__assert_false($entry['mirror'], 'Google serves gtag.js dynamically; it cannot be mirrored');
        static::__assert_equals('onload', $entry['readiness']);
        static::__assert_equals([], $entry['csp'], "gtag.js's transitive origins cannot be enumerated here - they are config csp.additional_sources");
    }

    /**
     * A staff-realm declaration is invisible to the portal: the portal bundle includes the
     * same rsx/lib directory, so only the realm keeps its map (and its policy) clean.
     */
    public static function test_the_template_analytics_declaration_is_staff_only()
    {
        static::__assert_equals('staff', Rsx_Externals::get('analytics')['realm']);

        static::__assert_true(
            isset(Rsx_Externals::all_for_realm('staff')['analytics']),
            'the staff realm sees its own declaration'
        );
        static::__assert_false(
            isset(Rsx_Externals::all_for_realm('portal')['analytics']),
            'the portal realm never inherits a staff declaration'
        );
        static::__assert_false(
            isset(Rsx_Externals::resolved_map_for_realm('portal')['analytics']),
            'and it is not baked into the portal bundle map either'
        );
    }
}
