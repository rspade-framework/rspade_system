<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_App_Url;
use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Portal_Site_Resolver;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Portal_Url - where the client portal lives, derived from ONE setting.
 *
 * config('rsx.portal.url') (fed from PORTAL_URL) is a TEMPLATE, written like APP_URL - a
 * scheme, a host, an optional port, an optional path, the $HOSTNAME token - or as a bare
 * path on APP_URL's host ('/clients'). Blank means APP_URL's origin plus '/_portal' in
 * single tenancy and '/_portal/:site_id' in multi tenancy (Rsx_Tenancy). Everything else
 * about the portal's address is DERIVED here, and nowhere else.
 *
 * In multi tenancy the template carries exactly one TENANT PLACEHOLDER - ':slug'
 * (sites.slug) or ':site_id' (sites.id) - either as the last path segment after a fixed
 * one ('/_portal/:slug') or as the host's first label ('https://:slug.myapp.com'). Single
 * tenancy refuses a placeholder.
 *
 *   PORTAL_URL                          mode  origin                     prefix           separate
 *   (blank, single)                     path  APP_URL's origin           /_portal         no
 *   /clients                            path  APP_URL's origin           /clients         no
 *   https://myapp.com/clients           path  https://myapp.com          /clients         no
 *   https://portal.myapp.com/           path  https://portal.myapp.com   ''               yes
 *   https://portal.myapp.com/x          path  https://portal.myapp.com   /x               yes
 *   (blank, multi)                      path  APP_URL's origin           /_portal/:site_id no
 *   /_portal/:slug                      path  APP_URL's origin           /_portal/:slug   no
 *   https://:slug.myapp.com             host  https://:slug.myapp.com    ''               yes
 *
 * THE PORTAL OF ONE SITE is for_site(): the template filled in with that site's slug or
 * id - or its custom domain (sites.portal_domain) when PORTAL_CUSTOM_DOMAINS allows - as
 * {origin, prefix, host, separate_host}, or FALSE when that site has no portal (the
 * feature off, the site unserved, under 'only' no domain, no site to ask about). "No
 * portal" is an expected answer, never an exception: every caller that can meet it handles
 * false. It is exactly the address Portal_Site_Resolver serves the site at.
 *
 *   for_site($id)       a named site - an admin screen or a task listing many sites
 *   for_site()          the CURRENT site: in a portal request the site its URL resolved to
 *                       (or the site a command or test declared through
 *                       Portal_Session::set_site_id()); elsewhere Session::get_site_id().
 *                       A single-tenant install's current site is always
 *                       Rsx_Tenancy::SINGLE_TENANT_SITE_ID.
 *
 * The single-valued accessors answer for the CURRENT site, and throw when it has no portal
 * (code that can meet "no portal" asks for_site() instead):
 *
 *   origin()            scheme://host[:port], the default port dropped
 *   host()              the lowercased host, no port
 *   prefix()            '' or '/seg[/seg]', no trailing slash - the path the portal
 *                       lives under on that host
 *   url()               origin + prefix
 *   is_separate_host()  the portal host differs from APP_URL's host (ports are not part
 *                       of the comparison: two ports on one hostname are one host)
 *
 * In single tenancy with the default columns these are exactly the template's values.
 * template() is the install-wide parse, placeholder and all.
 *
 * parse() and check() are pure. check() is judged at MANIFEST BUILD
 * (Portal_Url_ManifestSupport, every mode), together with the path-prefix collision with
 * staff routes; the "Portal URL" rsx:health row reports the same derivation and the same
 * refusals. fingerprint() is what the manifest stamps, so changing a portal setting
 * rebuilds a development box and is refused by a sealed one.
 *
 * Request classification reads the template through Portal_Site_Resolver (which site a
 * request serves), and URL generation (Rsx_Portal::Route(), its site_id: argument
 * included) reads for_site(); see rsx:man portal.
 */
class Rsx_Portal_Url
{
    /**
     * The prefix a blank PORTAL_URL derives under APP_URL's origin.
     */
    public const DEFAULT_PREFIX = '/_portal';

    /**
     * The template a blank PORTAL_URL derives in multi tenancy.
     */
    public const DEFAULT_MULTI_TEMPLATE = '/_portal/:site_id';

    /**
     * The two tenant placeholders, by the name parse() reports them under.
     */
    public const PLACEHOLDER_SLUG = 'slug';

    public const PLACEHOLDER_SITE_ID = 'site_id';

    /**
     * Where the placeholder sits.
     */
    public const MODE_PATH = 'path';

    public const MODE_HOST = 'host';

    /**
     * PORTAL_CUSTOM_DOMAINS values.
     */
    public const CUSTOM_DOMAINS_OFF = 'off';

    public const CUSTOM_DOMAINS_OVERRIDE = 'override';

    public const CUSTOM_DOMAINS_ONLY = 'only';

    /**
     * First path segments a portal prefix may not claim, beyond the framework's '_' names:
     * the external API namespace, the error-page namespace and the realtime socket.
     */
    private const RESERVED_SEGMENTS = ['api', 'error', 'ws'];

    /**
     * A placeholder token in a template: ':slug' or ':site_id', not followed by a name
     * character (':slugs' is not one).
     */
    private const PLACEHOLDER_PATTERN = '/:(slug|site_id)(?![A-Za-z0-9_])/';

    /**
     * What a placeholder is masked as while parse_url() reads the template: a valid host
     * label and path segment, so ':slug' in a host does not read as a port.
     */
    private const MASKS = [
        ':site_id' => 'rsx-tenant-placeholder-site-id',
        ':slug' => 'rsx-tenant-placeholder-slug',
    ];

    /**
     * parse() results, keyed by the three inputs - the accessors ask several times per request.
     */
    private static array $__parsed = [];

    // =========================================================================
    // The switches
    // =========================================================================

    /**
     * PORTAL_ENABLED: is the client portal on for this install? Blank is the default (on).
     *
     * @throws RuntimeException naming PORTAL_ENABLED when the value is not a boolean
     */
    public static function is_enabled(): bool
    {
        $value = config('rsx.portal.enabled');

        if ($value === null || $value === '') {
            return true;
        }

        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            throw new RuntimeException(
                'PORTAL_ENABLED must be true or false. Current value: "' . (is_scalar($value) ? (string) $value : get_debug_type($value)) . '".'
            );
        }

        return $enabled;
    }

    /**
     * PORTAL_CUSTOM_DOMAINS: whether sites.portal_domain is honoured - 'off', 'override' or
     * 'only'. Blank is the default ('off').
     *
     * @throws RuntimeException naming PORTAL_CUSTOM_DOMAINS when the value is anything else
     */
    public static function custom_domains(): string
    {
        $value = config('rsx.portal.custom_domains');

        if ($value === null || $value === '') {
            return self::CUSTOM_DOMAINS_OFF;
        }

        if (!in_array($value, [self::CUSTOM_DOMAINS_OFF, self::CUSTOM_DOMAINS_OVERRIDE, self::CUSTOM_DOMAINS_ONLY], true)) {
            throw new RuntimeException(
                'PORTAL_CUSTOM_DOMAINS must be "off", "override" or "only". Current value: "'
                . (is_scalar($value) ? (string) $value : get_debug_type($value)) . '". See rsx:man portal.'
            );
        }

        return $value;
    }

    // =========================================================================
    // The live derivation
    // =========================================================================

    /**
     * The current site's portal URL: origin + prefix ('' while APP_URL is empty and
     * PORTAL_URL is blank - the development first-run state).
     *
     * @throws RuntimeException when the current site has no portal (see for_site())
     */
    public static function url(): string
    {
        $address = static::__current_address('url');

        return $address['origin'] . $address['prefix'];
    }

    /**
     * scheme://host[:port] of the current site's portal, the default port dropped.
     *
     * @throws RuntimeException when the current site has no portal (see for_site())
     */
    public static function origin(): string
    {
        return static::__current_address('origin')['origin'];
    }

    /**
     * The current site's portal host, lowercased, without a port.
     *
     * @throws RuntimeException when the current site has no portal (see for_site())
     */
    public static function host(): string
    {
        return static::__current_address('host')['host'];
    }

    /**
     * The path the current site's portal lives under on its host: '' or '/seg[/seg]', no
     * trailing slash.
     *
     * @throws RuntimeException when the current site has no portal (see for_site())
     */
    public static function prefix(): string
    {
        return static::__current_address('prefix')['prefix'];
    }

    /**
     * True when the current site's portal is served on a host other than APP_URL's (always
     * for a host template or a custom domain).
     *
     * @throws RuntimeException when the current site has no portal (see for_site())
     */
    public static function is_separate_host(): bool
    {
        return static::__current_address('is_separate_host')['separate_host'];
    }

    /**
     * The portal address of one site, or false when it has no portal.
     *
     * False when: the portal is disabled (PORTAL_ENABLED=false); no site was named and none
     * is current; the site does not exist, is deleted, disabled (sites.is_enabled), has its
     * portal switched off (sites.is_portal_enabled) or is site 0; a single-tenant install is
     * asked about a site other than its one tenant; PORTAL_CUSTOM_DOMAINS=only and the site
     * has no portal_domain; or the site's slug cannot be written into the template's
     * address (a host label, a path segment).
     *
     * Otherwise, with PORTAL_CUSTOM_DOMAINS override or only and a sites.portal_domain:
     * https://<domain> with no prefix. Else the template with the placeholder filled in
     * (the slug lowercased, or the id). Reads the resolver's cached site lookups.
     *
     * @param int|null $site_id The site; null = the current site (see the class docblock)
     * @return array{origin: string, prefix: string, host: string, separate_host: bool}|false
     */
    public static function for_site(?int $site_id = null): array|false
    {
        if (!static::is_enabled()) {
            return false;
        }

        $site_id ??= static::current_site_id();

        if ($site_id === null) {
            return false;
        }

        if (!Rsx_Tenancy::is_multi() && $site_id !== Rsx_Tenancy::SINGLE_TENANT_SITE_ID) {
            return false;
        }

        $site = Portal_Site_Resolver::site($site_id);

        if (!Portal_Site_Resolver::is_servable($site)) {
            return false;
        }

        $custom_domains = static::custom_domains();

        if ($custom_domains !== self::CUSTOM_DOMAINS_OFF && $site['portal_domain'] !== null) {
            return [
                'origin' => 'https://' . $site['portal_domain'],
                'prefix' => '',
                'host' => $site['portal_domain'],
                'separate_host' => true,
            ];
        }

        if ($custom_domains === self::CUSTOM_DOMAINS_ONLY) {
            return false;
        }

        $template = static::__current();

        if ($template['placeholder'] === null) {
            return [
                'origin' => $template['origin'],
                'prefix' => $template['prefix'],
                'host' => $template['host'],
                'separate_host' => $template['separate_host'],
            ];
        }

        $token = $template['placeholder'] === self::PLACEHOLDER_SLUG ? strtolower($site['slug']) : (string) $site['id'];
        $writable = $template['mode'] === self::MODE_HOST
            ? Portal_Site_Resolver::tenant_label($token . (string) $template['host_suffix'], (string) $template['host_suffix']) === $token
            : (bool) preg_match('/^[A-Za-z0-9_-]+$/', $token);

        if (!$writable) {
            return false;
        }

        $fill = fn (string $value) => str_replace(':' . $template['placeholder'], $token, $value);

        // APP_URL's own host is never a tenant (the resolver serves staff there), so a site whose
        // slug lands on it has no portal address in this layout - a link there would open the
        // staff application.
        if ($template['mode'] === self::MODE_HOST
            && $fill($template['host']) === static::parse('', (string) config('app.url'))['host']) {
            return false;
        }

        return [
            'origin' => $fill($template['origin']),
            'prefix' => $fill($template['prefix']),
            'host' => $fill($template['host']),
            'separate_host' => $template['separate_host'],
        ];
    }

    /**
     * window.rsxapp.portal's address keys, for every page (Rsx_Portal.js reads them):
     *
     *   origin, prefix, separate_host   the current site's portal (for_site()); on a portal
     *                                   page the prefix is the base the request resolved to
     *   base                            origin + prefix, or null when the current site has no
     *                                   portal - Rsx_Portal.Route() then answers false
     *   app_host_prefix                 the fixed path the portal claims on the application
     *                                   host ('/_portal' for '/_portal' and '/_portal/:slug'),
     *                                   whatever the site; null when the portal is disabled
     *                                   or lives on hosts of its own. Login_Redirect.js keeps
     *                                   a staff return target out of it.
     *
     * A staff page whose site has no portal ships null origin and prefix. A portal page on
     * an address that serves no site (its 404) keeps its request's prefix and a null base.
     *
     * @return array{origin: ?string, prefix: ?string, separate_host: bool, base: ?string, app_host_prefix: ?string}
     */
    public static function client_payload(): array
    {
        $address = static::for_site();
        $template = static::__current();

        $payload = [
            'origin' => $address === false ? null : $address['origin'],
            'prefix' => $address === false ? null : $address['prefix'],
            'separate_host' => $address !== false && $address['separate_host'],
            'base' => $address === false ? null : $address['origin'] . $address['prefix'],
            'app_host_prefix' => static::is_enabled() && $template['mode'] === self::MODE_PATH && !$template['separate_host']
                ? $template['fixed_prefix']
                : null,
        ];

        if (Rsx_Portal::is_portal_request()) {
            $resolved = Rsx_Request_Channel::resolved_portal_base();

            if ($resolved !== null) {
                $payload['prefix'] = $resolved;
            }

            if ($address === false) {
                $payload['origin'] = '';
                $payload['separate_host'] = Rsx_Request_Channel::is_portal_host();
            }
        }

        return $payload;
    }

    /**
     * The site for_site() answers for when none is named, or null when there is none.
     *
     *   single tenancy   Rsx_Tenancy::SINGLE_TENANT_SITE_ID, always
     *   portal request   the site its URL resolved to (Rsx_Request_Channel::portal_site_id()),
     *                    else the site a command or test declared (Portal_Session)
     *   anything else    Session::get_site_id() - the staff sign-in's site, a command's or
     *                    task's declared site; null when that is 0 (none)
     *
     * @return int|null
     */
    public static function current_site_id(): ?int
    {
        if (!Rsx_Tenancy::is_multi()) {
            return Rsx_Tenancy::SINGLE_TENANT_SITE_ID;
        }

        if (Rsx_Portal::is_portal_request()) {
            return Rsx_Request_Channel::portal_site_id() ?? Portal_Session::_declared_site_id();
        }

        $site_id = Session::get_site_id();

        return $site_id > 0 ? $site_id : null;
    }

    /**
     * parse() of the live configuration: the template, whatever the tenancy.
     *
     * @return array See parse()
     */
    public static function template(): array
    {
        return static::__current();
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Every refusal of the live configuration, as a message naming the .env key, or null.
     * The judge Portal_Url_ManifestSupport calls at build.
     *
     * @throws RuntimeException when rsx.tenancy or PORTAL_ENABLED is outside its set
     */
    public static function check_configured(): ?string
    {
        return static::check(
            (string) config('rsx.portal.url'),
            (string) config('app.url'),
            Rsx_App_Url::http_allowed(),
            Rsx_Tenancy::mode(),
            static::is_enabled()
        );
    }

    /**
     * Every refusal, as a message naming PORTAL_URL, or null when the value is usable.
     * Pure. Nothing is checked while the portal is disabled, or while APP_URL is empty (the
     * development first-run state, when nothing can be compared against it).
     *
     * Refused, in either tenancy: an absolute URL that is not http(s); a scheme APP_URL's
     * rule refuses (Rsx_App_Url::scheme_problem() - https outside development); credentials,
     * a query or a fragment; a bare path that does not start with '/' and name at least one
     * segment; a path segment outside [A-Za-z0-9_-]; a first segment the framework owns
     * ('api', 'error', 'ws', or any '_' name other than '_portal' - a '/_' path is
     * framework-owned); and a URL equal to APP_URL - the same host and the same path,
     * whatever the scheme, port or trailing slash.
     *
     * Tenancy: single refuses a placeholder. Multi requires exactly one, either as the last
     * path segment after at least one fixed segment ('/_portal/:slug'), or as the whole
     * first label of the host ('https://:slug.myapp.com'). A blank value derives a valid
     * template in both.
     *
     * A collision between the prefix and a staff route is the manifest's to judge
     * (Portal_Url_ManifestSupport::find_route_conflicts()) - it needs the route table.
     *
     * @param string $portal_url The configured PORTAL_URL ($HOSTNAME already resolved)
     * @param string $app_url The configured APP_URL
     * @param bool $allow_http Whether this process accepts http (Rsx_App_Url::http_allowed())
     * @param string $tenancy Rsx_Tenancy::SINGLE or ::MULTI
     * @param bool $enabled PORTAL_ENABLED
     * @return string|null
     */
    public static function check(
        string $portal_url,
        string $app_url,
        bool $allow_http,
        string $tenancy = Rsx_Tenancy::SINGLE,
        bool $enabled = true
    ): ?string {
        $value = trim($portal_url);

        if (!$enabled || trim($app_url) === '' || $value === '') {
            return null;
        }

        $multi = $tenancy === Rsx_Tenancy::MULTI;

        if (str_starts_with($value, '/')) {
            if (strlen($value) < 2) {
                return 'PORTAL_URL written as a path must start with "/" and name at least one segment'
                    . ' ("/clients"). Current value: "' . $portal_url . '".';
            }
        } else {
            $parts = parse_url(static::__mask($value));

            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                return 'PORTAL_URL must be an absolute http:// or https:// URL, like APP_URL, or a path on'
                    . ' APP_URL\'s host starting with "/" (or blank, which serves the portal at APP_URL + '
                    . ($multi ? self::DEFAULT_MULTI_TEMPLATE : self::DEFAULT_PREFIX) . ').'
                    . ' Current value: "' . $portal_url . '".';
            }

            // Masked: parse_url() cannot read a placeholder host label (the shared rule's own
            // message is unmasked again, so it quotes the value as written).
            $scheme_problem = Rsx_App_Url::scheme_problem(static::__mask($value), $allow_http, 'PORTAL_URL');
            if ($scheme_problem !== null) {
                return static::__unmask($scheme_problem);
            }

            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                return 'PORTAL_URL may carry only a scheme, a host, a port and a path - no credentials,'
                    . ' query string or fragment. Current value: "' . $portal_url . '".';
            }
        }

        $portal = static::parse($value, $app_url, $tenancy);
        $segments = $portal['prefix'] === '' ? [] : explode('/', substr($portal['prefix'], 1));

        foreach ($segments as $segment) {
            if (str_starts_with($segment, ':') && !isset(self::MASKS[$segment])) {
                return 'PORTAL_URL tenant placeholders are :slug and :site_id - "' . $segment . '" is neither.'
                    . ' Current value: "' . $portal_url . '".';
            }
        }

        $placeholders = preg_match_all(self::PLACEHOLDER_PATTERN, $value);

        if (!$multi && $placeholders > 0) {
            return 'PORTAL_URL carries a tenant placeholder (:slug or :site_id), but rsx.tenancy is single:'
                . ' a single-tenant install serves one portal at one address. Remove the placeholder, or set'
                . ' \'tenancy\' => \'multi\' in rsx/resource/config/rsx.php. Current value: "' . $portal_url . '".';
        }

        if ($multi && $placeholders === 0) {
            return 'rsx.tenancy = multi requires PORTAL_URL to carry a tenant placeholder that picks the site:'
                . ' a path ending in /:slug or /:site_id after a fixed segment ("/_portal/:slug"), or a host'
                . ' whose first label is the placeholder ("https://:slug.myapp.com"). Leave PORTAL_URL blank'
                . ' for ' . self::DEFAULT_MULTI_TEMPLATE . '. Current value: "' . $portal_url . '".';
        }

        if ($placeholders > 1) {
            return 'PORTAL_URL may carry exactly one tenant placeholder (:slug or :site_id).'
                . ' Current value: "' . $portal_url . '".';
        }

        if (str_contains($portal['host'], ':') && $portal['host_suffix'] === null) {
            return 'A tenant placeholder in the PORTAL_URL host must be the whole first label'
                . ' ("https://:slug.myapp.com"), followed by the domain it is a subdomain of.'
                . ' Current value: "' . $portal_url . '".';
        }

        foreach ($segments as $segment) {
            if (isset(self::MASKS[$segment])) {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                return 'PORTAL_URL path segments may contain only letters, digits, "_" and "-"'
                    . ' (an empty segment is refused too). Current value: "' . $portal_url . '".';
            }
        }

        if ($portal['mode'] === self::MODE_PATH && $portal['placeholder'] !== null) {
            $last = end($segments);

            if (count($segments) < 2 || !isset(self::MASKS[$last])) {
                return 'A PORTAL_URL path template must END in /:slug or /:site_id, after at least one fixed'
                    . ' segment ("/_portal/:slug") - the fixed segment is what keeps the portal from claiming'
                    . ' every path on the host. Current value: "' . $portal_url . '".';
            }

            // A tenant's portal is its own address - a subdomain or a domain - never a path on a
            // shared portal host, where every tenant would share one cookie jar, one passkey
            // relying party and one sign-in. The path form exists on the APPLICATION's host only.
            if ($portal['separate_host']) {
                return 'A PORTAL_URL path template (:slug or :site_id in the path) must be on the'
                    . ' application\'s own host. A portal host of its own gives each tenant its own'
                    . ' address with the placeholder as the first host label ("https://:slug.myapp.com").'
                    . ' Current value: "' . $portal_url . '".';
            }
        }

        if ($segments !== []) {
            $first = strtolower($segments[0]);

            if (in_array($first, self::RESERVED_SEGMENTS, true)
                || (str_starts_with($first, '_') && '/' . $first !== self::DEFAULT_PREFIX)) {
                return 'PORTAL_URL may not place the portal under /' . $segments[0] . ' - that path belongs'
                    . ' to the framework (/api is the external API, /error the error pages, /ws the realtime'
                    . ' socket, and every /_ path is framework-owned; ' . self::DEFAULT_PREFIX . ' is the one'
                    . ' underscore prefix a portal may use). Current value: "' . $portal_url . '".';
            }
        }

        $app = static::__split($app_url);

        if ($portal['host'] === $app['host'] && $portal['prefix'] === $app['path']) {
            return 'PORTAL_URL must not equal APP_URL: the portal needs its own host, or a path under'
                . ' APP_URL\'s host (leave PORTAL_URL blank to serve it at APP_URL + ' . self::DEFAULT_PREFIX . ').'
                . ' Current values: PORTAL_URL="' . $portal_url . '", APP_URL="' . $app_url . '".';
        }

        return null;
    }

    /**
     * Derive the portal's address from the settings. Pure; never throws (check() is the
     * judge of a malformed value - a value it would refuse still derives something).
     *
     * Every address-shaped value is a TEMPLATE: it carries the placeholder (':slug' or
     * ':site_id') literally where the template does.
     *
     *   url           origin + prefix
     *   origin        scheme://host[:port], the default port dropped ('' with no host)
     *   host          the lowercased host, no port (':slug.myapp.com' in host mode)
     *   prefix        '' or '/seg[/seg]', no trailing slash ('/_portal/:slug' in path mode)
     *   separate_host the host differs from APP_URL's (always true in host mode)
     *   mode          'host' when the placeholder is in the host, else 'path'
     *   placeholder   null, 'slug' or 'site_id'
     *   host_suffix   host mode: the host after the placeholder label ('.myapp.com');
     *                 null otherwise, and null when the placeholder is not the first label
     *   fixed_prefix  the static part of the prefix before the placeholder ('/_portal'
     *                 for '/_portal/:slug'); the prefix itself when the path has none
     *
     * @param string $portal_url PORTAL_URL ('' = APP_URL's origin + the tenancy's default)
     * @param string $app_url APP_URL
     * @param string $tenancy Rsx_Tenancy::SINGLE or ::MULTI - decides only what blank means
     * @return array{url: string, origin: string, host: string, prefix: string, separate_host: bool, mode: string, placeholder: ?string, host_suffix: ?string, fixed_prefix: string}
     */
    public static function parse(string $portal_url, string $app_url, string $tenancy = Rsx_Tenancy::SINGLE): array
    {
        $app = static::__split($app_url);
        $value = trim($portal_url);

        if ($value === '') {
            $value = $tenancy === Rsx_Tenancy::MULTI ? self::DEFAULT_MULTI_TEMPLATE : self::DEFAULT_PREFIX;
        }

        if (str_starts_with($value, '/')) {
            $portal = $app;
            $portal['path'] = rtrim($value, '/');
        } else {
            $portal = static::__split(static::__mask($value));
            $portal['host'] = static::__unmask($portal['host']);
            $portal['path'] = static::__unmask($portal['path']);
        }

        $origin = $portal['host'] === ''
            ? ''
            : $portal['scheme'] . '://' . $portal['host'] . ($portal['port'] !== null ? ':' . $portal['port'] : '');

        $placeholder = null;
        if (preg_match(self::PLACEHOLDER_PATTERN, $portal['host'] . $portal['path'], $match)) {
            $placeholder = $match[1];
        }

        $in_host = (bool) preg_match(self::PLACEHOLDER_PATTERN, $portal['host']);

        $host_suffix = null;
        if ($in_host && preg_match('/^:(slug|site_id)(\.[a-z0-9-]+(\.[a-z0-9-]+)*)$/', $portal['host'], $host_match)) {
            $host_suffix = $host_match[2];
        }

        $fixed_prefix = $portal['path'];
        if (!$in_host && preg_match(self::PLACEHOLDER_PATTERN, $portal['path'], $path_match, PREG_OFFSET_CAPTURE)) {
            $fixed_prefix = rtrim(substr($portal['path'], 0, $path_match[0][1]), '/');
        }

        return [
            'url' => $origin . $portal['path'],
            'origin' => $origin,
            'host' => $portal['host'],
            'prefix' => $portal['path'],
            'separate_host' => $portal['host'] !== $app['host'],
            'mode' => $in_host ? self::MODE_HOST : self::MODE_PATH,
            'placeholder' => $placeholder,
            'host_suffix' => $host_suffix,
            'fixed_prefix' => $fixed_prefix,
        ];
    }

    /**
     * The portal settings as one string: what the manifest stamps (Manifest_Store), so a
     * development box rebuilds - re-running the build-time checks - when one changes, and a
     * sealed box refuses to serve settings it was not built with.
     *
     * PORTAL_URL is taken as .env wrote it, the $HOSTNAME token unresolved, when that is
     * what the configuration still holds: boxes sharing one .env and one build differ in
     * hostname, not in configuration.
     */
    public static function fingerprint(): string
    {
        $url = (string) config('rsx.portal.url');
        $raw = Rsx_App_Url::raw_value('PORTAL_URL');

        if ($raw !== null && Rsx_App_Url::resolve($raw, (string) gethostname()) === $url) {
            $url = $raw;
        }

        return sha1(implode("\n", [
            static::__stamp_value(config('rsx.tenancy')),
            static::__stamp_value(config('rsx.portal.enabled')),
            $url,
            static::__stamp_value(config('rsx.portal.custom_domains')),
        ]));
    }

    // =========================================================================
    // rsx:health
    // =========================================================================

    /**
     * The derived portal address, or the build check's refusal.
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    #[Health_Check('Portal URL')]
    public static function health_row(): array
    {
        $tenancy = Rsx_Tenancy::mode();

        if (!static::is_enabled()) {
            return [
                'status' => 'OK',
                'detail' => 'the client portal is disabled (PORTAL_ENABLED=false)',
                'remediation' => null,
            ];
        }

        $problem = static::check_configured();

        if ($problem !== null) {
            return [
                'status' => 'FAIL',
                'detail' => $problem,
                'remediation' => 'correct PORTAL_URL in .env, or leave it blank to serve the portal at APP_URL + '
                    . ($tenancy === Rsx_Tenancy::MULTI ? self::DEFAULT_MULTI_TEMPLATE : self::DEFAULT_PREFIX)
                    . ' (rsx:man portal)',
            ];
        }

        $parsed = static::__current();
        $tenancy_note = ' (' . $tenancy . ' tenancy, ' . $parsed['mode'] . ' mode)';

        if ($parsed['origin'] === '') {
            return [
                'status' => 'OK',
                'detail' => 'APP_URL is not configured yet; the portal will be served at APP_URL + ' . $parsed['prefix'] . $tenancy_note,
                'remediation' => null,
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $parsed['origin'] . ' prefix ' . ($parsed['prefix'] === '' ? '(none)' : $parsed['prefix'])
                . ($parsed['separate_host'] ? ' - its own host' : ' - on the application host') . $tenancy_note,
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
        $tenancy = Rsx_Tenancy::mode();
        $key = $portal_url . "\n" . $app_url . "\n" . $tenancy;

        return static::$__parsed[$key] ??= static::parse($portal_url, $app_url, $tenancy);
    }

    /**
     * for_site() of the current site, refused when it has no portal.
     *
     * @param string $accessor The accessor asking, for the message
     * @return array See for_site()
     * @throws RuntimeException when the current site has no portal
     */
    private static function __current_address(string $accessor): array
    {
        $address = static::for_site();

        if ($address === false) {
            throw new RuntimeException(
                'Rsx_Portal_Url::' . $accessor . '() answers for the current site\'s client portal, and the current'
                . ' site (' . (static::current_site_id() ?? 'none') . ') has none: the portal is disabled, the site is'
                . ' not served, or no site is current. Code that can meet a site without a portal asks'
                . ' Rsx_Portal_Url::for_site(), which answers false. See rsx:man portal.'
            );
        }

        return $address;
    }

    /**
     * A configured switch as it enters the fingerprint.
     */
    private static function __stamp_value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The template with each placeholder replaced by its mask, for parse_url().
     */
    private static function __mask(string $value): string
    {
        return preg_replace_callback(self::PLACEHOLDER_PATTERN, fn ($m) => self::MASKS[$m[0]], $value);
    }

    /**
     * A parsed part with each mask restored to its placeholder.
     */
    private static function __unmask(string $value): string
    {
        return str_replace(array_values(self::MASKS), array_keys(self::MASKS), $value);
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
