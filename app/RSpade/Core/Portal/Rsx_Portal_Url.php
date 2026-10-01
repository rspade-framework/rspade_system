<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Env\Rsx_App_Url;

/**
 * Rsx_Portal_Url - where the client portal lives, derived from ONE setting.
 *
 * config('rsx.portal.url') (fed from PORTAL_URL) is written exactly like APP_URL: a
 * scheme, a host, an optional port, an optional path, the $HOSTNAME token. Blank means
 * APP_URL's origin plus '/_portal'. Everything else about the portal's address is DERIVED
 * here, and nowhere else:
 *
 *   origin()            scheme://host[:port], the default port dropped
 *   host()              the lowercased host, no port
 *   prefix()            '' or '/seg[/seg]', no trailing slash - the path the portal
 *                       lives under on that host
 *   is_separate_host()  the portal host differs from APP_URL's host (ports are not part
 *                       of the comparison: two ports on one hostname are one host)
 *
 *   PORTAL_URL                          origin                     prefix     separate
 *   (blank)                             APP_URL's origin           /_portal   no
 *   https://myapp.com/clients           https://myapp.com          /clients   no
 *   https://portal.myapp.com/           https://portal.myapp.com   ''         yes
 *   https://portal.myapp.com/x          https://portal.myapp.com   /x         yes
 *
 * parse() and check() are pure; the accessors read the live configuration. validate()
 * is the boot guard (Rsx_Framework_Provider::boot()), and the "Portal URL" rsx:health
 * row reports the same derivation and the same refusals.
 *
 * Request classification (Rsx_Request_Channel) and URL generation (Rsx_Portal::Route())
 * read these values; see rsx:man portal.
 */
class Rsx_Portal_Url
{
    /**
     * The prefix a blank PORTAL_URL derives under APP_URL's origin.
     */
    public const DEFAULT_PREFIX = '/_portal';

    /**
     * First path segments a portal prefix may not claim, beyond the framework's '_' names:
     * the external API namespace, the error-page namespace and the realtime socket.
     */
    private const RESERVED_SEGMENTS = ['api', 'error', 'ws'];

    /**
     * parse() results, keyed by the two inputs - the accessors ask several times per request.
     */
    private static array $__parsed = [];

    // =========================================================================
    // The live derivation
    // =========================================================================

    /**
     * The effective portal URL: origin + prefix ('' while APP_URL is empty and
     * PORTAL_URL is blank - the development first-run state).
     */
    public static function url(): string
    {
        return static::__current()['url'];
    }

    /**
     * scheme://host[:port] of the portal, the default port dropped.
     */
    public static function origin(): string
    {
        return static::__current()['origin'];
    }

    /**
     * The portal's host, lowercased, without a port.
     */
    public static function host(): string
    {
        return static::__current()['host'];
    }

    /**
     * The path the portal lives under on its host: '' or '/seg[/seg]', no trailing slash.
     */
    public static function prefix(): string
    {
        return static::__current()['prefix'];
    }

    /**
     * True when the portal is served on a host other than APP_URL's.
     */
    public static function is_separate_host(): bool
    {
        return static::__current()['separate_host'];
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * The boot guard: throw the first refusal check() finds in the configured values.
     * Called from Rsx_Framework_Provider::boot() beside the APP_URL scheme check, every
     * boot, web and CLI.
     *
     * @throws RuntimeException naming PORTAL_URL
     */
    public static function validate(): void
    {
        $problem = static::check(
            (string) config('rsx.portal.url'),
            (string) config('app.url'),
            Rsx_App_Url::http_allowed()
        );

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    /**
     * Every refusal, as a message naming PORTAL_URL, or null when the value is usable.
     * Pure. Skipped entirely while APP_URL is empty (the development first-run state),
     * because nothing can be compared against it.
     *
     * Refused: a URL that is not absolute http(s); a scheme APP_URL's rule refuses
     * (Rsx_App_Url::scheme_problem() - https outside development); credentials, a query
     * or a fragment; a path segment outside [A-Za-z0-9_-]; a first segment the framework
     * owns ('api', 'error', 'ws', or any '_' name other than '_portal' - a '/_' path is
     * framework-owned); and a URL equal to APP_URL - the same host and the same path,
     * whatever the scheme, port or trailing slash.
     *
     * A prefix that shadows an APPLICATION staff route is allowed: on that host the portal
     * wins.
     *
     * @param string $portal_url The configured PORTAL_URL ($HOSTNAME already resolved)
     * @param string $app_url The configured APP_URL
     * @param bool $allow_http Whether this process accepts http (Rsx_App_Url::http_allowed())
     * @return string|null
     */
    public static function check(string $portal_url, string $app_url, bool $allow_http): ?string
    {
        if (trim($app_url) === '' || trim($portal_url) === '') {
            return null;
        }

        $parts = parse_url(trim($portal_url));

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'PORTAL_URL must be an absolute http:// or https:// URL, like APP_URL (or blank,'
                . ' which serves the portal at APP_URL + ' . self::DEFAULT_PREFIX . ').'
                . ' Current value: "' . $portal_url . '".';
        }

        $scheme_problem = Rsx_App_Url::scheme_problem(trim($portal_url), $allow_http, 'PORTAL_URL');
        if ($scheme_problem !== null) {
            return $scheme_problem;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'PORTAL_URL may carry only a scheme, a host, a port and a path - no credentials,'
                . ' query string or fragment. Current value: "' . $portal_url . '".';
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        if ($path !== '') {
            $segments = explode('/', substr($path, 1));

            foreach ($segments as $segment) {
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                    return 'PORTAL_URL path segments may contain only letters, digits, "_" and "-"'
                        . ' (an empty segment is refused too). Current value: "' . $portal_url . '".';
                }
            }

            $first = strtolower($segments[0]);

            if (in_array($first, self::RESERVED_SEGMENTS, true)
                || (str_starts_with($first, '_') && '/' . $first !== self::DEFAULT_PREFIX)) {
                return 'PORTAL_URL may not place the portal under /' . $segments[0] . ' - that path belongs'
                    . ' to the framework (/api is the external API, /error the error pages, /ws the realtime'
                    . ' socket, and every /_ path is framework-owned; ' . self::DEFAULT_PREFIX . ' is the one'
                    . ' underscore prefix a portal may use). Current value: "' . $portal_url . '".';
            }
        }

        $portal = static::parse($portal_url, $app_url);
        $app = static::__split($app_url);

        if ($portal['host'] === $app['host'] && $portal['prefix'] === $app['path']) {
            return 'PORTAL_URL must not equal APP_URL: the portal needs its own host, or a path under'
                . ' APP_URL\'s host (leave PORTAL_URL blank to serve it at APP_URL + ' . self::DEFAULT_PREFIX . ').'
                . ' Current values: PORTAL_URL="' . $portal_url . '", APP_URL="' . $app_url . '".';
        }

        return null;
    }

    /**
     * Derive the portal's address from the two settings. Pure; never throws (check() is
     * the judge of a malformed value - a value it would refuse still derives something).
     *
     * @param string $portal_url PORTAL_URL ('' = APP_URL's origin + DEFAULT_PREFIX)
     * @param string $app_url APP_URL
     * @return array{url: string, origin: string, host: string, prefix: string, separate_host: bool}
     */
    public static function parse(string $portal_url, string $app_url): array
    {
        $app = static::__split($app_url);

        if (trim($portal_url) === '') {
            $portal = $app;
            $portal['path'] = self::DEFAULT_PREFIX;
        } else {
            $portal = static::__split($portal_url);
        }

        $origin = $portal['host'] === ''
            ? ''
            : $portal['scheme'] . '://' . $portal['host'] . ($portal['port'] !== null ? ':' . $portal['port'] : '');

        return [
            'url' => $origin . $portal['path'],
            'origin' => $origin,
            'host' => $portal['host'],
            'prefix' => $portal['path'],
            'separate_host' => $portal['host'] !== $app['host'],
        ];
    }

    // =========================================================================
    // rsx:health
    // =========================================================================

    /**
     * The derived portal address, or the boot guard's refusal.
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    #[Health_Check('Portal URL')]
    public static function health_row(): array
    {
        $portal_url = (string) config('rsx.portal.url');
        $app_url = (string) config('app.url');

        $problem = static::check($portal_url, $app_url, Rsx_App_Url::http_allowed());

        if ($problem !== null) {
            return [
                'status' => 'FAIL',
                'detail' => $problem,
                'remediation' => 'correct PORTAL_URL in .env, or leave it blank to serve the portal at APP_URL + '
                    . self::DEFAULT_PREFIX . ' (rsx:man portal)',
            ];
        }

        $parsed = static::parse($portal_url, $app_url);

        if ($parsed['origin'] === '') {
            return [
                'status' => 'OK',
                'detail' => 'APP_URL is not configured yet; the portal will be served at APP_URL + ' . $parsed['prefix'],
                'remediation' => null,
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $parsed['origin'] . ' prefix ' . ($parsed['prefix'] === '' ? '(none)' : $parsed['prefix'])
                . ($parsed['separate_host'] ? ' - its own host' : ' - on the application host'),
            'remediation' => null,
        ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * parse() of the live configuration.
     */
    private static function __current(): array
    {
        $portal_url = (string) config('rsx.portal.url');
        $app_url = (string) config('app.url');
        $key = $portal_url . "\n" . $app_url;

        return static::$__parsed[$key] ??= static::parse($portal_url, $app_url);
    }

    /**
     * A URL's normalised parts: scheme and host lowercased, the scheme's default port
     * dropped, the path without its trailing slash. A value parse_url() cannot read
     * yields empty parts.
     *
     * @return array{scheme: string, host: string, port: ?int, path: string}
     */
    private static function __split(string $url): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false) {
            $parts = [];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower((string) ($parts['host'] ?? '')),
            'port' => $port,
            'path' => rtrim((string) ($parts['path'] ?? ''), '/'),
        ];
    }
}
