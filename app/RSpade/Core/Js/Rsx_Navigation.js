/**
 * Rsx_Navigation - the ONE door through which code moves the browser to another URL.
 *
 * Applications call the facades Rsx.navigate(url, options) and Rsx.redirect(url, options);
 * the SPA's link handler calls the same door. Whatever the target - a page of the SPA on
 * screen, a Blade page, another SPA bootstrap, a download route, another site - the call is
 * the same, and the pipeline below decides how the browser gets there:
 *
 *   1. validate   the options (an unknown key, value or combination throws)
 *   2. classify   the target against the page on screen: elsewhere | same_site | in_app
 *   3. augment    carry the current breadcrumb origin (_via) onto the target
 *   4. transport  the one place that writes location or history:
 *                   load 'none'                      rewrite the address bar only
 *                   fragment-only move               a native fragment navigation
 *                   exactly the URL on screen        location.reload()
 *                   in_app, SPA enabled              Spa._dispatch() (no page load)
 *                   anything else                    a full page load, with the hand-off
 *   5. hand-off   before a full load, what the in-app path would have told the next page
 *                 (how its _via arrived, and the decider of the page being left) is written
 *                 to session storage; the next SPA document reads it once at boot. So a
 *                 full load keeps a breadcrumb origin exactly as an SPA navigation does.
 *
 * Options (each absent = its default):
 *   history     'push' (default) | 'replace'
 *   via         'carry' (default) | 'drop' | 'continue'   (rsx:man breadcrumb_origins)
 *   load        'auto' (default) | 'full' | 'none'
 *                 auto  the cheapest correct move (see transport above)
 *                 full  always a document load, even to a page of this SPA
 *                 none  only the address bar changes: nothing loads, nothing dispatches.
 *                       In a SPA the URL must belong to the action on screen.
 *   title_hint  string: the document title shown at once while an in-app page loads
 *
 * THE RETURNED PROMISE. An in-app navigation resolves once the new page is committed (its
 * layouts rendered, the action created - not necessarily ready); one made while a dispatch
 * is running (an on_load() redirect) is queued behind it and resolves when queued, since
 * awaiting the dispatch it waits behind from inside it could never finish. Every other kind resolves
 * as soon as the browser has been told to move. A full load or reload resolves BEFORE the
 * document unloads - and a download route never unloads it - so code after an awaited
 * full-load navigate may or may not run, and must not matter.
 *
 * Navigation from code is NOT guarded: a navigation that leaves the page turns the
 * navigation guard off (Rsx.clear_navigation_guard()) and goes ahead with no prompt.
 * A fragment-only move and a load:'none' rewrite do not leave the page and leave it on.
 */
class Rsx_Navigation {
    static HISTORY_OPTIONS = ['push', 'replace'];

    /**
     * The values a navigation's {via} option accepts. Absent means 'carry'.
     *
     * - 'carry'    the current _via rides along; kept only while the same page decides it.
     * - 'drop'     the current _via is not carried.
     * - 'continue' this navigation continues the same detour (a create page handing over to
     *              the record it created): the current _via is carried as an explicit claim,
     *              kept when any page in the new trail accepts and verifies it.
     */
    static VIA_OPTIONS = ['carry', 'drop', 'continue'];

    static LOAD_OPTIONS = ['auto', 'full', 'none'];

    static OPTION_KEYS = ['history', 'via', 'load', 'title_hint'];

    // The session-storage key of the full-load hand-off (see _write_handoff()).
    static HANDOFF_KEY = 'rsx_navigation_handoff';

    /**
     * Rsx.navigate(). Validation, classification and augmentation run synchronously, so
     * a bad option or an impossible load:'none' throws at the call.
     *
     * @param {string} url
     * @param {object} options
     * @returns {Promise<void>}
     */
    static navigate(url, options = {}) {
        const opts = Rsx_Navigation._validate(options, 'Rsx.navigate');
        return Rsx_Navigation._run(url, opts);
    }

    /**
     * Rsx.redirect(): navigate() replacing the current history entry. It takes every
     * option except history, which it IS.
     */
    static redirect(url, options = {}) {
        if (options && typeof options === 'object' && 'history' in options) {
            throw new Error(
                'Rsx.redirect() takes no {history} option: a redirect replaces the current history entry. '
                + "Use Rsx.navigate(url, {history: 'push'}) to add an entry instead."
            );
        }

        const opts = Rsx_Navigation._validate({ ...options, history: 'replace' }, 'Rsx.redirect');
        return Rsx_Navigation._run(url, opts);
    }

    /**
     * 1. VALIDATE. Every key and value is checked; nothing unknown is read as a default.
     *
     * @returns {{history: string, via: string, load: string, title_hint: string|null}}
     */
    static _validate(options, caller) {
        if (options === null || typeof options !== 'object' || Array.isArray(options)) {
            throw new Error(`${caller}(url, options): options must be an object, got ${JSON.stringify(options)}.`);
        }

        for (const key of Object.keys(options)) {
            if (!Rsx_Navigation.OPTION_KEYS.includes(key)) {
                throw new Error(
                    `${caller}(): unknown option {${key}}. Accepted: history ('push' | 'replace'), `
                    + "via ('carry' | 'drop' | 'continue'), load ('auto' | 'full' | 'none'), title_hint (a string)."
                );
            }
        }

        const opts = {
            history: 'history' in options ? options.history : 'push',
            via: 'via' in options ? options.via : 'carry',
            load: 'load' in options ? options.load : 'auto',
            title_hint: 'title_hint' in options ? options.title_hint : null,
        };

        if (!Rsx_Navigation.HISTORY_OPTIONS.includes(opts.history)) {
            throw new Error(
                `${caller}(): option {history: ${JSON.stringify(opts.history)}} is not understood. `
                + "Accepted: 'push' (the default), 'replace'."
            );
        }

        Rsx_Navigation._validate_via(opts.via, caller);

        if (!Rsx_Navigation.LOAD_OPTIONS.includes(opts.load)) {
            throw new Error(
                `${caller}(): option {load: ${JSON.stringify(opts.load)}} is not understood. `
                + "Accepted: 'auto' (the default), 'full', 'none'."
            );
        }

        if (opts.title_hint !== null && (typeof opts.title_hint !== 'string' || opts.title_hint === '')) {
            throw new Error(`${caller}(): option {title_hint} must be a non-empty string, got ${JSON.stringify(opts.title_hint)}.`);
        }

        if (opts.load === 'none' && opts.via === 'continue') {
            throw new Error(
                `${caller}(): {via: 'continue'} cannot be combined with {load: 'none'}: `
                + 'nothing loads, so no page judges the breadcrumb origin it would continue.'
            );
        }

        return opts;
    }

    /**
     * Throw unless a {via} value is one of VIA_OPTIONS - a typo is never read as the default.
     * Also the check Rsx_Form applies to an endpoint's redirect_via.
     */
    static _validate_via(via, caller) {
        if (!Rsx_Navigation.VIA_OPTIONS.includes(via)) {
            throw new Error(
                `${caller}(): option {via: ${JSON.stringify(via)}} is not understood. `
                + "Accepted: 'carry' (the default), 'drop', 'continue'. See rsx:man breadcrumb_origins."
            );
        }
    }

    /**
     * The pipeline: classify, augment, transport. One path for every caller.
     */
    static _run(url, opts) {
        // 2. CLASSIFY
        const target = Rsx_Navigation._classify(url);

        // 3. AUGMENT
        const augmented = Rsx_Navigation.augment(target.url, opts.via);

        // 4. TRANSPORT
        return Rsx_Navigation._transport({
            url: augmented.url,
            arrival: augmented.arrival,
            kind: target.kind,
            relation: Rsx_Navigation._relation_to_current(augmented.url, target.kind),
            options: opts,
        });
    }

    /**
     * 2. CLASSIFY the target against the page on screen.
     *
     *   elsewhere  another host, or not http(s) (mailto:, a custom scheme)
     *   same_site  this host, but not a page of the SPA running now: a Blade page, an action
     *              of another bootstrap, a portal path outside this portal, a download route
     *   in_app     a route of this SPA whose action runs under this page's bootstrap
     *
     * A same-host URL is returned RELATIVE (path + query + fragment); anything else as given.
     *
     * @returns {{url: string, kind: string}}
     */
    static _classify(url) {
        if (typeof url !== 'string' || url === '') {
            throw new Error(`Rsx.navigate(): the URL must be a non-empty string, got ${JSON.stringify(url)}.`);
        }

        const parsed = new URL(url, window.location.href);

        if ((parsed.protocol !== 'http:' && parsed.protocol !== 'https:') || parsed.host !== window.location.host) {
            return { url: url, kind: 'elsewhere' };
        }

        const relative = parsed.pathname + parsed.search + parsed.hash;
        const kind = Spa._route_of_this_spa(relative) ? 'in_app' : 'same_site';

        return { url: relative, kind: kind };
    }

    /**
     * 3. AUGMENT: carry the current page's breadcrumb origin (_via) onto the target, and say
     * how the target's _via got there.
     *
     * - A target naming its own _via keeps it: 'explicit'.
     * - {via: 'drop'} never carries.
     * - Otherwise, when the current URL has a _via and the target is a route this bundle
     *   knows on this host, the current value is copied: 'carried' - or, under
     *   {via: 'continue'}, 'explicit'. Navigation does not ask whether the origin applies
     *   there - the resolver decides that after the walk, and strips it if not.
     *
     * Also the new-tab href rewrite's rule (Spa._rewrite_link_via()).
     *
     * @param {string} url
     * @param {string} via - one of VIA_OPTIONS
     * @returns {{url: string, arrival: string|null}} arrival null = the target has no _via
     */
    static augment(url, via) {
        if (Spa_Via.raw(url) !== null) {
            return { url: url, arrival: 'explicit' };
        }

        if (via === 'drop') {
            return { url: url, arrival: null };
        }

        const current = Spa_Via.raw(window.location.search);
        if (current === null) {
            return { url: url, arrival: null };
        }

        const target = new URL(url, window.location.href);
        if (target.host !== window.location.host || !Spa.match_url_to_route(target.pathname + target.search)) {
            return { url: url, arrival: null };
        }

        return {
            url: Spa_Via.with_value(url, current),
            arrival: via === 'continue' ? 'explicit' : 'carried',
        };
    }

    /**
     * The (augmented) target against the URL on screen:
     *
     *   exact_same     identical path, query and fragment
     *   fragment_only  identical path and query, a different fragment
     *   other
     *
     * While an SPA dispatch is in progress the address bar already shows the page being
     * dispatched, which is not yet on screen; a navigation made then (an on_load()
     * redirect) is always 'other', so it queues behind the dispatch instead of reloading
     * or moving the fragment of a page that has not committed.
     */
    static _relation_to_current(url, kind) {
        if (kind === 'elsewhere' || (window.rsxapp.is_spa && Spa.is_dispatching)) {
            return 'other';
        }

        const target = new URL(url, window.location.href);
        const here = window.location;

        if (target.pathname !== here.pathname || target.search !== here.search) {
            return 'other';
        }

        return target.hash === here.hash ? 'exact_same' : 'fragment_only';
    }

    /**
     * 4. TRANSPORT. The only place outside the framework's internals that writes location
     * or history.
     *
     * @param {{url: string, arrival: string|null, kind: string, relation: string, options: object}} nav
     * @returns {Promise<void>}
     */
    static _transport(nav) {
        const { url, arrival, kind, relation, options } = nav;

        if (options.load === 'none') {
            Rsx_Navigation._rewrite_address(url, options.history, kind);
            return Promise.resolve();
        }

        if (options.load === 'auto' && relation === 'fragment_only') {
            // A native fragment navigation: the page stays, the browser adds (or replaces)
            // the entry and fires popstate, which the SPA turns into spa_hash_change.
            if (options.history === 'replace') {
                window.location.replace(url);
            } else {
                window.location.assign(url);
            }
            return Promise.resolve();
        }

        // Everything below leaves the page on screen. Code that navigates has decided.
        Rsx.clear_navigation_guard();

        if (options.load === 'auto' && relation === 'exact_same') {
            // Navigating to exactly where you are means "reload".
            window.location.reload();
            return Promise.resolve();
        }

        if (options.load === 'auto' && kind === 'in_app' && Spa._spa_enabled) {
            return Spa._dispatch(url, {
                history: options.history,
                arrival: arrival,
                title_hint: options.title_hint,
            });
        }

        return Rsx_Navigation._full_load(url, options.history, kind === 'elsewhere' ? null : arrival);
    }

    /**
     * {load: 'none'}: the address bar changes and nothing else - no load, no dispatch.
     *
     * In a SPA the new URL must still be a URL of the action on screen; anything else would
     * leave the address bar describing a page that is not there, so it throws. Off the SPA
     * any same-origin URL is accepted (the browser refuses another origin itself).
     */
    static _rewrite_address(url, history_mode, kind) {
        if (window.rsxapp.is_spa) {
            const target = new URL(url, window.location.href);
            const match = kind === 'elsewhere' ? null : Spa.match_url_to_route(target.pathname + target.search);
            const action = Spa.action();

            if (!match || !action || match.action_class !== action.constructor) {
                throw new Error(
                    `Rsx.navigate(${JSON.stringify(url)}, {load: 'none'}): that URL is not a URL of the action on `
                    + `screen (${action ? action.constructor.name : 'none'}), so rewriting the address bar to it `
                    + "would describe a page that is not there. Navigate to it instead (drop {load: 'none'})."
                );
            }

            Spa._rewrite_entry(url, history_mode);
            return;
        }

        if (history_mode === 'replace') {
            history.replaceState(history.state, '', url);
        } else {
            history.pushState(history.state, '', url);
        }
    }

    /**
     * A full document load. The ONE function that sends the browser to a new document -
     * Rsx.navigate()'s fall-through, and Spa._dispatch() when Back/Forward lands on a URL
     * this SPA cannot render.
     *
     * When the target is this site and carries a _via, the hand-off is written first, so the
     * next document judges its origin exactly as an SPA navigation would have.
     *
     * A target with the same path and query as the page on screen would be a mere fragment
     * navigation to location.assign() (no document load at all), so the entry is written
     * and the document reloaded instead.
     *
     * @param {string} url
     * @param {string} history_mode - 'push' | 'replace'
     * @param {string|null} arrival - how the target's _via arrived; null = no hand-off
     * @returns {Promise<void>}
     */
    static async _full_load(url, history_mode, arrival) {
        if (arrival !== null && Spa_Via.raw(url) !== null) {
            await Rsx_Navigation._write_handoff(url, arrival);
        }

        const target = new URL(url, window.location.href);
        const here = window.location;

        if (target.host === here.host && target.pathname === here.pathname && target.search === here.search) {
            if (target.hash !== here.hash) {
                if (history_mode === 'replace') {
                    history.replaceState(history.state, '', url);
                } else {
                    history.pushState(null, '', url);
                }
            }
            window.location.reload();
            return;
        }

        if (history_mode === 'replace') {
            window.location.replace(url);
        } else {
            window.location.assign(url);
        }
    }

    /**
     * 5. THE HAND-OFF. One session-storage entry naming the target (path + query) and what
     * the in-app path would have handed the next action: how its _via arrived and the
     * decider of the page being left (awaited from that page's own settlement).
     *
     * Single use and URL-matched - the next SPA boot deletes it whatever it says, and uses
     * it only when it names the URL that booted. No expiry of any kind.
     */
    static async _write_handoff(url, arrival) {
        let previous_decider = null;

        if (arrival === 'carried') {
            const leaving = window.rsxapp.is_spa ? Spa._action : null;
            const settlement = leaving ? leaving._spa_via_settlement : null;
            if (settlement) {
                previous_decider = (await settlement).decider ?? null;
            }
        }

        const target = new URL(url, window.location.href);

        Rsx_Storage.session_set(Rsx_Navigation.HANDOFF_KEY, {
            url: target.pathname + target.search,
            arrival: arrival,
            previous_decider: previous_decider,
        });
    }

    /**
     * Read and delete the hand-off (Spa._on_spa_init, once per document). Returns
     * {arrival, previous_decider} when it names this document's path + query, else null.
     */
    static _take_handoff() {
        const handoff = Rsx_Storage.session_get(Rsx_Navigation.HANDOFF_KEY);
        Rsx_Storage.session_remove(Rsx_Navigation.HANDOFF_KEY);

        if (!handoff || handoff.url !== window.location.pathname + window.location.search) {
            return null;
        }

        return { arrival: handoff.arrival, previous_decider: handoff.previous_decider ?? null };
    }
}
