<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use RuntimeException;
use App\RSpade\Core\Rsx;

/**
 * Dev-mode .env hostname tripwire.
 *
 * A copy-pasted .env can declare an APP_URL host that belongs to a DIFFERENT
 * instance than the one the site is actually browsed under. When both names
 * resolve to the same outer reverse proxy, every observable signal looks healthy
 * while generated URLs (and the derived realtime relay URL wss://{host}/ws) point
 * at the wrong box. This guard fails loud on that mismatch during development.
 *
 * THE DECLARED HOSTS are the APP_URL host and, when PORTAL_URL names a host of its
 * own, the portal's host (Rsx_Portal_Url). A request passes when its host equals ANY
 * declared host. Hosts are compared without ports, exactly as cookies are scoped.
 *
 * check() runs once per web request (immediately before route dispatch). It is a
 * DEVELOPMENT-mode tripwire only - debug and production validate the request host in
 * Rsx::get_hostname() (APP_URL host, its sub-hosts, the PORTAL_URL host) - so the guard
 * is gated on RSX_MODE, not on is_dev_site(). Loopback REQUESTS (localhost, a parsed
 * 127.0.0.0/8 address, ::1 - the curl/rsx:debug testing channel) never trip it; links
 * that leave the browser are still built from APP_URL for them (rsx_absolute_url()).
 * A loopback-VALUED APP_URL is NOT exempted: an APP_URL=https://localhost browsed under
 * a real hostname is exactly the pasted .env this guard exists to catch, so it fatals.
 *
 * The pure comparison core (build_declared + find_mismatch) takes plain inputs and
 * touches neither env nor $_SERVER, so it is unit-testable in isolation.
 */
class Rsx_Env_Hostname_Guard
{
    /**
     * Memoize the per-request decision. A passing (or bailed) check sets this so
     * repeat calls in one request are free; a mismatch throws before it is set, so
     * the fatal is raised on every call until .env is fixed.
     */
    private static bool $_checked = false;

    /**
     * Per-request memo of the collected declared hosts (env is read once).
     * @var array<int,array{var:string,host:string}>|null
     */
    private static ?array $_declared_cache = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Per-request entry point. Silently returns when the guard does not apply
     * (CLI, non-development mode, no HTTP host, or a loopback request); otherwise
     * throws RuntimeException when the request host matches no declared host.
     */
    public static function check(): void
    {
        if (self::$_checked) {
            return;
        }

        // CLI has no request host to check against.
        if (php_sapi_name() === 'cli') {
            self::$_checked = true;
            return;
        }

        // Development mode only: prod sites may answer on many hostnames / CDNs.
        if (!Rsx::is_development()) {
            self::$_checked = true;
            return;
        }

        if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
            self::$_checked = true;
            return;
        }

        $request_host = self::normalize_request_host($_SERVER['HTTP_HOST']);

        // The localhost testing channel (curl / rsx:debug) is always exempt.
        if (self::is_loopback_host($request_host)) {
            self::$_checked = true;
            return;
        }

        $declared = self::__collect_declared_hosts();
        $mismatch = self::find_mismatch($request_host, $declared);

        if ($mismatch !== null) {
            // NOTE: do NOT memoize on a mismatch - keep failing loud until fixed.
            throw new RuntimeException(self::mismatch_message($mismatch));
        }

        self::$_checked = true;
    }

    /**
     * Pure comparison core: given the (already normalized) request host and the
     * collected declared entries, return null when the request host equals ANY
     * declared host, else the mismatch (every declared entry and the request host).
     * No declared entry (APP_URL not configured yet) is never a mismatch.
     *
     * The match is EXACT - a sub-host of a declared host does NOT satisfy it. Each
     * declared entry is {var, host}.
     *
     * @param array<int,array{var:string,host:string}> $declared
     * @return array{declared:array<int,array{var:string,host:string}>,request_host:string}|null
     */
    public static function find_mismatch(string $request_host, array $declared): ?array
    {
        if ($declared === []) {
            return null;
        }

        foreach ($declared as $entry) {
            if ($request_host === $entry['host']) {
                return null;
            }
        }

        return [
            'declared' => $declared,
            'request_host' => $request_host,
        ];
    }

    /**
     * The fatal's text for a find_mismatch() result: the request host, every declared
     * host with the variable that declared it, and what to fix.
     *
     * @param array{declared:array<int,array{var:string,host:string}>,request_host:string} $mismatch
     */
    public static function mismatch_message(array $mismatch): string
    {
        $declared = [];
        foreach ($mismatch['declared'] as $entry) {
            $declared[] = $entry['var'] . ' host "' . $entry['host'] . '"';
        }

        $matches = count($declared) === 1
            ? 'does not match ' . $declared[0]
            : 'matches neither ' . implode(' nor ', $declared);

        return 'Dev-mode .env hostname mismatch: request host "' . $mismatch['request_host']
            . '" ' . $matches . '.'
            . ' The .env of this instance declares a different hostname than the one it is'
            . ' being browsed on - fix APP_URL (or PORTAL_URL, for the client portal\'s own host)'
            . ' in .env to match. [rsx:man realtime]';
    }

    /**
     * Pure declared-host builder: given the relevant raw .env values, produce the
     * normalized comparison entries - the APP_URL host, then the PORTAL_URL host when
     * it names a host other than APP_URL's (a same-host portal, or a blank PORTAL_URL,
     * adds nothing). An empty APP_URL yields no entry at all; a non-empty but
     * unparseable APP_URL or PORTAL_URL is a fatal misconfiguration (fail loud). A
     * loopback-VALUED APP_URL is NOT skipped.
     *
     * @param array{APP_URL?: ?string, PORTAL_URL?: ?string} $env
     * @return array<int,array{var:string,host:string}>
     */
    public static function build_declared(array $env): array
    {
        $declared = [];

        $app_url = trim((string) ($env['APP_URL'] ?? ''));
        if ($app_url === '') {
            return $declared;
        }

        $app_host = self::__host_of('APP_URL', $app_url);
        $declared[] = ['var' => 'APP_URL', 'host' => $app_host];

        $portal_url = trim((string) ($env['PORTAL_URL'] ?? ''));
        if ($portal_url !== '') {
            $portal_host = self::__host_of('PORTAL_URL', $portal_url);

            if ($portal_host !== $app_host) {
                $declared[] = ['var' => 'PORTAL_URL', 'host' => $portal_host];
            }
        }

        return $declared;
    }

    /**
     * A host is loopback when it is exactly "localhost", a syntactically valid dotted-quad
     * IPv4 address inside 127.0.0.0/8, or the IPv6 loopback "::1" (bracketed or not).
     * Only loopback REQUEST hosts are exempted; a loopback-valued APP_URL is a real
     * mismatch when browsed under a real host.
     *
     * The IPv4 test is a real address parse, never a string prefix: "127.attacker.example"
     * is a DNS name anybody can register, and a prefix test exempted it. Shorthand forms
     * ("127.1") and out-of-range octets ("127.0.0.256") are not valid dotted quads, so
     * they are not loopback and face the ordinary declared-host comparison.
     */
    public static function is_loopback_host(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === 'localhost' || $host === '::1' || $host === '[::1]') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return (ip2long($host) >> 24) === 127;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Gather the live values and build the declared entries once per request.
     * PORTAL_URL is read as configured (rsx.portal.url, $HOSTNAME already resolved).
     *
     * @return array<int,array{var:string,host:string}>
     */
    private static function __collect_declared_hosts(): array
    {
        if (self::$_declared_cache !== null) {
            return self::$_declared_cache;
        }

        self::$_declared_cache = self::build_declared([
            'APP_URL' => env('APP_URL'),
            'PORTAL_URL' => config('rsx.portal.url'),
        ]);

        return self::$_declared_cache;
    }

    /**
     * The lowercased host of a declared URL, or a fatal naming the variable when none
     * can be parsed.
     */
    private static function __host_of(string $var, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            throw new RuntimeException(
                'Dev-mode .env hostname guard: ' . $var . ' is set to "' . $url
                . '" but no host could be parsed from it - fix ' . $var . ' in .env'
                . ' (expected e.g. https://host). [rsx:man realtime]'
            );
        }

        return strtolower(trim($host));
    }

    /**
     * Normalize a raw HTTP_HOST to a bare lowercased hostname (port stripped),
     * matching the normalization Rsx::get_hostname() applies. Handles the IPv6
     * bracket form ("[::1]:6200"). Public so the comparison path is fully
     * unit-testable (the PHP test runner is CLI, where check() itself bails).
     */
    public static function normalize_request_host(string $raw): string
    {
        $host = strtolower(trim($raw));

        if ($host !== '' && $host[0] === '[') {
            // IPv6 literal, possibly with a port: "[::1]:6200" -> "::1".
            $close = strpos($host, ']');
            if ($close !== false) {
                return substr($host, 1, $close - 1);
            }
        }

        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        return $host;
    }

    /**
     * Reset the per-request memo. Used by tests so successive cases each run the
     * full check; never needed in normal request flow.
     */
    public static function _testing_reset(): void
    {
        self::$_checked = false;
        self::$_declared_cache = null;
    }
}
