<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Csp\Php;

use RuntimeException;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Csp composes ONE policy per realm out of four inputs: the framework's own hardened
 * defaults, the nonce, the declared external registry (mirror:false asset origins plus every
 * entry's csp extras), and config. Every case below pins one of those seams, driving the
 * registry through Rsx_Externals::$_testing_entries and the rollout switches through config()
 * - never through whatever the tree happens to declare.
 *
 * Pure logic, no DB.
 */
class Csp_Compose_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const STAFF_JS = 'https://staff.example.com/lib.js';
    private const BOTH_JS = 'https://widget.example.net/api.js';
    private const BOTH_CSS = 'https://widget.example.net/api.css';

    /**
     * A staff-only script entry and a both-realms entry that also carries runtime csp extras.
     */
    private static function _entries(): array
    {
        return [
            'staff_lib' => [
                'identifier' => 'staff_lib',
                'js' => [self::STAFF_JS],
                'css' => [],
                'integrity' => [],
                'mirror' => false,
                'realm' => 'staff',
                'readiness' => 'onload',
                'csp' => [],
                'file' => 'fixture/staff_lib.externals.php',
            ],
            'widget' => [
                'identifier' => 'widget',
                'js' => [self::BOTH_JS],
                'css' => [self::BOTH_CSS],
                'integrity' => [],
                'mirror' => false,
                'realm' => 'both',
                'readiness' => 'onload',
                'csp' => ['frame-src' => ['https://widget.example.net']],
                'file' => 'fixture/widget.externals.php',
            ],
        ];
    }

    /**
     * Run $fn against the fixture registry with a known config, restoring everything after.
     *
     * The request-scoped nonce is cleared on BOTH sides: a PHP test process is one long
     * request, so state left behind by one case would otherwise be read by the next.
     */
    private static function _with(array $config, callable $fn): void
    {
        $keys = ['rsx.csp.enabled', 'rsx.csp.additional_sources', 'rsx.realtime.enabled'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = config($key);
        }

        config(array_merge([
            'rsx.csp.enabled' => true,
            'rsx.csp.additional_sources' => [],
            'rsx.realtime.enabled' => false,
        ], $config));

        Rsx_Externals::$_testing_entries = self::_entries();
        Rsx_Csp::_reset_request_state();

        try {
            $fn();
        } finally {
            Rsx_Csp::_reset_request_state();
            Rsx_Externals::$_testing_entries = null;
            config($saved);
        }
    }

    /**
     * directive => [sources] parsed back out of a composed header value.
     */
    private static function _parse(string $value): array
    {
        $directives = [];

        foreach (explode('; ', $value) as $part) {
            $tokens = explode(' ', $part);
            $directive = array_shift($tokens);
            $directives[$directive] = $tokens;
        }

        return $directives;
    }

    // -------------------------------------------------------------------------
    // Shape
    // -------------------------------------------------------------------------

    public static function test_the_staff_policy_carries_the_hardened_defaults_and_the_nonce()
    {
        self::_with([], function () {
            $policy = Rsx_Csp::compose('staff');
            $directives = self::_parse($policy['value']);

            static::__assert_equals(["'self'"], $directives['default-src'], "default-src is 'self'");
            static::__assert_equals(["'none'"], $directives['object-src'], "object-src is 'none'");
            static::__assert_equals(["'self'"], $directives['base-uri'], "base-uri is 'self'");
            static::__assert_equals(["'self'"], $directives['frame-ancestors'], "frame-ancestors is 'self'");
            static::__assert_equals(["'self'", 'data:'], $directives['img-src'], 'img-src permits data: URIs');

            static::__assert_contains("'nonce-" . Rsx_Csp::nonce() . "'", $policy['value'], 'the nonce is in script-src');
            static::__assert_false(
                in_array("'unsafe-inline'", $directives['script-src'], true),
                "script-src never carries 'unsafe-inline'"
            );

            static::__assert_contains("'unsafe-inline'", implode(' ', $directives['style-src']), 'style-src keeps unsafe-inline');
            static::__assert_false(
                str_contains(implode(' ', $directives['style-src']), 'nonce-'),
                "style-src carries NO nonce - a nonce there makes browsers ignore 'unsafe-inline'"
            );

            static::__assert_contains('report-uri ' . Rsx_Csp::REPORT_PATH, $policy['value'], 'reports have a destination');
        });
    }

    public static function test_declared_externals_reach_the_realm_that_declared_them()
    {
        self::_with([], function () {
            $staff = self::_parse(Rsx_Csp::compose('staff')['value']);

            static::__assert_contains('https://staff.example.com', implode(' ', $staff['script-src']));
            static::__assert_contains('https://widget.example.net', implode(' ', $staff['script-src']));

            $portal = self::_parse(Rsx_Csp::compose('portal')['value']);

            static::__assert_false(
                str_contains(implode(' ', $portal['script-src']), 'https://staff.example.com'),
                'the portal policy never inherits a staff-only origin'
            );
            static::__assert_contains('https://widget.example.net', implode(' ', $portal['script-src']));
        });
    }

    public static function test_an_entrys_runtime_csp_extras_create_the_directive_seeded_with_self()
    {
        self::_with([], function () {
            $directives = self::_parse(Rsx_Csp::compose('staff')['value']);

            static::__assert_equals(
                ["'self'", 'https://widget.example.net'],
                $directives['frame-src'],
                "a created directive is seeded with 'self' so it never narrows the default-src fallback it replaced"
            );
        });
    }

    /**
     * There is no style-src -> font-src heuristic any more. A mirrored stylesheet's fonts are
     * mirrored with it and are same-origin; a mirror:false stylesheet whose fonts live on
     * another host declares them itself in its `csp => ['font-src' => [...]]` extras.
     */
    public static function test_a_stylesheet_origin_joins_style_src_and_not_font_src()
    {
        self::_with([], function () {
            $directives = self::_parse(Rsx_Csp::compose('staff')['value']);

            static::__assert_contains(
                'https://widget.example.net',
                implode(' ', $directives['style-src']),
                'a mirror:false stylesheet is still fetched from its own host'
            );
            static::__assert_equals(
                ["'self'", 'data:'],
                $directives['font-src'],
                'font-src is untouched - a font host is declared, never derived'
            );
        });
    }

    /**
     * A bundle's cdn_assets are mirrored and served from /_vendor/ in EVERY mode, so they
     * contribute no origin at all: the composed script-src is exactly the hardened base plus
     * the declared mirror:false origins, on a development box and in a sealed build alike.
     */
    public static function test_bundle_cdn_assets_contribute_no_origin_in_any_mode()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_PRODUCTION] as $mode) {
            Rsx::_testing_set_mode($mode);

            try {
                self::_with([], function () use ($mode) {
                    $directives = self::_parse(Rsx_Csp::compose('staff')['value']);

                    static::__assert_equals(
                        ["'self'", "'nonce-" . Rsx_Csp::nonce() . "'", 'https://staff.example.com', 'https://widget.example.net'],
                        $directives['script-src'],
                        "script-src is the base plus the declared mirror:false origins, nothing else ({$mode})"
                    );
                });
            } finally {
                Rsx::clear_mode_cache();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Realtime
    // -------------------------------------------------------------------------

    public static function test_realtime_off_leaves_connect_src_at_self()
    {
        self::_with(['rsx.realtime.enabled' => false], function () {
            $directives = self::_parse(Rsx_Csp::compose('staff')['value']);

            static::__assert_equals(["'self'"], $directives['connect-src'], 'no websocket origin when realtime is off');
        });
    }

    public static function test_realtime_on_whitelists_wss_and_the_dev_ws_downgrade()
    {
        self::_with(['rsx.realtime.enabled' => true], function () {
            $host = Rsx::get_hostname();
            $directives = self::_parse(Rsx_Csp::compose('staff')['value']);

            static::__assert_contains('wss://' . $host, implode(' ', $directives['connect-src']));

            // Rsx_Realtime._connect_url downgrades to ws:// whenever the page is plain http,
            // which is possible in every mode except strict production.
            static::__assert_equals(
                !Rsx::is_production(),
                in_array('ws://' . $host, $directives['connect-src'], true),
                'ws:// is whitelisted exactly where the client may downgrade to it'
            );
        });
    }

    // -------------------------------------------------------------------------
    // The header name, and the kill-switch
    // -------------------------------------------------------------------------

    /**
     * The policy always ENFORCES: one header name, no observe-only mode, and the report-uri
     * still names the collector so a blocked resource is recorded as well as refused.
     */
    public static function test_the_policy_always_enforces()
    {
        self::_with([], function () {
            $policy = Rsx_Csp::compose('staff');

            static::__assert_equals('Content-Security-Policy', $policy['header']);
            static::__assert_equals(Rsx_Csp::HEADER_ENFORCE, $policy['header']);
            static::__assert_contains(
                'report-uri ' . Rsx_Csp::REPORT_PATH,
                $policy['value'],
                'enforcing still reports - the log is the triage tool'
            );

            $response = response('<html></html>');
            Rsx_Csp::apply_to_response($response, 'staff');

            static::__assert_true($response->headers->has('Content-Security-Policy'));
            static::__assert_false(
                $response->headers->has('Content-Security-Policy-Report-Only'),
                'the report-only header is never emitted'
            );
        });
    }

    /**
     * An app config left carrying the retired `report_only` key changes nothing.
     *
     * The rsx config is a plain deep merge with no schema, so an unknown key is inert - it
     * cannot resurrect the observe-only mode by accident, and it cannot error either.
     */
    public static function test_a_stray_report_only_config_key_is_inert()
    {
        self::_with(['rsx.csp.report_only' => true], function () {
            static::__assert_equals(Rsx_Csp::HEADER_ENFORCE, Rsx_Csp::compose('staff')['header']);
        });

        config(['rsx.csp.report_only' => null]);
    }

    public static function test_disabled_composes_nothing_and_stamps_nothing()
    {
        self::_with(['rsx.csp.enabled' => false], function () {
            static::__assert_null(Rsx_Csp::compose('staff'), 'a disabled policy is not composed at all');

            $response = response('<html></html>');
            Rsx_Csp::apply_to_response($response, 'staff');

            static::__assert_false(
                $response->headers->has(Rsx_Csp::HEADER_ENFORCE),
                'and no header reaches the response'
            );
        });
    }

    // -------------------------------------------------------------------------
    // Config widening
    // -------------------------------------------------------------------------

    public static function test_additional_sources_append_and_never_replace()
    {
        self::_with(['rsx.csp.additional_sources' => ['script-src' => ['https://transitive.example.org']]], function () {
            $directives = self::_parse(Rsx_Csp::compose('staff')['value']);

            static::__assert_contains('https://transitive.example.org', implode(' ', $directives['script-src']));
            static::__assert_contains("'self'", implode(' ', $directives['script-src']), 'the framework sources survive');
            static::__assert_contains('https://staff.example.com', implode(' ', $directives['script-src']), 'declared origins survive');
        });
    }

    public static function test_widening_object_src_is_refused_loudly()
    {
        self::_with(['rsx.csp.additional_sources' => ['object-src' => ['https://plugins.example.org']]], function () {
            $exception = static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Csp::compose('staff'),
                "Invalid csp.additional_sources directive 'object-src'"
            );

            static::__assert_contains('rsx:man csp', $exception->getMessage());
        });
    }

    // -------------------------------------------------------------------------
    // The nonce
    // -------------------------------------------------------------------------

    public static function test_the_nonce_is_stable_within_a_request_and_fresh_after_a_reset()
    {
        self::_with([], function () {
            $first = Rsx_Csp::nonce();

            static::__assert_equals($first, Rsx_Csp::nonce(), 'every emitter in one request stamps the same value');
            static::__assert_contains("'nonce-{$first}'", Rsx_Csp::compose('staff')['value'], 'and the header carries it');

            Rsx_Csp::_reset_request_state();

            static::__assert_not_equals($first, Rsx_Csp::nonce(), 'a new request mints a new one');
        });
    }

    // -------------------------------------------------------------------------
    // Response stamping
    // -------------------------------------------------------------------------

    public static function test_only_html_responses_are_stamped()
    {
        self::_with([], function () {
            $html = response('<html></html>');
            Rsx_Csp::apply_to_response($html, 'staff');
            static::__assert_true($html->headers->has(Rsx_Csp::HEADER_ENFORCE), 'an undeclared type defaults to html');

            $typed = response('<html></html>')->header('Content-Type', 'text/html; charset=UTF-8');
            Rsx_Csp::apply_to_response($typed, 'staff');
            static::__assert_true($typed->headers->has(Rsx_Csp::HEADER_ENFORCE), 'declared text/html is stamped');

            $json = response()->json(['ok' => true]);
            Rsx_Csp::apply_to_response($json, 'staff');
            static::__assert_false($json->headers->has(Rsx_Csp::HEADER_ENFORCE), 'a JSON envelope carries no policy');
        });
    }

    public static function test_a_response_that_already_declares_a_policy_is_left_alone()
    {
        self::_with([], function () {
            $response = response('<html></html>')->header(Rsx_Csp::HEADER_ENFORCE, "default-src 'none'");
            Rsx_Csp::apply_to_response($response, 'staff');

            static::__assert_equals(
                "default-src 'none'",
                $response->headers->get(Rsx_Csp::HEADER_ENFORCE),
                "AssetHandler's own static-HTML policy is never overwritten"
            );
            static::__assert_false(
                $response->headers->has('Content-Security-Policy-Report-Only'),
                'and no second policy header is added beside it'
            );
        });
    }

    // -------------------------------------------------------------------------
    // End to end against the real registry
    // -------------------------------------------------------------------------

    /**
     * Run $fn against whatever THIS TREE declares - no fixture registry - so the wiring from
     * a *.externals.php file all the way to a composed header is proven, not simulated.
     */
    private static function _with_real_registry(callable $fn): void
    {
        $keys = ['rsx.csp.enabled', 'rsx.csp.additional_sources'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = config($key);
        }

        config(['rsx.csp.enabled' => true, 'rsx.csp.additional_sources' => []]);
        Rsx_Csp::_reset_request_state();

        try {
            $fn();
        } finally {
            Rsx_Csp::_reset_request_state();
            config($saved);
        }
    }

    /**
     * The template's staff-only analytics declaration reaches the staff policy and stops there.
     * Its mirror:false means the origin is whitelisted in every mode, sealed builds included.
     */
    public static function test_the_template_analytics_origin_reaches_the_staff_policy_only()
    {
        self::_with_real_registry(function () {
            $staff = self::_parse(Rsx_Csp::compose('staff')['value']);
            $portal = self::_parse(Rsx_Csp::compose('portal')['value']);

            static::__assert_true(
                in_array('https://www.googletagmanager.com', $staff['script-src'], true),
                'the declared gtag.js origin is derived into the staff script-src'
            );
            static::__assert_false(
                in_array('https://www.googletagmanager.com', $portal['script-src'], true),
                'a staff declaration never widens the portal policy'
            );

            // Transitive Google origins are NOT derivable - config additional_sources is the
            // only way they can appear, and this template ships with that stanza commented out.
            static::__assert_false(
                in_array('https://www.google-analytics.com', $staff['script-src'], true),
                'what gtag.js loads at runtime is config, never a derived source'
            );
        });
    }
}
