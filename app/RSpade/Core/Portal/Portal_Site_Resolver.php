<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Portal;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;

/**
 * Portal_Site_Resolver - which site a client portal request serves, read from its URL.
 *
 * THE URL IS THE AUTHORITY. Every portal request names its tenant in its address, and this
 * class reads it: Rsx_Request_Channel classifies the request with match(), settle() judges
 * the site it names, and the Dispatcher declares the answer for the request
 * (Portal_Session::_declare_resolved_site()) before the application's Portal_Main::init()
 * runs. The site is never stored anywhere.
 *
 * THE ORDER, first answer wins:
 *
 *   1. A custom domain (PORTAL_CUSTOM_DOMAINS override or only): the request host equals a
 *      sites.portal_domain exactly. The portal is served at that host's root (base '').
 *      APP_URL's own host is never looked up.
 *   2. The template (PORTAL_URL, Rsx_Portal_Url::template()):
 *        host mode    'https://:slug.myapp.com[/x]' - the request host is one DNS label
 *                     followed by the template's host suffix; that label is the tenant.
 *                     APP_URL's host is never a tenant, even when it fits the pattern.
 *        path mode    '/_portal/:slug' - the segment after the fixed prefix is the tenant;
 *                     the base is the fixed prefix plus that segment ('/_portal/acme').
 *        single       no placeholder: the template's host and prefix as written, serving
 *                     Rsx_Tenancy::SINGLE_TENANT_SITE_ID.
 *      A portal on a host of its own (any host mode, a separate-host path template) claims
 *      EVERY path on that host: a path outside the base is a portal path no route matches.
 *      On the application host the portal claims only the paths under its base.
 *   3. Otherwise it is not a portal request (null) - and nothing is while PORTAL_ENABLED is
 *      false.
 *
 * THE VERDICT (settle()) on the site the address named:
 *
 *   not served   no such slug or id; a site that is deleted, disabled (sites.is_enabled),
 *                has its portal switched off (sites.is_portal_enabled) or is site 0; in
 *                single tenancy a site other than the default; under 'only' any template
 *                address; a portal-host path with no tenant at all. Answered with the
 *                portal's 404 page, before dispatch.
 *   redirect     under 'override', a template address for a site that has a portal_domain:
 *                a 301 to the same realm path (and query string) at https://<domain>.
 *   served       the site id, declared for the request.
 *
 * Slug and domain lookups compare lowercased. An id is accepted only in its canonical
 * spelling ('7', never '07').
 *
 * CACHING. Three lookups - a site's portal facts by id, a slug's site id, a domain's site id
 * - are cached in RsxCache's persistent family (plain arrays; it needs no manifest, so the
 * classification of a build-artifact request costs no database query on a warm cache). A
 * miss is cached too. Site_Model's saved/deleted/restored/forceDeleted hooks forget every key
 * a change can move (_forget_site(): the id, the old and new slug, the old and new domain),
 * at once and again after the transaction commits; a transaction rollback, migrate and
 * rsx:clean clear the whole cache scope. A write to `sites` that bypasses the model must call
 * _forget_site() itself.
 *
 * host_patterns() / served_by_portal() / is_portal_domain() are the host guards' half: the
 * development hostname tripwire (Rsx_Env_Hostname_Guard) and the sealed-build host rule
 * (Rsx::get_hostname(), Rsx::absolute_url_origin()) accept every host the portal may answer
 * on.
 */
class Portal_Site_Resolver
{
    /**
     * How match() found the tenant.
     */
    public const BY_DOMAIN = 'domain';

    public const BY_SLUG = Rsx_Portal_Url::PLACEHOLDER_SLUG;

    public const BY_SITE_ID = Rsx_Portal_Url::PLACEHOLDER_SITE_ID;

    public const BY_DEFAULT = 'default';

    /**
     * A portal address with no tenant in it (a portal host's path outside the template, a
     * path template's fixed prefix alone).
     */
    public const BY_NONE = 'none';

    /**
     * Persistent RsxCache key prefix.
     */
    private const CACHE_PREFIX = 'portal_site_resolver:';

    // =========================================================================
    // Resolution
    // =========================================================================

    /**
     * Resolve a request address: match() and settle() in one step.
     *
     * @param string $host The request host, lowercased, no port
     * @param string $path The request path, leading slash, no query string
     * @param string $query The query string without '?', carried onto a redirect
     * @return array|null Null when it is not a portal request; otherwise settle()'s answer
     */
    public static function resolve(string $host, string $path, string $query = ''): ?array
    {
        $match = static::match($host, $path);

        return $match === null ? null : static::settle($match, $query);
    }

    /**
     * Whether the portal claims a path on the APPLICATION host - a path under a same-host
     * template's fixed prefix ('/_portal', and in multi tenancy every '/_portal/<tenant>'),
     * whichever site it names or none. False when the portal lives on hosts of its own or
     * is disabled. Reads no database. Login_Redirect keeps a staff return target out of it.
     *
     * @param string $path A path, leading slash (a query string or fragment may follow)
     * @return bool
     */
    public static function claims_application_path(string $path): bool
    {
        if (!Rsx_Portal_Url::is_enabled()) {
            return false;
        }

        $template = Rsx_Portal_Url::template();

        return $template['mode'] === Rsx_Portal_Url::MODE_PATH
            && !$template['separate_host']
            && Rsx_Portal::is_under_base($path, $template['fixed_prefix']);
    }

    /**
     * Whether match() needs the request host: when custom domains are honoured or the
     * template names a host other than APP_URL's. Lets the classifier leave a Host header
     * unread (and unvalidated) where the answer cannot depend on it.
     *
     * @return bool
     */
    public static function needs_host(): bool
    {
        if (!Rsx_Portal_Url::is_enabled()) {
            return false;
        }

        return Rsx_Portal_Url::custom_domains() !== Rsx_Portal_Url::CUSTOM_DOMAINS_OFF
            || Rsx_Portal_Url::template()['separate_host'];
    }

    /**
     * Where an address falls, without judging the site it names. Reads the live
     * configuration; touches the database (through the cache) only for a custom-domain host
     * lookup.
     *
     * @param string $host The request host, lowercased, no port ('' when unknown)
     * @param string $path The request path, leading slash, no query string
     * @return array|null Null when it is not a portal request, else:
     *   base_path    '' or '/seg[/seg]' - the concrete prefix of this request's portal
     *   realm_path   the path with the base removed ('/' at the base itself); the path as
     *                given when it is outside the base
     *   under_base   the path is the base or beneath it
     *   portal_host  the request is on a host that belongs to the portal (its own host, a
     *                tenant host, a custom domain) rather than APP_URL's host
     *   by           BY_DOMAIN | BY_SLUG | BY_SITE_ID | BY_DEFAULT | BY_NONE
     *   token        the slug or id as written in the address, the domain's site id, or null
     */
    public static function match(string $host, string $path): ?array
    {
        if (!Rsx_Portal_Url::is_enabled()) {
            return null;
        }

        $host = strtolower($host);
        $template = Rsx_Portal_Url::template();
        $app_host = static::__app_host();

        // 1. A custom domain, exact.
        if (Rsx_Portal_Url::custom_domains() !== Rsx_Portal_Url::CUSTOM_DOMAINS_OFF && $host !== '' && $host !== $app_host) {
            $site_id = static::site_id_for_domain($host);

            if ($site_id !== null) {
                return static::__answer('', $path, true, self::BY_DOMAIN, $site_id);
            }
        }

        // 2a. Host mode: one label, then the template's host suffix.
        if ($template['mode'] === Rsx_Portal_Url::MODE_HOST) {
            $label = static::tenant_label($host, (string) $template['host_suffix']);

            if ($label === null || $host === $app_host) {
                return null;
            }

            return static::__answer($template['prefix'], $path, true, $template['placeholder'], $label);
        }

        // 2b. Path mode. A template on a host of its own claims that host; on the application
        // host it claims its prefix on whatever host the application answers on.
        $separate = $template['separate_host'];

        if ($separate && $host !== $template['host']) {
            return null;
        }

        if ($template['placeholder'] === null) {
            if (!$separate && !Rsx_Portal::is_under_base($path, $template['prefix'])) {
                return null;
            }

            return static::__answer($template['prefix'], $path, $separate, self::BY_DEFAULT, null);
        }

        $fixed = $template['fixed_prefix'];

        if (!Rsx_Portal::is_under_base($path, $fixed)) {
            return $separate ? static::__answer($fixed, $path, true, self::BY_NONE, null) : null;
        }

        $rest = substr($path, strlen($fixed));

        if (!preg_match('#^/([^/]+)#', $rest, $segment)) {
            return static::__answer($fixed, $path, $separate, self::BY_NONE, null);
        }

        return static::__answer($fixed . '/' . $segment[1], $path, $separate, $template['placeholder'], $segment[1]);
    }

    /**
     * Judge the site a match() names.
     *
     * @param array $match A match() answer
     * @param string $query The query string without '?', carried onto a redirect
     * @return array The match's base_path, realm_path, under_base and portal_host, plus:
     *   site_id      the served site, or null
     *   redirect_to  the absolute URL a 301 sends the browser to, or null
     *   not_served   true when the address names no servable portal (the portal's 404)
     */
    public static function settle(array $match, string $query = ''): array
    {
        $answer = [
            'base_path' => $match['base_path'],
            'realm_path' => $match['realm_path'],
            'under_base' => $match['under_base'],
            'portal_host' => $match['portal_host'],
            'site_id' => null,
            'redirect_to' => null,
            'not_served' => true,
        ];

        $site_id = match ($match['by']) {
            self::BY_DOMAIN => (int) $match['token'],
            self::BY_DEFAULT => Rsx_Tenancy::SINGLE_TENANT_SITE_ID,
            self::BY_SLUG => static::site_id_for_slug((string) $match['token']),
            self::BY_SITE_ID => static::__canonical_id((string) $match['token']),
            default => null,
        };

        if ($site_id === null) {
            return $answer;
        }

        $site = static::site($site_id);

        if (!static::is_servable($site)) {
            return $answer;
        }

        // A single-tenant install serves its one tenant, whichever address reached it.
        if (!Rsx_Tenancy::is_multi() && $site_id !== Rsx_Tenancy::SINGLE_TENANT_SITE_ID) {
            return $answer;
        }

        $custom_domains = Rsx_Portal_Url::custom_domains();

        if ($match['by'] !== self::BY_DOMAIN) {
            if ($custom_domains === Rsx_Portal_Url::CUSTOM_DOMAINS_ONLY) {
                return $answer;
            }

            if ($custom_domains === Rsx_Portal_Url::CUSTOM_DOMAINS_OVERRIDE && $site['portal_domain'] !== null) {
                // A custom domain serves the portal at its root, so the realm path carries over.
                $answer['redirect_to'] = 'https://' . $site['portal_domain'] . $match['realm_path']
                    . ($query !== '' ? '?' . $query : '');
                $answer['not_served'] = false;

                return $answer;
            }
        }

        $answer['site_id'] = $site_id;
        $answer['not_served'] = false;

        return $answer;
    }

    /**
     * Whether a site may serve a portal: it exists, is not deleted, is enabled, has its
     * portal switched on, and is not site 0.
     *
     * @param array|null $site A site() answer
     * @return bool
     */
    public static function is_servable(?array $site): bool
    {
        return $site !== null
            && $site['id'] !== 0
            && !$site['is_deleted']
            && $site['is_enabled']
            && $site['is_portal_enabled'];
    }

    /**
     * The DNS label in front of a host-mode suffix: 'acme' for ('acme.myapp.com',
     * '.myapp.com'). Null when the host does not end in the suffix after exactly one label.
     *
     * @param string $host Lowercased host
     * @param string $suffix The template's host_suffix ('.myapp.com'); '' matches nothing
     * @return string|null
     */
    public static function tenant_label(string $host, string $suffix): ?string
    {
        if ($suffix === '' || !str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) ? $label : null;
    }

    // =========================================================================
    // The host guards' half
    // =========================================================================

    /**
     * The hosts a PORTAL_URL template adds to the ones the application answers on, beyond
     * APP_URL's own: the portal's host when it is not APP_URL's, or '*<suffix>' (one label
     * then the suffix: '*.myapp.com') for a host template. Pure.
     *
     * @param string $portal_url PORTAL_URL as configured
     * @param string $app_url APP_URL
     * @param string $tenancy Rsx_Tenancy::SINGLE or ::MULTI
     * @param bool $enabled PORTAL_ENABLED
     * @return string[]
     */
    public static function host_patterns(string $portal_url, string $app_url, string $tenancy, bool $enabled): array
    {
        $value = trim($portal_url);

        if (!$enabled || $value === '' || str_starts_with($value, '/')) {
            return [];
        }

        $template = Rsx_Portal_Url::parse($value, $app_url, $tenancy);

        if ($template['mode'] === Rsx_Portal_Url::MODE_HOST) {
            return $template['host_suffix'] === null ? [] : ['*' . $template['host_suffix']];
        }

        $app_host = strtolower((string) parse_url(trim($app_url), PHP_URL_HOST));

        return ($template['host'] !== '' && $template['host'] !== $app_host) ? [$template['host']] : [];
    }

    /**
     * Whether a host matches one host_patterns() entry: exactly, or for '*<suffix>' as a
     * single label in front of the suffix.
     *
     * @param string $host Lowercased host, no port
     * @param string $pattern
     * @return bool
     */
    public static function host_matches(string $host, string $pattern): bool
    {
        if (str_starts_with($pattern, '*.')) {
            return static::tenant_label($host, substr($pattern, 1)) !== null;
        }

        return $host === $pattern;
    }

    /**
     * host_patterns() of the live configuration.
     *
     * @return string[]
     */
    public static function configured_host_patterns(): array
    {
        return static::host_patterns(
            (string) config('rsx.portal.url'),
            (string) config('app.url'),
            Rsx_Tenancy::mode(),
            Rsx_Portal_Url::is_enabled()
        );
    }

    /**
     * Whether the live configuration serves the portal on a host: a template host pattern,
     * or a custom domain.
     *
     * @param string $host Lowercased host, no port
     * @return bool
     */
    public static function served_by_portal(string $host): bool
    {
        foreach (static::configured_host_patterns() as $pattern) {
            if (static::host_matches($host, $pattern)) {
                return true;
            }
        }

        return static::is_portal_domain($host);
    }

    /**
     * Whether a host is some site's sites.portal_domain while custom domains are honoured.
     * Any site's, a disabled or deleted one included: such a host is answered (with the
     * portal's 404), never refused as a host the application does not serve.
     *
     * @param string $host Lowercased host, no port
     * @return bool
     */
    public static function is_portal_domain(string $host): bool
    {
        if ($host === '' || !Rsx_Portal_Url::is_enabled()
            || Rsx_Portal_Url::custom_domains() === Rsx_Portal_Url::CUSTOM_DOMAINS_OFF) {
            return false;
        }

        return static::site_id_for_domain($host) !== null;
    }

    // =========================================================================
    // The cached lookups
    // =========================================================================

    /**
     * A site's portal facts, or null when no row has that id (deleted rows included):
     * ['id', 'slug', 'is_enabled', 'is_portal_enabled', 'portal_domain', 'is_deleted'].
     *
     * @param int $site_id
     * @return array|null
     */
    public static function site(int $site_id): ?array
    {
        $key = self::CACHE_PREFIX . 'id:' . $site_id;
        $cached = RsxCache::get_persistent($key);

        if (is_array($cached)) {
            return $cached['found'] ? $cached['site'] : null;
        }

        // withTrashed(): a deleted site must answer "not served", not "unknown"; toBase() keeps
        // the row plain data for the cache.
        $row = Site_Model::withTrashed()
            ->where('id', $site_id)
            ->toBase()
            ->first(['id', 'slug', 'is_enabled', 'is_portal_enabled', 'portal_domain', 'deleted_at']);

        $site = $row === null ? null : [
            'id' => (int) $row->id,
            'slug' => (string) $row->slug,
            'is_enabled' => (bool) $row->is_enabled,
            'is_portal_enabled' => (bool) $row->is_portal_enabled,
            'portal_domain' => $row->portal_domain === null || $row->portal_domain === '' ? null : (string) $row->portal_domain,
            'is_deleted' => $row->deleted_at !== null,
        ];

        RsxCache::set_persistent($key, ['found' => $site !== null, 'site' => $site]);

        return $site;
    }

    /**
     * The id of the site with this slug (compared lowercased), deleted rows included, or null.
     *
     * @param string $slug
     * @return int|null
     */
    public static function site_id_for_slug(string $slug): ?int
    {
        return static::__id_for_column('slug', strtolower($slug));
    }

    /**
     * The id of the site whose portal_domain is this host, deleted rows included, or null.
     *
     * @param string $host Lowercased host, no port
     * @return int|null
     */
    public static function site_id_for_domain(string $host): ?int
    {
        return static::__id_for_column('portal_domain', strtolower($host));
    }

    /**
     * Forget every cached lookup a change to this site can move: its id, its slug and
     * portal_domain as they were and as they are. Now, and again after the surrounding
     * transaction commits (another process may cache the old row in between).
     *
     * FRAMEWORK INTERNAL - Site_Model_Abstract's hooks, and any write to `sites` that
     * bypasses the model.
     *
     * @param \App\RSpade\Core\Models\Site_Model_Abstract $site
     * @return void
     */
    public static function _forget_site($site): void
    {
        $keys = ['id:' . (int) $site->id];

        foreach (array_unique([(string) $site->getOriginal('slug'), (string) $site->slug]) as $slug) {
            if ($slug !== '') {
                $keys[] = 'slug:' . strtolower($slug);
            }
        }

        foreach (array_unique([(string) $site->getOriginal('portal_domain'), (string) $site->portal_domain]) as $domain) {
            if ($domain !== '') {
                $keys[] = 'portal_domain:' . strtolower($domain);
            }
        }

        $forget = static function () use ($keys) {
            foreach ($keys as $key) {
                RsxCache::delete_persistent(self::CACHE_PREFIX . $key);
            }
        };

        $forget();
        DB::afterCommit($forget);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * A match() answer for an address under (or, on a portal host, outside) a base.
     */
    private static function __answer(string $base, string $path, bool $portal_host, string $by, string|int|null $token): array
    {
        $under = Rsx_Portal::is_under_base($path, $base);

        return [
            'base_path' => $base,
            'realm_path' => $under ? Rsx_Portal::strip_base($path, $base) : $path,
            'under_base' => $under,
            'portal_host' => $portal_host,
            'by' => $by,
            'token' => $token,
        ];
    }

    /**
     * A site id written canonically ('7'), else null ('07', '7a', '0' and '' name nothing).
     */
    private static function __canonical_id(string $token): ?int
    {
        if (!ctype_digit($token) || $token[0] === '0') {
            return null;
        }

        return (int) $token;
    }

    /**
     * The cached site id behind a unique column value.
     */
    private static function __id_for_column(string $column, string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $key = self::CACHE_PREFIX . $column . ':' . $value;
        $cached = RsxCache::get_persistent($key);

        if (is_array($cached)) {
            return $cached['site_id'];
        }

        $id = Site_Model::withTrashed()->where($column, $value)->toBase()->value('id');
        $site_id = $id === null ? null : (int) $id;

        RsxCache::set_persistent($key, ['site_id' => $site_id]);

        return $site_id;
    }

    /**
     * APP_URL's host, lowercased ('' when APP_URL names none).
     */
    private static function __app_host(): string
    {
        return strtolower((string) parse_url(trim((string) config('app.url')), PHP_URL_HOST));
    }
}
