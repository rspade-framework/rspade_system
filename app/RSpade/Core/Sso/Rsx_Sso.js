/**
 * Rsx_Sso - the browser's view of which federated sign-in providers are live.
 *
 * It is a reader and nothing else. The whole ceremony is browser NAVIGATION - a button is an
 * anchor to /_sso/<key>/begin, and the provider navigates back to /_sso/<key>/callback - so
 * there is no client-side ceremony to implement and no Ajax anywhere in it. What the client
 * needs is the roster, and the server exported it into window.rsxapp.sso.
 *
 * ABSENT MEANS OFF. The key is omitted entirely when no provider is switched on (the
 * turnstile pattern), because a key that is always present but sometimes empty makes "off"
 * and "configured but empty" indistinguishable. So enabled_providers() answers [] for a
 * page that was served with no key at all, and a login page renders no divider and no
 * buttons rather than an empty row of chrome.
 *
 * WHAT IS IN A PROVIDER ENTRY is public by contract, and it is exactly what the server's
 * Rsx_Sso::enabled_providers() returns: {key, label, begin_url, icon_svg}. No client id, no
 * secret, nothing that is not already visible in the authorize URL the button leads to.
 *
 * See: php artisan rsx:man sso
 */
class Rsx_Sso {
    /**
     * Every provider this install has switched on, in the order a login page renders them.
     *
     * @returns {Array} [{key, label, begin_url, icon_svg}], possibly empty.
     */
    static enabled_providers() {
        const sso = window.rsxapp.sso;

        return sso && is_array(sso.providers) ? sso.providers : [];
    }

    /**
     * Is federated sign-in live on this install?
     *
     * @returns {boolean}
     */
    static is_enabled() {
        return Rsx_Sso.enabled_providers().length > 0;
    }
}
