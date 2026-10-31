<?php

namespace App\RSpade\Core\Time;

use Carbon\Carbon;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Core\Time\Rsx_Timestamp_Format;

/**
 * Rsx_Time - Datetime handling for RSpade (moments in time with timezone)
 *
 * Datetimes represent specific moments in time. They always have a time component
 * and are timezone-aware. Stored in UTC, displayed in user's timezone.
 *
 * STRINGS, NOT CARBON: All external-facing methods return ISO 8601 strings
 * (format: "2024-12-24T15:30:45.123Z"). This keeps PHP and JavaScript in sync
 * with identical formats on both sides. Carbon is used internally for calculations.
 *
 * Model properties ($model->created_at) also return ISO strings via Rsx_DateTime_Cast.
 *
 * Core Principles:
 * - All datetimes stored in database as UTC
 * - All serialization uses ISO 8601 format
 * - External APIs return strings, not Carbon
 * - The display zone is resolved per request (get_user_timezone): a signed-in staff user's
 *   EXPLICIT zone (login_users.timezone; NULL means automatic), then the browser's own zone
 *   (the rsx_tz cookie the framework's boot script keeps current), then the site's, then
 *   the server's, then UTC. A portal account has no personal zone and starts at the cookie.
 * - Formatting happens on-demand, not on storage
 * - PHP and JS APIs are parallel (same method names)
 * - Datetime functions THROW if passed a date-only string
 * - Use Rsx_Date for calendar dates without time components
 *
 * See: php artisan rsx:man time
 */
class Rsx_Time
{
    // =========================================================================
    // TIMEZONE CACHING
    // =========================================================================

    /**
     * Cached user timezone
     * @var string|null
     */
    private static ?string $_cached_user_timezone = null;

    /**
     * Where the cached timezone came from (a TIMEZONE_SOURCE_* value)
     * @var string|null
     */
    private static ?string $_cached_user_timezone_source = null;

    /**
     * User ID when timezone was cached (for invalidation)
     * @var int|null
     */
    private static ?int $_cached_user_id = null;

    /**
     * Site ID when timezone was cached (for invalidation)
     * @var int|null
     */
    private static ?int $_cached_site_id = null;

    /**
     * Experience the cached timezone was resolved for (for invalidation).
     *
     * Part of the cache key, not decoration: the staff and portal identities live on
     * the SAME session row, so (user_id, site_id) can repeat across a realm flip - a
     * process that serves a portal request and then a staff request (tests, a queue
     * worker asserting a request context) would otherwise be handed the other
     * experience's zone.
     *
     * @var bool|null
     */
    private static ?bool $_cached_is_portal = null;

    /**
     * The browser zone the cached timezone was resolved with (part of the cache key).
     *
     * @var string|null
     */
    private static ?string $_cached_browser_timezone = null;

    /**
     * Test seam: a BROWSER standing in for a command's missing one. null (the default) is no
     * browser at all - what a command or task is; '' is a browser that sent no rsx_tz cookie;
     * any other value is the cookie it sent. Read by every caller but an external API
     * request; the test harness clears it at every method boundary (_testing_reset()).
     *
     * @var string|null
     */
    public static ?string $browser_timezone_cookie_for_tests = null;

    // =========================================================================
    // THE BROWSER TIMEZONE COOKIE
    // =========================================================================

    /**
     * The cookie the browser's zone travels in: its IANA identifier, written by
     * Rsx_Timezone_Cookie.js on every page load (and by the first-visit interstitial), read
     * by get_user_timezone(). Not HttpOnly - JavaScript writes it. The JS twin carries the
     * same spellings (Rsx_Timezone_Cookie.COOKIE_NAME etc.).
     */
    public const TIMEZONE_COOKIE = 'rsx_tz';

    /** The cookie's value when the browser could not report a zone: not a zone, never used as one. */
    public const TIMEZONE_COOKIE_ERROR = 'error_reading_browser_timezone';

    /** Lifetime of a zone in the cookie (refreshed on every load anyway). */
    public const TIMEZONE_COOKIE_MAX_AGE = 31536000;

    /** Lifetime of the error value: short, so a browser that failed once tries again. */
    public const TIMEZONE_COOKIE_ERROR_MAX_AGE = 86400;

    /**
     * The query parameter the first-visit interstitial's reload carries. A request bearing
     * it and still no cookie is a browser refusing cookies, and renders without one rather
     * than being sent the interstitial again.
     */
    public const TIMEZONE_RELOAD_MARKER = '_rsx_tz';

    // =========================================================================
    // WHERE A RESOLVED ZONE CAME FROM (get_user_timezone_source())
    // =========================================================================

    /** The signed-in staff user's own choice, login_users.timezone. */
    public const TIMEZONE_SOURCE_EXPLICIT = 'explicit';

    /** The browser's zone, from the rsx_tz cookie. */
    public const TIMEZONE_SOURCE_BROWSER = 'browser';

    /** Neither: the site's timezone. Not evidence of where the person was. */
    public const TIMEZONE_SOURCE_SITE = 'site';

    /** Nothing better: the server's timezone (or UTC). Not evidence of where the person was. */
    public const TIMEZONE_SOURCE_SERVER = 'server';

    // =========================================================================
    // CURRENT TIME
    // =========================================================================

    /**
     * Get current time as UTC Carbon instance
     *
     * @return Carbon
     */
    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Get current time as ISO 8601 UTC string
     * Format: "2024-12-24T15:30:45.123Z"
     *
     * @return string
     */
    public static function now_iso(): string
    {
        return static::now()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Get current time as Unix timestamp (milliseconds)
     *
     * @return int
     */
    public static function now_ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    // =========================================================================
    // PARSING & VALIDATION
    // =========================================================================

    /**
     * Check if input is a valid datetime (not a date-only value)
     *
     * @param mixed $input
     * @return bool
     */
    public static function is_datetime($input): bool
    {
        if ($input instanceof Carbon || $input instanceof \DateTimeInterface) {
            return true;
        }

        if (is_numeric($input)) {
            return true; // Timestamps are datetimes
        }

        if (is_string($input)) {
            // Date-only strings are NOT datetimes
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
                return false;
            }

            // Has time component (T separator or HH:MM pattern)
            if (str_contains($input, 'T') || preg_match('/\d{2}:\d{2}/', $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse any reasonable datetime input to Carbon (UTC)
     * THROWS if passed a date-only string - use Rsx_Date for dates
     *
     * Accepts:
     * - Carbon instance (returned as copy, converted to UTC)
     * - ISO 8601 string: "2024-12-24T15:30:45Z" or "2024-12-24T15:30:45.123Z"
     * - Database format: "2024-12-24 15:30:45" (assumed UTC unless source_timezone specified)
     * - Unix timestamp (seconds or milliseconds - auto-detected)
     * - null (returns null)
     *
     * @param mixed $input
     * @param string|null $source_timezone If input has no timezone indicator, assume this (default: UTC)
     * @return Carbon|null
     * @throws \InvalidArgumentException If input is a date-only string
     */
    public static function parse($input, ?string $source_timezone = 'UTC'): ?Carbon
    {
        if ($input === null || $input === '') {
            return null;
        }

        // REJECT date-only strings - these should use Rsx_Date
        if (is_string($input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            throw new \InvalidArgumentException(
                "Rsx_Time::parse() received date-only string '{$input}'. " .
                "Use Rsx_Date::parse() for dates without time components."
            );
        }

        if ($input instanceof Carbon) {
            return $input->copy()->setTimezone('UTC');
        }

        if ($input instanceof \DateTimeInterface) {
            return Carbon::instance($input)->setTimezone('UTC');
        }

        if (is_numeric($input)) {
            // Detect milliseconds vs seconds (after year 2001, ms > 10 digits)
            if ($input > 10000000000) {
                return Carbon::createFromTimestampMs((int) $input, 'UTC');
            }
            return Carbon::createFromTimestamp((int) $input, 'UTC');
        }

        if (is_string($input)) {
            // ISO 8601 with timezone indicator - parse directly
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $input)) {
                return Carbon::parse($input)->setTimezone('UTC');
            }

            // Database format (no timezone indicator) - use source_timezone
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $input)) {
                // Handle optional milliseconds
                $format = strlen($input) > 19 ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s';
                return Carbon::createFromFormat(
                    $format,
                    $input,
                    $source_timezone
                )->setTimezone('UTC');
            }

            // Unrecognized format - this is a bug in calling code
            throw new \InvalidArgumentException(
                "Rsx_Time::parse() received unrecognized datetime format: '{$input}'. " .
                "Supported formats: ISO 8601 (2024-12-24T15:30:45Z) or database (2024-12-24 15:30:45)."
            );
        }

        return null;
    }

    // =========================================================================
    // TIMEZONE CONVERSION
    // =========================================================================

    /**
     * Convert time to a specific timezone
     *
     * @param mixed $time Parseable time input
     * @param string $timezone IANA timezone (e.g., "America/Chicago")
     * @return Carbon
     * @throws \InvalidArgumentException If time cannot be parsed
     */
    public static function to_timezone($time, string $timezone): Carbon
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            throw new \InvalidArgumentException("Cannot parse time: " . print_r($time, true));
        }
        return $carbon->setTimezone($timezone);
    }

    /**
     * Convert time to current user's timezone
     * Falls back to site default, then system default
     *
     * @param mixed $time
     * @return Carbon
     */
    public static function to_user_timezone($time): Carbon
    {
        return static::to_timezone($time, static::get_user_timezone());
    }

    /**
     * The zone THIS REQUEST displays times in. Every tier is skipped when its value is
     * absent or not an IANA identifier, so the answer is always a real zone:
     *
     *   1. a signed-in staff user's EXPLICIT timezone - login_users.timezone when non-null
     *      (NULL means automatic). It outranks the cookie, which can be stale on the first
     *      request after the preference changed in another browser.
     *   2. the browser's zone - the rsx_tz cookie (Rsx_Timezone_Cookie.js); its error value
     *      is not a zone and falls through. Never read for an external API request, and a
     *      task, a command or a #[Run_As_Dispatcher] run has no cookie to read.
     *   3. the site's timezone (sites.timezone)
     *   4. the server's (get_server_timezone(), RSX_SERVER_TIMEZONE)
     *   5. UTC
     *
     * A PORTAL request starts at tier 2: a portal account has no personal zone, and a
     * staff sign-in on the same browser never lends it one.
     *
     * An invalid stored value is never an exception here - it falls through. The result is
     * cached and invalidated when the experience, the user, the site or the cookie changes.
     *
     * @return string IANA timezone identifier
     */
    public static function get_user_timezone(): string
    {
        $is_portal = Rsx_Portal::is_portal_request();
        $raw_user_id = $is_portal ? Portal_Session::get_portal_user_id() : Session::get_login_user_id();
        $current_user_id = $raw_user_id === null ? null : (int) $raw_user_id;
        $current_site_id = (int) ($is_portal ? Portal_Session::get_site_id() : Session::get_site_id());
        $browser_timezone = static::browser_timezone();

        if (static::$_cached_user_timezone !== null
            && static::$_cached_is_portal === $is_portal
            && static::$_cached_user_id === $current_user_id
            && static::$_cached_site_id === $current_site_id
            && static::$_cached_browser_timezone === $browser_timezone) {
            return static::$_cached_user_timezone;
        }

        $explicit = $is_portal ? null : static::explicit_user_timezone();

        // The site is read only when it is needed: a portal request with no declared site
        // throws on reading it (__current_site()).
        $site_timezone = null;
        if ($explicit === null && $browser_timezone === null) {
            $site_timezone = static::__current_site()?->timezone;
        }

        [$timezone, $source] = static::_resolve_timezone($explicit, $browser_timezone, $site_timezone);

        static::$_cached_user_timezone = $timezone;
        static::$_cached_user_timezone_source = $source;
        static::$_cached_is_portal = $is_portal;
        static::$_cached_user_id = $current_user_id;
        static::$_cached_site_id = $current_site_id;
        static::$_cached_browser_timezone = $browser_timezone;

        return $timezone;
    }

    /**
     * Where get_user_timezone()'s answer came from: TIMEZONE_SOURCE_EXPLICIT (the user's own
     * choice), _BROWSER (the rsx_tz cookie), _SITE or _SERVER. The last two say the framework
     * did NOT know where the person was - a reader must never present such a zone as their
     * location. Recorded beside every sign-in and acting context (rsx:man time, RECORDED
     * ZONES).
     *
     * @return string A TIMEZONE_SOURCE_* value
     */
    public static function get_user_timezone_source(): string
    {
        static::get_user_timezone();

        return static::$_cached_user_timezone_source;
    }

    /**
     * The resolution order, as a pure function: [zone, TIMEZONE_SOURCE_*] from an explicit
     * zone, a browser zone and a site zone, each skipped when it is null or not a zone, then
     * the server's. get_user_timezone() is this over the request; a sign-in record is this
     * over the identity being recorded, which need not be signed in yet.
     *
     * FRAMEWORK INTERNAL.
     *
     * @param string|null $explicit
     * @param string|null $browser
     * @param string|null $site
     * @return array{0: string, 1: string}
     */
    public static function _resolve_timezone(?string $explicit, ?string $browser, ?string $site): array
    {
        if (static::is_timezone($explicit)) {
            return [$explicit, self::TIMEZONE_SOURCE_EXPLICIT];
        }

        if (static::is_timezone($browser)) {
            return [$browser, self::TIMEZONE_SOURCE_BROWSER];
        }

        if (static::is_timezone($site)) {
            return [$site, self::TIMEZONE_SOURCE_SITE];
        }

        return [static::get_server_timezone(), self::TIMEZONE_SOURCE_SERVER];
    }

    /**
     * The signed-in staff user's EXPLICIT timezone, or null when they are automatic (or the
     * stored value is not a zone), on a portal request, or with nobody signed in.
     *
     * @return string|null
     */
    public static function explicit_user_timezone(): ?string
    {
        if (Rsx_Portal::is_portal_request()) {
            return null;
        }

        $login_user = Session::get_login_user();

        return ($login_user && static::is_timezone($login_user->timezone)) ? $login_user->timezone : null;
    }

    /**
     * The browser's zone from the rsx_tz cookie, or null when there is none, it holds the
     * error value or anything that is not a zone, or this is an external API request (the
     * key holder is described by the account, never by a cookie a client chose to send).
     *
     * @return string|null
     */
    public static function browser_timezone(): ?string
    {
        if (Session::is_api_request()) {
            return null;
        }

        return static::normalize_timezone(static::__timezone_cookie());
    }

    /**
     * The raw rsx_tz cookie (the test seam first), or null outside a web request.
     */
    private static function __timezone_cookie(): ?string
    {
        if (static::$browser_timezone_cookie_for_tests !== null) {
            return static::$browser_timezone_cookie_for_tests === '' ? null : static::$browser_timezone_cookie_for_tests;
        }

        if (php_sapi_name() === 'cli') {
            return null;
        }

        $value = request()->cookies->get(self::TIMEZONE_COOKIE);

        return is_string($value) ? $value : null;
    }

    /**
     * Is this an IANA timezone identifier PHP knows?
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_timezone($value): bool
    {
        static $known = null;

        if (!is_string($value) || $value === '') {
            return false;
        }

        if ($known === null) {
            $known = array_flip(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC));
        }

        return isset($known[$value]);
    }

    /**
     * The identifier PHP knows for a zone a BROWSER reported, or null when it is none.
     *
     * Browsers report ICU's canonical names, some of which are older spellings PHP's own
     * zone database may not carry at all (Chromium reports Asia/Calcutta; PHP knows only
     * Asia/Kolkata). Such a name is mapped through ICU to the equivalent identifier PHP
     * knows, so the user renders in their own zone rather than falling to the site's.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function normalize_timezone($value): ?string
    {
        if (static::is_timezone($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '' || !str_contains($value, '/')) {
            return null;
        }

        $count = \IntlTimeZone::countEquivalentIDs($value);
        for ($i = 0; $i < $count; $i++) {
            $equivalent = \IntlTimeZone::getEquivalentID($value, $i);
            if (is_string($equivalent) && str_contains($equivalent, '/') && static::is_timezone($equivalent)) {
                return $equivalent;
            }
        }

        return null;
    }

    /**
     * Set the signed-in user's timezone preference (STAFF only): an IANA identifier, or
     * null for AUTOMATIC (each browser's own zone, through the rsx_tz cookie).
     *
     * Writes login_users.timezone, then INVALIDATES the resolution cache - without that,
     * code running later in this same request (including the Ajax envelope's own
     * _user_timezone field) would keep serving the zone resolved before the write.
     *
     * REFUSALS:
     * - Unknown IANA identifier -> InvalidArgumentException. This is the one refusal
     *   driven by user-supplied data; a controller converts it to a validation error.
     * - Portal request, or no signed-in login user -> shouldnt_happen(). Both are
     *   broken assumptions, not input: a portal account has no personal zone, and the
     *   surfaces that call this are gated is_logged_in.
     *
     * @param string|null $timezone IANA timezone identifier (e.g. "America/Chicago"), or null
     * @return bool Whether the RESOLVED zone of this request changed - what tells the
     *              client its page is now in the wrong zone
     * @throws \InvalidArgumentException If $timezone is not a known identifier
     */
    public static function set_user_timezone(?string $timezone): bool
    {
        if ($timezone !== null && !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException(
                "Rsx_Time::set_user_timezone() received unknown timezone identifier '{$timezone}'."
            );
        }

        if (Rsx_Portal::is_portal_request()) {
            shouldnt_happen('Rsx_Time::set_user_timezone() called on a portal request - portal accounts have no personal timezone');
        }

        $login_user = Session::get_login_user();
        if (!$login_user) {
            shouldnt_happen('Rsx_Time::set_user_timezone() called with no signed-in login user');
        }

        $before = static::get_user_timezone();

        // Explicit assignment - no mass assignment.
        $login_user->timezone = $timezone;
        $login_user->save();

        static::_clear_user_timezone_cache();

        return static::get_user_timezone() !== $before;
    }

    /**
     * The timezone a NEW login identity starts with when its creator set none, from
     * rsx.datetime.default_user_timezone: 'automatic' -> null; 'site' -> the creating
     * site's zone (the server's when no site is declared); 'server' -> the server's; an
     * IANA identifier -> itself. 'site' and 'server' are COPIED: a later change to either
     * does not reach an account created before it.
     *
     * @return string|null
     * @throws \RuntimeException When the configured value is none of those
     */
    public static function default_user_timezone(): ?string
    {
        $configured = (string) config('rsx.datetime.default_user_timezone');

        if ($configured === '' || $configured === 'automatic') {
            return null;
        }

        if ($configured === 'site') {
            return static::get_site_timezone();
        }

        if ($configured === 'server') {
            return static::get_server_timezone();
        }

        if (static::is_timezone($configured)) {
            return $configured;
        }

        throw new \RuntimeException(
            "rsx.datetime.default_user_timezone is '{$configured}': it must be automatic, site, server or an IANA "
            . "timezone identifier (e.g. America/Chicago). Correct it in rsx/resource/config/rsx.php."
        );
    }

    /**
     * Make sure this page is rendered knowing the browser's zone: the first-visit
     * interstitial. The framework's page dispatch calls it for every matched page route
     * after the gates (Dispatcher); it is public so a test, or a route the framework does
     * not dispatch, can ask the same question.
     *
     * Answers:
     *   false      not a request this concerns - not a GET document navigation that wants
     *              HTML (Ajax, the external API, a task or a command, a POST, a download,
     *              a JSON route, a webhook) - or a signed-in user with an explicit zone (the
     *              cookie is never consulted), or the cookie holds the error value, or the
     *              reload marker is present and the cookie is still missing (the browser
     *              refuses cookies: render down the remaining tiers rather than loop)
     *   true       the cookie holds a zone
     *   Response   no cookie yet: a minimal page whose script writes rsx_tz and reloads the
     *              same URL with the marker; a <noscript> refresh does the same for a
     *              client that runs no script
     *
     * @param \Illuminate\Http\Request $request
     * @return bool|\Symfony\Component\HttpFoundation\Response
     */
    public static function ensure_timezone(\Illuminate\Http\Request $request)
    {
        // A task or a command has no browser to send the page to (the seam stands one in).
        if (php_sapi_name() === 'cli' && static::$browser_timezone_cookie_for_tests === null) {
            return false;
        }

        if (Session::is_api_request()
            || \App\RSpade\Core\Dispatch\Rsx_Request_Channel::current() !== \App\RSpade\Core\Dispatch\Rsx_Request_Channel::PAGE
            || $request->getMethod() !== 'GET'
            || !str_contains((string) $request->headers->get('Accept', ''), 'text/html')
            || !in_array((string) $request->headers->get('Sec-Fetch-Dest', 'document'), ['document', ''], true)) {
            return false;
        }

        if (static::explicit_user_timezone() !== null) {
            return false;
        }

        $cookie = static::__timezone_cookie();

        if (static::normalize_timezone($cookie) !== null) {
            return true;
        }

        if ($cookie !== null && $cookie !== '') {
            // The error value (or garbage): the browser answered; render without it.
            return false;
        }

        if ($request->query->has(self::TIMEZONE_RELOAD_MARKER)) {
            return false;
        }

        return static::__timezone_interstitial($request);
    }

    /**
     * The first-visit page: no content, one nonce'd script that writes the cookie exactly as
     * Rsx_Timezone_Cookie.js does and replaces the location with the marked URL.
     */
    private static function __timezone_interstitial(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $uri = $request->getRequestUri();
        $marked = $uri . (str_contains($uri, '?') ? '&' : '?') . self::TIMEZONE_RELOAD_MARKER . '=1';

        $config = json_encode([
            'name' => self::TIMEZONE_COOKIE,
            'error' => self::TIMEZONE_COOKIE_ERROR,
            'max_age' => self::TIMEZONE_COOKIE_MAX_AGE,
            'error_max_age' => self::TIMEZONE_COOKIE_ERROR_MAX_AGE,
            'url' => $marked,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $nonce = htmlspecialchars(\App\RSpade\Core\Csp\Rsx_Csp::nonce(), ENT_QUOTES);
        $refresh = htmlspecialchars('0;url=' . $marked, ENT_QUOTES);

        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title></title>
<noscript><meta http-equiv="refresh" content="{$refresh}"></noscript>
<script nonce="{$nonce}">
(function (c) {
    var zone = null;
    try {
        zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        new Intl.DateTimeFormat('en-US', {timeZone: zone});
    } catch (e) {
        zone = null;
    }
    var ok = typeof zone === 'string' && zone !== '';
    document.cookie = c.name + '=' + encodeURIComponent(ok ? zone : c.error)
        + '; Path=/; Max-Age=' + (ok ? c.max_age : c.error_max_age) + '; SameSite=Lax'
        + (location.protocol === 'https:' ? '; Secure' : '');
    location.replace(c.url);
})({$config});
</script>
</head><body></body></html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * One timestamp as an external API carries it: the UTC value, the same instant in the
     * request's zone with its offset, and that zone's identifier.
     *
     *     ['last_action_at' => '2026-10-29T10:13:03.000Z',
     *      'last_action_at__localized' => '2026-10-29T05:13:03.000-05:00',
     *      'last_action_at__localized_tz' => 'America/Chicago']
     *
     * The model serializer uses it for every DATETIME/TIMESTAMP column of an API response;
     * a controller building an API payload by hand uses it for each timestamp it writes, so
     * an API response is never half-localized. A null value gives three nulls. Never for a
     * DATE: a calendar date has no zone, and converting one moves it a day.
     *
     * @param string $field
     * @param mixed $value Anything parse() accepts, or null
     * @return array<string,string|null>
     */
    public static function encode_for_output(string $field, $value): array
    {
        $utc = ($value === null || $value === '') ? null : static::to_iso($value);

        if ($utc === null) {
            return [$field => null, $field . '__localized' => null, $field . '__localized_tz' => null];
        }

        $timezone = static::get_user_timezone();

        return [
            $field => $utc,
            $field . '__localized' => static::parse($utc)->setTimezone($timezone)->format('Y-m-d\TH:i:s.vP'),
            $field . '__localized_tz' => $timezone,
        ];
    }

    /**
     * Drop the memoized user-timezone resolution, forcing the next
     * get_user_timezone() call to resolve from scratch.
     *
     * Public because it is also the seam a test uses to prove the cache is honest
     * across a write it did not make itself.
     */
    public static function _clear_user_timezone_cache(): void
    {
        static::$_cached_user_timezone = null;
        static::$_cached_user_timezone_source = null;
        static::$_cached_is_portal = null;
        static::$_cached_user_id = null;
        static::$_cached_site_id = null;
        static::$_cached_browser_timezone = null;
    }

    /**
     * The test harness's request boundary: forget the browser cookie a test stood in for
     * ($browser_timezone_cookie_for_tests) and every resolved zone.
     */
    public static function _testing_reset(): void
    {
        static::$browser_timezone_cookie_for_tests = null;
        static::_clear_user_timezone_cache();
    }

    /**
     * Every IANA timezone identifier, mapped to a selectable label carrying its
     * CURRENT UTC offset: "America/Chicago" => "America/Chicago (UTC-05:00)".
     *
     * Offsets are computed at call time, so the label is DST-honest for the moment
     * the list is requested (Chicago reads -06:00 in January, -05:00 in July).
     *
     * @return array<string,string> identifier => label, sorted by identifier
     */
    public static function timezone_options(): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $options = [];

        foreach (timezone_identifiers_list() as $identifier) {
            $offset_seconds = (new \DateTimeZone($identifier))->getOffset($now);
            $sign = $offset_seconds < 0 ? '-' : '+';
            $offset_seconds = abs($offset_seconds);
            $hours = intdiv($offset_seconds, 3600);
            $minutes = intdiv($offset_seconds % 3600, 60);

            $options[$identifier] = sprintf('%s (UTC%s%02d:%02d)', $identifier, $sign, $hours, $minutes);
        }

        ksort($options);

        return $options;
    }

    /**
     * Get the current site's timezone (ignoring user preference)
     * Resolution: sites.timezone -> get_server_timezone()
     *
     * @return string IANA timezone identifier
     */
    public static function get_site_timezone(): string
    {
        $site = static::__current_site();
        if ($site && static::is_timezone($site->timezone)) {
            return $site->timezone;
        }

        return static::get_server_timezone();
    }

    /**
     * The server's timezone: config('rsx.datetime.server_timezone'), from
     * RSX_SERVER_TIMEZONE, UTC when unset.
     *
     * Every process still RUNS in UTC (PHP's default zone and the MySQL session both);
     * this is the zone a #[Schedule] wall-clock time is read in, the zone a new site
     * record gets when none was assigned, and the zone times are displayed in when
     * neither the user nor the site names one.
     *
     * @return string IANA timezone identifier
     * @throws \RuntimeException When the configured identifier is not a known zone
     */
    public static function get_server_timezone(): string
    {
        $timezone = (string) config('rsx.datetime.server_timezone');
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \RuntimeException(
                "RSX_SERVER_TIMEZONE is '{$timezone}', which is not an IANA timezone identifier "
                . "(e.g. America/Chicago). Correct it in .env, or leave it blank for UTC."
            );
        }

        return $timezone;
    }

    /**
     * The operating system's own timezone - what `date` prints on this host - read from
     * the TZ environment variable, then the /etc/localtime link, then /etc/timezone.
     * A host with none of them configured is UTC, which is the C library's own default.
     *
     * Only the log timestamps use it (System_Timezone_Log_Tap), so a log reads in the
     * same clock as the host's other logs. Application time never does.
     *
     * @return string IANA timezone identifier
     */
    public static function get_system_timezone(): string
    {
        $known = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);

        $tz = (string) getenv('TZ');
        $tz = ltrim($tz, ':');
        if ($tz !== '' && in_array($tz, $known, true)) {
            return $tz;
        }

        if (is_link('/etc/localtime')) {
            $target = (string) readlink('/etc/localtime');
            $position = strpos($target, 'zoneinfo/');
            if ($position !== false) {
                $name = substr($target, $position + strlen('zoneinfo/'));
                if (in_array($name, $known, true)) {
                    return $name;
                }
            }
        }

        if (is_readable('/etc/timezone')) {
            $name = trim((string) file_get_contents('/etc/timezone'));
            if (in_array($name, $known, true)) {
                return $name;
            }
        }

        return 'UTC';
    }

    /**
     * The site whose timezone this request lives under, resolved by the EXPERIENCE of
     * the request (Rsx_Portal::is_portal_request()) rather than by who is signed in -
     * one browser session carries both identities, so an identity test picks the wrong
     * tenant in both directions.
     *
     * A portal request with no declared site throws (Portal_Session::get_site_id's
     * contract): the same refusal the ORM's tenant boundary makes, for the same reason.
     *
     * @return \App\RSpade\Core\Models\Site_Model|null
     */
    private static function __current_site()
    {
        if (Rsx_Portal::is_portal_request()) {
            return Portal_Session::get_site();
        }

        return Session::get_site();
    }

    /**
     * The zone abbreviation at an instant ("CST" in January, "CDT" in July, "IST", "UTC-3"),
     * from the tz database through Rsx_Timestamp_Format - the same string the JS twin answers.
     *
     * @param mixed $time
     * @param string|null $timezone If null, uses user's timezone
     * @return string '' for null or an unparseable value
     */
    public static function get_timezone_abbr($time, ?string $timezone = null): string
    {
        $epoch = static::__epoch_to_format($time);

        return $epoch === null ? '' : Rsx_Timestamp_Format::zone_abbreviation($epoch, $timezone ?? static::get_user_timezone());
    }

    // =========================================================================
    // A DAY AND A CLOCK IN A NAMED ZONE (rsx:man time_value_design)
    // =========================================================================

    /**
     * The calendar date an instant falls on in $zone ("2026-10-29"). Never slice a date out of
     * an ISO string or take SQL DATE() of an instant: that is the UTC date, which is the next
     * day for every evening instant west of UTC.
     *
     * @param mixed $time Anything parse() accepts
     * @param string $zone IANA timezone identifier
     * @return string|null "Y-m-d", or null for null
     */
    public static function local_date($time, string $zone): ?string
    {
        $carbon = static::parse($time);

        return $carbon ? $carbon->setTimezone(static::__zone($zone))->format('Y-m-d') : null;
    }

    /**
     * The wall-clock time an instant shows in $zone ("22:30").
     *
     * @param mixed $time Anything parse() accepts
     * @param string $zone IANA timezone identifier
     * @return string|null "H:i", or null for null
     */
    public static function local_clock($time, string $zone): ?string
    {
        $carbon = static::parse($time);

        return $carbon ? $carbon->setTimezone(static::__zone($zone))->format('H:i') : null;
    }

    /**
     * The instant a person means by a DATE and a wall CLOCK typed in $zone - the inverse of
     * local_date() + local_clock(). A clock skipped by a daylight-saving jump resolves to the
     * instant after it, the way the clock itself moves.
     *
     * @param string $date "Y-m-d"
     * @param string|null $clock "H:i" or "H:i:s"; null or '' answers null (no time was given)
     * @param string $zone IANA timezone identifier
     * @return string|null ISO 8601 UTC
     * @throws \InvalidArgumentException For a malformed date or clock, or an unknown zone
     */
    public static function combine_date_and_time(string $date, ?string $clock, string $zone): ?string
    {
        if ($clock === null || $clock === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $clock)) {
            throw new \InvalidArgumentException("Rsx_Time::combine_date_and_time() needs a Y-m-d date and an H:i clock, got '{$date}' and '{$clock}'.");
        }

        $local = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' ' . (strlen($clock) === 5 ? $clock . ':00' : $clock), static::__zone($zone));

        return static::to_iso($local->setTimezone('UTC'));
    }

    /**
     * The instant $date begins in $zone: the inclusive lower bound of a date window over
     * instants. Converted per date, so a window crossing a daylight-saving change is right.
     *
     *     ->where('started_at', '>=', Rsx_Time::to_database(Rsx_Time::day_start_utc($from, $zone)))
     *     ->where('started_at', '<', Rsx_Time::to_database(Rsx_Time::day_after_utc($to, $zone)))
     *
     * @param string $date "Y-m-d"
     * @param string $zone IANA timezone identifier
     * @return string ISO 8601 UTC
     */
    public static function day_start_utc(string $date, string $zone): string
    {
        return static::combine_date_and_time($date, '00:00', $zone);
    }

    /**
     * The instant the day AFTER $date begins in $zone: the EXCLUSIVE upper bound of a date
     * window ending on $date.
     *
     * @param string $date "Y-m-d"
     * @param string $zone IANA timezone identifier
     * @return string ISO 8601 UTC
     */
    public static function day_after_utc(string $date, string $zone): string
    {
        return static::day_start_utc(Rsx_Date::add_days($date, 1), $zone);
    }

    /**
     * A zone a caller named: refused loudly when it is not one.
     */
    private static function __zone(string $zone): \DateTimeZone
    {
        if (!static::is_timezone($zone)) {
            throw new \InvalidArgumentException("'{$zone}' is not an IANA timezone identifier (e.g. America/Chicago).");
        }

        return new \DateTimeZone($zone);
    }

    // =========================================================================
    // SERIALIZATION (for JSON/API responses)
    // =========================================================================

    /**
     * Serialize time to ISO 8601 UTC string for JSON
     * Format: "2024-12-24T15:30:45.123Z"
     *
     * @param mixed $time
     * @return string|null
     */
    public static function to_iso($time): ?string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return null;
        }
        return $carbon->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Serialize time to Unix milliseconds for JavaScript
     *
     * @param mixed $time
     * @return int|null
     */
    public static function to_ms($time): ?int
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return null;
        }
        return (int) ($carbon->timestamp * 1000 + (int) ($carbon->micro / 1000));
    }

    // =========================================================================
    // DURATION HANDLING
    // =========================================================================

    /**
     * Calculate duration between two times in seconds
     *
     * @param mixed $start
     * @param mixed $end
     * @return int Seconds (can be negative if end < start)
     */
    public static function diff_seconds($start, $end): int
    {
        $start_carbon = static::parse($start);
        $end_carbon = static::parse($end);

        if (!$start_carbon || !$end_carbon) {
            return 0;
        }

        // Carbon 3's diffIn*() family returns a FLOAT. This function promises whole
        // seconds, so the narrowing is deliberate and spelled out: (int) truncates toward
        // zero, which is Carbon 2's behaviour and the convention every relative-time
        // formatter follows ("59 minutes ago" at 59.6, a deadline never reported reached
        // early). An implicit float-to-int narrowing is a deprecation today and a
        // TypeError in PHP 9 - an intentional lossy conversion is always written down.
        return (int) $start_carbon->diffInSeconds($end_carbon, false);
    }

    /**
     * Seconds until a future time (negative if past)
     *
     * @param mixed $time
     * @return int
     */
    public static function seconds_until($time): int
    {
        return static::diff_seconds(static::now(), $time);
    }

    /**
     * Seconds since a past time (negative if future)
     *
     * @param mixed $time
     * @return int
     */
    public static function seconds_since($time): int
    {
        return static::diff_seconds($time, static::now());
    }

    /**
     * Format duration as human-readable string
     *
     * @param int $seconds
     * @param bool $short Use short format ("2h 30m") vs long ("2 hours and 30 minutes")
     * @return string
     */
    public static function duration_to_human(int $seconds, bool $short = false): string
    {
        $negative = $seconds < 0;
        $seconds = abs($seconds);

        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($short) {
            if ($days > 0) $parts[] = "{$days}d";
            if ($hours > 0) $parts[] = "{$hours}h";
            if ($minutes > 0) $parts[] = "{$minutes}m";
            if ($secs > 0 && empty($parts)) $parts[] = "{$secs}s";
            $result = implode(' ', $parts) ?: '0s';
        } else {
            if ($days > 0) $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
            if ($hours > 0) $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
            if ($minutes > 0) $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
            if ($secs > 0 && empty($parts)) $parts[] = $secs . ' ' . ($secs === 1 ? 'second' : 'seconds');

            if (count($parts) > 1) {
                $last = array_pop($parts);
                $result = implode(', ', $parts) . ' and ' . $last;
            } else {
                $result = $parts[0] ?? '0 seconds';
            }
        }

        return $negative ? '-' . $result : $result;
    }

    /**
     * Format relative time ("2 hours ago", "in 3 days")
     *
     * @param mixed $time
     * @return string
     */
    public static function relative($time): string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return '';
        }
        return $carbon->diffForHumans();
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /**
     * Add duration to time
     *
     * @param mixed $time
     * @param int $seconds
     * @return string ISO 8601 UTC string
     */
    public static function add($time, int $seconds): string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            throw new \InvalidArgumentException("Cannot parse time");
        }
        return $carbon->addSeconds($seconds)->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Subtract duration from time
     *
     * @param mixed $time
     * @param int $seconds
     * @return string ISO 8601 UTC string
     */
    public static function subtract($time, int $seconds): string
    {
        return static::add($time, -$seconds);
    }

    // =========================================================================
    // COMPARISON
    // =========================================================================

    /**
     * Check if time is in the past
     *
     * @param mixed $time
     * @return bool
     */
    public static function is_past($time): bool
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return false;
        }
        return $carbon->isPast();
    }

    /**
     * Check if time is in the future
     *
     * @param mixed $time
     * @return bool
     */
    public static function is_future($time): bool
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return false;
        }
        return $carbon->isFuture();
    }

    /**
     * Check if time is today (in user's timezone)
     *
     * @param mixed $time
     * @return bool
     */
    public static function is_today($time): bool
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return false;
        }
        return static::to_user_timezone($carbon)->isToday();
    }

    // =========================================================================
    // FORMATTING - Rsx_Timestamp_Format, byte-identical to the JavaScript twins
    // =========================================================================

    /**
     * The instant a formatter prints, as epoch seconds: null for null, '' or an unparseable
     * value (the formatter answers ''); a date-only value THROWS (a calendar date is
     * Rsx_Date's, and printing it as a moment moves it a day west of UTC).
     */
    private static function __epoch_to_format($time): ?int
    {
        if (is_string($time) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $time)) {
            static::parse($time);
        }

        try {
            $carbon = static::parse($time);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return $carbon ? $carbon->getTimestamp() : null;
    }

    /**
     * A moment, long form: "Oct 9, 2026 2:30 PM" - the application's patterns
     * (rsx.datetime.display.date + ' ' + .time) - plus " IST" when $timezone differs from the
     * viewer's at that instant (by offset or by abbreviation). The JS twin prints the same string.
     *
     * @param mixed $time
     * @param string|null $timezone The zone to print in; null = the viewer's (get_user_timezone())
     * @return string '' for null or an unparseable value
     */
    public static function format_timestamp($time, ?string $timezone = null): string
    {
        $epoch = static::__epoch_to_format($time);
        if ($epoch === null) {
            return '';
        }

        $viewer = static::get_user_timezone();

        return Rsx_Timestamp_Format::timestamp($epoch, $timezone ?? $viewer, $viewer);
    }

    /**
     * A moment, dense form, for grid cells, logs and history rows: "10-09 2:30p", with the year
     * ("10-09-25 2:30p") only when it is not the current year - both judged in the zone printed -
     * plus the zone suffix. Not configurable.
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_timestamp_short($time, ?string $timezone = null): string
    {
        $epoch = static::__epoch_to_format($time);
        if ($epoch === null) {
            return '';
        }

        $viewer = static::get_user_timezone();

        return Rsx_Timestamp_Format::timestamp_short($epoch, $timezone ?? $viewer, $viewer, static::now()->getTimestamp());
    }

    /**
     * A start and an end in one zone: "Oct 9, 2026 2:30 - 4:00 PM" on one local day (the date
     * once, the shared meridiem once), "Oct 9, 2026 11:00 PM - Oct 10, 2026 1:00 AM" across days,
     * the start alone for a null end; the zone suffix once, at the end. $short gives
     * "10-09 2:30-4:00p". (The separator is an en dash.)
     *
     * @param mixed $start
     * @param mixed $end
     * @param string|null $timezone
     * @param bool $short
     * @return string '' when $start is null or unparseable
     */
    public static function format_time_range($start, $end, ?string $timezone = null, bool $short = false): string
    {
        $start_epoch = static::__epoch_to_format($start);
        if ($start_epoch === null) {
            return '';
        }

        $viewer = static::get_user_timezone();

        return Rsx_Timestamp_Format::time_range(
            $start_epoch,
            static::__epoch_to_format($end),
            $timezone ?? $viewer,
            $viewer,
            $short,
            static::now()->getTimestamp()
        );
    }

    /**
     * The date part of a moment: "Oct 9, 2026" (rsx.datetime.display.date).
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_date($time, ?string $timezone = null): string
    {
        $epoch = static::__epoch_to_format($time);

        return $epoch === null ? '' : Rsx_Timestamp_Format::pattern($epoch, $timezone ?? static::get_user_timezone(), Rsx_Timestamp_Format::display_patterns()['date']);
    }

    /**
     * The clock part of a moment: "2:30 PM" (rsx.datetime.display.time).
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_time($time, ?string $timezone = null): string
    {
        $epoch = static::__epoch_to_format($time);

        return $epoch === null ? '' : Rsx_Timestamp_Format::pattern($epoch, $timezone ?? static::get_user_timezone(), Rsx_Timestamp_Format::display_patterns()['time']);
    }

    // =========================================================================
    // COMPONENT EXTRACTORS
    // =========================================================================

    /**
     * Parse and convert to user's timezone (internal helper)
     * Returns null if invalid, throws on date-only input
     */
    private static function _to_user_carbon($time): ?Carbon
    {
        $carbon = static::parse($time);
        return $carbon ? static::to_user_timezone($carbon) : null;
    }

    /**
     * Get day of month (1-31). Uses user's timezone.
     */
    public static function day($time): ?int
    {
        return static::_to_user_carbon($time)?->day;
    }

    /**
     * Get day of week (0=Sunday, 6=Saturday). Uses user's timezone.
     */
    public static function dow($time): ?int
    {
        return static::_to_user_carbon($time)?->dayOfWeek;
    }

    /**
     * Get full day name ("Monday", "Tuesday", etc.). Uses user's timezone.
     */
    public static function dow_human($time): string
    {
        return static::_to_user_carbon($time)?->format('l') ?? '';
    }

    /**
     * Get short day name ("Mon", "Tue", etc.). Uses user's timezone.
     */
    public static function dow_short($time): string
    {
        return static::_to_user_carbon($time)?->format('D') ?? '';
    }

    /**
     * Get month (1-12). Uses user's timezone.
     */
    public static function month($time): ?int
    {
        return static::_to_user_carbon($time)?->month;
    }

    /**
     * Get full month name ("January", "February", etc.). Uses user's timezone.
     */
    public static function month_human($time): string
    {
        return static::_to_user_carbon($time)?->format('F') ?? '';
    }

    /**
     * Get short month name ("Jan", "Feb", etc.). Uses user's timezone.
     */
    public static function month_human_short($time): string
    {
        return static::_to_user_carbon($time)?->format('M') ?? '';
    }

    /**
     * Get year (e.g., 2025). Uses user's timezone.
     */
    public static function year($time): ?int
    {
        return static::_to_user_carbon($time)?->year;
    }

    /**
     * Get hour (0-23). Uses user's timezone.
     */
    public static function hour($time): ?int
    {
        return static::_to_user_carbon($time)?->hour;
    }

    /**
     * Get minute (0-59). Uses user's timezone.
     */
    public static function minute($time): ?int
    {
        return static::_to_user_carbon($time)?->minute;
    }

    // =========================================================================
    // DATABASE HELPERS
    // =========================================================================

    /**
     * Format time for database storage (UTC)
     * Returns "2024-12-24 15:30:45.123" format
     *
     * @param mixed $time
     * @return string|null
     */
    public static function to_database($time): ?string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return null;
        }
        return $carbon->format('Y-m-d H:i:s.v');
    }
}
