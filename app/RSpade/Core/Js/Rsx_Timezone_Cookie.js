/**
 * Rsx_Timezone_Cookie - keep the server told which zone this browser is in.
 *
 * The display zone is resolved PER REQUEST on the server (Rsx_Time::get_user_timezone()):
 * a signed-in user's EXPLICIT zone, else the browser's - which reaches the server only as
 * the rsx_tz cookie this class writes - else the site's, the server's, UTC. Nothing is ever
 * written to the account: two browsers in two zones on one account each render in their own,
 * and a headless browser (rsx:debug) changes nobody's preference.
 *
 * EVERY PAGE (core init, before the first Ajax call):
 *   - signed out, a portal page, or an AUTOMATIC user (rsxapp.user_timezone_preference is
 *     null): write the browser's current zone - or ERROR_VALUE when the browser cannot
 *     report one, with a short lifetime so it tries again tomorrow;
 *   - a user with an EXPLICIT zone: clear the cookie, which the server would not consult.
 *   - and strip the first-visit interstitial's reload marker (?_rsx_tz=1) from the address.
 *
 * AN SPA PAGE ALSO WATCHES (watch(), called by Spa's router initialization), because it may
 * live for days: there is no browser event for a timezone change, so the zone is re-read every
 * minute and on visibilitychange / focus (a laptop waking in a new zone). On a change the
 * cookie is updated at once, Spa.disable() makes the next link a full page load - the
 * destination renders wholly in the new zone - and Rsx_Time stops adopting the zone from Ajax
 * envelopes, so the CURRENT page keeps one zone until that load. An explicit-zone user is
 * never watched.
 *
 * The spellings match Rsx_Time's PHP constants (TIMEZONE_COOKIE and friends), which the
 * server-rendered first-visit interstitial also writes with.
 */
class Rsx_Timezone_Cookie {
    static COOKIE_NAME = 'rsx_tz';
    static ERROR_VALUE = 'error_reading_browser_timezone';
    static MAX_AGE = 31536000;
    static ERROR_MAX_AGE = 86400;
    static RELOAD_MARKER = '_rsx_tz';
    static POLL_MS = 60000;

    /** The zone the watcher last wrote; null until watch() starts. @type {string|null} */
    static _watched_value = null;

    /** Whether a change was detected on this page. @type {boolean} */
    static _changed = false;

    /**
     * Does this page keep the cookie current? Yes unless a user with an explicit zone is
     * signed in (the server reads that zone and never the cookie).
     *
     * @param {Object} rsxapp
     * @returns {boolean}
     */
    static wants_cookie(rsxapp) {
        return !(rsxapp && is_string(rsxapp.user_timezone_preference) && rsxapp.user_timezone_preference !== '');
    }

    /**
     * The browser's IANA zone, or ERROR_VALUE when it cannot report a usable one.
     *
     * @returns {string}
     */
    static browser_value() {
        try {
            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (!is_string(zone) || zone === '') return Rsx_Timezone_Cookie.ERROR_VALUE;
            // A zone Intl itself cannot format in is no zone.
            new Intl.DateTimeFormat('en-US', { timeZone: zone });
            return zone;
        } catch (error) {
            return Rsx_Timezone_Cookie.ERROR_VALUE;
        }
    }

    /**
     * The cookie's current value, or null when it is absent.
     *
     * @returns {string|null}
     */
    static read() {
        const prefix = Rsx_Timezone_Cookie.COOKIE_NAME + '=';
        for (const part of document.cookie.split(';')) {
            const cookie = part.trim();
            if (cookie.startsWith(prefix)) return decodeURIComponent(cookie.substring(prefix.length));
        }
        return null;
    }

    /**
     * Write a value: a zone for a year, the error value for a day.
     *
     * @param {string} value
     */
    static write(value) {
        const max_age = value === Rsx_Timezone_Cookie.ERROR_VALUE ? Rsx_Timezone_Cookie.ERROR_MAX_AGE : Rsx_Timezone_Cookie.MAX_AGE;
        document.cookie = Rsx_Timezone_Cookie.COOKIE_NAME + '=' + encodeURIComponent(value)
            + '; Path=/; Max-Age=' + max_age + '; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');
    }

    /** Remove the cookie. */
    static clear() {
        document.cookie = Rsx_Timezone_Cookie.COOKIE_NAME + '=; Path=/; Max-Age=0; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');
    }

    /**
     * Framework core init - every page.
     */
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) return;

        Rsx_Timezone_Cookie._strip_reload_marker();

        if (Rsx_Timezone_Cookie.wants_cookie(window.rsxapp)) {
            Rsx_Timezone_Cookie.write(Rsx_Timezone_Cookie.browser_value());
        } else if (Rsx_Timezone_Cookie.read() !== null) {
            Rsx_Timezone_Cookie.clear();
        }
    }

    /**
     * Start watching for a zone change (an SPA page; called once by Spa's initialization).
     */
    static watch() {
        if (Rsx.is_ssr() || !Rsx_Timezone_Cookie.wants_cookie(window.rsxapp)) return;
        if (Rsx_Timezone_Cookie._watched_value !== null) return;

        Rsx_Timezone_Cookie._watched_value = Rsx_Timezone_Cookie.browser_value();

        setInterval(() => Rsx_Timezone_Cookie.check(), Rsx_Timezone_Cookie.POLL_MS);
        document.addEventListener('visibilitychange', () => Rsx_Timezone_Cookie.check());
        window.addEventListener('focus', () => Rsx_Timezone_Cookie.check());
    }

    /**
     * Compare the browser's zone with the one last written; act on a change.
     *
     * @returns {boolean} whether a change was found
     */
    static check() {
        const value = Rsx_Timezone_Cookie.browser_value();
        if (value === Rsx_Timezone_Cookie._watched_value) return false;

        Rsx_Timezone_Cookie._watched_value = value;
        Rsx_Timezone_Cookie.write(value);

        if (!Rsx_Timezone_Cookie._changed) {
            Rsx_Timezone_Cookie._changed = true;
            console_debug('TIMEZONE', 'Browser timezone changed to ' + value + '; the next navigation is a full page load');
            Rsx_Time._freeze_timezone();
            Spa.disable();
        }

        return true;
    }

    /**
     * Remove ?_rsx_tz=1 (the interstitial's reload marker) from the address without a
     * navigation, so it is never bookmarked, shared or read as a route argument.
     */
    static _strip_reload_marker() {
        const url = new URL(window.location.href);
        if (!url.searchParams.has(Rsx_Timezone_Cookie.RELOAD_MARKER)) return;

        url.searchParams.delete(Rsx_Timezone_Cookie.RELOAD_MARKER);
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    }
}
